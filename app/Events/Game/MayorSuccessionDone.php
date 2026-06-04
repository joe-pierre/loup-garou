<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MayorSuccessionDone implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $newMayor,
        public readonly bool $wasRandom,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'mayor.succession.done';
    }

    public function broadcastWith(): array
    {
        return [
            'new_mayor_id'     => $this->newMayor->id,
            'new_mayor_pseudo' => $this->newMayor->pseudo,
            'was_random'       => $this->wasRandom,
        ];
    }
}
