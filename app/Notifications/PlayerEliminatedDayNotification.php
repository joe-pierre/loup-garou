<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class PlayerEliminatedDayNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $role) {}

    public function via($notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        $label = config('game_ui.role_labels_emoji')[$this->role] ?? $this->role;

        return (new WebPushMessage)
            ->title('⚖️ Le village t\'a éliminé')
            ->body("Tu étais {$label}.")
            ->icon('/images/icon-192.png')
            ->badge('/images/badge-72.png');
    }
}
