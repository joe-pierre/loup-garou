<?php

namespace App\Events\Game;

use App\Models\Game;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notification publique indiquant que la sorcière a agi cette nuit.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : GameService::witchAct() en parallèle de WitchActed (canal privé).
 *
 * Données sensibles : le payload est intentionnellement vide.
 *   La nature de l'action (soin ou poison) et l'identité de la cible
 *   sont exclusivement dans WitchActed (canal privé sorcière).
 */
class WitchActedPublic implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Game $game) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'witch.acted.public';
    }

    /**
     * @return array{}  — payload intentionnellement vide, aucune info sur la potion ni la cible
     */
    public function broadcastWith(): array
    {
        return []; // intentionnellement vide — aucune info sur la potion ni la cible
    }
}
