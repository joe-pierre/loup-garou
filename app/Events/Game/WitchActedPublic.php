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
 * Déclencheur : ActionController::witchAct() en parallèle de WitchActed (canal privé).
 *
 * target_pseudo est fourni uniquement pour l'action 'kill' (poison public), null pour 'heal'.
 * La nature exacte de l'action reste dans WitchActed (canal privé sorcière).
 */
class WitchActedPublic implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly ?string $targetPseudo = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'witch.acted.public';
    }

    /**
     * @return array{target_pseudo: string|null}  — pseudo de la cible empoisonnée, null pour heal
     */
    public function broadcastWith(): array
    {
        return ['target_pseudo' => $this->targetPseudo];
    }
}
