<?php

namespace Tests\Feature\Game;

use App\Events\Game\DayStarted;
use App\Events\Game\MayorSuccessionDone;
use App\Events\Game\NightStarted;
use App\Events\Game\PlayerEliminated;
use App\Jobs\ProcessMayorSuccession;
use App\Jobs\ProcessNightActions;
use App\Jobs\ProcessNightEnd;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\PhaseManager;
use App\Services\VoteService;
use App\Services\WinConditionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MayorSuccessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Succession en contexte nuit (shouldStartNight=true) : un successeur est
     * désigné et MayorSuccessionDone est broadcasté, mais aucune nouvelle phase
     * n'est démarrée — la fin de nuit reste la responsabilité de ProcessNightEnd.
     */
    public function test_succession_nuit_designe_un_successeur_sans_changer_de_phase(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create([
            'status'      => 'processing_night',
            'max_players' => 6,
            'round'       => 1,
        ]);

        $mayor = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id, 'is_mayor' => true, 'is_alive' => false,
        ]);
        GamePlayer::factory()->count(5)->villager()->create([
            'game_id' => $game->id, 'is_alive' => true,
        ]);

        (new ProcessMayorSuccession($game->id, $game->round, shouldStartNight: true))
            ->handle(app(PhaseManager::class));

        Event::assertDispatched(MayorSuccessionDone::class);
        Event::assertNotDispatched(NightStarted::class);

        $this->assertFalse($mayor->fresh()->is_mayor);
        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('is_mayor', true)->where('is_alive', true)->exists()
        );
        $this->assertSame('processing_night', $game->fresh()->status);
        $this->assertSame(1, $game->fresh()->round);
    }

    /**
     * Succession en contexte jour (shouldStartNight=false, valeur par défaut) :
     * un successeur est désigné, MayorSuccessionDone est broadcasté, puis la nuit
     * suivante démarre (startNight) — round incrémenté.
     */
    public function test_succession_jour_designe_un_successeur_puis_demarre_la_nuit(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create([
            'status'      => 'processing_day',
            'max_players' => 6,
            'round'       => 1,
        ]);

        $mayor = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id, 'is_mayor' => true, 'is_alive' => false,
        ]);
        GamePlayer::factory()->count(5)->villager()->create([
            'game_id' => $game->id, 'is_alive' => true,
        ]);

        (new ProcessMayorSuccession($game->id, $game->round))
            ->handle(app(PhaseManager::class));

        Event::assertDispatched(MayorSuccessionDone::class);
        Event::assertDispatched(NightStarted::class);

        $this->assertFalse($mayor->fresh()->is_mayor);
        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('is_mayor', true)->where('is_alive', true)->exists()
        );
        $this->assertSame('night', $game->fresh()->status);
        $this->assertSame(2, $game->fresh()->round);
    }

    /**
     * Régression Tâche J : si ProcessNightEnd s'exécute AVANT ProcessMayorSuccession
     * pour le même round (ordre de queue non garanti), le statut est déjà passé à
     * 'day' au moment où ProcessMayorSuccession s'exécute. Grâce au flag
     * shouldStartNight=true (porté par ProcessNightActions, qui connaît le contexte
     * nuit au moment du dispatch), la succession reste traitée en contexte nuit :
     * MayorSuccessionDone est broadcasté mais startNight() n'est PAS rappelé —
     * la phase jour n'est pas sautée.
     */
    public function test_succession_nuit_ne_redemarre_pas_la_nuit_si_le_statut_est_deja_passe_a_day(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create([
            'status'      => 'day',
            'max_players' => 6,
            'round'       => 1,
        ]);

        $mayor = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id, 'is_mayor' => true, 'is_alive' => false,
        ]);
        GamePlayer::factory()->count(5)->villager()->create([
            'game_id' => $game->id, 'is_alive' => true,
        ]);

        (new ProcessMayorSuccession($game->id, $game->round, shouldStartNight: true))
            ->handle(app(PhaseManager::class));

        Event::assertDispatched(MayorSuccessionDone::class);
        Event::assertNotDispatched(NightStarted::class);

        $this->assertFalse($mayor->fresh()->is_mayor);
        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('is_mayor', true)->where('is_alive', true)->exists()
        );
        // Le statut et le round ne doivent pas avoir bougé : pas de saut de phase jour.
        $this->assertSame('day', $game->fresh()->status);
        $this->assertSame(1, $game->fresh()->round);
    }

    /**
     * Cascade complète : le maire meurt la nuit (round 1), un successeur est désigné,
     * la nuit se termine normalement (ProcessNightEnd → jour round 1), puis la nuit
     * suivante démarre (round 2) et le nouveau maire (successeur) meurt à son tour.
     * ProcessMayorSuccession doit être re-dispatché avec shouldStartNight=true et
     * désigner un second successeur — la modale "Succession du Maire" se ferme bien
     * les deux fois (MayorSuccessionDone x2), sans saut de phase jour pour le round 2.
     */
    public function test_cascade_succession_nuit_puis_nouveau_maire_tue_la_nuit_suivante(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create([
            'status'      => 'night',
            'max_players' => 8,
            'round'       => 1,
        ]);

        $mayor = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id, 'is_mayor' => true, 'is_alive' => true,
        ]);
        $wolf1 = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $wolf2 = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(5)->villager()->create(['game_id' => $game->id]);

        // Round 1 : les loups tuent le maire.
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf1->id, 'type' => 'night_vote',
            'target_player_id' => $mayor->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessNightActions($game->id, 1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertFalse($mayor->fresh()->is_alive);
        $this->assertSame('processing_night', $game->fresh()->status);

        Queue::assertPushed(
            ProcessMayorSuccession::class,
            fn ($job) => $job->gameId === $game->id && $job->round === 1 && $job->shouldStartNight === true
        );

        // Traitement de la succession round 1 : un premier successeur est désigné.
        (new ProcessMayorSuccession($game->id, 1, shouldStartNight: true))->handle(app(PhaseManager::class));

        $successorA = GamePlayer::where('game_id', $game->id)->where('is_mayor', true)->where('is_alive', true)->first();
        $this->assertNotNull($successorA);
        $this->assertSame('processing_night', $game->fresh()->status);

        // Fin de la nuit round 1 : transition vers le jour round 1 (même round).
        (new ProcessNightEnd($game->id, 1))->handle(app(PhaseManager::class));

        $this->assertSame('day', $game->fresh()->status);
        $this->assertSame(1, $game->fresh()->round);

        // Le jour round 1 se termine sans nouvelle mort de maire : passage à la nuit round 2.
        app(PhaseManager::class)->startNight($game->fresh());

        $this->assertSame('night', $game->fresh()->status);
        $this->assertSame(2, $game->fresh()->round);

        // Round 2 : les loups tuent le nouveau maire (successeur A). Le votant est
        // un loup différent du successeur (au cas où le successeur A désigné au
        // hasard soit lui-même un loup).
        $voter = $successorA->id === $wolf1->id ? $wolf2 : $wolf1;

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $voter->id, 'type' => 'night_vote',
            'target_player_id' => $successorA->id, 'round' => 2, 'phase' => 'night',
        ]);

        (new ProcessNightActions($game->id, 2))->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertFalse($successorA->fresh()->is_alive);
        $this->assertSame('processing_night', $game->fresh()->status);

        Queue::assertPushed(
            ProcessMayorSuccession::class,
            fn ($job) => $job->gameId === $game->id && $job->round === 2 && $job->shouldStartNight === true
        );

        // Traitement de la succession round 2 : un second successeur est désigné.
        (new ProcessMayorSuccession($game->id, 2, shouldStartNight: true))->handle(app(PhaseManager::class));

        Event::assertDispatchedTimes(MayorSuccessionDone::class, 2);
        // Une seule transition vers la nuit (round 1 → 2), pas de saut de phase
        // provoqué par la succession du round 2.
        Event::assertDispatchedTimes(NightStarted::class, 1);
        Event::assertDispatched(DayStarted::class);
        Event::assertDispatched(PlayerEliminated::class);

        $this->assertFalse($successorA->fresh()->is_mayor);
        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('is_mayor', true)->where('is_alive', true)->exists()
        );
        $this->assertSame('processing_night', $game->fresh()->status);
        $this->assertSame(2, $game->fresh()->round);
        $this->assertSame(2, GameAction::where('game_id', $game->id)->where('type', 'mayor_succession')->count());
    }
}
