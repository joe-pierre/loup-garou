<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NoElimination implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly string $reason,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'no.elimination';
    }

    public function broadcastWith(): array
    {
        return ['reason' => $this->reason];
    }
}
