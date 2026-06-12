<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WitchTurnStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $witch,
        public readonly GamePlayer $victim,
        public readonly bool $healAvailable,
        public readonly bool $killAvailable,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.player.{$this->witch->id}")];
    }

    public function broadcastAs(): string
    {
        return 'witch.turn.started';
    }

    public function broadcastWith(): array
    {
        return [
            'victim' => [
                'id'     => $this->victim->id,
                'pseudo' => $this->victim->pseudo,
            ],
            'heal_available' => $this->healAvailable,
            'kill_available' => $this->killAvailable,
            'timer'          => $this->game->timer('witch'),
        ];
    }
}
