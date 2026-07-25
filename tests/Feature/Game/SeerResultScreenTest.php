<?php

namespace Tests\Feature\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fix : l'écran seer_result (night.blade.php) revenait automatiquement à
 * village_sleeping via un setTimeout(5000) fixe, indexé sur le mauvais repère
 * (mi-timer + 5s au lieu du vrai passage aux Loups à seerTimer + 2s côté
 * serveur) — voir DECISIONS.md "Écran Voyante (seer_result) se referme sur un
 * mauvais repère temporel". La Voyante ne peut structurellement pas recevoir
 * WerewolvesTurnStarted (canal privé loups) : la seule transition légitime
 * hors événement serveur global (DayStarted) est le dismiss volontaire du
 * joueur via le bouton "J'ai compris" — jamais un minuteur client arbitraire.
 */
class SeerResultScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_lecran_voyante_ne_contient_plus_de_minuterie_automatique_vers_village_sleeping(): void
    {
        $game = Game::factory()->create(['status' => 'night', 'max_players' => 6, 'round' => 1]);
        $user = User::factory()->create();
        $seer = GamePlayer::factory()->seer()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/game/{$game->code}/night");

        $response->assertOk();

        // Le pattern fautif (setTimeout fixe qui écrase nightPhase après réception
        // de seer-result) ne doit plus jamais réapparaître dans la vue.
        $response->assertDontSee('Après 5s sans action', false);
        $response->assertDontSee('}, 5000);', false);
    }

    public function test_lecran_voyante_garde_le_bouton_de_dismiss_volontaire(): void
    {
        $game = Game::factory()->create(['status' => 'night', 'max_players' => 6, 'round' => 1]);
        $user = User::factory()->create();
        $seer = GamePlayer::factory()->seer()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->get("/game/{$game->code}/night");

        $response->assertOk();

        // Seul mécanisme restant pour quitter l'écran seer_result : le choix du joueur.
        $response->assertSee("nightPhase = 'village_sleeping'", false);
        $response->assertSee("J'ai compris", false);
    }
}
