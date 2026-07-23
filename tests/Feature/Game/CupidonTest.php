<?php

namespace Tests\Feature\Game;

use App\Events\Game\CupidonTurnStarted;
use App\Events\Game\GameFinished;
use App\Events\Game\LoverRevealed;
use App\Events\Game\SeerTurnStarted;
use App\Jobs\ProcessCupidonAutoAction;
use App\Jobs\ProcessCupidonTurn;
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
