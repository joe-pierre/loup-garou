<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WerewolvesVoteCast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly array $wolfVoteState,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.werewolves")];
    }

    public function broadcastAs(): string
    {
        return 'werewolves.vote.cast';
    }

    public function broadcastWith(): array
    {
        return ['wolves' => $this->wolfVoteState];
    }
}
