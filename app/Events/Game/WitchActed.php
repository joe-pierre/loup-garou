<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WitchActed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $witch,
        public readonly string $action,
        public readonly ?GamePlayer $target,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.player.{$this->witch->id}")];
    }

    public function broadcastAs(): string
    {
        return 'witch.acted';
    }

    public function broadcastWith(): array
    {
        return [
            'action'           => $this->action,
            'target_player_id' => $this->target?->id,
            'pseudo'           => $this->target?->pseudo,
        ];
    }
}
