<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste l'autorisation des canaux WebSocket privés (routes/channels.php).
 *
 * Méthode : POST /broadcasting/auth avec le channel_name attendu.
 * Laravel vérifie la closure du canal indépendamment du driver de broadcast.
 */
class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function authChannel(User $user, string $channelName): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => $channelName,
            'socket_id'    => '1234.5678',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Canal game.{gameId}.werewolves
    // ──────────────────────────────────────────────────────────────────────────

    public function test_canal_werewolves_accessible_aux_loups(): void
    {
        $game = Game::factory()->create(['status' => 'night', 'max_players' => 6]);
        $user = User::factory()->create();
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->authChannel($user, "private-game.{$game->id}.werewolves");

        $response->assertStatus(200);
    }

    public function test_canal_werewolves_inaccessible_aux_villageois(): void
    {
        $game = Game::factory()->create(['status' => 'night', 'max_players' => 6]);
        $user = User::factory()->create();
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->authChannel($user, "private-game.{$game->id}.werewolves");

        $response->assertStatus(403);
    }

    public function test_canal_werewolves_inaccessible_aux_voyantes(): void
    {
        $game = Game::factory()->create(['status' => 'night', 'max_players' => 6]);
        $user = User::factory()->create();
        GamePlayer::factory()->seer()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->authChannel($user, "private-game.{$game->id}.werewolves");

        $response->assertStatus(403);
    }

    public function test_canal_werewolves_inaccessible_si_pas_dans_la_partie(): void
    {
        $game    = Game::factory()->create(['status' => 'night', 'max_players' => 6]);
        $outsider = User::factory()->create();
        // Pas de GamePlayer créé pour $outsider

        $response = $this->authChannel($outsider, "private-game.{$game->id}.werewolves");

        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Canal game.{gameId}.player.{playerId}
    // ──────────────────────────────────────────────────────────────────────────

    public function test_canal_player_prive_accessible_a_son_proprietaire(): void
    {
        $game   = Game::factory()->create(['status' => 'night', 'max_players' => 6]);
        $userA  = User::factory()->create();
        $player = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $userA->id]);

        $response = $this->authChannel($userA, "private-game.{$game->id}.player.{$player->id}");

        $response->assertStatus(200);
    }

    public function test_canal_player_prive_inaccessible_a_un_autre_joueur(): void
    {
        $game    = Game::factory()->create(['status' => 'night', 'max_players' => 6]);
        $userA   = User::factory()->create();
        $userB   = User::factory()->create();
        $playerA = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $userA->id]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $userB->id]);

        // B tente de s'authentifier sur le canal privé de A → 403
        $response = $this->authChannel($userB, "private-game.{$game->id}.player.{$playerA->id}");

        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Canal game.{gameId} (public/presence — accessible à tous les joueurs)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_canal_public_accessible_aux_joueurs_de_la_partie(): void
    {
        $game = Game::factory()->create(['status' => 'day', 'max_players' => 6]);
        $user = User::factory()->create();
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        // Canal public avec préfixe 'private-' car il est protégé par auth de joueur
        $response = $this->authChannel($user, "private-game.{$game->id}");

        $response->assertStatus(200);
    }

    public function test_canal_public_inaccessible_a_un_non_joueur(): void
    {
        $game     = Game::factory()->create(['status' => 'day', 'max_players' => 6]);
        $outsider = User::factory()->create();

        $response = $this->authChannel($outsider, "private-game.{$game->id}");

        $response->assertStatus(403);
    }
}
