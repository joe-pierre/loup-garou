<?php

namespace App\Events\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Début du tour de la sorcière.
 *
 * Canal : PRIVÉ — game.{gameId}.player.{witchPlayerId}
 * Seule la sorcière reçoit cet event. La victime des loups ne doit pas
 * être révélée publiquement avant DayStarted.
 *
 * Déclencheur : ProcessWitchTurn::handle() après résolution du vote des loups.
 *
 * Données sensibles : l'identité de la victime nocturne est confidentielle
 *   jusqu'à l'annonce publique du matin (DayStarted).
 */
class WitchTurnStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $witch,
        public readonly ?GamePlayer $victim,
        public readonly bool $healAvailable,
        public readonly bool $killAvailable,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.player.{$this->witch->id}")];
    }

    public function broadcastAs(): string
    {
        return 'witch.turn.started';
    }

    /**
     * @return array{
     *   victim: array{id: int, pseudo: string}|null,  // victime des loups cette nuit, null si égalité de vote
     *   heal_available: bool,   // true si la potion de soin n'a pas encore été utilisée
     *   kill_available: bool,   // true si la potion de poison n'a pas encore été utilisée
     *   timer: int,             // durée du tour de la sorcière en secondes
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'victim' => $this->victim ? [
                'id'     => $this->victim->id,
                'pseudo' => $this->victim->pseudo,
            ] : null,
            'heal_available' => $this->healAvailable,
            'kill_available' => $this->killAvailable,
            'timer'          => $this->game->timer('witch'),
        ];
    }
}
