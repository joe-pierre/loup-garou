<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DayVoteCast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly array $summary,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'day.vote.cast';
    }

    public function broadcastWith(): array
    {
        return [
            'votes' => collect($this->summary)
                ->map(fn ($totalWeight, $targetPlayerId) => [
                    'target_player_id' => $targetPlayerId,
                    'total_weight'     => $totalWeight,
                ])
                ->values()
                ->toArray(),
        ];
    }
}
