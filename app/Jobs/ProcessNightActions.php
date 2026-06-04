<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Implémenté en Tâche 16
class ProcessNightActions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $gameId) {}

    public function handle(): void
    {
        // TODO (tâche 16) : après $killedPlayer->update(['is_alive' => false]) :
        // try {
        //     $killedPlayer->load('user');
        //     $killedPlayer->user->notify(new \App\Notifications\PlayerKilledNightNotification());
        // } catch (\Throwable) {}
    }
}
