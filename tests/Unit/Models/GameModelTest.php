<?php

namespace Tests\Unit\Models;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_night_phase_retourne_true_pour_night_wolves_turn_processing_night(): void
    {
        foreach (['night', 'wolves_turn', 'processing_night'] as $status) {
            $game = Game::factory()->create(['status' => $status]);
            $this->assertTrue($game->isNightPhase(), "isNightPhase() devrait être true pour status=$status");
        }
    }

    public function test_is_night_phase_retourne_false_pour_day_et_autres(): void
    {
        foreach (['day', 'processing_day', 'waiting', 'electing_mayor', 'finished'] as $status) {
            $game = Game::factory()->create(['status' => $status]);
            $this->assertFalse($game->isNightPhase(), "isNightPhase() devrait être false pour status=$status");
        }
    }

    public function test_is_day_phase_retourne_true_pour_day_et_processing_day(): void
    {
        foreach (['day', 'processing_day'] as $status) {
            $game = Game::factory()->create(['status' => $status]);
            $this->assertTrue($game->isDayPhase());
        }
    }

    public function test_is_cancelled_retourne_true_uniquement_si_finished_sans_vainqueur(): void
    {
        $cancelled  = Game::factory()->create(['status' => 'finished', 'winner_team' => null]);
        $finished   = Game::factory()->create(['status' => 'finished', 'winner_team' => 'villagers']);
        $inProgress = Game::factory()->create(['status' => 'day']);

        $this->assertTrue($cancelled->isCancelled());
        $this->assertFalse($finished->isCancelled());
        $this->assertFalse($inProgress->isCancelled());
    }

    public function test_alive_werewolves_count_ne_compte_pas_les_morts(): void
    {
        $game = Game::factory()->create(['status' => 'night', 'max_players' => 6]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => false]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);

        $this->assertSame(1, $game->aliveWerewolvesCount());
    }

    public function test_alive_villagers_count_exclut_les_loups(): void
    {
        $game = Game::factory()->create(['status' => 'night', 'max_players' => 6]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->seer()->create(['game_id' => $game->id, 'is_alive' => true]);

        $this->assertSame(2, $game->aliveVillagersCount());
    }

    public function test_timer_fallback_si_settings_null(): void
    {
        $game = Game::factory()->create(['status' => 'waiting', 'settings' => null]);
        $this->assertSame(config('game.timers.seer'), $game->timer('seer'));
    }
}
