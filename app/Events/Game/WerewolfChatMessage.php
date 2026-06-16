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
 * Message envoyé dans le chat secret des loups-garous.
 *
 * Canal : PRIVÉ — game.{gameId}.werewolves
 * Seuls les joueurs avec le rôle werewolf ont accès à ce canal.
 * Ce message ne doit jamais transiter par le canal public game.{gameId}.
 *
 * Déclencheur : ChatService::sendMessage() pour les messages de canal 'werewolves'.
 *   Cet event est immédiat (pas différé par overlay).
 */
class WerewolfChatMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $player,
        public readonly string $message,
        public readonly string $timestamp,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("game.{$this->game->id}.werewolves")];
    }

    public function broadcastAs(): string
    {
        return 'werewolf.chat.message';
    }

    /**
     * @return array{
     *   pseudo: string,     // pseudo du loup expéditeur
     *   message: string,    // contenu du message (max 200 caractères)
     *   timestamp: string,  // horodatage ISO 8601 de l'envoi
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'pseudo'    => $this->player->pseudo,
            'message'   => $this->message,
            'timestamp' => $this->timestamp,
        ];
    }
}
