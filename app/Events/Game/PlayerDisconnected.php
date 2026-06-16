<?php

namespace App\Events\Game;

use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un joueur s'est déconnecté pendant une partie en cours.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : GameService::handleDisconnection() depuis GameController::disconnect().
 *   Cet event est immédiat (pas différé par overlay).
 */
class PlayerDisconnected implements ShouldBroadcastNow
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

    /**
     * @return array{
     *   pseudo: string,               // pseudo du joueur déconnecté
     *   reconnection_timeout: int,    // délai en secondes avant que le joueur soit déclaré inactif
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'pseudo'               => $this->pseudo,
            'reconnection_timeout' => $this->reconnectionTimeout,
        ];
    }
}
