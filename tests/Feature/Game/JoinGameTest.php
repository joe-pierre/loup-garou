<?php

namespace Tests\Feature\Game;

use App\Models\Exclusion;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JoinGameTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejoindre_une_partie_waiting_réussie(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create(['max_players' => 6]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson("/game/{$game->code}/join", [
            'pseudo' => 'NouveauJoueur',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['player_id', 'game_code']]);

        $this->assertDatabaseHas('game_players', [
            'game_id' => $game->id,
            'user_id' => $user->id,
            'pseudo'  => 'NouveauJoueur',
        ]);
    }

    public function test_rejoindre_une_partie_non_waiting_retourne_409(): void
    {
        $game = Game::factory()->inProgress()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/game/{$game->code}/join", ['pseudo' => 'Joueur'])
            ->assertStatus(409);
    }

    public function test_rejoindre_une_partie_pleine_retourne_409(): void
    {
        Event::fake();

        $game = Game::factory()->create(['max_players' => 6]);
        GamePlayer::factory()->count(6)->create(['game_id' => $game->id]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/game/{$game->code}/join", ['pseudo' => 'Retardataire'])
            ->assertStatus(409);
    }

    public function test_joueur_exclu_ne_peut_pas_rejoindre_retourne_403(): void
    {
        $game = Game::factory()->create();
        $user = User::factory()->create();

        // Exclusion créée directement (user pas dans game_players → pas de court-circuit "déjà en partie")
        Exclusion::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson("/game/{$game->code}/join", ['pseudo' => 'Retour'])
            ->assertStatus(403);
    }

    public function test_code_inexistant_retourne_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/game/XXXXXX/join', ['pseudo' => 'Joueur'])
            ->assertStatus(404);
    }

    public function test_race_condition_deux_joueurs_remplissent_le_dernier_slot(): void
    {
        Event::fake();
        Queue::fake();

        // Partie avec un seul slot restant
        $game = Game::factory()->create(['max_players' => 6]);
        GamePlayer::factory()->count(5)->create(['game_id' => $game->id]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Premier joueur prend le dernier slot → startGame() → status = electing_mayor
        $responseA = $this->actingAs($userA)->postJson("/game/{$game->code}/join", ['pseudo' => 'JoueurA']);
        $responseA->assertStatus(200);

        // Deuxième joueur : game.status != 'waiting' → 409
        $responseB = $this->actingAs($userB)->postJson("/game/{$game->code}/join", ['pseudo' => 'JoueurB']);
        $responseB->assertStatus(409);
    }
}
