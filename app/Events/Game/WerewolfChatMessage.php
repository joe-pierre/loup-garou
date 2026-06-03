<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WerewolfChatMessage implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $player,
        public readonly string $message,
        public readonly string $timestamp,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.werewolves")];
    }

    public function broadcastAs(): string
    {
        return 'werewolf.chat.message';
    }

    public function broadcastWith(): array
    {
        return [
            'pseudo'    => $this->player->pseudo,
            'message'   => $this->message,
            'timestamp' => $this->timestamp,
        ];
    }
}
