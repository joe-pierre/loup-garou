<?php

namespace App\Events\Game;

use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerDisconnected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $gameId,
        public readonly string $pseudo,
        public readonly int    $reconnectionTimeout,
    ) {}

    public static function fromPlayer(GamePlayer $player, int $timeout): self
    {
        return new self($player->game_id, $player->pseudo, $timeout);
    }

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->gameId}")];
    }

    public function broadcastAs(): string
    {
        return 'player.disconnected';
    }

    public function broadcastWith(): array
    {
        return [
            'pseudo'               => $this->pseudo,
            'reconnection_timeout' => $this->reconnectionTimeout,
        ];
    }
}
