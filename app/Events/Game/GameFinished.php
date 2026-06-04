<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class GameFinished implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly int     $gameId;
    public readonly ?string $winnerTeam;
    /** @var array<int, array{id:int,pseudo:string,role:string|null}> */
    public readonly array   $players;

    /**
     * @param  Collection<int, GamePlayer> $players
     * @param  string|null                 $winnerTeam  null = partie annulée (rôles non révélés)
     */
    public function __construct(Game $game, Collection $players, ?string $winnerTeam)
    {
        $this->gameId     = $game->id;
        $this->winnerTeam = $winnerTeam;
        $revealRoles      = $winnerTeam !== null;

        $this->players = $players->map(fn (GamePlayer $p) => [
            'id'       => $p->id,
            'pseudo'   => $p->pseudo,
            'role'     => $revealRoles ? $p->role : null,
            'is_alive' => (bool) $p->is_alive,
        ])->values()->toArray();
    }

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->gameId}")];
    }

    public function broadcastAs(): string
    {
        return 'game.finished';
    }

    public function broadcastWith(): array
    {
        return [
            'winner_team' => $this->winnerTeam,
            'players'     => $this->players,
        ];
    }
}
