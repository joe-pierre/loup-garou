<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class GameFinishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?string $winnerTeam) {}

    public function via($notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        $title = match ($this->winnerTeam) {
            'villagers'  => '🏆 Le village a gagné !',
            'werewolves' => '🐺 Les loups ont gagné !',
            default      => '🏁 Partie terminée',
        };

        return (new WebPushMessage)
            ->title($title)
            ->body('La partie est terminée.')
            ->icon('/images/icon-192.png')
            ->badge('/images/badge-72.png');
    }
}
