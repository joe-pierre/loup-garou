<?php

namespace Tests\Feature\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimerSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeWaitingGame(): array
    {
        $game = Game::factory()->create(['status' => 'waiting']);
        $host = GamePlayer::factory()->host()->create(['game_id' => $game->id]);

        return [$game, $host];
    }

    public function test_host_peut_modifier_les_timers(): void
    {
        [$game, $host] = $this->makeWaitingGame();

        $response = $this->actingAs($host->user)
            ->postJson("/game/{$game->id}/settings/timers", [
                'timers' => ['seer' => 45, 'day_vote' => 120],
            ]);

        $response->assertStatus(200)->assertJsonPath('success', true);

        $game->refresh();
        $this->assertSame(45, $game->settings['timers']['seer']);
        $this->assertSame(120, $game->settings['timers']['day_vote']);
    }

    public function test_joueur_non_host_ne_peut_pas_modifier_les_timers(): void
    {
        [$game, $host] = $this->makeWaitingGame();
        $nonHost = GamePlayer::factory()->create(['game_id' => $game->id]);

        $this->actingAs($nonHost->user)
            ->postJson("/game/{$game->id}/settings/timers", [
                'timers' => ['seer' => 45],
            ])
            ->assertStatus(403);
    }

    public function test_timer_hors_plage_est_rejeté(): void
    {
        [$game, $host] = $this->makeWaitingGame();

        $this->actingAs($host->user)
            ->postJson("/game/{$game->id}/settings/timers", [
                'timers' => ['seer' => 200],
            ])
            ->assertStatus(422);
    }

    public function test_timer_non_configurable_est_rejeté(): void
    {
        [$game, $host] = $this->makeWaitingGame();

        $this->actingAs($host->user)
            ->postJson("/game/{$game->id}/settings/timers", [
                'timers' => ['reconnection' => 20],
            ])
            ->assertStatus(422);
    }

    public function test_game_timer_lit_settings_en_priorite(): void
    {
        $game = Game::factory()->create([
            'status'   => 'waiting',
            'settings' => ['timers' => ['seer' => 45]],
        ]);

        $this->assertSame(45, $game->timer('seer'));
    }

    public function test_game_timer_fallback_sur_config(): void
    {
        $game = Game::factory()->create([
            'status'   => 'waiting',
            'settings' => null,
        ]);

        $this->assertSame(config('game.timers.seer'), $game->timer('seer'));
    }

    public function test_timers_fixes_ignorent_settings(): void
    {
        $game = Game::factory()->create([
            'status'   => 'waiting',
            'settings' => ['timers' => [
                'reconnection'      => 99,
                'ready_timeout'     => 99,
                'night_start_delay' => 99,
                'mayor_reveal'      => 99,
            ]],
        ]);

        $this->assertSame(config('game.timers.reconnection'), $game->timer('reconnection'));
        $this->assertSame(config('game.timers.ready_timeout'), $game->timer('ready_timeout'));
        $this->assertSame(config('game.timers.night_start_delay'), $game->timer('night_start_delay'));
        $this->assertSame(config('game.timers.mayor_reveal'), $game->timer('mayor_reveal'));
    }

    public function test_modification_impossible_hors_waiting(): void
    {
        $game = Game::factory()->inProgress()->create();
        $host = GamePlayer::factory()->host()->create(['game_id' => $game->id]);

        $this->actingAs($host->user)
            ->postJson("/game/{$game->id}/settings/timers", [
                'timers' => ['seer' => 45],
            ])
            ->assertStatus(409);
    }
}
