<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Début de la phase de jour — révèle le bilan de la nuit.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : PhaseManager::startDay() après résolution complète de la nuit
 *   (loups + éventuellement sorcière + éventuellement chasseur).
 *
 * Note : cet event est différé pendant l'overlay d'annonce (PhaseAnnouncement).
 *   Il ne doit pas être dispatché avant que l'overlay soit consommé côté client.
 */
class DayStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly ?GamePlayer $victim,
        public readonly bool $witchActed = false,
        public readonly ?int $savedPlayerId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'day.started';
    }

    /**
     * @return array{
     *   round: int,                              // numéro du round courant
     *   killed: array{
     *     player_id: int,                        // identifiant du joueur tué cette nuit
     *     pseudo: string,                        // pseudo du joueur tué
     *     role: string,                          // rôle révélé à l'élimination
     *   }|null,                                  // null si personne n'a été tué (sorcière a sauvé, ou égalité loups)
     *   witch_acted: bool,                       // true si la sorcière a utilisé une potion cette nuit
     *   saved_player_id: int|null,               // identifiant du joueur sauvé par la sorcière, null sinon
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'round'           => $this->game->round,
            'killed'          => $this->victim ? [
                'player_id' => $this->victim->id,
                'pseudo'    => $this->victim->pseudo,
                'role'      => $this->victim->role,
            ] : null,
            'witch_acted'     => $this->witchActed,
            'saved_player_id' => $this->savedPlayerId,
        ];
    }
}