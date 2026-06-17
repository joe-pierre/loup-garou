<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class RoleAssignedNotification extends Notification implements ShouldQueue
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
            ->title('La partie commence !')
            ->body("Votre rôle : {$label}")
            ->icon('/images/icon-192.png')
            ->badge('/images/badge-72.png');
    }
}
