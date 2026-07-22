<?php

namespace Tests\Feature\Game;

use App\Events\Game\CupidonTurnStarted;
use App\Events\Game\LoverRevealed;
use App\Jobs\ProcessCupidonAutoAction;
use App\Jobs\ProcessCupidonTurn;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ces deux Jobs ne sont pas branchés dans PhaseManager à ce stade
 * (SPEC_CUPIDON.md §8, tâche 3) — ils sont dispatchés manuellement ici,
 * sans dépendre du flux de jeu réel.
 */
class CupidonJobsTest extends TestCase
{
    use RefreshDatabase;

    private function makeNightRoundOneGame(): Game
    {
        return Game::factory()->create([
            'status'      => 'night',
            'max_players' => 6,
            'round'       => 1,
        ]);
    }

    // --- ProcessCupidonTurn ---------------------------------------------

    public function test_cupidon_turn_broadcast_et_dispatch_auto_action(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightRoundOneGame();
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessCupidonTurn($game->id, $game->round))->handle();

        Event::assertDispatched(CupidonTurnStarted::class, fn ($e) => $e->cupidon->id === $cupidon->id);
        Queue::assertPushed(ProcessCupidonAutoAction::class, function ($job) use ($game, $cupidon) {
            return $job->gameId === $game->id
                && $job->cupidonId === $cupidon->id
                && $job->round === $game->round;
        });
    }

    public function test_cupidon_turn_ne_fait_rien_si_pas_de_cupidon_dans_la_composition(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightRoundOneGame();
        GamePlayer::factory()->count(5)->villager()->create(['game_id' => $game->id]);

        (new ProcessCupidonTurn($game->id, $game->round))->handle();

        Event::assertNotDispatched(CupidonTurnStarted::class);
        Queue::assertNotPushed(ProcessCupidonAutoAction::class);
    }

    public function test_cupidon_turn_ne_fait_rien_si_cupidon_mort(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightRoundOneGame();
        GamePlayer::factory()->cupidon()->dead()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessCupidonTurn($game->id, $game->round))->handle();

        Event::assertNotDispatched(CupidonTurnStarted::class);
        Queue::assertNotPushed(ProcessCupidonAutoAction::class);
    }

    public function test_cupidon_turn_ne_fait_rien_si_cupidon_inactif(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightRoundOneGame();
        GamePlayer::factory()->cupidon()->create(['game_id' => $game->id, 'is_inactive' => true]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessCupidonTurn($game->id, $game->round))->handle();

        Event::assertNotDispatched(CupidonTurnStarted::class);
        Queue::assertNotPushed(ProcessCupidonAutoAction::class);
    }

    public function test_cupidon_turn_skip_si_mauvais_round(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightRoundOneGame();
        GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessCupidonTurn($game->id, 2))->handle();

        Event::assertNotDispatched(CupidonTurnStarted::class);
        Queue::assertNotPushed(ProcessCupidonAutoAction::class);
    }

    public function test_cupidon_turn_skip_si_mauvais_status(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessCupidonTurn($game->id, $game->round))->handle();

        Event::assertNotDispatched(CupidonTurnStarted::class);
        Queue::assertNotPushed(ProcessCupidonAutoAction::class);
    }

    // --- ProcessCupidonAutoAction ---------------------------------------

    public function test_cupidon_auto_action_ne_forme_aucun_couple_si_pas_dagi(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightRoundOneGame();
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessCupidonAutoAction($game->id, $cupidon->id, $game->round))->handle();

        $this->assertNull($cupidon->fresh()->lover_player_id);
        $this->assertSame(0, GameAction::where('game_id', $game->id)->where('type', 'cupidon_link')->count());
        Event::assertNotDispatched(CupidonTurnStarted::class);
        Event::assertNotDispatched(LoverRevealed::class);
        Queue::assertNothingPushed();
    }

    public function test_cupidon_auto_action_skipped_if_already_acted(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightRoundOneGame();
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        $target1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $cupidon->id, 'type' => 'cupidon_link',
            'target_player_id' => $target1->id, 'round' => $game->round, 'phase' => 'night',
        ]);
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $cupidon->id, 'type' => 'cupidon_link',
            'target_player_id' => $target2->id, 'round' => $game->round, 'phase' => 'night',
        ]);

        (new ProcessCupidonAutoAction($game->id, $cupidon->id, $game->round))->handle();

        // Aucune GameAction supplémentaire créée par le job (toujours un no-op).
        $this->assertSame(2, GameAction::where('game_id', $game->id)->where('type', 'cupidon_link')->count());
        Event::assertNotDispatched(CupidonTurnStarted::class);
        Event::assertNotDispatched(LoverRevealed::class);
        Queue::assertNothingPushed();
    }

    public function test_cupidon_auto_action_skip_si_mauvais_round(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightRoundOneGame();
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessCupidonAutoAction($game->id, $cupidon->id, 2))->handle();

        Event::assertNotDispatched(CupidonTurnStarted::class);
        Event::assertNotDispatched(LoverRevealed::class);
        Queue::assertNothingPushed();
    }

    public function test_cupidon_auto_action_skip_si_mauvais_status(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessCupidonAutoAction($game->id, $cupidon->id, $game->round))->handle();

        Event::assertNotDispatched(CupidonTurnStarted::class);
        Event::assertNotDispatched(LoverRevealed::class);
        Queue::assertNothingPushed();
    }
}
