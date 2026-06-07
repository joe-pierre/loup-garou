<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Recette manuelle "Couche 2 (lobby)" — CAS 1 à 4 (TODO.md).
 *
 * Le flux create/join est piloté en AJAX (lobbyApp() dans lobby/index.blade.php) :
 * le contrôleur répond en JSON ({success, data, message}) et c'est le JS qui exécute
 * `window.location.href = '/game/{code}/lobby'`. Il n'y a donc pas de 302 serveur —
 * on vérifie ici le contrat JSON qui pilote cette redirection côté client, puis que
 * la page cible est bien atteignable avec le code retourné.
 */
class LobbyTest extends TestCase
{
    use RefreshDatabase;

    public function test_cas1_creer_une_partie_fournit_le_code_qui_mene_au_lobby(): void
    {
        Event::fake();
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/game', [
            'pseudo'      => 'MonPseudo',
            'max_players' => 6,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['game_id', 'code']]);

        $code = $response->json('data.code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $code);

        // La redirection JS cible /game/{code}/lobby : on vérifie qu'elle est atteignable.
        $this->actingAs($user)
            ->get("/game/{$code}/lobby")
            ->assertOk()
            ->assertViewIs('game.waiting-room');
    }

    public function test_cas2_rejoindre_avec_code_valide_fournit_le_code_qui_mene_au_lobby(): void
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

        $this->assertSame($game->code, $response->json('data.game_code'));

        $this->actingAs($user)
            ->get("/game/{$game->code}/lobby")
            ->assertOk()
            ->assertViewIs('game.waiting-room');
    }

    public function test_cas3_rejoindre_avec_code_invalide_renvoie_un_message_derreur_exploitable(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/game/ZZZZZZ/join', [
            'pseudo' => 'Joueur',
        ]);

        // lobbyApp() lit `json.message` pour l'afficher dans `joinError` (x-text).
        $response->assertStatus(404)
            ->assertJsonStructure(['message']);

        $this->assertNotEmpty($response->json('message'));
    }

    public function test_cas4_pseudo_vide_renvoie_une_erreur_de_validation_exploitable(): void
    {
        $game = Game::factory()->create(['max_players' => 6]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson("/game/{$game->code}/join", [
            'pseudo' => '',
        ]);

        // lobbyApp() lit `json.errors.pseudo[0]` pour l'afficher dans `joinErrors.pseudo`.
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pseudo']);
    }
}
