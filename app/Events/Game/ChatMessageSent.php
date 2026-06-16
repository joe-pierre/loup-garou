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
 * Message envoyé dans le chat général ou le chat des morts.
 *
 * Canal : PUBLIC — game.{gameId}
 *
 * Déclencheur : ChatService::sendMessage() depuis ChatController::send().
 *   Cet event est immédiat (pas différé par overlay).
 *
 * Note : les messages du canal 'werewolves' utilisent WerewolfChatMessage
 *   (canal privé loups) et ne passent pas par cet event.
 */
class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $player,
        public readonly string $message,
        public readonly string $timestamp,
        public readonly string $channel = 'general',
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->game->id}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.sent';
    }

    /**
     * @return array{
     *   pseudo: string,     // pseudo de l'expéditeur
     *   message: string,    // contenu du message (max 200 caractères)
     *   channel: string,    // canal du message : 'general' ou 'dead'
     *   timestamp: string,  // horodatage ISO 8601 de l'envoi
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'pseudo'    => $this->player->pseudo,
            'message'   => $this->message,
            'channel'   => $this->channel,
            'timestamp' => $this->timestamp,
        ];
    }
}
