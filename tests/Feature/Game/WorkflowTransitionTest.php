<?php

namespace Tests\Feature\Game;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTransitionTest extends TestCase
{
    use RefreshDatabase;

    // --- Transitions autorisées ---------------------------------------

    public function test_waiting_vers_electing_mayor_autorise(): void
    {
        $game = Game::factory()->create(['status' => 'waiting']);

        $this->assertTrue($game->canTransition('start_election'));
    }

    public function test_electing_mayor_vers_night_autorise(): void
    {
        $game = Game::factory()->create(['status' => 'electing_mayor']);

        $this->assertTrue($game->canTransition('start_night'));
    }

    public function test_night_vers_day_autorise(): void
    {
        $game = Game::factory()->create(['status' => 'night']);

        $this->assertTrue($game->canTransition('start_day'));
    }

    public function test_day_vers_night_autorise(): void
    {
        $game = Game::factory()->create(['status' => 'day']);

        $this->assertTrue($game->canTransition('continue_night'));
    }

    public function test_night_vers_finished_autorise(): void
    {
        $game = Game::factory()->create(['status' => 'night']);

        $this->assertTrue($game->canTransition('finish'));
    }

    public function test_day_vers_finished_autorise(): void
    {
        $game = Game::factory()->create(['status' => 'day']);

        $this->assertTrue($game->canTransition('finish'));
    }

    // --- Transitions interdites ----------------------------------------

    public function test_waiting_vers_night_interdit(): void
    {
        $game = Game::factory()->create(['status' => 'waiting']);

        $this->assertFalse($game->canTransition('start_night'));
        $this->assertFalse($game->canTransition('continue_night'));
    }

    public function test_finished_vers_quoi_que_ce_soit_interdit(): void
    {
        $game = Game::factory()->create(['status' => 'finished']);

        $this->assertFalse($game->canTransition('start_election'));
        $this->assertFalse($game->canTransition('start_night'));
        $this->assertFalse($game->canTransition('start_day'));
        $this->assertFalse($game->canTransition('continue_night'));
        $this->assertFalse($game->canTransition('finish'));
    }

    public function test_day_vers_start_night_interdit(): void
    {
        $game = Game::factory()->create(['status' => 'day']);

        $this->assertFalse($game->canTransition('start_night'));
    }

    public function test_electing_mayor_vers_continue_night_interdit(): void
    {
        $game = Game::factory()->create(['status' => 'electing_mayor']);

        $this->assertFalse($game->canTransition('continue_night'));
    }

    // --- Persistance ------------------------------------------------------

    public function test_apply_transition_persiste_le_nouveau_status(): void
    {
        $game = Game::factory()->create(['status' => 'waiting']);

        $game->applyTransition('start_election');

        $this->assertSame('electing_mayor', $game->status);
        $this->assertSame('electing_mayor', $game->fresh()->status);
    }
}
