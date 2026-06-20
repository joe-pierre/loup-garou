<?php

namespace Tests\Feature\Game;

use App\Events\Game\PlayerReconnected;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReconnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_endpoint_retourne_phase_courante(): void
    {
        $game = Game::factory()->create([
            'status'         => 'day',
            'max_players'    => 6,
            'round'          => 1,
            'phase_deadline' => now()->addSeconds(60),
        ]);
        $user   = User::factory()->create();
        $player = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data'    => [
                'phase'   => 'day',
                'my_role' => 'villager',
            ],
        ]);
        $response->assertJsonPath('data.is_alive', true);
    }

    public function test_state_traduit_wolves_turn_en_night(): void
    {
        $game = Game::factory()->create([
            'status'         => 'wolves_turn',
            'max_players'    => 6,
            'round'          => 1,
            'phase_deadline' => now()->addSeconds(30),
        ]);
        $user = User::factory()->create();
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertStatus(200);
        $response->assertJsonPath('data.phase', 'wolves_turn');
        $response->assertJsonPath('data.werewolves_turn_active', true);
    }

    public function test_state_traduit_processing_day_en_day(): void
    {
        $game = Game::factory()->create([
            'status'         => 'processing_day',
            'max_players'    => 6,
            'round'          => 1,
            'phase_deadline' => now()->addSeconds(30),
        ]);
        $user = User::factory()->create();
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertStatus(200);
        $response->assertJsonPath('data.phase', 'processing_day');
        $response->assertJsonPath('data.seer_turn_active', false);
        $response->assertJsonPath('data.werewolves_turn_active', false);
    }

    public function test_state_endpoint_retourne_la_liste_des_joueurs(): void
    {
        $game = Game::factory()->create([
            'status'         => 'day',
            'max_players'    => 6,
            'round'          => 1,
            'phase_deadline' => now()->addSeconds(60),
        ]);
        $user       = User::factory()->create();
        $player     = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $deadPlayer = GamePlayer::factory()->create([
            'game_id'  => $game->id,
            'role'     => 'werewolf',
            'is_alive' => false,
        ]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'players' => [
                    '*' => ['id', 'pseudo', 'is_alive', 'is_mayor'],
                ],
            ],
        ]);

        $players = $response->json('data.players');
        $this->assertNotEmpty($players);

        // Aucun joueur vivant ne doit exposer revealed_role
        foreach ($players as $p) {
            if ($p['is_alive']) {
                $this->assertArrayNotHasKey('revealed_role', $p, "Le rôle d'un joueur vivant ne doit pas être exposé.");
            }
        }

        // Le joueur mort doit avoir revealed_role et revealed_role_label
        $dead = collect($players)->firstWhere('id', $deadPlayer->id);
        $this->assertNotNull($dead);
        $this->assertFalse($dead['is_alive']);
        $this->assertEquals('werewolf', $dead['revealed_role']);
        $this->assertEquals('Loup-Garou', $dead['revealed_role_label']);
    }

    public function test_state_retourne_403_si_joueur_absent(): void
    {
        $game = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->getJson("/game/{$game->code}/state");

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
    }

    public function test_state_retourne_404_si_partie_inexistante(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/game/NOTFOUND/state');

        $response->assertStatus(404);
        $response->assertJson(['success' => false]);
    }

    public function test_reconnect_remet_is_inactive_a_false(): void
    {
        Event::fake();

        $game = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $user = User::factory()->create();
        $player = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id, 'user_id' => $user->id, 'is_inactive' => true,
        ]);

        $response = $this->actingAs($user)->postJson("/game/{$game->code}/reconnect");

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertFalse($player->fresh()->is_inactive);
        Event::assertDispatched(PlayerReconnected::class, fn ($e) => $e->pseudo === $player->pseudo);
    }

    public function test_reconnect_invalide_token_cache(): void
    {
        Event::fake();

        $game = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $user = User::factory()->create();
        $player = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id, 'user_id' => $user->id, 'is_inactive' => true,
        ]);

        $cacheKey = "player_disconnected.{$player->id}";
        Cache::put($cacheKey, 'some-token', now()->addSeconds(60));

        $response = $this->actingAs($user)->postJson("/game/{$game->code}/reconnect");

        $response->assertStatus(200);
        $this->assertFalse(Cache::has($cacheKey));
    }
}
