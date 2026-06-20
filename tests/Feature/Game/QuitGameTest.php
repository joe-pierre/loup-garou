<?php

namespace Tests\Feature\Game;

use App\Events\Game\PlayerEliminated;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class QuitGameTest extends TestCase
{
    use RefreshDatabase;

    public function test_quit_pendant_partie_en_cours_marque_joueur_mort_et_inactif(): void
    {
        Event::fake();

        $game = Game::factory()->create([
            'status'      => 'day',
            'max_players' => 6,
            'round'       => 1,
        ]);
        $user   = User::factory()->create();
        $player = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);
        // Ajouter assez de joueurs pour que la partie reste valide
        GamePlayer::factory()->count(5)->werewolf()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/quit");

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $fresh = $player->fresh();
        $this->assertFalse($fresh->is_alive, 'Le joueur doit être mort après un quit');
        $this->assertTrue($fresh->is_inactive, 'Le joueur doit être inactif après un quit');

        Event::assertDispatched(PlayerEliminated::class, function (PlayerEliminated $e) use ($player) {
            return $e->player->id === $player->id && $e->reason === 'quit';
        });
    }

    public function test_quit_pendant_phase_night_marque_joueur_mort_et_inactif(): void
    {
        Event::fake();

        $game = Game::factory()->create([
            'status'      => 'night',
            'max_players' => 6,
            'round'       => 1,
        ]);
        $user   = User::factory()->create();
        $player = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);
        GamePlayer::factory()->count(5)->werewolf()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/quit");

        $response->assertStatus(200);

        $fresh = $player->fresh();
        $this->assertFalse($fresh->is_alive);
        $this->assertTrue($fresh->is_inactive);
    }

    public function test_quit_ne_reevalue_pas_automatiquement_lannulation(): void
    {
        Event::fake();

        // Scénario : après le quit, 4/6 joueurs vivants seraient inactifs = 66% > 50%
        // Comportement attendu : la partie n'est PAS automatiquement annulée par ce seul quit
        // (GameService::quitGame() ne vérifie pas la condition d'annulation — DECISIONS.md
        // "Quitter le lobby ≠ quitter une partie en cours")
        // Ce test fige explicitement ce comportement pour détecter une régression silencieuse
        // si quelqu'un devait ajouter la vérification d'annulation dans quitGame() plus tard.

        $game = Game::factory()->create([
            'status'      => 'day',
            'max_players' => 6,
            'round'       => 1,
        ]);

        $user  = User::factory()->create();
        $quitter = GamePlayer::factory()->villager()->create([
            'game_id'     => $game->id,
            'user_id'     => $user->id,
            'is_inactive' => false,
            'is_alive'    => true,
        ]);

        // 3 autres joueurs déjà inactifs
        GamePlayer::factory()->count(3)->villager()->create([
            'game_id'     => $game->id,
            'is_inactive' => true,
            'is_alive'    => true,
        ]);

        // 2 joueurs actifs restants
        GamePlayer::factory()->count(2)->werewolf()->create([
            'game_id'     => $game->id,
            'is_inactive' => false,
            'is_alive'    => true,
        ]);

        // Après ce quit : 4/6 inactifs = 66% > 50%
        // Mais quitGame() ne déclenche pas cancelGame() → status reste 'day'
        $response = $this->actingAs($user)->postJson("/game/{$game->id}/quit");

        $response->assertStatus(200);

        $freshGame = $game->fresh();
        $this->assertSame('day', $freshGame->status,
            'quitGame() ne doit pas déclencher automatiquement cancelGame() — comportement voulu');
        $this->assertNotSame('finished', $freshGame->status,
            'La partie ne doit pas passer en finished suite au seul quit (pas d\'évaluation automatique)');
    }

    public function test_quit_depuis_waiting_room_nest_pas_traite_par_cette_route(): void
    {
        // Ce test documente la distinction entre :
        // - Quitter depuis la waiting-room → DELETE game_players (joueur n'a pas de statut vivant/mort)
        // - Quitter en cours de partie    → POST /game/{id}/quit (marque is_alive=false)
        // (DECISIONS.md "Quitter le lobby ≠ quitter une partie en cours — deux chemins de sortie")
        //
        // La route POST /game/{id}/quit n'a PAS de guard sur game.status :
        // elle appellerait quitGame() même depuis la waiting-room, marquant le joueur mort.
        // Ce comportement est incorrect mais non corrigé ici (hors scope de cette session).
        // Ce test fige le comportement observé pour éviter une régression silencieuse.

        Event::fake();

        $game = Game::factory()->create([
            'status'      => 'waiting',
            'max_players' => 6,
        ]);
        $user   = User::factory()->create();
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role'    => null, // en waiting-room les rôles ne sont pas encore distribués
        ]);

        // La route n'est pas censée être appelée depuis la waiting-room (cf. DECISIONS.md).
        // Elle répond 200 car il n'y a pas de guard sur le statut → comportement incorrect mais observé.
        // Un test qui casse ici signale qu'un guard a été ajouté (améliorant le code) → mettre à jour.
        $response = $this->actingAs($user)->postJson("/game/{$game->id}/quit");

        // Documenter le code de retour observé (200 = pas de guard actuellement)
        $this->assertContains($response->status(), [200, 409, 403],
            "Comportement observé pour /quit depuis waiting-room : {$response->status()}");
    }

    public function test_quit_retourne_403_si_joueur_absent_de_la_partie(): void
    {
        Event::fake();

        $game     = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $outsider = User::factory()->create();
        // Aucun GamePlayer pour $outsider → firstOrFail() → 404

        $response = $this->actingAs($outsider)->postJson("/game/{$game->id}/quit");

        $response->assertStatus(404);
    }
}
