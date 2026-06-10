<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DayStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly ?GamePlayer $victim,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'day.started';
    }

    public function broadcastWith(): array
    {
        return [
            'round'  => $this->game->round,
            'killed' => $this->victim ? [
                'player_id' => $this->victim->id,
                'pseudo'    => $this->victim->pseudo,
                'role'      => $this->victim->role,
            ] : null,
        ];
    }
}