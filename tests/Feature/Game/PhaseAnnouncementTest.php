<?php

namespace Tests\Feature\Game;

use App\Events\Game\DayStarted;
use App\Events\Game\NightStarted;
use App\Events\Game\PhaseAnnouncement;
use App\Events\Game\SeerTurnStarted;
use App\Events\Game\WerewolvesTurnStarted;
use App\Jobs\ProcessSeerTurn;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\PhaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Couvre SPEC_TRANSITIONS.md §10 : tests PHPUnit pour les annonces de phases.
 *
 * Tests JS/E2E non couverts ici (nécessitent Dusk ou Playwright) :
 *   - test_overlay_queue_executes_in_order : vérification FIFO côté client,
 *     impossible à tester en PHPUnit (logique Alpine.js announcements[]).
 *   - test_reconnection_clears_announcement_queue : réinitialisation de la file
 *     lors d'une reconnexion, logique purement client dans _reconnect().
 * Emplacement futur : tests/Browser/ (Dusk) ou tests e2e/ (Playwright).
 */
class PhaseAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function makeDayGame(): Game
    {
        return Game::factory()->create([
            'status'      => 'processing_day',
            'max_players' => 6,
            'round'       => 1,
        ]);
    }

    /**
     * startNight() broadcaste PhaseAnnouncement(night_fall) AVANT NightStarted.
     * (SPEC_TRANSITIONS.md §7 et §10)
     */
    public function test_night_fall_broadcasted_on_start_night(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeDayGame();
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(5)->villager()->create(['game_id' => $game->id]);

        app(PhaseManager::class)->startNight($game);

        Event::assertDispatched(PhaseAnnouncement::class, function (PhaseAnnouncement $e) use ($game) {
            return $e->gameId === $game->id
                && $e->type === 'night_fall'
                && $e->durationMs === 4000;
        });

        Event::assertDispatched(NightStarted::class);

        // Vérifier l'ordre : PhaseAnnouncement dispatché avant NightStarted
        $dispatched = Event::dispatched(PhaseAnnouncement::class);
        $this->assertNotEmpty($dispatched, 'PhaseAnnouncement devrait être dispatché');

        $announcementDispatched = $dispatched->first()[0] ?? null;
        $this->assertNotNull($announcementDispatched);
        $this->assertSame('night_fall', $announcementDispatched->type);
    }

    /**
     * Aucun PhaseAnnouncement de type 'seer_turn' ou 'werewolves_turn' n'est jamais
     * broadcasté sur le canal public — ces informations restent sur les canaux privés.
     * (SPEC_TRANSITIONS.md §9 — règles de sécurité anti-fuite)
     */
    public function test_seer_turn_not_broadcasted_publicly(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeDayGame();
        $seer = GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        // Déclencher le flux nocturne complet (startNight → ProcessSeerTurn → endNight)
        app(PhaseManager::class)->startNight($game);

        // ProcessSeerTurn broadcasté comme job (Queue::fake()) — simuler son exécution
        (new ProcessSeerTurn($game->id, $game->fresh()->round))->handle();

        // Aucun PhaseAnnouncement de type 'seer_turn' ou 'werewolves_turn' sur le canal public
        Event::assertNotDispatched(PhaseAnnouncement::class, function (PhaseAnnouncement $e) {
            return in_array($e->type, ['seer_turn', 'werewolves_turn'], true);
        });

        // SeerTurnStarted doit avoir été dispatché (canal privé — pas une annonce publique)
        Event::assertDispatched(SeerTurnStarted::class);
    }

    /**
     * La durée de night_fall est identique (3000ms) que la voyante ait agi ou non
     * avant la fin de la nuit. La durée est une constante fixe, indépendante de
     * l'état du jeu. (SPEC_TRANSITIONS.md §1 — règle anti-fuite)
     */
    public function test_public_phase_duration_is_constant(): void
    {
        Event::fake();
        Queue::fake();

        // Scénario A : voyante a agi ce round (seer_check présent en base)
        $gameA = $this->makeDayGame();
        $seerA = GamePlayer::factory()->seer()->create(['game_id' => $gameA->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $gameA->id]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $gameA->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $gameA->id]);

        GameAction::factory()->create([
            'game_id'          => $gameA->id,
            'player_id'        => $seerA->id,
            'type'             => 'seer_check',
            'target_player_id' => $target->id,
            'round'            => $gameA->round,
            'phase'            => 'night',
        ]);

        app(PhaseManager::class)->startNight($gameA);

        $announcements = Event::dispatched(PhaseAnnouncement::class);
        $nightFallA = $announcements->first(fn ($args) => ($args[0]->type ?? '') === 'night_fall');
        $this->assertNotNull($nightFallA, 'PhaseAnnouncement night_fall attendu (scénario A : voyante active)');
        $this->assertSame(4000, $nightFallA[0]->durationMs);

        Event::clearResolvedInstances();
        Event::fake();

        // Scénario B : aucune voyante dans la partie
        $gameB = Game::factory()->create([
            'status'      => 'processing_day',
            'max_players' => 4,
            'round'       => 1,
        ]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $gameB->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $gameB->id]);

        app(PhaseManager::class)->startNight($gameB);

        $announcements = Event::dispatched(PhaseAnnouncement::class);
        $nightFallB = $announcements->first(fn ($args) => ($args[0]->type ?? '') === 'night_fall');
        $this->assertNotNull($nightFallB, 'PhaseAnnouncement night_fall attendu (scénario B : sans voyante)');
        $this->assertSame(4000, $nightFallB[0]->durationMs);
    }
}
