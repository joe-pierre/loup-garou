<?php

namespace App\Events\Game;

use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un joueur a été déclaré inactif après expiration du délai de reconnexion.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : CheckReconnectionTimeout::handle() via GameService après expiration
 *   du timer de reconnexion (timer fixe, non configurable).
 *   Cet event est immédiat (pas différé par overlay).
 *
 * Note : implémente ShouldBroadcast (avec queue) et non ShouldBroadcastNow,
 *   car il est dispatché depuis un Job qui tourne en arrière-plan.
 */
class PlayerInactive implements ShouldBroadcast
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
        return 'player.inactive';
    }

    /**
     * @return array{
     *   pseudo: string,  // pseudo du joueur déclaré inactif
     * }
     */
    public function broadcastWith(): array
    {
        return ['pseudo' => $this->pseudo];
    }
}
