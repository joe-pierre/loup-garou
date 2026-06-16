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
 * Résultat de l'inspection nocturne de la voyante.
 *
 * Canal : PRIVÉ — game.{gameId}.player.{seerPlayerId}
 * ⚠️ Cet event ne doit JAMAIS être broadcasté sur un canal public.
 *    Le rôle de la cible est une information secrète réservée à la voyante.
 *
 * Déclencheur : GameService::seerCheck() après enregistrement de seer_check en base.
 *
 * Données sensibles : contient le rôle réel de la cible — canal privé obligatoire.
 */
class SeerResult implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $seer,
        public readonly GamePlayer $target,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.player.{$this->seer->id}")];
    }

    public function broadcastAs(): string
    {
        return 'seer.result';
    }

    /**
     * @return array{
     *   target_player_id: int,    // identifiant de la cible inspectée
     *   pseudo: string,           // pseudo de la cible
     *   role: string,             // rôle réel de la cible (villager, werewolf, seer, etc.)
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'target_player_id' => $this->target->id,
            'pseudo'           => $this->target->pseudo,
            'role'             => $this->target->role,
        ];
    }
}
