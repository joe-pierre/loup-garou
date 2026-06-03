<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerEliminated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $player,
        public readonly string $reason,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'player.eliminated';
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->player->id,
            'pseudo'    => $this->player->pseudo,
            'role'      => $this->player->role,
            'reason'    => $this->reason,
        ];
    }
}
