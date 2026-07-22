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
 * Révèle à un joueur l'identité de son amoureux, une fois Cupidon a formé le couple.
 *
 * Canal : PRIVÉ — game.{gameId}.player.{loverPlayerId}
 * Confidentialité totale (SPEC_CUPIDON.md §1) : seuls les deux amoureux reçoivent
 * cet event. Jamais broadcasté à Cupidon lui-même s'il fait partie du couple — il
 * connaît déjà le résultat via la réponse de son action (CupidonAction::link()).
 *
 * Déclencheur : CupidonAction::link(), une fois par amoureux distinct de Cupidon
 * (donc un seul broadcast si Cupidon s'est choisi lui-même).
 */
class LoverRevealed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $lover,
        public readonly GamePlayer $partner,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.player.{$this->lover->id}")];
    }

    public function broadcastAs(): string
    {
        return 'lover.revealed';
    }

    /**
     * @return array{
     *   partner_player_id: int,
     *   partner_pseudo: string,
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'partner_player_id' => $this->partner->id,
            'partner_pseudo'    => $this->partner->pseudo,
        ];
    }
}
