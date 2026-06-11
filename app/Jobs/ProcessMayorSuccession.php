<?php

namespace App\Jobs;

use App\Events\Game\MayorSuccessionDone;
use App\Models\Game;
use App\Models\GameAction;
use App\Services\PhaseManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessMayorSuccession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
    ) {}

    public function handle(PhaseManager $phaseManager): void
    {
        $game = Game::find($this->gameId);

        // Accepter night, processing_night, day ET processing_day
        if (! $game || ! in_array($game->status, ['night', 'processing_night', 'day', 'processing_day']) || $game->round !== $this->round) {
            return;
        }

        $phaseToStart = in_array($game->status, ['night', 'processing_night']) ? 'night' : 'day';

        // La transaction retourne les données nécessaires au broadcast
        // mais ne broadcaste pas — le broadcast doit être HORS transaction
        $result = DB::transaction(function () use ($game) {
            $locked = Game::where('id', $game->id)
                ->whereIn('status', ['night', 'processing_night', 'day', 'processing_day'])
                ->where('round', $this->round)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return null;
            }

            $alreadyDone = GameAction::where('game_id', $locked->id)
                ->where('type', 'mayor_succession')
                ->where('round', $locked->round)
                ->lockForUpdate()
                ->exists();

            if ($alreadyDone) {
                return null;
            }

            $deadMayor = $locked->players()->where('is_mayor', true)->first();
            $deadMayor?->update(['is_mayor' => false]);

            $successor = $locked->alivePlayers()->inRandomOrder()->first();

            if (! $successor) {
                return null;
            }

            $successor->update(['is_mayor' => true]);

            GameAction::create([
                'game_id'          => $locked->id,
                'player_id'        => $deadMayor?->id ?? $successor->id,
                'type'             => 'mayor_succession',
                'target_player_id' => $successor->id,
                'round'            => $locked->round,
                'phase'            => $locked->status,
            ]);

            // NE PAS broadcaster ici — la transaction n'est pas encore committée
            return ['game' => $locked, 'successor' => $successor];
        });

        if (! $result) {
            return;
        }

        // Broadcast APRÈS commit de la transaction
        broadcast(new MayorSuccessionDone($result['game'], $result['successor'], true));

        if ($phaseToStart === 'night') {
            // Succession déclenchée la nuit : ne pas démarrer de nouvelle phase ici.
            // La fin de nuit est gérée par ProcessNightEnd (Tâche H) avec un délai buffer.
            return;
        }

        $game->refresh();
        $phaseManager->startNight($game);
    }
}