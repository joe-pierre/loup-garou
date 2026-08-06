<?php

namespace Tests\Feature\Admin;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_ne_peut_pas_acceder_au_leaderboard(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.leaderboard'))
            ->assertForbidden();
    }

    public function test_admin_voit_le_leaderboard_avec_les_trois_classements(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $player = User::factory()->create();

        $game = Game::factory()->finished()->create(['winner_team' => 'villagers']);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $player->id]);

        $response = $this->actingAs($admin)->get(route('admin.leaderboard'));

        $response->assertOk();
        $response->assertViewIs('admin.leaderboard');
        $response->assertViewHas('period', 'current_month');
        $response->assertSee($player->name);
    }

    public function test_periode_invalide_retombe_sur_le_mois_courant(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get(route('admin.leaderboard', ['period' => 'not-a-period']));

        $response->assertOk();
        $response->assertViewHas('period', 'current_month');
    }
}
