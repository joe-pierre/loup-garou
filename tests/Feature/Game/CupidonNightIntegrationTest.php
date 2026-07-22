<?php

namespace Tests\Feature\Game;

use App\Events\Game\CupidonTurnStarted;
use App\Events\Game\SeerTurnStarted;
use App\Jobs\ProcessCupidonAutoAction;
use App\Jobs\ProcessCupidonTurn;
use App\Jobs\ProcessSeerTurn;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\PhaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Intégration de Cupidon dans PhaseManager::startNight() (SPEC_CUPIDON.md §3, §8 tâche 4).
 *
 * Zéro régression attendue : une partie sans Cupidon distribué doit démarrer la
 * Voyante exactement comme avant (ProcessSeerTurn dispatché directement par
 * startNight(), aucun délai supplémentaire, aucun Job Cupidon dispatché).
 */
class CupidonNightIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function makeDayGame(int $round): Game
    {
        return Game::factory()->create([
            'status'      => 'day',
            'max_players' => 6,
            'round'       => $round,
        ]);
    }

    public function test_cupidon_turn_started_avant_seer_turn_started_si_cupidon_distribue(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeDayGame(0);
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        app(PhaseManager::class)->startNight($game);

        $round = $game->fresh()->round;
        $this->assertSame(1, $round);

        Queue::assertPushed(ProcessCupidonTurn::class, fn ($job) => $job->gameId === $game->id && $job->round === $round);
        Queue::assertNotPushed(ProcessSeerTurn::class);

        // Exécution du tour Cupidon : broadcast CupidonTurnStarted, la Voyante n'a pas encore démarré.
        (new ProcessCupidonTurn($game->id, $round))->handle();

        Event::assertDispatched(CupidonTurnStarted::class, fn ($e) => $e->cupidon->id === $cupidon->id);
        Event::assertNotDispatched(SeerTurnStarted::class);
        Queue::assertPushed(ProcessCupidonAutoAction::class);

        // Timeout Cupidon (aucune action volontaire) : la nuit continue, la Voyante démarre.
        (new ProcessCupidonAutoAction($game->id, $cupidon->id, $round))->handle();
        Queue::assertPushed(ProcessSeerTurn::class, fn ($job) => $job->gameId === $game->id && $job->round === $round);

        (new ProcessSeerTurn($game->id, $round))->handle();

        Event::assertDispatched(SeerTurnStarted::class);
    }

    public function test_seer_turn_started_immediat_si_pas_de_cupidon(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeDayGame(0);
        GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        app(PhaseManager::class)->startNight($game);

        $round = $game->fresh()->round;
        $this->assertSame(1, $round);

        // Comportement identique à avant l'intégration Cupidon : aucun délai
        // supplémentaire, aucun Job Cupidon dispatché.
        Queue::assertNotPushed(ProcessCupidonTurn::class);
        Queue::assertPushed(ProcessSeerTurn::class, fn ($job) => $job->gameId === $game->id && $job->round === $round);

        (new ProcessSeerTurn($game->id, $round))->handle();

        Event::assertDispatched(SeerTurnStarted::class);
        Event::assertNotDispatched(CupidonTurnStarted::class);
    }

    public function test_pas_de_cupidon_turn_started_au_round_2_meme_avec_cupidon_distribue(): void
    {
        Event::fake();
        Queue::fake();

        // round = 1 avant startNight() → round = 2 après (Cupidon n'agit qu'au round 1).
        $game = $this->makeDayGame(1);
        GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        app(PhaseManager::class)->startNight($game);

        $round = $game->fresh()->round;
        $this->assertSame(2, $round);

        Queue::assertNotPushed(ProcessCupidonTurn::class);
        Queue::assertPushed(ProcessSeerTurn::class, fn ($job) => $job->gameId === $game->id && $job->round === $round);

        (new ProcessSeerTurn($game->id, $round))->handle();

        Event::assertNotDispatched(CupidonTurnStarted::class);
        Event::assertDispatched(SeerTurnStarted::class);
    }
}
