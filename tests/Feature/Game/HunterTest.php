<?php

namespace Tests\Feature\Game;

use App\Events\Game\DayStarted;
use App\Events\Game\HunterShot;
use App\Events\Game\HunterTurnStarted;
use App\Events\Game\MayorSuccessionStarted;
use App\Events\Game\PlayerEliminated;
use App\Jobs\ProcessHunterAutoAction;
use App\Jobs\ProcessHunterTurn;
use App\Jobs\ProcessMayorSuccession;
use App\Jobs\ProcessNightActions;
use App\Jobs\ProcessNightEnd;
use App\Jobs\ProcessDayVote;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\PhaseManager;
use App\Services\VoteService;
use App\Services\WinConditionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HunterTest extends TestCase
{
    use RefreshDatabase;

    private function makeNightGame(int $round = 1): Game
    {
        return Game::factory()->create([
            'status'      => 'night',
            'max_players' => 6,
            'round'       => $round,
        ]);
    }

    private function makeDayGame(int $round = 1): Game
    {
        return Game::factory()->create([
            'status'      => 'day',
            'max_players' => 6,
            'round'       => $round,
        ]);
    }

    public function test_chasseur_tire_apres_mort_nuit(): void
    {
        Event::fake();
        Queue::fake();

        $game   = $this->makeNightGame();
        $user   = User::factory()->create();
        $hunter = GamePlayer::factory()->hunter()->dead()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        (new ProcessHunterTurn($game->id, $game->round, $hunter->id))
            ->handle(app(PhaseManager::class), app(WinConditionChecker::class));

        Event::assertDispatched(HunterTurnStarted::class, fn ($e) => $e->hunter->id === $hunter->id);
        Queue::assertPushed(ProcessHunterAutoAction::class, fn ($job) => $job->gameId === $game->id
            && $job->hunterId === $hunter->id
            && $job->fromNight === true);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/hunter/shoot", [
            'target_player_id' => $target->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertFalse($target->fresh()->is_alive);
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $hunter->id, 'type' => 'hunter_shot',
            'target_player_id' => $target->id, 'round' => $game->round,
        ]);

        Event::assertDispatched(HunterShot::class, fn ($e) => $e->target->id === $target->id);
        Event::assertDispatched(PlayerEliminated::class, fn ($e) => $e->player->id === $target->id && $e->reason === 'hunter_shot');
    }

    public function test_chasseur_tire_apres_mort_jour(): void
    {
        Event::fake();
        Queue::fake();

        $game   = $this->makeDayGame();
        $user   = User::factory()->create();
        $hunter = GamePlayer::factory()->hunter()->dead()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        (new ProcessHunterTurn($game->id, $game->round, $hunter->id))
            ->handle(app(PhaseManager::class), app(WinConditionChecker::class));

        Event::assertDispatched(HunterTurnStarted::class, fn ($e) => $e->hunter->id === $hunter->id);
        Queue::assertPushed(ProcessHunterAutoAction::class, fn ($job) => $job->gameId === $game->id
            && $job->hunterId === $hunter->id
            && $job->fromNight === false);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/hunter/shoot", [
            'target_player_id' => $target->id,
        ]);

        $response->assertStatus(200);
        $this->assertFalse($target->fresh()->is_alive);

        Event::assertDispatched(HunterShot::class, fn ($e) => $e->target->id === $target->id);
        Event::assertDispatched(PlayerEliminated::class, fn ($e) => $e->player->id === $target->id && $e->reason === 'hunter_shot');
    }

    public function test_chasseur_ne_peut_pas_tirer_sur_lui_meme(): void
    {
        Event::fake();
        Queue::fake();

        $game   = $this->makeNightGame();
        $user   = User::factory()->create();
        $hunter = GamePlayer::factory()->hunter()->dead()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/hunter/shoot", [
            'target_player_id' => $hunter->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('game_actions', [
            'game_id' => $game->id, 'type' => 'hunter_shot',
        ]);
    }

    public function test_chasseur_ne_peut_pas_tirer_sur_joueur_mort(): void
    {
        Event::fake();
        Queue::fake();

        $game   = $this->makeNightGame();
        $user   = User::factory()->create();
        $hunter = GamePlayer::factory()->hunter()->dead()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $deadTarget = GamePlayer::factory()->villager()->dead()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/hunter/shoot", [
            'target_player_id' => $deadTarget->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('game_actions', [
            'game_id' => $game->id, 'type' => 'hunter_shot',
        ]);
    }

    public function test_chasseur_ne_peut_pas_tirer_deux_fois(): void
    {
        Event::fake();
        Queue::fake();

        $game   = $this->makeNightGame();
        $user   = User::factory()->create();
        $hunter = GamePlayer::factory()->hunter()->dead()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $target1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        $first = $this->actingAs($user)->postJson("/game/{$game->id}/hunter/shoot", [
            'target_player_id' => $target1->id,
        ]);
        $first->assertStatus(200);

        $second = $this->actingAs($user)->postJson("/game/{$game->id}/hunter/shoot", [
            'target_player_id' => $target2->id,
        ]);

        $second->assertStatus(409);
        $this->assertTrue($target2->fresh()->is_alive);
    }

    public function test_hunter_auto_action_skipped_if_already_shot(): void
    {
        Event::fake();
        Queue::fake();

        // La transition de phase a déjà eu lieu (suite au tir volontaire) :
        // le status n'est plus dans les statuts attendus pour fromNight=true.
        $game   = $this->makeDayGame();
        $hunter = GamePlayer::factory()->hunter()->dead()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $hunter->id, 'type' => 'hunter_shot',
            'target_player_id' => null, 'round' => $game->round, 'phase' => 'night',
        ]);

        (new ProcessHunterAutoAction($game->id, $game->round, $hunter->id, fromNight: true))
            ->handle(app(PhaseManager::class), app(WinConditionChecker::class));

        Event::assertNotDispatched(DayStarted::class);
        Queue::assertNotPushed(ProcessDayVote::class);
        $this->assertSame('day', $game->fresh()->status);
    }

    /**
     * Bug 4 — Chasseur Maire : tir avant succession.
     * Scénario : chasseur-maire éliminé le jour → ProcessHunterTurn dispatché avec $isMayor=true
     * → tir volontaire → MayorSuccessionStarted broadcasté APRÈS le tir, pas avant.
     * ProcessHunterAutoAction (delay 0) ne doit PAS re-déclencher la succession (guard hunter_shot).
     */
    public function test_chasseur_maire_elimine_jour_succession_apres_tir(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeDayGame();
        $user = User::factory()->create();

        $hunter = GamePlayer::factory()->hunter()->create([
            'game_id'  => $game->id,
            'user_id'  => $user->id,
            'is_mayor' => true,
        ]);
        $wolf   = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        // Simuler l'état post-transaction de resolveDayVote : hunter mort + hunter_pending créé
        $hunter->update(['is_alive' => false]);
        GameAction::create([
            'game_id'   => $game->id,
            'player_id' => $hunter->id,
            'type'      => 'hunter_pending',
            'round'     => $game->round,
            'phase'     => 'day',
        ]);

        // ProcessHunterTurn dispatché par VoteService avec $isMayor=true
        (new ProcessHunterTurn($game->id, $game->round, $hunter->id, isMayor: true))
            ->handle(app(PhaseManager::class), app(WinConditionChecker::class));

        // HunterTurnStarted broadcasté, ProcessHunterAutoAction dispatché avec isMayor=true
        Event::assertDispatched(HunterTurnStarted::class, fn ($e) => $e->hunter->id === $hunter->id);
        Queue::assertPushed(ProcessHunterAutoAction::class, fn ($job) => $job->isMayor === true);

        // Tir volontaire via ActionController
        $response = $this->actingAs($user)->postJson("/game/{$game->id}/hunter/shoot", [
            'target_player_id' => $target->id,
        ]);
        $response->assertStatus(200);

        // La cible est morte
        $this->assertFalse($target->fresh()->is_alive);

        // MayorSuccessionStarted broadcasté APRÈS le tir (par ActionController)
        Event::assertDispatched(MayorSuccessionStarted::class);

        // ProcessMayorSuccession dispatché APRÈS le tir
        Queue::assertPushed(ProcessMayorSuccession::class);

        // ProcessHunterAutoAction (delay 0) avec isMayor=true + hunter_shot en DB
        // → guard "alreadyShot" → return sans re-déclencher la succession
        $autoAction = new ProcessHunterAutoAction(
            $game->id, $game->round, $hunter->id, fromNight: false, isMayor: true
        );
        $autoAction->handle(app(PhaseManager::class), app(WinConditionChecker::class));

        // Une seule occurrence de ProcessMayorSuccession (pas de double succession)
        Queue::assertPushed(ProcessMayorSuccession::class, 1);
    }

    /**
     * Régression : Chasseur-Maire tué par les loups (sans sorcière vivante, donc mort
     * immédiate dans ProcessNightActions). hunter_pending doit être créé et la succession
     * du maire ne doit PAS être déclenchée à ce stade — priorité tir > succession, comme
     * pour le cas jour. Voir DECISIONS.md "Chasseur Maire — tir avant succession du maire".
     */
    public function test_chasseur_maire_tue_par_loups_nuit_succession_pas_declenchee_avant_tir(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();

        $hunter = GamePlayer::factory()->hunter()->create([
            'game_id'  => $game->id,
            'is_mayor' => true,
        ]);
        $wolf = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf->id, 'type' => 'night_vote',
            'target_player_id' => $hunter->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessNightActions($game->id, 1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertFalse($hunter->fresh()->is_alive);
        // is_mayor doit rester true : aucune succession n'a dû s'exécuter à ce stade.
        $this->assertTrue($hunter->fresh()->is_mayor);

        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $hunter->id, 'type' => 'hunter_pending',
            'round' => 1, 'phase' => 'night',
        ]);

        Event::assertNotDispatched(MayorSuccessionStarted::class);
        Queue::assertNotPushed(ProcessMayorSuccession::class);
        Queue::assertPushed(ProcessNightEnd::class, fn ($job) => $job->gameId === $game->id && $job->round === 1);
    }

    public function test_hunter_auto_action_no_elimination_if_inactive(): void
    {
        Event::fake();
        Queue::fake();

        $game   = $this->makeNightGame();
        $hunter = GamePlayer::factory()->hunter()->dead()->create(['game_id' => $game->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessHunterAutoAction($game->id, $game->round, $hunter->id, fromNight: true))
            ->handle(app(PhaseManager::class), app(WinConditionChecker::class));

        $this->assertSame('day', $game->fresh()->status);
        Event::assertDispatched(DayStarted::class);
        Queue::assertPushed(ProcessDayVote::class);

        // Aucune élimination supplémentaire : seul le chasseur (déjà mort) est mort
        $this->assertSame(1, GamePlayer::where('game_id', $game->id)->where('is_alive', false)->count());
    }

    /**
     * Guard #2 : quand la victime de la nuit est le chasseur, ProcessNightActions
     * doit poser le cache "hunter_must_shoot_{id}" avant de dispatcher ProcessNightEnd.
     */
    public function test_chasseur_tire_apres_resolution_complete_de_nuit(): void
    {
        Event::fake();
        Queue::fake();

        $game   = $this->makeNightGame();
        $hunter = GamePlayer::factory()->hunter()->create(['game_id' => $game->id]);
        $wolf   = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf->id, 'type' => 'night_vote',
            'target_player_id' => $hunter->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessNightActions($game->id, 1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertFalse($hunter->fresh()->is_alive);
        $this->assertDatabaseHas('game_actions', [
            'game_id'   => $game->id,
            'player_id' => $hunter->id,
            'type'      => 'hunter_pending',
            'round'     => 1,
        ]);
        Queue::assertPushed(ProcessNightEnd::class, fn ($job) => $job->gameId === $game->id && $job->round === $game->round);
    }

    /**
     * Guard #2 (suite) : ProcessNightEnd doit consommer le cache et dispatcher
     * ProcessHunterTurn SANS appeler endNight() — la nuit ne se termine pas tant
     * que le chasseur n'a pas tiré (ou que son auto-action n'a pas résolu le tour).
     */
    public function test_chasseur_ne_tire_pas_avant_day_started(): void
    {
        Event::fake();
        Queue::fake();

        $game   = $this->makeNightGame();
        $hunter = GamePlayer::factory()->hunter()->dead()->create(['game_id' => $game->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        $game->update(['status' => 'processing_night']);
        GameAction::create([
            'game_id'   => $game->id,
            'player_id' => $hunter->id,
            'type'      => 'hunter_pending',
            'round'     => $game->round,
            'phase'     => 'night',
        ]);

        (new ProcessNightEnd($game->id, $game->round))->handle(app(PhaseManager::class));

        Event::assertNotDispatched(DayStarted::class);
        $this->assertSame('processing_night', $game->fresh()->status);
        $this->assertDatabaseMissing('game_actions', ['game_id' => $game->id, 'type' => 'hunter_pending']);
        Queue::assertPushed(ProcessHunterTurn::class, fn ($job) => $job->gameId === $game->id
            && $job->round === $game->round
            && $job->hunterId === $hunter->id);
    }
}
