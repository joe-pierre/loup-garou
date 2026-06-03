<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerExcluded implements ShouldBroadcast
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
