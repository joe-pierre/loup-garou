<?php

namespace App\Console\Commands;

use App\Models\Game;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanOldGames extends Command
{
    protected $signature   = 'games:clean';
    protected $description = 'Supprime les parties terminées depuis plus de 7 jours';

    public function handle(): int
    {
        $count = Game::where('finished_at', '<', now()->subDays(7))->delete();

        $this->info("{$count} partie(s) supprimée(s).");
        Log::info("CleanOldGames: {$count} parties supprimées");

        return self::SUCCESS;
    }
}
