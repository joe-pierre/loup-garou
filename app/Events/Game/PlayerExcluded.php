<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un joueur a été exclu de la partie par le host.
 *
 * Canal : double selon le contexte
 *   - PUBLIC — game.{gameId} : si $reason = null (annonce sans motif à tous)
 *   - PRIVÉ — game.{gameId}.player.{excludedPlayerId} : si $reason = string (motif pour le joueur exclu)
 *
 * Déclencheur : GameService::excludePlayer() depuis LobbyController::exclude().
 *   Dispatché deux fois : une fois public (sans motif), une fois privé (avec motif).
 */
class PlayerExcluded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly int    $gameId;
    public readonly int    $excludedPlayerId;
    public readonly string $pseudo;

    /**
     * Instancier avec $reason = null pour le broadcast public (pseudo seulement).
     * Instancier avec $reason = string pour le broadcast privé (motif inclus).
     */
    public function __construct(
        Game $game,
        GamePlayer $player,
        public readonly ?string $reason = null,
    ) {
        $this->gameId           = $game->id;
        $this->excludedPlayerId = $player->id;
        $this->pseudo           = $player->pseudo;
    }

    public function broadcastOn(): array
    {
        if ($this->reason === null) {
            return [new Channel("game.{$this->gameId}")];
        }

        return [new PrivateChannel("game.{$this->gameId}.player.{$this->excludedPlayerId}")];
    }

    public function broadcastAs(): string
    {
        return 'player.excluded';
    }

    /**
     * Payload public ($reason === null) :
     * @return array{pseudo: string, excluded_player_id: int}
     *
     * Payload privé ($reason !== null) :
     * @return array{pseudo: string, excluded_player_id: int, reason: string}
     *   reason : motif de l'exclusion fourni par le host
     */
    public function broadcastWith(): array
    {
        $payload = [
            'pseudo'             => $this->pseudo,
            'excluded_player_id' => $this->excludedPlayerId,
        ];

        if ($this->reason !== null) {
            $payload['reason'] = $this->reason;
        }

        return $payload;
    }
}
