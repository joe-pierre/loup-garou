<?php

namespace Tests\Feature\Admin;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_ne_peut_pas_acceder_a_la_liste_des_utilisateurs(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_victoire_amoureux_comptee_dans_wins_count_de_la_liste(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $lover = User::factory()->create();

        $game = Game::factory()->finished()->create(['winner_team' => 'lovers']);
        $partner = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->werewolf()->create([
            'game_id'         => $game->id,
            'user_id'         => $lover->id,
            'lover_player_id' => $partner->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertViewHas('users', function ($users) use ($lover) {
            $row = $users->firstWhere('id', $lover->id);

            return $row !== null && (int) $row->wins_count === 1;
        });
    }

    public function test_victoire_amoureux_comptee_dans_le_donut_de_la_fiche_utilisateur(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $lover = User::factory()->create();

        $game = Game::factory()->finished()->create(['winner_team' => 'lovers']);
        $partner = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->werewolf()->create([
            'game_id'         => $game->id,
            'user_id'         => $lover->id,
            'lover_player_id' => $partner->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.show', $lover->id));

        $response->assertOk();
        $response->assertViewHas('winsCount', 1);
        $response->assertViewHas('lossesCount', 0);
        $response->assertViewHas('winRate', 100.0);
    }

    public function test_amoureux_non_gagnant_compte_comme_defaite(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $notLover = User::factory()->create();

        $game = Game::factory()->finished()->create(['winner_team' => 'werewolves']);
        GamePlayer::factory()->villager()->create([
            'game_id' => $game->id,
            'user_id' => $notLover->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.show', $notLover->id));

        $response->assertViewHas('winsCount', 0);
        $response->assertViewHas('lossesCount', 1);
    }
}
