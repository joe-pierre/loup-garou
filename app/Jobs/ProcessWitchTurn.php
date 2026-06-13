<?php

namespace App\Jobs;

use App\Events\Game\WitchTurnStarted;
use App\Models\Game;
use App\Services\VoteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWitchTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $gameId,
        public readonly int $round,
    ) {}

    public function handle(VoteService $voteService): void
    {
        $game = Game::find($this->gameId);

        if (! $game || $game->round !== $this->round || ! in_array($game->status, ['night', 'processing_night'])) {
            return;
        }

        $witch = $game->players()->where('role', 'witch')->where('is_alive', true)->first();

        if (! $witch) {
            return;
        }

        // Guard #6 : sorcière a déjà agi ce round (ne re-déclenche pas le tour)
        $alreadyActed = $game->actions()
            ->where('round', $this->round)
            ->whereIn('type', ['witch_heal', 'witch_kill', 'witch_pass'])
            ->exists();

        if ($alreadyActed) {
            return;
        }

        // Guard #3 : égalité chez les loups -> pas de victime à sauver/voir
        $victim = $voteService->resolveNightVote($game);

        if (! $victim) {
            ProcessNightEnd::dispatch($this->gameId, $this->round)->delay(0);
            return;
        }

        $healAvailable = ! ($witch->settings['witch_heal_used'] ?? false) && $victim->id !== $witch->id;
        $killAvailable = ! ($witch->settings['witch_kill_used'] ?? false);

        broadcast(new WitchTurnStarted($game, $witch, $victim, $healAvailable, $killAvailable));

        ProcessWitchAutoAction::dispatch($this->gameId, $this->round)
            ->delay(now()->addSeconds($game->timer('witch')));
    }
}
