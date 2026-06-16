<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un nouveau joueur a rejoint la salle d'attente.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : GameService::joinGame() après création du GamePlayer en base.
 */
class PlayerJoined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $player,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'player.joined';
    }

    /**
     * @return array{
     *   pseudo: string,           // pseudo du joueur qui vient de rejoindre
     *   players: array<int, array{id: int, pseudo: string, is_host: bool}>,  // liste complète des joueurs actuels
     *   slots_remaining: int,     // nombre de places encore disponibles
     * }
     */
    public function broadcastWith(): array
    {
        $players = $this->game->players()
            ->select(['id', 'pseudo', 'is_host'])
            ->get()
            ->map(fn ($p) => [
                'id'      => $p->id,
                'pseudo'  => $p->pseudo,
                'is_host' => (bool) $p->is_host,
            ])
            ->toArray();

        return [
            'pseudo'          => $this->player->pseudo,
            'players'         => $players,
            'slots_remaining' => $this->game->max_players - count($players),
        ];
    }
}
