<?php

namespace App\Jobs;

use App\Events\Game\MayorSuccessionDone;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
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
        public readonly ?int $victimId = null,
    ) {}

    public function handle(PhaseManager $phaseManager): void
    {
        $game = Game::find($this->gameId);

        // Accepter night, processing_night ET day
        if (! $game || ! in_array($game->status, ['night', 'processing_night', 'day']) || $game->round !== $this->round) {
            return;
        }

        $phaseToStart = in_array($game->status, ['night', 'processing_night']) ? 'night' : 'day';

        $shouldTransition = DB::transaction(function () use ($game) {
            $locked = Game::where('id', $game->id)
                ->whereIn('status', ['night', 'processing_night', 'day'])
                ->where('round', $this->round)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            $alreadyDone = GameAction::where('game_id', $locked->id)
                ->where('type', 'mayor_succession')
                ->where('round', $locked->round)
                ->lockForUpdate()
                ->exists();

            if ($alreadyDone) {
                return false;
            }

            $deadMayor = $locked->players()->where('is_mayor', true)->first();
            $deadMayor?->update(['is_mayor' => false]);

            $successor = $locked->alivePlayers()->inRandomOrder()->first();

            if (! $successor) {
                return false;
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

            broadcast(new MayorSuccessionDone($locked, $successor, true));

            return true;
        });

        if (! $shouldTransition) {
            return;
        }

        $game->refresh();

        if ($phaseToStart === 'night') {
            $victim = $this->victimId ? GamePlayer::find($this->victimId) : null;
            $phaseManager->startDay($game, $victim);
        } else {
            $phaseManager->startNight($game);
        }
    }
}