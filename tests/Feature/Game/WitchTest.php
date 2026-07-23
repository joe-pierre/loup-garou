<?php

namespace Tests\Feature\Game;

use App\Events\Game\MayorSuccessionStarted;
use App\Events\Game\PlayerEliminated;
use App\Events\Game\WitchActed;
use App\Events\Game\WitchTurnStarted;
use App\Jobs\ProcessMayorSuccession;
use App\Jobs\ProcessNightActions;
use App\Jobs\ProcessNightEnd;
use App\Jobs\ProcessWitchAutoAction;
use App\Jobs\ProcessWitchTurn;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\RoleActions\WitchAction;
use App\Services\VoteService;
use App\Services\WinConditionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WitchTest extends TestCase
{
    use RefreshDatabase;

    private function makeNightGame(): Game
    {
        return Game::factory()->create([
            'status'      => 'night',
            'max_players' => 6,
            'round'       => 1,
        ]);
    }

    public function test_sorciere_peut_sauver_la_victime_des_loups(): void
    {
        Event::fake();
        Queue::fake();

        $game  = $this->makeNightGame();
        $user  = User::factory()->create();
        $witch = GamePlayer::factory()->witch()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $wolf   = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $victim = GamePlayer::factory()->villager()->dead()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf->id, 'type' => 'night_vote',
            'target_player_id' => $victim->id, 'round' => 1, 'phase' => 'night',
        ]);

        // Simule la résolution persistée par ProcessNightActions (night_resolve).
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $victim->id, 'type' => 'night_resolve',
            'target_player_id' => $victim->id, 'round' => 1, 'phase' => 'night',
        ]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/witch/act", [
            'action'           => 'heal',
            'target_player_id' => $victim->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertTrue($victim->fresh()->is_alive);
        $this->assertTrue($witch->fresh()->settings['witch_heal_used'] ?? false);
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $witch->id, 'type' => 'witch_heal',
            'target_player_id' => $victim->id, 'round' => 1,
        ]);
    }

    public function test_sorciere_peut_sauver_si_elle_est_la_victime(): void
    {
        Event::fake();
        Queue::fake();

        $game  = $this->makeNightGame();
        $user  = User::factory()->create();
        $witch = GamePlayer::factory()->witch()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $wolf = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        // Simule la résolution persistée par ProcessNightActions : la sorcière est la victime.
        // ProcessNightActions ne l'a PAS marquée morte (victimIsWitchWithHeal = true).
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $witch->id, 'type' => 'night_resolve',
            'target_player_id' => $witch->id, 'round' => 1, 'phase' => 'night',
        ]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/witch/act", [
            'action'           => 'heal',
            'target_player_id' => $witch->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // La sorcière reste vivante (auto-soin)
        $this->assertTrue($witch->fresh()->is_alive);
        $this->assertTrue($witch->fresh()->settings['witch_heal_used'] ?? false);

        // L'action est enregistrée
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $witch->id, 'type' => 'witch_heal',
            'target_player_id' => $witch->id, 'round' => 1,
        ]);

        // Aucun PlayerEliminated pour la sorcière (elle a survécu)
        Event::assertNotDispatched(PlayerEliminated::class, fn ($e) => $e->player->id === $witch->id);
    }

    public function test_sorciere_peut_empoisonner_un_joueur(): void
    {
        Event::fake();
        Queue::fake();

        $game  = $this->makeNightGame();
        $user  = User::factory()->create();
        $witch = GamePlayer::factory()->witch()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/witch/act", [
            'action'           => 'kill',
            'target_player_id' => $target->id,
        ]);

        $response->assertStatus(200);
        $this->assertFalse($target->fresh()->is_alive);
        $this->assertTrue($witch->fresh()->settings['witch_kill_used'] ?? false);
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $witch->id, 'type' => 'witch_kill',
            'target_player_id' => $target->id, 'round' => 1,
        ]);

        Event::assertDispatched(PlayerEliminated::class);
        Event::assertDispatched(WitchActed::class);
    }

    public function test_sorciere_ne_peut_pas_utiliser_deux_fois_la_meme_potion(): void
    {
        Event::fake();
        Queue::fake();

        $game  = $this->makeNightGame();
        $user  = User::factory()->create();
        $witch = GamePlayer::factory()->witch()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
            'settings' => ['witch_kill_used' => true],
        ]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/witch/act", [
            'action'           => 'kill',
            'target_player_id' => $target->id,
        ]);

        $response->assertStatus(409);
        $this->assertTrue($target->fresh()->is_alive);
    }

    /**
     * Guard #3 révisé : sans victime des loups, la sorcière reçoit quand même
     * WitchTurnStarted si elle a encore son poison — heal_available doit être false.
     */
    public function test_witch_turn_avec_poison_disponible_si_egalite_loups(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();
        GamePlayer::factory()->witch()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);
        // Pas de night_vote → resolveNightVote() retourne null

        (new ProcessWitchTurn($game->id, $game->round))->handle(app(VoteService::class));

        // WitchTurnStarted doit être broadcasté avec victim null et heal_available false
        Event::assertDispatched(WitchTurnStarted::class, function ($e) {
            return $e->victim === null
                && $e->healAvailable === false
                && $e->killAvailable === true;
        });
        Queue::assertPushed(ProcessWitchAutoAction::class);
        Queue::assertNotPushed(ProcessNightEnd::class);
    }

    /**
     * Guard #3 : si les deux potions sont épuisées ET pas de victime → skip silencieux.
     */
    public function test_witch_turn_skipped_si_egalite_loups_et_potions_epuisees(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();
        GamePlayer::factory()->witch()->create([
            'game_id'  => $game->id,
            'settings' => ['witch_heal_used' => true, 'witch_kill_used' => true],
        ]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessWitchTurn($game->id, $game->round))->handle(app(VoteService::class));

        Event::assertNotDispatched(WitchTurnStarted::class);
        // ProcessNightEnd est dispatché par ProcessNightActions, pas par ProcessWitchTurn.
        Queue::assertNotPushed(ProcessNightEnd::class);
    }

    /**
     * Si seul le soin est épuisé mais pas le poison, et pas de victime →
     * la sorcière reçoit quand même WitchTurnStarted pour utiliser son poison.
     */
    public function test_witch_turn_avec_soin_epuise_mais_poison_disponible(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();
        GamePlayer::factory()->witch()->create([
            'game_id'  => $game->id,
            'settings' => ['witch_heal_used' => true, 'witch_kill_used' => false],
        ]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessWitchTurn($game->id, $game->round))->handle(app(VoteService::class));

        Event::assertDispatched(WitchTurnStarted::class, function ($e) {
            return $e->victim === null
                && $e->healAvailable === false
                && $e->killAvailable === true;
        });
        Queue::assertPushed(ProcessWitchAutoAction::class);
    }

    /**
     * Guard #4 : seul ProcessNightActions doit dispatcher ProcessWitchTurn,
     * après résolution du vote des loups.
     */
    public function test_witch_turn_dispatche_par_night_actions_uniquement(): void
    {
        Event::fake();
        Queue::fake();

        $game   = $this->makeNightGame();
        GamePlayer::factory()->witch()->create(['game_id' => $game->id]);
        $wolf   = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $victim = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf->id, 'type' => 'night_vote',
            'target_player_id' => $victim->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessNightActions($game->id, 1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        Queue::assertPushed(ProcessWitchTurn::class, fn ($job) => $job->gameId === $game->id && $job->round === 1);
    }

    /**
     * Guard #6 : si la sorcière a déjà agi ce round (witch_heal/kill/pass existant),
     * ProcessWitchTurn ne doit pas re-broadcaster WitchTurnStarted ni re-planifier
     * ProcessWitchAutoAction.
     */
    public function test_witch_turn_non_double_dispatche_meme_round(): void
    {
        Event::fake();
        Queue::fake();

        $game   = $this->makeNightGame();
        $witch  = GamePlayer::factory()->witch()->create(['game_id' => $game->id]);
        $wolf   = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $victim = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf->id, 'type' => 'night_vote',
            'target_player_id' => $victim->id, 'round' => 1, 'phase' => 'night',
        ]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $witch->id, 'type' => 'witch_pass',
            'target_player_id' => null, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessWitchTurn($game->id, $game->round))->handle(app(VoteService::class));

        Event::assertNotDispatched(WitchTurnStarted::class);
        Queue::assertNotPushed(ProcessWitchAutoAction::class);
    }

    /**
     * La sorcière empoisonne un joueur vivant pendant processing_night :
     * l'action doit être persistée même avec un worker rapide.
     */
    public function test_sorciere_empoisonne_joueur_vivant_pendant_processing_night(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create([
            'status' => 'processing_night', 'max_players' => 6, 'round' => 1,
        ]);
        $user   = User::factory()->create();
        $witch  = GamePlayer::factory()->witch()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/witch/act", [
            'action'           => 'kill',
            'target_player_id' => $target->id,
        ]);

        $response->assertStatus(200);
        $this->assertFalse($target->fresh()->is_alive);
        $this->assertDatabaseHas('game_actions', [
            'game_id'   => $game->id,
            'player_id' => $witch->id,
            'type'      => 'witch_kill',
            'round'     => 1,
        ]);

        // ProcessWitchAutoAction ne doit PAS créer de witch_pass puisque witch_kill existe
        Queue::assertPushed(\App\Jobs\ProcessWitchAutoAction::class);
        // Simuler l'exécution du job
        (new \App\Jobs\ProcessWitchAutoAction($game->id, 1))->handle(app(WitchAction::class));

        $this->assertSame(
            0,
            GameAction::where('game_id', $game->id)->where('type', 'witch_pass')->count()
        );
    }

    /**
     * La victime présentée à la sorcière est exactement celle persistée par
     * ProcessNightActions (night_resolve), même en cas d'égalité parfaite entre loups.
     *
     * Avant ce fix, ProcessWitchTurn rappelait resolveNightVote() indépendamment,
     * pouvant retourner une victime différente via inRandomOrder().
     */
    public function test_victime_sorciere_identique_a_victime_loups_en_cas_egalite(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();

        // 2 villageois cibles (vote en égalité parfaite), 2 villageois extra pour éviter
        // la condition de victoire (2 loups vs 4 côté village après élimination).
        $target1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->witch()->create(['game_id' => $game->id]);
        $wolf1   = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $wolf2   = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);

        // Égalité parfaite : chaque loup vote pour une cible différente.
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf1->id, 'type' => 'night_vote',
            'target_player_id' => $target1->id, 'round' => 1, 'phase' => 'night',
        ]);
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf2->id, 'type' => 'night_vote',
            'target_player_id' => $target2->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessNightActions($game->id, 1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        // Capture la victime persistée par ProcessNightActions.
        $nightResolve = \App\Models\GameAction::where('game_id', $game->id)
            ->where('type', 'night_resolve')
            ->where('round', 1)
            ->first();

        $this->assertNotNull($nightResolve, 'night_resolve doit exister après ProcessNightActions');

        // Exécute le tour sorcière directement (Queue::fake() a empêché son dispatch réel).
        (new ProcessWitchTurn($game->id, 1))->handle(app(VoteService::class));

        // La sorcière doit voir exactement la même victime que celle résolue par les loups.
        Event::assertDispatched(WitchTurnStarted::class, function ($e) use ($nightResolve) {
            return $e->victim !== null
                && $e->victim->id === $nightResolve->target_player_id;
        });
    }

    /**
     * Atomicité : witch_kill et hunter_pending doivent être créés dans la même transaction.
     * Si l'un est absent, le chasseur perd silencieusement son tour de tir.
     */
    public function test_hunter_pending_dans_transaction_witch_kill(): void
    {
        Event::fake();
        Queue::fake();

        $game    = $this->makeNightGame();
        $user    = User::factory()->create();
        $witch   = GamePlayer::factory()->witch()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $hunter  = GamePlayer::factory()->hunter()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/witch/act", [
            'action'           => 'kill',
            'target_player_id' => $hunter->id,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('game_actions', [
            'game_id'          => $game->id,
            'player_id'        => $witch->id,
            'type'             => 'witch_kill',
            'target_player_id' => $hunter->id,
            'round'            => 1,
        ]);

        $this->assertDatabaseHas('game_actions', [
            'game_id'   => $game->id,
            'player_id' => $hunter->id,
            'type'      => 'hunter_pending',
            'round'     => 1,
            'phase'     => 'night',
        ]);
    }

    /**
     * Régression : la sorcière empoisonne directement le Chasseur-Maire. hunter_pending
     * doit être créé (comportement déjà correct) et la succession du maire ne doit PAS
     * être déclenchée ici — priorité tir > succession. Voir DECISIONS.md
     * "Chasseur Maire — tir avant succession du maire".
     */
    public function test_sorciere_empoisonne_chasseur_maire_succession_pas_declenchee(): void
    {
        Event::fake();
        Queue::fake();

        $game  = $this->makeNightGame();
        $user  = User::factory()->create();
        $witch = GamePlayer::factory()->witch()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $hunterMayor = GamePlayer::factory()->hunter()->create([
            'game_id' => $game->id, 'is_mayor' => true,
        ]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/witch/act", [
            'action'           => 'kill',
            'target_player_id' => $hunterMayor->id,
        ]);

        $response->assertStatus(200);

        $this->assertFalse($hunterMayor->fresh()->is_alive);
        $this->assertTrue($hunterMayor->fresh()->is_mayor);

        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $hunterMayor->id, 'type' => 'hunter_pending',
            'round' => 1, 'phase' => 'night',
        ]);

        Event::assertNotDispatched(MayorSuccessionStarted::class);
        Queue::assertNotPushed(ProcessMayorSuccession::class);
    }

    /**
     * Régression : le maire en sursis visé par les loups (sorcière avec soin disponible,
     * cf. "Maire en sursis" dans DECISIONS.md) est aussi le Chasseur. La sorcière choisit
     * de ne pas le sauver (elle passe). Avant ce fix, hunter_pending n'était JAMAIS créé
     * pour ce chemin (bug distinct, découvert par audit) et la succession était déclenchée
     * immédiatement — le Chasseur ne tirait jamais. Voir DECISIONS.md
     * "Chasseur Maire — tir avant succession du maire".
     */
    public function test_maire_en_sursis_chasseur_non_sauve_par_sorciere_cree_hunter_pending(): void
    {
        Event::fake();
        Queue::fake();

        $game  = $this->makeNightGame();
        $user  = User::factory()->create();
        $witch = GamePlayer::factory()->witch()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $hunterMayor = GamePlayer::factory()->hunter()->create([
            'game_id' => $game->id, 'is_mayor' => true,
        ]);
        $wolf = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf->id, 'type' => 'night_vote',
            'target_player_id' => $hunterMayor->id, 'round' => 1, 'phase' => 'night',
        ]);

        // Résout la nuit : le maire-chasseur est en sursis (sorcière vivante, soin disponible)
        // → pas encore marqué mort, pas de hunter_pending à ce stade.
        (new ProcessNightActions($game->id, 1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertTrue($hunterMayor->fresh()->is_alive);
        $this->assertDatabaseMissing('game_actions', [
            'game_id' => $game->id, 'player_id' => $hunterMayor->id, 'type' => 'hunter_pending',
        ]);

        // La sorcière choisit de ne pas sauver le maire-chasseur : elle passe son tour.
        $response = $this->actingAs($user)->postJson("/game/{$game->id}/witch/act", [
            'action' => 'pass',
        ]);

        $response->assertStatus(200);

        $this->assertFalse($hunterMayor->fresh()->is_alive);
        $this->assertTrue($hunterMayor->fresh()->is_mayor);

        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $hunterMayor->id, 'type' => 'hunter_pending',
            'round' => 1, 'phase' => 'night',
        ]);

        Event::assertDispatched(PlayerEliminated::class, fn ($e) => $e->player->id === $hunterMayor->id);
        Event::assertNotDispatched(MayorSuccessionStarted::class);
        Queue::assertNotPushed(ProcessMayorSuccession::class);
    }

    /**
     * Guard #3 (variante AutoAction) : même sans victime des loups,
     * ProcessWitchAutoAction doit résoudre le tour (witch_pass) et
     * dispatcher ProcessNightEnd sans bloquer.
     */
    public function test_sorciere_auto_action_sans_victime_ne_bloque_pas(): void
    {
        Event::fake();
        Queue::fake();

        $game  = $this->makeNightGame();
        $witch = GamePlayer::factory()->witch()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessWitchAutoAction($game->id, $game->round))->handle(app(WitchAction::class));

        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $witch->id, 'type' => 'witch_pass',
            'round' => $game->round,
        ]);
        Queue::assertPushed(ProcessNightEnd::class, fn ($job) => $job->gameId === $game->id && $job->round === $game->round);
    }

    /**
     * Fix witch-timeout-victim-not-eliminated, cas 1/3 : victime ordinaire (ni sorcière,
     * ni maire) toujours vivante quand le timer de la Sorcière expire sans qu'elle agisse.
     * Avant le fix, is_alive restait true indéfiniment (bug de prod).
     */
    public function test_timeout_elimine_victime_ordinaire_des_loups(): void
    {
        Event::fake();
        Queue::fake();

        $game  = $this->makeNightGame();
        $witch = GamePlayer::factory()->witch()->create(['game_id' => $game->id]);
        $victim = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $victim->id, 'type' => 'night_resolve',
            'target_player_id' => $victim->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessWitchAutoAction($game->id, $game->round))->handle(app(WitchAction::class));

        $this->assertFalse($victim->fresh()->is_alive);
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $witch->id, 'type' => 'witch_pass',
            'round' => 1,
        ]);
        Event::assertDispatched(PlayerEliminated::class, fn ($e) => $e->player->id === $victim->id);
    }

    /**
     * Fix witch-timeout-victim-not-eliminated, cas 2/3 : la Sorcière elle-même était la
     * victime des loups et n'a pas utilisé son soin avant l'expiration du timer.
     */
    public function test_timeout_elimine_sorciere_si_elle_etait_la_victime(): void
    {
        Event::fake();
        Queue::fake();

        $game  = $this->makeNightGame();
        $witch = GamePlayer::factory()->witch()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $witch->id, 'type' => 'night_resolve',
            'target_player_id' => $witch->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessWitchAutoAction($game->id, $game->round))->handle(app(WitchAction::class));

        $this->assertFalse($witch->fresh()->is_alive);
        Event::assertDispatched(PlayerEliminated::class, fn ($e) => $e->player->id === $witch->id);
    }

    /**
     * Fix witch-timeout-victim-not-eliminated, cas 3/3 (sans Chasseur) : le maire en sursis
     * (victime des loups, ni sorcière ni déjà mort) doit être éliminé et sa succession
     * déclenchée quand le timer sorcière expire sans action.
     */
    public function test_timeout_elimine_maire_en_sursis_et_declenche_succession(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();
        GamePlayer::factory()->witch()->create(['game_id' => $game->id]);
        $mayor = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_mayor' => true]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $mayor->id, 'type' => 'night_resolve',
            'target_player_id' => $mayor->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessWitchAutoAction($game->id, $game->round))->handle(app(WitchAction::class));

        $this->assertFalse($mayor->fresh()->is_alive);
        $this->assertTrue($mayor->fresh()->is_mayor);
        Event::assertDispatched(PlayerEliminated::class, fn ($e) => $e->player->id === $mayor->id);
        Event::assertDispatched(MayorSuccessionStarted::class);
        Queue::assertPushed(ProcessMayorSuccession::class);
    }

    /**
     * Fix witch-timeout-victim-not-eliminated, cas 3/3 (sous-cas Chasseur-maire) : priorité
     * tir > succession — hunter_pending doit être créé et la succession NE DOIT PAS être
     * déclenchée par ce chemin, exactement comme pour le pass manuel (voir DECISIONS.md
     * "Chasseur Maire — tir avant succession du maire").
     */
    public function test_timeout_elimine_maire_chasseur_en_sursis_sans_declencher_succession(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();
        GamePlayer::factory()->witch()->create(['game_id' => $game->id]);
        $hunterMayor = GamePlayer::factory()->hunter()->create([
            'game_id' => $game->id, 'is_mayor' => true,
        ]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $hunterMayor->id, 'type' => 'night_resolve',
            'target_player_id' => $hunterMayor->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessWitchAutoAction($game->id, $game->round))->handle(app(WitchAction::class));

        $this->assertFalse($hunterMayor->fresh()->is_alive);
        $this->assertTrue($hunterMayor->fresh()->is_mayor);
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $hunterMayor->id, 'type' => 'hunter_pending',
            'round' => 1, 'phase' => 'night',
        ]);
        Event::assertDispatched(PlayerEliminated::class, fn ($e) => $e->player->id === $hunterMayor->id);
        Event::assertNotDispatched(MayorSuccessionStarted::class);
        Queue::assertNotPushed(ProcessMayorSuccession::class);
    }

    /**
     * Guard anti-doublon (contrainte de non-régression) : si une action sorcière manuelle
     * existe déjà pour ce round au moment où ProcessWitchAutoAction s'exécute (course
     * gagnée par le chemin manuel), le timeout ne doit ni re-créer de witch_pass, ni
     * re-finaliser/re-broadcaster une victime déjà traitée par WitchAction::act().
     */
    public function test_timeout_ne_finalise_pas_si_sorciere_a_deja_agi(): void
    {
        Event::fake();
        Queue::fake();

        $game   = $this->makeNightGame();
        $witch  = GamePlayer::factory()->witch()->create(['game_id' => $game->id]);
        $victim = GamePlayer::factory()->villager()->dead()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $victim->id, 'type' => 'night_resolve',
            'target_player_id' => $victim->id, 'round' => 1, 'phase' => 'night',
        ]);

        // Le chemin manuel a déjà résolu ce round (pass déjà posé, victime déjà finalisée).
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $witch->id, 'type' => 'witch_pass',
            'target_player_id' => null, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessWitchAutoAction($game->id, $game->round))->handle(app(WitchAction::class));

        $this->assertSame(
            1,
            GameAction::where('game_id', $game->id)->where('type', 'witch_pass')->count(),
            'Aucun second witch_pass ne doit être créé par le timeout.'
        );
        Event::assertNotDispatched(PlayerEliminated::class);
        Event::assertNotDispatched(MayorSuccessionStarted::class);
    }
}
