<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly int     $gameId;
    public readonly ?int    $targetPlayerId;
    public readonly ?string $targetRole;
    public readonly ?array  $allies; // [{id, pseudo}] pour les loups-garous, null sinon

    /**
     * Instancier sans $player → broadcast public (liste joueurs, sans rôles).
     * Instancier avec $player → broadcast privé (rôle + alliés loups si applicable).
     *
     * @param  array<int, array{id:int,pseudo:string}>|null $allies
     */
    public function __construct(Game $game, ?GamePlayer $player = null, ?array $allies = null)
    {
        $this->gameId         = $game->id;
        $this->targetPlayerId = $player?->id;
        $this->targetRole     = $player?->role;
        $this->allies         = $allies;
    }

    public function broadcastOn(): array
    {
        if ($this->targetPlayerId === null) {
            return [new Channel("game.{$this->gameId}")];
        }

        return [new PrivateChannel("game.{$this->gameId}.player.{$this->targetPlayerId}")];
    }

    public function broadcastAs(): string
    {
        return 'game.started';
    }

    public function broadcastWith(): array
    {
        // Liste publique sans rôles (même payload pour public et privé)
        $publicPlayers = Game::with('players')->find($this->gameId)
            ->players
            ->map(fn (GamePlayer $p) => [
                'id'      => $p->id,
                'pseudo'  => $p->pseudo,
                'is_host' => (bool) $p->is_host,
            ])
            ->values()
            ->toArray();

        if ($this->targetPlayerId === null) {
            return ['players' => $publicPlayers];
        }

        return [
            'players' => $publicPlayers,
            'role'    => $this->targetRole,
            'allies'  => $this->allies ?? [],
        ];
    }
}
