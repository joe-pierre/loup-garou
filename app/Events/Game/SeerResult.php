<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SeerResult implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $seer,
        public readonly GamePlayer $target,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.player.{$this->seer->id}")];
    }

    public function broadcastAs(): string
    {
        return 'seer.result';
    }

    public function broadcastWith(): array
    {
        return [
            'target_player_id' => $this->target->id,
            'pseudo'           => $this->target->pseudo,
            'role'             => $this->target->role,
        ];
    }
}
