<?php

namespace Tests\Feature\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\TimerCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fix : la modale ⚙️ Paramètres de la waiting-room (timerSettings() dans
 * waiting-room.blade.php) affichait des constantes 30/30/90 codées en dur,
 * déconnectées de TimerCalculator::TIMERS. Ces tests couvrent la synchronisation
 * via TimerCalculator::forPlayerCount() injecté par LobbyController::waitingRoom().
 */
class WaitingRoomTimerDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function makeWaitingGame(int $maxPlayers, ?array $settings = null): array
    {
        $game = Game::factory()->create([
            'status'      => 'waiting',
            'max_players' => $maxPlayers,
            'settings'    => $settings,
        ]);
        $host = GamePlayer::factory()->host()->create(['game_id' => $game->id]);

        return [$game, $host];
    }

    public function test_salle_6_joueurs_sans_settings_affiche_les_defauts_calcules(): void
    {
        [$game, $host] = $this->makeWaitingGame(6);

        $response = $this->actingAs($host->user)->get("/game/{$game->code}/lobby");

        $response->assertOk()->assertViewIs('game.waiting-room');
        $response->assertViewHas('timerDefaults', TimerCalculator::forPlayerCount(6));

        // Anciennes constantes hardcodées jamais retournées par TimerCalculator pour 6 joueurs.
        $response->assertSee('seer:             20', false);
        $response->assertSee('werewolves:       25', false);
        $response->assertSee('day_vote:         115', false);
    }

    public function test_salle_8_joueurs_sans_settings_affiche_les_defauts_calcules(): void
    {
        [$game, $host] = $this->makeWaitingGame(8);

        $response = $this->actingAs($host->user)->get("/game/{$game->code}/lobby");

        $response->assertOk();
        $response->assertViewHas('timerDefaults', TimerCalculator::forPlayerCount(8));
        $response->assertSee('seer:             25', false);
        $response->assertSee('werewolves:       45', false);
        $response->assertSee('day_vote:         115', false);
    }

    public function test_salle_10_joueurs_sans_settings_affiche_les_defauts_calcules(): void
    {
        [$game, $host] = $this->makeWaitingGame(10);

        $response = $this->actingAs($host->user)->get("/game/{$game->code}/lobby");

        $response->assertOk();
        $response->assertViewHas('timerDefaults', TimerCalculator::forPlayerCount(10));
        $response->assertSee('seer:             30', false);
        $response->assertSee('werewolves:       45', false);
        $response->assertSee('day_vote:         115', false);
    }

    public function test_salle_12_joueurs_sans_settings_affiche_les_defauts_calcules(): void
    {
        [$game, $host] = $this->makeWaitingGame(12);

        $response = $this->actingAs($host->user)->get("/game/{$game->code}/lobby");

        $response->assertOk();
        $response->assertViewHas('timerDefaults', TimerCalculator::forPlayerCount(12));
        $response->assertSee('seer:             35', false);
        $response->assertSee('werewolves:       45', false);
        $response->assertSee('day_vote:         115', false);
    }

    public function test_settings_deja_sauvegardes_restent_prioritaires_sur_les_nouveaux_defauts(): void
    {
        [$game, $host] = $this->makeWaitingGame(8, [
            'timers' => ['seer' => 50, 'werewolves' => 12, 'day_vote' => 150],
        ]);

        $response = $this->actingAs($host->user)->get("/game/{$game->code}/lobby");

        $response->assertOk();
        // La valeur host prime sur le défaut TimerCalculator::forPlayerCount(8) (25/45/115).
        $response->assertSee('seer:             50', false);
        $response->assertSee('werewolves:       12', false);
        $response->assertSee('day_vote:         150', false);
    }

    public function test_mayor_election_et_mayor_succession_restent_fixes_quel_que_soit_leffectif(): void
    {
        foreach ([6, 8, 10, 12] as $maxPlayers) {
            [$game, $host] = $this->makeWaitingGame($maxPlayers);

            $response = $this->actingAs($host->user)->get("/game/{$game->code}/lobby");

            $response->assertOk();
            $response->assertSee('mayor_election:   30', false);
            $response->assertSee('mayor_succession: 15', false);
        }
    }
}
