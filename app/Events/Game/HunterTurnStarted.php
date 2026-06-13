<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HunterTurnStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $hunter,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.player.{$this->hunter->id}")];
    }

    public function broadcastAs(): string
    {
        return 'hunter.turn.started';
    }

    public function broadcastWith(): array
    {
        return [
            'timer' => $this->game->timer('hunter'),
        ];
    }
}
