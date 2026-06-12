<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\GameAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWitchAutoAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
    ) {}

    public function handle(): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->round !== $this->round || ! in_array($game->status, ['night', 'processing_night'])) {
            return; // idempotent : déjà transitionné
        }

        $alreadyActed = $game->actions()
            ->where('round', $this->round)
            ->whereIn('type', ['witch_heal', 'witch_kill', 'witch_pass'])
            ->exists();

        if (! $alreadyActed) {
            $witch = $game->players()->where('role', 'witch')->where('is_alive', true)->first();

            if ($witch) {
                GameAction::create([
                    'game_id'          => $game->id,
                    'player_id'        => $witch->id,
                    'type'             => 'witch_pass',
                    'target_player_id' => null,
                    'round'            => $this->round,
                    'phase'            => 'night',
                ]);
            }
        }

        ProcessNightEnd::dispatch($this->gameId, $this->round)->delay(0);
    }
}
