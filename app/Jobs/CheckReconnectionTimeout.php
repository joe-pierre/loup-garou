<?php

namespace App\Jobs;

use App\Events\Game\PlayerInactive;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\GameService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Vérifie si un joueur déconnecté a reconnu sa reconnexion dans le délai imparti.
 *
 * Dispatché par GameService::handleDisconnection() avec un délai de reconnection_timeout.
 *
 * Pattern cache token : handleDisconnection() génère un UUID stocké dans
 * Cache::put("player_disconnected.{id}", $token, TTL). Le endpoint /reconnect
 * supprime la clé via handleReconnection(). Ce job compare son token au cache :
 *   - Mismatch ou clé absente → le joueur s'est reconnecté → return (no-op).
 *   - Match → timeout dépassé → joueur marqué inactif.
 *
 * Voir DECISIONS.md "Détection reconnexion via token Cache plutôt que colonne DB".
 */
class CheckReconnectionTimeout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param int    $playerId        Identifiant du joueur déconnecté.
     * @param string $disconnectToken UUID généré à la déconnexion — invalide si /reconnect a été appelé.
     */
    public function __construct(
        public readonly int    $playerId,
        public readonly string $disconnectToken,
    ) {}

    /**
     * Invalide le joueur si le token cache correspond encore (pas de reconnexion).
     *
     * Guards d'entrée :
     *   - Le joueur existe.
     *   - Cache::get("player_disconnected.{$playerId}") === $this->disconnectToken.
     *
     * Effets si timeout confirmé :
     *   - Supprime la clé cache.
     *   - Marque le joueur is_inactive=true.
     *   - Broadcast PlayerInactive.
     *   - Si >50% des joueurs vivants sont inactifs → GameService::cancelGame().
     */
    public function handle(GameService $gameService): void
    {
        $player = GamePlayer::find($this->playerId);

        if (! $player) {
            return;
        }

        // Si le token en cache ne correspond plus → /reconnect a été appelé
        $cachedToken = Cache::get("player_disconnected.{$this->playerId}");
        if ($cachedToken !== $this->disconnectToken) {
            return;
        }

        Cache::forget("player_disconnected.{$this->playerId}");

        $player->update(['is_inactive' => true]);

        broadcast(PlayerInactive::fromPlayer($player));

        $game = Game::find($player->game_id);

        if (! $game || ! in_array($game->status, ['night', 'day', 'electing_mayor'], true)) {
            return;
        }

        $aliveCount         = $game->aliveCount();
        $inactiveAliveCount = $game->alivePlayers()->where('is_inactive', true)->count();

        if ($aliveCount > 0 && $inactiveAliveCount > $aliveCount / 2) {
            $gameService->cancelGame($game);
        }
    }
}
