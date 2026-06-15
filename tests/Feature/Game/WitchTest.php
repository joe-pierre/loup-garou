<?php

namespace Tests\Feature\Game;

use App\Events\Game\PlayerEliminated;
use App\Events\Game\WitchActed;
use App\Events\Game\WitchTurnStarted;
use App\Jobs\ProcessNightActions;
use App\Jobs\ProcessNightEnd;
use App\Jobs\ProcessWitchAutoAction;
use App\Jobs\ProcessWitchTurn;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
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

    public function test_sorciere_ne_peut_pas_sauver_si_elle_est_la_victime(): void
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

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf->id, 'type' => 'night_vote',
            'target_player_id' => $witch->id, 'round' => 1, 'phase' => 'night',
        ]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/witch/act", [
            'action'           => 'heal',
            'target_player_id' => $witch->id,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('game_actions', [
            'game_id' => $game->id, 'type' => 'witch_heal',
        ]);
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

        (new ProcessWitchAutoAction($game->id, $game->round))->handle();

        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $witch->id, 'type' => 'witch_pass',
            'round' => $game->round,
        ]);
        Queue::assertPushed(ProcessNightEnd::class, fn ($job) => $job->gameId === $game->id && $job->round === $game->round);
    }
}
