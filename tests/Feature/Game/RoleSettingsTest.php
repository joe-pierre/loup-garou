<?php

namespace Tests\Feature\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\RoleDistributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeWaitingGame(int $maxPlayers = 6): Game
    {
        return Game::factory()->create([
            'status'      => 'waiting',
            'max_players' => $maxPlayers,
            'round'       => 0,
        ]);
    }

    public function test_host_peut_activer_sorciere(): void
    {
        $game = $this->makeWaitingGame();
        $user = User::factory()->create();
        GamePlayer::factory()->host()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/settings/roles", [
            'roles' => ['witch' => 1],
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertSame(1, $game->fresh()->settings['roles']['witch'] ?? null);
    }

    public function test_host_peut_activer_chasseur(): void
    {
        $game = $this->makeWaitingGame();
        $user = User::factory()->create();
        GamePlayer::factory()->host()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/settings/roles", [
            'roles' => ['hunter' => 1],
        ]);

        $response->assertStatus(200);
        $this->assertSame(1, $game->fresh()->settings['roles']['hunter'] ?? null);
    }

    public function test_joueur_non_host_ne_peut_pas_modifier_roles(): void
    {
        $game = $this->makeWaitingGame();
        $user = User::factory()->create();
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'is_host' => false]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/settings/roles", [
            'roles' => ['witch' => 1],
        ]);

        $response->assertStatus(403);
    }

    public function test_modification_roles_impossible_hors_waiting(): void
    {
        $game = $this->makeWaitingGame();
        $game->update(['status' => 'night']);

        $user = User::factory()->create();
        GamePlayer::factory()->host()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/settings/roles", [
            'roles' => ['witch' => 1],
        ]);

        $response->assertStatus(409);
    }

    public function test_role_invalide_est_rejete(): void
    {
        $game = $this->makeWaitingGame();
        $user = User::factory()->create();
        GamePlayer::factory()->host()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $tooMany = $this->actingAs($user)->postJson("/game/{$game->id}/settings/roles", [
            'roles' => ['witch' => 2],
        ]);
        $tooMany->assertStatus(422);

        $unknownRole = $this->actingAs($user)->postJson("/game/{$game->id}/settings/roles", [
            'roles' => ['white_wolf' => 1],
        ]);
        $unknownRole->assertStatus(422);
    }

    public function test_role_distributor_inclut_sorciere_si_configuree(): void
    {
        $game = $this->makeWaitingGame(8);
        $game->update(['settings' => ['roles' => ['witch' => 1]]]);

        $players = GamePlayer::factory()->count(8)->create(['game_id' => $game->id]);

        $assignments = app(RoleDistributor::class)->distribute($players, $game->fresh());

        $this->assertSame(1, count(array_filter($assignments, fn ($role) => $role === 'witch')));
    }

    public function test_role_distributor_inclut_chasseur_si_configure(): void
    {
        $game = $this->makeWaitingGame(8);
        $game->update(['settings' => ['roles' => ['hunter' => 1]]]);

        $players = GamePlayer::factory()->count(8)->create(['game_id' => $game->id]);

        $assignments = app(RoleDistributor::class)->distribute($players, $game->fresh());

        $this->assertSame(1, count(array_filter($assignments, fn ($role) => $role === 'hunter')));
    }

    public function test_role_distributor_inclut_cupidon_si_configure(): void
    {
        $game = $this->makeWaitingGame(8);
        $game->update(['settings' => ['roles' => ['cupidon' => 1]]]);

        $players = GamePlayer::factory()->count(8)->create(['game_id' => $game->id]);

        $assignments = app(RoleDistributor::class)->distribute($players, $game->fresh());

        $this->assertSame(1, count(array_filter($assignments, fn ($role) => $role === 'cupidon')));
    }

    /**
     * Zéro régression : cupidon est désactivé par défaut (config/game.php roles.cupidon = 0),
     * aucune UI host ne permet encore de le configurer — une partie sans settings explicites
     * ne doit jamais recevoir de Cupidon.
     */
    public function test_role_distributor_ninclut_pas_cupidon_par_defaut(): void
    {
        $game = $this->makeWaitingGame(8);

        $players = GamePlayer::factory()->count(8)->create(['game_id' => $game->id]);

        $assignments = app(RoleDistributor::class)->distribute($players, $game->fresh());

        $this->assertSame(0, count(array_filter($assignments, fn ($role) => $role === 'cupidon')));
    }

    public function test_role_distributor_remplit_villageois_automatiquement(): void
    {
        $game = $this->makeWaitingGame(8);
        $game->update(['settings' => ['roles' => ['witch' => 1, 'hunter' => 1]]]);

        $players = GamePlayer::factory()->count(8)->create(['game_id' => $game->id]);

        $assignments = app(RoleDistributor::class)->distribute($players, $game->fresh());

        $counts = array_count_values($assignments);

        $this->assertSame(2, $counts['werewolf'] ?? 0);
        $this->assertSame(1, $counts['seer'] ?? 0);
        $this->assertSame(1, $counts['witch'] ?? 0);
        $this->assertSame(1, $counts['hunter'] ?? 0);
        $this->assertSame(3, $counts['villager'] ?? 0);
        $this->assertSame(8, count($assignments));
    }

    public function test_deux_sorcieres_impossibles(): void
    {
        $game = $this->makeWaitingGame();
        $user = User::factory()->create();
        GamePlayer::factory()->host()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/settings/roles", [
            'roles' => ['witch' => 2],
        ]);

        $response->assertStatus(422);
        $this->assertArrayNotHasKey('roles', $game->fresh()->settings ?? []);
    }

    public function test_villageois_residuels_toujours_positifs(): void
    {
        $game = $this->makeWaitingGame(6);
        $game->update(['settings' => ['roles' => ['witch' => 1, 'hunter' => 1]]]);

        $players = GamePlayer::factory()->count(6)->create(['game_id' => $game->id]);

        $assignments = app(RoleDistributor::class)->distribute($players, $game->fresh());

        $counts = array_count_values($assignments);

        $this->assertGreaterThanOrEqual(1, $counts['villager'] ?? 0);
        $this->assertSame(6, count($assignments));
    }
}
