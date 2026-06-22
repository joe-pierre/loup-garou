<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class PlayerEliminatedPublicNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $pseudo,
        public readonly string $role,
        public readonly string $context, // 'night' | 'day' | 'random'
    ) {}

    public function via($notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        $roleLabel = config('game_ui.role_labels')[$this->role] ?? $this->role;

        $title = match ($this->context) {
            'night'  => '🌙 Élimination nocturne',
            'random' => '🎲 Tirage au sort',
            default  => '⚖️ Vote du village',
        };

        return (new WebPushMessage)
            ->title($title)
            ->body("{$this->pseudo} a été éliminé — il était {$roleLabel}.")
            ->icon('/images/icon-192.png')
            ->badge('/images/badge-72.png');
    }
}
