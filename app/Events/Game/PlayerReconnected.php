<?php

namespace App\Events\Game;

use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlayerReconnected implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $gameId,
        public readonly string $pseudo,
    ) {}

    public static function fromPlayer(GamePlayer $player): self
    {
        return new self($player->game_id, $player->pseudo);
    }

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->gameId}")];
    }

    public function broadcastAs(): string
    {
        return 'player.reconnected';
    }

    public function broadcastWith(): array
    {
        return ['pseudo' => $this->pseudo];
    }
}
