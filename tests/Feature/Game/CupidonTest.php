<?php

namespace Tests\Feature\Game;

use App\Events\Game\CupidonTurnStarted;
use App\Events\Game\DayStarted;
use App\Events\Game\GameFinished;
use App\Events\Game\HunterShot;
use App\Events\Game\LoverRevealed;
use App\Events\Game\MayorSuccessionStarted;
use App\Events\Game\SeerTurnStarted;
use App\Jobs\ProcessCupidonAutoAction;
use App\Jobs\ProcessCupidonTurn;
use App\Jobs\ProcessHunterTurn;
use App\Jobs\ProcessMayorSuccession;
use App\Jobs\ProcessNightActions;
use App\Jobs\ProcessNightEnd;
use App\Jobs\ProcessSeerTurn;
use App\Jobs\ProcessWerewolvesTurn;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\PhaseManager;
use App\Services\RoleActions\HunterAction;
use App\Services\RoleActions\WitchAction;
use App\Services\VoteService;
use App\Services\WinConditionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests d'intégration bout en bout de Cupidon (SPEC_CUPIDON.md §8, tâche 7).
 *
 * De vraies parties factory jusqu'au bout, à travers les vrais Services/Jobs
 * (pas des mocks unitaires isolés — voir CupidonActionTest et CupidonJobsTest
 * pour ceux-ci). Event::fake() + Queue::fake() sont utilisés uniquement pour
 * contrôler le timing des délais (impossible d'attendre 30s en test, et les
 * broadcasts Reverb réels ralentiraient chaque test) — chaque Job est ensuite
 * invoqué manuellement dans l'ordre exact de production, exactement comme le
 * font déjà NightPhaseTest/DayPhaseTest/WitchTest/HunterTest pour le reste du
 * flux nocturne.
 */
class CupidonTest extends TestCase
{
    use RefreshDatabase;

    private function makeDayGameRoundZero(int $maxPlayers = 6): Game
    {
        return Game::factory()->create([
            'status'      => 'day',
            'max_players' => $maxPlayers,
            'round'       => 0,
        ]);
    }

    private function makeNightGame(int $round = 1, int $maxPlayers = 6): Game
    {
        return Game::factory()->create([
            'status'      => 'night',
            'max_players' => $maxPlayers,
            'round'       => $round,
        ]);
    }

    private function linkLovers(GamePlayer $a, GamePlayer $b): void
    {
        $a->update(['lover_player_id' => $b->id]);
        $b->update(['lover_player_id' => $a->id]);
    }

    // ------------------------------------------------------------------
    // 1. Cupidon forme un couple au round 1, avant la Voyante
    // ------------------------------------------------------------------

    public function test_cupidon_forme_un_couple_au_round_1_avant_la_voyante(): void
    {
        Event::fake();
        Queue::fake();

        $game        = $this->makeDayGameRoundZero();
        $cupidonUser = User::factory()->create();
        $cupidon     = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id, 'user_id' => $cupidonUser->id]);
        $seer        = GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        $wolfUser    = User::factory()->create();
        $wolf        = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $wolfUser->id]);
        $v1          = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $v2          = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $v3          = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        // day → night : Cupidon distribué et round === 1 → ProcessCupidonTurn, jamais ProcessSeerTurn.
        app(PhaseManager::class)->startNight($game);
        $round = $game->fresh()->round;
        $this->assertSame(1, $round);
        Queue::assertPushed(ProcessCupidonTurn::class);
        Queue::assertNotPushed(ProcessSeerTurn::class);

        (new ProcessCupidonTurn($game->id, $round))->handle();
        Event::assertDispatched(CupidonTurnStarted::class, fn ($e) => $e->cupidon->id === $cupidon->id);
        Event::assertNotDispatched(SeerTurnStarted::class);

        // Action volontaire AVANT le timeout : Cupidon couple la Voyante et un villageois.
        $this->actingAs($cupidonUser)->postJson("/game/{$game->id}/cupidon/link", [
            'target1_player_id' => $seer->id,
            'target2_player_id' => $v1->id,
        ])->assertStatus(200);

        $this->assertSame($v1->id, $seer->fresh()->lover_player_id);
        $this->assertSame($seer->id, $v1->fresh()->lover_player_id);
        Event::assertDispatched(LoverRevealed::class, 2);
        Queue::assertPushed(ProcessSeerTurn::class, fn ($job) => $job->gameId === $game->id && $job->round === $round);

        // La nuit continue normalement : la Voyante démarre après Cupidon, puis les loups.
        (new ProcessSeerTurn($game->id, $round))->handle();
        Event::assertDispatched(SeerTurnStarted::class);

        (new ProcessWerewolvesTurn($game->id, $round))->handle();
        $this->actingAs($wolfUser)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $v2->id,
        ])->assertStatus(200);

        (new ProcessNightActions($game->id, $round))->handle(app(VoteService::class), app(WinConditionChecker::class));
        (new ProcessNightEnd($game->id, $round))->handle(app(PhaseManager::class));

        $this->assertSame('day', $game->fresh()->status);
        $this->assertFalse($v2->fresh()->is_alive);
        $this->assertTrue($seer->fresh()->is_alive);
        $this->assertTrue($v1->fresh()->is_alive);
        // Le couple survit à la résolution de la nuit, confidentiel jusqu'ici.
        $this->assertSame($v1->id, $seer->fresh()->lover_player_id);
        $this->assertSame($seer->id, $v1->fresh()->lover_player_id);
    }

    // ------------------------------------------------------------------
    // 2. Cupidon se choisit lui-même comme un des deux amoureux
    // ------------------------------------------------------------------

    public function test_cupidon_se_choisit_lui_meme_comme_amoureux(): void
    {
        Event::fake();
        Queue::fake();

        $game        = $this->makeDayGameRoundZero();
        $cupidonUser = User::factory()->create();
        $cupidon     = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id, 'user_id' => $cupidonUser->id]);
        $v1          = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $wolfUser    = User::factory()->create();
        $wolf        = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $wolfUser->id]);
        $v2          = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $v3          = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $v4          = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        app(PhaseManager::class)->startNight($game);
        $round = $game->fresh()->round;

        (new ProcessCupidonTurn($game->id, $round))->handle();

        $this->actingAs($cupidonUser)->postJson("/game/{$game->id}/cupidon/link", [
            'target1_player_id' => $cupidon->id,
            'target2_player_id' => $v1->id,
        ])->assertStatus(200);

        $this->assertSame($v1->id, $cupidon->fresh()->lover_player_id);
        $this->assertSame($cupidon->id, $v1->fresh()->lover_player_id);

        // Cupidon connaît déjà son choix via la réponse HTTP : un seul LoverRevealed, pour l'autre.
        Event::assertDispatched(LoverRevealed::class, 1);
        Event::assertDispatched(LoverRevealed::class, fn ($e) => $e->lover->id === $v1->id && $e->partner->id === $cupidon->id);

        // Pas de Voyante dans cette composition : la nuit passe directement aux loups.
        (new ProcessSeerTurn($game->id, $round))->handle();
        Event::assertNotDispatched(SeerTurnStarted::class);

        (new ProcessWerewolvesTurn($game->id, $round))->handle();
        $this->actingAs($wolfUser)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $v2->id,
        ])->assertStatus(200);

        (new ProcessNightActions($game->id, $round))->handle(app(VoteService::class), app(WinConditionChecker::class));
        (new ProcessNightEnd($game->id, $round))->handle(app(PhaseManager::class));

        $this->assertSame('day', $game->fresh()->status);
        $this->assertFalse($v2->fresh()->is_alive);
        $this->assertTrue($cupidon->fresh()->is_alive);
        $this->assertTrue($v1->fresh()->is_alive);
        $this->assertSame($v1->id, $cupidon->fresh()->lover_player_id);
    }

    // ------------------------------------------------------------------
    // 3. Timeout Cupidon (aucune action volontaire) → aucun couple,
    //    la partie continue, round 2 n'affiche plus jamais Cupidon
    // ------------------------------------------------------------------

    public function test_timeout_cupidon_sans_action_aucun_couple_puis_round_2_sans_cupidon(): void
    {
        Event::fake();
        Queue::fake();

        $game     = $this->makeDayGameRoundZero();
        $cupidon  = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        $seer     = GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        $wolfUser = User::factory()->create();
        $wolf     = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $wolfUser->id]);
        $v1       = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $v2       = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $v3       = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        app(PhaseManager::class)->startNight($game);
        $round1 = $game->fresh()->round;
        $this->assertSame(1, $round1);

        (new ProcessCupidonTurn($game->id, $round1))->handle();
        Event::assertDispatched(CupidonTurnStarted::class);

        // Timeout : aucune action volontaire, ProcessCupidonAutoAction s'exécute directement.
        (new ProcessCupidonAutoAction($game->id, $cupidon->id, $round1))->handle();

        $this->assertNull($cupidon->fresh()->lover_player_id);
        $this->assertSame(0, GameAction::where('game_id', $game->id)->where('type', 'cupidon_link')->count());

        (new ProcessSeerTurn($game->id, $round1))->handle();
        Event::assertDispatched(SeerTurnStarted::class);

        (new ProcessWerewolvesTurn($game->id, $round1))->handle();
        $this->actingAs($wolfUser)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $v3->id,
        ])->assertStatus(200);

        (new ProcessNightActions($game->id, $round1))->handle(app(VoteService::class), app(WinConditionChecker::class));
        (new ProcessNightEnd($game->id, $round1))->handle(app(PhaseManager::class));

        $this->assertSame('day', $game->fresh()->status);
        $this->assertFalse($v3->fresh()->is_alive);

        // Résolution du vote jour (majorité sur v2), sans mettre fin à la partie —
        // dispatchDayVoteConsequences() appelle PhaseManager::startNight() directement.
        GameAction::create([
            'game_id' => $game->id, 'player_id' => $cupidon->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $v2->id, 'round' => $round1, 'phase' => 'day',
        ]);
        GameAction::create([
            'game_id' => $game->id, 'player_id' => $seer->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $v2->id, 'round' => $round1, 'phase' => 'day',
        ]);

        app(VoteService::class)->resolveDayVote($game);

        $this->assertFalse($v2->fresh()->is_alive);
        $round2 = $game->fresh()->round;
        $this->assertSame(2, $round2);
        $this->assertSame('night', $game->fresh()->status);

        // Round 2 : Cupidon n'agit plus jamais, même distribué dans la composition.
        // ProcessCupidonTurn n'a été poussé qu'une seule fois (round 1) sur tout le test.
        Queue::assertPushed(ProcessCupidonTurn::class, 1);
        Event::assertDispatchedTimes(CupidonTurnStarted::class, 1);
        $this->assertNull($cupidon->fresh()->lover_player_id);

        (new ProcessSeerTurn($game->id, $round2))->handle();
        Event::assertDispatched(SeerTurnStarted::class);
        // CupidonTurnStarted n'a jamais été redéclenché pour le round 2.
        Event::assertDispatchedTimes(CupidonTurnStarted::class, 1);
    }

    // ------------------------------------------------------------------
    // 4. Un amoureux meurt (n'importe quelle cause) → l'autre meurt en
    //    cascade immédiatement, via les vrais points d'entrée d'élimination
    // ------------------------------------------------------------------

    public function test_amoureux_meurt_tue_par_les_loups_cascade_immediate(): void
    {
        Event::fake();
        Queue::fake();

        $game     = $this->makeNightGame();
        $wolfUser = User::factory()->create();
        $wolf     = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $wolfUser->id]);
        $loverA   = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $loverB   = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        $this->linkLovers($loverA, $loverB);

        $this->actingAs($wolfUser)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $loverA->id,
        ])->assertStatus(200);

        (new ProcessNightActions($game->id, 1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertFalse($loverA->fresh()->is_alive);
        $this->assertFalse($loverB->fresh()->is_alive);
    }

    public function test_amoureux_empoisonne_par_la_sorciere_cascade_immediate(): void
    {
        Event::fake();
        Queue::fake();

        $game      = $this->makeNightGame();
        $witchUser = User::factory()->create();
        $witch     = GamePlayer::factory()->witch()->create(['game_id' => $game->id, 'user_id' => $witchUser->id]);
        $loverA    = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $loverB    = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        $this->linkLovers($loverA, $loverB);

        $this->actingAs($witchUser)->postJson("/game/{$game->id}/witch/act", [
            'action'           => 'kill',
            'target_player_id' => $loverA->id,
        ])->assertStatus(200);

        $this->assertFalse($loverA->fresh()->is_alive);
        $this->assertFalse($loverB->fresh()->is_alive);
        $this->assertTrue($witch->fresh()->settings['witch_kill_used'] ?? false);
    }

    public function test_amoureux_elimine_par_vote_jour_cascade_immediate(): void
    {
        Event::fake();
        Queue::fake();

        $game   = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $loverA = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $loverB = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $v2     = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $v3     = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $this->linkLovers($loverA, $loverB);

        GameAction::create([
            'game_id' => $game->id, 'player_id' => $v2->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $loverA->id, 'round' => 1, 'phase' => 'day',
        ]);
        GameAction::create([
            'game_id' => $game->id, 'player_id' => $v3->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $loverA->id, 'round' => 1, 'phase' => 'day',
        ]);

        app(VoteService::class)->resolveDayVote($game);

        $this->assertFalse($loverA->fresh()->is_alive);
        $this->assertFalse($loverB->fresh()->is_alive);
    }

    public function test_amoureux_tue_par_le_tir_du_chasseur_cascade_immediate(): void
    {
        Event::fake();
        Queue::fake();

        $game   = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $hunter = GamePlayer::factory()->hunter()->dead()->create(['game_id' => $game->id]);
        $loverA = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $loverB = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        $this->linkLovers($loverA, $loverB);

        app(HunterAction::class)->shoot($hunter, $loverA->id);

        $this->assertFalse($loverA->fresh()->is_alive);
        $this->assertFalse($loverB->fresh()->is_alive);
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $hunter->id, 'type' => 'hunter_shot',
            'target_player_id' => $loverA->id, 'round' => 1,
        ]);
    }

    // ------------------------------------------------------------------
    // 4ter. Chasseur mort de chagrin (cascade amoureux) : doit pouvoir tirer
    //    avant la fin de la nuit / avant la nuit suivante, comme n'importe
    //    quel autre chemin de mort du Chasseur. Fix PlayerEliminationService.
    // ------------------------------------------------------------------

    public function test_amoureux_chasseur_mort_de_chagrin_nuit_peut_tirer_avant_fin_de_nuit(): void
    {
        Event::fake();
        Queue::fake();

        $game        = $this->makeNightGame();
        $wolfUser    = User::factory()->create();
        $wolf        = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $wolfUser->id]);
        $loverA      = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $hunterUser  = User::factory()->create();
        $loverB      = GamePlayer::factory()->hunter()->create(['game_id' => $game->id, 'user_id' => $hunterUser->id]);
        $shootTarget = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $filler      = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $this->linkLovers($loverA, $loverB);

        $this->actingAs($wolfUser)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $loverA->id,
        ])->assertStatus(200);

        (new ProcessNightActions($game->id, 1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        // Le Chasseur (amoureux de la victime des loups) meurt en cascade, mais son
        // hunter_pending doit avoir été créé par PlayerEliminationService lui-même.
        $this->assertFalse($loverA->fresh()->is_alive);
        $this->assertFalse($loverB->fresh()->is_alive);
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $loverB->id, 'type' => 'hunter_pending',
            'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessNightEnd($game->id, 1))->handle(app(PhaseManager::class));

        // La nuit ne se termine pas tant que le Chasseur n'a pas tiré (ou renoncé).
        Event::assertNotDispatched(DayStarted::class);
        $this->assertSame('processing_night', $game->fresh()->status);
        Queue::assertPushed(ProcessHunterTurn::class, fn ($job) => $job->gameId === $game->id
            && $job->round === 1
            && $job->hunterId === $loverB->id);

        (new ProcessHunterTurn($game->id, 1, $loverB->id))
            ->handle(app(PhaseManager::class), app(WinConditionChecker::class));

        $response = $this->actingAs($hunterUser)->postJson("/game/{$game->id}/hunter/shoot", [
            'target_player_id' => $shootTarget->id,
        ]);
        $response->assertStatus(200);

        $this->assertFalse($shootTarget->fresh()->is_alive);
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $loverB->id, 'type' => 'hunter_shot',
            'target_player_id' => $shootTarget->id, 'round' => 1,
        ]);
        Event::assertDispatched(HunterShot::class, fn ($e) => $e->hunter->id === $loverB->id && $e->target->id === $shootTarget->id);
    }

    public function test_amoureux_chasseur_mort_de_chagrin_jour_peut_tirer_avant_nuit_suivante(): void
    {
        Event::fake();
        Queue::fake();

        $game        = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $wolf        = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $loverA      = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $hunterUser  = User::factory()->create();
        $loverB      = GamePlayer::factory()->hunter()->create(['game_id' => $game->id, 'user_id' => $hunterUser->id]);
        $v2          = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $v3          = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $shootTarget = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $this->linkLovers($loverA, $loverB);

        GameAction::create([
            'game_id' => $game->id, 'player_id' => $v2->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $loverA->id, 'round' => 1, 'phase' => 'day',
        ]);
        GameAction::create([
            'game_id' => $game->id, 'player_id' => $v3->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $loverA->id, 'round' => 1, 'phase' => 'day',
        ]);

        app(VoteService::class)->resolveDayVote($game);

        $this->assertFalse($loverA->fresh()->is_alive);
        $this->assertFalse($loverB->fresh()->is_alive);
        // dispatchDayVoteConsequences() consomme (delete) le hunter_pending créé par la
        // cascade dans la même transaction que la résolution du vote — contrairement au
        // chemin nuit (ProcessNightEnd consomme plus tard), il n'y a donc plus de ligne en
        // base une fois resolveDayVote() retourné. La preuve que le fix a fonctionné est le
        // dispatch de ProcessHunterTurn ciblant bien le Chasseur cascadé (loverB), pas v2/v3.
        $this->assertDatabaseMissing('game_actions', ['game_id' => $game->id, 'type' => 'hunter_pending']);
        Queue::assertPushed(ProcessHunterTurn::class, fn ($job) => $job->gameId === $game->id
            && $job->round === 1
            && $job->hunterId === $loverB->id);

        (new ProcessHunterTurn($game->id, 1, $loverB->id, false))
            ->handle(app(PhaseManager::class), app(WinConditionChecker::class));

        $response = $this->actingAs($hunterUser)->postJson("/game/{$game->id}/hunter/shoot", [
            'target_player_id' => $shootTarget->id,
        ]);
        $response->assertStatus(200);

        $this->assertFalse($shootTarget->fresh()->is_alive);
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $loverB->id, 'type' => 'hunter_shot',
            'target_player_id' => $shootTarget->id, 'round' => 1,
        ]);
        Event::assertDispatched(HunterShot::class, fn ($e) => $e->hunter->id === $loverB->id && $e->target->id === $shootTarget->id);
    }

    /**
     * Priorité Chasseur > Maire (RISK_GUARDS Guard #2) appliquée à la cascade amoureux :
     * l'amoureux mort de chagrin est à la fois Chasseur et Maire — le tir doit avoir lieu
     * AVANT toute succession, sans double-déclenchement ni inversion d'ordre.
     */
    public function test_amoureux_chasseur_maire_mort_de_chagrin_tir_avant_succession(): void
    {
        Event::fake();
        Queue::fake();

        $game        = $this->makeNightGame();
        $wolfUser    = User::factory()->create();
        $wolf        = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $wolfUser->id]);
        $loverA      = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $hunterUser  = User::factory()->create();
        $loverB      = GamePlayer::factory()->hunter()->create([
            'game_id' => $game->id, 'user_id' => $hunterUser->id, 'is_mayor' => true,
        ]);
        $shootTarget = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $filler      = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $this->linkLovers($loverA, $loverB);

        $this->actingAs($wolfUser)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $loverA->id,
        ])->assertStatus(200);

        (new ProcessNightActions($game->id, 1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertFalse($loverB->fresh()->is_alive);
        // is_mayor doit rester true : aucune succession n'a dû s'exécuter à ce stade.
        $this->assertTrue($loverB->fresh()->is_mayor);
        Event::assertNotDispatched(MayorSuccessionStarted::class);
        Queue::assertNotPushed(ProcessMayorSuccession::class);

        (new ProcessNightEnd($game->id, 1))->handle(app(PhaseManager::class));

        Queue::assertPushed(ProcessHunterTurn::class, fn ($job) => $job->hunterId === $loverB->id && $job->isMayor === true);

        (new ProcessHunterTurn($game->id, 1, $loverB->id, isMayor: true))
            ->handle(app(PhaseManager::class), app(WinConditionChecker::class));

        // Toujours aucune succession déclenchée avant le tir volontaire.
        Event::assertNotDispatched(MayorSuccessionStarted::class);
        Queue::assertNotPushed(ProcessMayorSuccession::class);

        $response = $this->actingAs($hunterUser)->postJson("/game/{$game->id}/hunter/shoot", [
            'target_player_id' => $shootTarget->id,
        ]);
        $response->assertStatus(200);

        $this->assertFalse($shootTarget->fresh()->is_alive);

        // La succession est déclenchée APRÈS le tir, une seule fois.
        Event::assertDispatched(MayorSuccessionStarted::class);
        Queue::assertPushed(ProcessMayorSuccession::class, 1);
    }

    // ------------------------------------------------------------------
    // 4bis. Reproduction bug prod RIQPAZ (2026-07-24) : un vote de jour qui
    //    élimine le dernier autre joueur (aucune cascade, aucun amoureux
    //    ciblé) doit lui aussi déclencher la victoire des amoureux
    //    immédiatement — via VoteService::resolveDayVote(), pas le chemin
    //    nocturne Sorcière/Chasseur déjà couvert par ailleurs.
    // ------------------------------------------------------------------

    public function test_victoire_amoureux_declenchee_par_vote_de_jour_qui_fait_tomber_effectif_a_deux(): void
    {
        Event::fake();
        Queue::fake();

        $game   = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $loverA = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $loverB = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $last   = GamePlayer::factory()->witch()->create(['game_id' => $game->id]);

        $this->linkLovers($loverA, $loverB);

        // Le village élimine le dernier autre joueur (majorité 2 voix contre 1,
        // comme la partie RIQPAZ : Brave 2 éliminée 2 voix contre 1).
        GameAction::create([
            'game_id' => $game->id, 'player_id' => $loverA->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $last->id, 'round' => 1, 'phase' => 'day',
        ]);
        GameAction::create([
            'game_id' => $game->id, 'player_id' => $loverB->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $last->id, 'round' => 1, 'phase' => 'day',
        ]);
        GameAction::create([
            'game_id' => $game->id, 'player_id' => $last->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $loverA->id, 'round' => 1, 'phase' => 'day',
        ]);

        app(VoteService::class)->resolveDayVote($game);

        $this->assertFalse($last->fresh()->is_alive);
        $this->assertTrue($loverA->fresh()->is_alive);
        $this->assertTrue($loverB->fresh()->is_alive);

        $this->assertSame('finished', $game->fresh()->status, 'La partie aurait dû se terminer en victoire des amoureux dès la résolution du vote de jour.');
        $this->assertSame('lovers', $game->fresh()->winner_team);
        Event::assertDispatched(GameFinished::class, fn ($e) => $e->winnerTeam === 'lovers');
    }

    // ------------------------------------------------------------------
    // 5. Les 2 amoureux deviennent les 2 derniers survivants, camps
    //    différents (1 loup + 1 villageois) → winner_team = lovers
    // ------------------------------------------------------------------

    public function test_victoire_amoureux_loup_et_villageois_derniers_survivants(): void
    {
        Event::fake();
        Queue::fake();

        $game            = $this->makeDayGameRoundZero(4);
        $cupidonUser     = User::factory()->create();
        $cupidon         = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id, 'user_id' => $cupidonUser->id]);
        $wolfUser        = User::factory()->create();
        $wolfLover       = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $wolfUser->id]);
        $villagerLover   = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $extraVillager   = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        app(PhaseManager::class)->startNight($game);
        $round = $game->fresh()->round;

        (new ProcessCupidonTurn($game->id, $round))->handle();

        // Cupidon couple deux joueurs de camps différents (loup + villageois).
        $this->actingAs($cupidonUser)->postJson("/game/{$game->id}/cupidon/link", [
            'target1_player_id' => $wolfLover->id,
            'target2_player_id' => $villagerLover->id,
        ])->assertStatus(200);

        $this->assertSame($villagerLover->id, $wolfLover->fresh()->lover_player_id);
        $this->assertSame($wolfLover->id, $villagerLover->fresh()->lover_player_id);

        // Pas de Voyante : passage direct aux loups.
        (new ProcessSeerTurn($game->id, $round))->handle();
        (new ProcessWerewolvesTurn($game->id, $round))->handle();

        $this->actingAs($wolfUser)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $extraVillager->id,
        ])->assertStatus(200);

        (new ProcessNightActions($game->id, $round))->handle(app(VoteService::class), app(WinConditionChecker::class));
        (new ProcessNightEnd($game->id, $round))->handle(app(PhaseManager::class));

        $this->assertSame('day', $game->fresh()->status);
        $this->assertFalse($extraVillager->fresh()->is_alive);

        // Vote jour : Cupidon (ni loup ni amoureux) est éliminé — il ne reste plus
        // que les deux amoureux, de camps différents (loup + villageois).
        GameAction::create([
            'game_id' => $game->id, 'player_id' => $wolfLover->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $cupidon->id, 'round' => $round, 'phase' => 'day',
        ]);
        GameAction::create([
            'game_id' => $game->id, 'player_id' => $villagerLover->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $cupidon->id, 'round' => $round, 'phase' => 'day',
        ]);

        app(VoteService::class)->resolveDayVote($game);

        $this->assertFalse($cupidon->fresh()->is_alive);
        $this->assertTrue($wolfLover->fresh()->is_alive);
        $this->assertTrue($villagerLover->fresh()->is_alive);

        // La victoire amoureux prime sur le calcul loups/village classique (1 loup vs 1
        // villageois aurait normalement donné la victoire aux loups — voir WinConditionChecker).
        $this->assertSame('finished', $game->fresh()->status);
        $this->assertSame('lovers', $game->fresh()->winner_team);
        Event::assertDispatched(GameFinished::class, fn ($e) => $e->winnerTeam === 'lovers');
    }

    // ------------------------------------------------------------------
    // 5bis. Reproduction bug prod SFICZ8 (2026-07-24) : victoire amoureux
    //    non déclenchée quand la résolution d'un tour de Sorcière fait
    //    tomber l'effectif à 2 amoureux mutuels en une seule fois (la
    //    Sorcière, ciblée par les loups, ne se sauve pas et empoisonne
    //    le Chasseur — les deux derniers autres joueurs meurent dans la
    //    même action WitchAction::act('kill'), sans jamais appeler
    //    WinConditionChecker::check()).
    //
    // Note d'investigation : le scénario littéral du rapport (Chasseur
    // strictement seul survivant autre que le couple, aucune Sorcière en
    // vie) a été vérifié séparément et fonctionne déjà correctement avec
    // le code existant — ProcessNightActions::handle() appelle check()
    // immédiatement après une élimination non différée (ligne ~157).
    // Le gap réel est dans WitchAction, qui ne l'appelle JAMAIS après
    // avoir résolu une victime différée (sorcière elle-même, maire en
    // sursis, ou victime ordinaire) — voir DECISIONS.md.
    // ------------------------------------------------------------------

    public function test_victoire_amoureux_declenchee_apres_resolution_sorciere_qui_fait_tomber_effectif_a_deux(): void
    {
        Event::fake();
        Queue::fake();

        $game        = $this->makeDayGameRoundZero(6);
        $cupidonUser = User::factory()->create();
        $cupidon     = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id, 'user_id' => $cupidonUser->id]);
        $wolfUser    = User::factory()->create();
        $wolfLover   = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $wolfUser->id]);
        $seerLover   = GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        $witchUser   = User::factory()->create();
        $witch       = GamePlayer::factory()->witch()->create(['game_id' => $game->id, 'user_id' => $witchUser->id]);
        $hunter      = GamePlayer::factory()->hunter()->create(['game_id' => $game->id]);

        // Round 1 : Cupidon couple le Loup-Garou et la Voyante (camps différents,
        // exactement comme la partie SFICZ8 : Louy li loup-garou + Tey nguene dh voyante).
        app(PhaseManager::class)->startNight($game);
        $round1 = $game->fresh()->round;
        $this->assertSame(1, $round1);

        (new ProcessCupidonTurn($game->id, $round1))->handle();

        $this->actingAs($cupidonUser)->postJson("/game/{$game->id}/cupidon/link", [
            'target1_player_id' => $wolfLover->id,
            'target2_player_id' => $seerLover->id,
        ])->assertStatus(200);

        $this->assertSame($seerLover->id, $wolfLover->fresh()->lover_player_id);
        $this->assertSame($wolfLover->id, $seerLover->fresh()->lover_player_id);

        (new ProcessSeerTurn($game->id, $round1))->handle();
        $this->actingAs($seerLover->user)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $cupidon->id,
        ])->assertStatus(200);

        // Nuit 1 : les loups tuent Cupidon (son rôle est joué, il n'est plus utile
        // à la partie). La sorcière n'intervient pas.
        (new ProcessWerewolvesTurn($game->id, $round1))->handle();
        $this->actingAs($wolfUser)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $cupidon->id,
        ])->assertStatus(200);

        (new ProcessNightActions($game->id, $round1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->actingAs($witchUser)->postJson("/game/{$game->id}/witch/act", [
            'action' => 'pass',
        ])->assertStatus(200);

        $this->assertFalse($cupidon->fresh()->is_alive);

        (new ProcessNightEnd($game->id, $round1))->handle(app(PhaseManager::class));
        $this->assertSame('day', $game->fresh()->status);

        // Jour 1 : égalité de votes (2 contre 2) → personne éliminé, la partie
        // repasse directement à la nuit suivante (dispatchDayVoteConsequences
        // branche noElimReason → startNight(), aucun rapport avec WinConditionChecker).
        GameAction::create(['game_id' => $game->id, 'player_id' => $wolfLover->id, 'type' => 'day_vote', 'weight' => 1, 'target_player_id' => $witch->id, 'round' => $round1, 'phase' => 'day']);
        GameAction::create(['game_id' => $game->id, 'player_id' => $witch->id, 'type' => 'day_vote', 'weight' => 1, 'target_player_id' => $wolfLover->id, 'round' => $round1, 'phase' => 'day']);
        GameAction::create(['game_id' => $game->id, 'player_id' => $seerLover->id, 'type' => 'day_vote', 'weight' => 1, 'target_player_id' => $hunter->id, 'round' => $round1, 'phase' => 'day']);
        GameAction::create(['game_id' => $game->id, 'player_id' => $hunter->id, 'type' => 'day_vote', 'weight' => 1, 'target_player_id' => $seerLover->id, 'round' => $round1, 'phase' => 'day']);

        app(VoteService::class)->resolveDayVote($game);

        $this->assertTrue($witch->fresh()->is_alive);
        $this->assertTrue($hunter->fresh()->is_alive);
        $round2 = $game->fresh()->round;
        $this->assertSame(2, $round2);
        $this->assertSame('night', $game->fresh()->status);

        // Round 2 (dernière nuit) : seuls le couple + la Sorcière + le Chasseur sont
        // encore vivants. Les loups ciblent cette fois la Sorcière elle-même.
        (new ProcessSeerTurn($game->id, $round2))->handle();
        $this->actingAs($seerLover->user)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $hunter->id,
        ])->assertStatus(200);

        (new ProcessWerewolvesTurn($game->id, $round2))->handle();
        $this->actingAs($wolfUser)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $witch->id,
        ])->assertStatus(200);

        (new ProcessNightActions($game->id, $round2))->handle(app(VoteService::class), app(WinConditionChecker::class));

        // La sorcière (elle-même la cible des loups) a encore sa potion de soin
        // disponible → sa propre mort est différée (victimIsWitchWithHeal),
        // pas d'élimination immédiate ici, donc pas de détection de victoire
        // à ce stade — attendu.
        $this->assertTrue($witch->fresh()->is_alive);
        $this->assertSame('processing_night', $game->fresh()->status);

        // La Sorcière choisit de ne PAS se sauver, et empoisonne le Chasseur à la
        // place. En une seule action WitchAction::act('kill') :
        //   1. Le Chasseur meurt du poison (élimination directe du 'kill').
        //   2. La Sorcière elle-même meurt des loups (witchDiedFromWolves, car
        //      elle ne s'est pas soignée) — resolveDeferredVictim().
        // Il ne reste alors plus que les deux amoureux en vie : la partie DOIT se
        // terminer immédiatement en victoire des amoureux (SPEC_CUPIDON.md §6),
        // sans attendre un quelconque tour de Chasseur en attente.
        $this->actingAs($witchUser)->postJson("/game/{$game->id}/witch/act", [
            'action'           => 'kill',
            'target_player_id' => $hunter->id,
        ])->assertStatus(200);

        $this->assertFalse($hunter->fresh()->is_alive);
        $this->assertFalse($witch->fresh()->is_alive);
        $this->assertTrue($wolfLover->fresh()->is_alive);
        $this->assertTrue($seerLover->fresh()->is_alive);

        // Cœur du bug : la partie doit déjà être terminée ici, sans attendre la
        // chaîne ProcessNightEnd → ProcessHunterTurn → tir du Chasseur.
        $this->assertSame('finished', $game->fresh()->status, 'La partie aurait dû se terminer en victoire des amoureux dès la résolution de la Sorcière, sans attendre le tour du Chasseur.');
        $this->assertSame('lovers', $game->fresh()->winner_team);
        Event::assertDispatched(GameFinished::class, fn ($e) => $e->winnerTeam === 'lovers');

        // Le tir du Chasseur en attente ne doit jamais avoir lieu après la fin de partie :
        // aucun hunter_shot ne doit exister, quel que soit ce que la chaîne de jobs ferait
        // si elle tournait encore (elle doit désormais no-op sur un statut 'finished').
        $this->assertSame(0, GameAction::where('game_id', $game->id)->where('type', 'hunter_shot')->count());
    }

    // ------------------------------------------------------------------
    // 5ter. Le scénario littéral du rapport SFICZ8 (Chasseur strictement
    //    seul autre survivant, aucune Sorcière en vie) fonctionne déjà
    //    correctement — non-régression, voir note d'investigation ci-dessus.
    // ------------------------------------------------------------------

    public function test_victoire_amoureux_deja_correcte_quand_chasseur_seul_autre_survivant_sans_sorciere(): void
    {
        Event::fake();
        Queue::fake();

        $game        = $this->makeDayGameRoundZero(6);
        $cupidonUser = User::factory()->create();
        $cupidon     = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id, 'user_id' => $cupidonUser->id]);
        $wolfUser    = User::factory()->create();
        $wolfLover   = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $wolfUser->id]);
        $seerLover   = GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        $hunter      = GamePlayer::factory()->hunter()->create(['game_id' => $game->id]);
        $filler      = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        app(PhaseManager::class)->startNight($game);
        $round1 = $game->fresh()->round;

        (new ProcessCupidonTurn($game->id, $round1))->handle();
        $this->actingAs($cupidonUser)->postJson("/game/{$game->id}/cupidon/link", [
            'target1_player_id' => $wolfLover->id,
            'target2_player_id' => $seerLover->id,
        ])->assertStatus(200);

        (new ProcessSeerTurn($game->id, $round1))->handle();
        $this->actingAs($seerLover->user)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $filler->id,
        ])->assertStatus(200);

        (new ProcessWerewolvesTurn($game->id, $round1))->handle();
        $this->actingAs($wolfUser)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $filler->id,
        ])->assertStatus(200);

        (new ProcessNightActions($game->id, $round1))->handle(app(VoteService::class), app(WinConditionChecker::class));
        (new ProcessNightEnd($game->id, $round1))->handle(app(PhaseManager::class));
        $this->assertSame('day', $game->fresh()->status);

        GameAction::create(['game_id' => $game->id, 'player_id' => $wolfLover->id, 'type' => 'day_vote', 'weight' => 1, 'target_player_id' => $cupidon->id, 'round' => $round1, 'phase' => 'day']);
        GameAction::create(['game_id' => $game->id, 'player_id' => $seerLover->id, 'type' => 'day_vote', 'weight' => 1, 'target_player_id' => $cupidon->id, 'round' => $round1, 'phase' => 'day']);
        GameAction::create(['game_id' => $game->id, 'player_id' => $hunter->id, 'type' => 'day_vote', 'weight' => 1, 'target_player_id' => $cupidon->id, 'round' => $round1, 'phase' => 'day']);
        app(VoteService::class)->resolveDayVote($game);
        $this->assertFalse($cupidon->fresh()->is_alive);
        $round2 = $game->fresh()->round;
        $this->assertSame('night', $game->fresh()->status);

        // Round 2 (dernière nuit) : seuls le couple + le Chasseur restent — aucune
        // Sorcière dans cette composition. Les loups tuent le Chasseur.
        (new ProcessSeerTurn($game->id, $round2))->handle();
        $this->actingAs($seerLover->user)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $hunter->id,
        ])->assertStatus(200);

        (new ProcessWerewolvesTurn($game->id, $round2))->handle();
        $this->actingAs($wolfUser)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $hunter->id,
        ])->assertStatus(200);

        (new ProcessNightActions($game->id, $round2))->handle(app(VoteService::class), app(WinConditionChecker::class));

        // Pas de sorcière : élimination immédiate au sein de ProcessNightActions,
        // qui appelle déjà WinConditionChecker::check() avant de dispatcher quoi
        // que ce soit d'autre (ligne ~157) — ce chemin n'a jamais été buggé.
        $this->assertFalse($hunter->fresh()->is_alive);
        $this->assertSame('finished', $game->fresh()->status);
        $this->assertSame('lovers', $game->fresh()->winner_team);
        $this->assertSame(0, GameAction::where('game_id', $game->id)->where('type', 'hunter_shot')->count());
    }

    // ------------------------------------------------------------------
    // 6. Partie SANS Cupidon distribué → comportement identique à avant
    //    (aucune régression sur le flux v1.2 : Voyante, Sorcière, Loups, Jour)
    // ------------------------------------------------------------------

    public function test_partie_sans_cupidon_comportement_v1_2_inchange(): void
    {
        Event::fake();
        Queue::fake();

        $game      = $this->makeDayGameRoundZero();
        $seerUser  = User::factory()->create();
        $seer      = GamePlayer::factory()->seer()->create(['game_id' => $game->id, 'user_id' => $seerUser->id]);
        $witchUser = User::factory()->create();
        $witch     = GamePlayer::factory()->witch()->create(['game_id' => $game->id, 'user_id' => $witchUser->id]);
        $wolfUser  = User::factory()->create();
        $wolf      = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $wolfUser->id]);
        $v1        = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $v2        = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $v3        = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        // Pas de Cupidon distribué : startNight() dispatche ProcessSeerTurn directement.
        app(PhaseManager::class)->startNight($game);
        $round = $game->fresh()->round;
        $this->assertSame(1, $round);
        Queue::assertNotPushed(ProcessCupidonTurn::class);
        Queue::assertPushed(ProcessSeerTurn::class, fn ($job) => $job->gameId === $game->id && $job->round === $round);

        (new ProcessSeerTurn($game->id, $round))->handle();
        Event::assertDispatched(SeerTurnStarted::class);

        $this->actingAs($seerUser)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $v1->id,
        ])->assertStatus(200);

        (new ProcessWerewolvesTurn($game->id, $round))->handle();

        $this->actingAs($wolfUser)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $v2->id,
        ])->assertStatus(200);

        (new ProcessNightActions($game->id, $round))->handle(app(VoteService::class), app(WinConditionChecker::class));

        // La sorcière est vivante avec son soin disponible : la mort de v2 est différée
        // jusqu'à son action (comportement v1.2 inchangé, indépendant de Cupidon).
        $this->assertTrue($v2->fresh()->is_alive);

        $this->actingAs($witchUser)->postJson("/game/{$game->id}/witch/act", [
            'action' => 'pass',
        ])->assertStatus(200);

        $this->assertFalse($v2->fresh()->is_alive);

        (new ProcessNightEnd($game->id, $round))->handle(app(PhaseManager::class));

        $this->assertSame('day', $game->fresh()->status);

        // Aucune trace de Cupidon dans une partie où il n'est pas distribué.
        Event::assertNotDispatched(CupidonTurnStarted::class);
        Event::assertNotDispatched(LoverRevealed::class);
        foreach ([$seer, $witch, $wolf, $v1, $v3] as $player) {
            $this->assertNull($player->fresh()->lover_player_id);
        }
    }
}
