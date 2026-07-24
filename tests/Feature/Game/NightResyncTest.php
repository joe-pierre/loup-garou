<?php

namespace Tests\Feature\Game;

use App\Jobs\ProcessCupidonTurn;
use App\Jobs\ProcessHunterTurn;
use App\Jobs\ProcessSeerTurn;
use App\Jobs\ProcessWerewolvesTurn;
use App\Jobs\ProcessWitchTurn;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\VoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Couvre la resynchronisation de sous-phase de nuit (GET /game/{code}/state,
 * champ `night_action`) après refresh ou reconnexion Echo — voir DECISIONS.md
 * "Resynchronisation des sous-phases de nuit".
 *
 * Pour chacun des 5 rôles actifs de nuit : refresh sans action soumise, refresh
 * avec action déjà soumise, et reconnexion (POST /reconnect puis GET /state,
 * même endpoit que le refresh côté client — voir game-state.js `_reconnect()`).
 */
class NightResyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeGame(?string $subPhase, int $round = 1): Game
    {
        return Game::factory()->create([
            'status'          => 'night',
            'max_players'     => 8,
            'round'           => $round,
            'phase_deadline'  => now()->addSeconds(20),
            'night_sub_phase' => $subPhase,
        ]);
    }

    private function assertReconnectionRestoresSamePayload(Game $game, User $user, string $expectedPhase): void
    {
        Event::fake();

        $reconnect = $this->actingAs($user)->postJson("/game/{$game->code}/reconnect");
        $reconnect->assertStatus(200);

        $state = $this->actingAs($user)->getJson("/game/{$game->code}/state");
        $state->assertStatus(200);
        $state->assertJsonPath('data.night_action.phase', $expectedPhase);
    }

    // ── CUPIDON ─────────────────────────────────────────────────────────────

    public function test_cupidon_turn_refresh_sans_action_soumise(): void
    {
        $game    = $this->makeGame('cupidon_turn');
        $user    = User::factory()->create();
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertStatus(200);
        $response->assertJsonPath('data.night_action.phase', 'cupidon_turn');
        $response->assertJsonPath('data.night_action.already_acted', false);
        $this->assertGreaterThan(0, $response->json('data.night_action.remaining_seconds'));
    }

    public function test_cupidon_turn_refresh_avec_action_deja_soumise(): void
    {
        $game    = $this->makeGame('cupidon_turn');
        $user    = User::factory()->create();
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $other   = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $cupidon->id, 'type' => 'cupidon_link',
            'target_player_id' => $cupidon->id, 'round' => 1, 'phase' => 'night',
        ]);
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $cupidon->id, 'type' => 'cupidon_link',
            'target_player_id' => $other->id, 'round' => 1, 'phase' => 'night',
        ]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertJsonPath('data.night_action.phase', 'cupidon_turn');
        $response->assertJsonPath('data.night_action.already_acted', true);
    }

    public function test_cupidon_turn_reconnexion_echo(): void
    {
        $game = $this->makeGame('cupidon_turn');
        $user = User::factory()->create();
        GamePlayer::factory()->cupidon()->create(['game_id' => $game->id, 'user_id' => $user->id, 'is_inactive' => true]);

        $this->assertReconnectionRestoresSamePayload($game, $user, 'cupidon_turn');
    }

    // ── VOYANTE ──────────────────────────────────────────────────────────────

    public function test_seer_turn_refresh_sans_action_soumise(): void
    {
        $game = $this->makeGame('seer_turn');
        $user = User::factory()->create();
        GamePlayer::factory()->seer()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertJsonPath('data.night_action.phase', 'seer_turn');
        $response->assertJsonPath('data.night_action.already_acted', false);
        $response->assertJsonPath('data.night_action.payload.result', null);
    }

    public function test_seer_turn_refresh_avec_action_deja_soumise(): void
    {
        $game   = $this->makeGame('seer_turn');
        $user   = User::factory()->create();
        $seer   = GamePlayer::factory()->seer()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $target = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $seer->id, 'type' => 'seer_check',
            'target_player_id' => $target->id, 'round' => 1, 'phase' => 'night',
        ]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertJsonPath('data.night_action.already_acted', true);
        $response->assertJsonPath('data.night_action.payload.result.pseudo', $target->pseudo);
        $response->assertJsonPath('data.night_action.payload.result.role', 'werewolf');
        $response->assertJsonPath('data.night_action.payload.result.is_werewolf', true);
    }

    public function test_seer_turn_reconnexion_echo(): void
    {
        $game = $this->makeGame('seer_turn');
        $user = User::factory()->create();
        GamePlayer::factory()->seer()->create(['game_id' => $game->id, 'user_id' => $user->id, 'is_inactive' => true]);

        $this->assertReconnectionRestoresSamePayload($game, $user, 'seer_turn');
    }

    // ── LOUPS ────────────────────────────────────────────────────────────────

    public function test_werewolves_turn_refresh_sans_action_soumise(): void
    {
        $game = $this->makeGame('werewolves_turn');
        $user = User::factory()->create();
        $wolf = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertJsonPath('data.night_action.phase', 'werewolves_turn');
        $response->assertJsonPath('data.night_action.already_acted', false);
        $this->assertCount(3, $response->json('data.night_action.payload.eligible_targets'));
        $this->assertCount(2, $response->json('data.night_action.payload.vote_state'));
    }

    public function test_werewolves_turn_refresh_avec_vote_deja_soumis(): void
    {
        $game   = $this->makeGame('werewolves_turn');
        $user   = User::factory()->create();
        $wolf   = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf->id, 'type' => 'night_vote',
            'target_player_id' => $target->id, 'round' => 1, 'phase' => 'night',
        ]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertJsonPath('data.night_action.already_acted', true);
        $response->assertJsonPath('data.night_action.payload.my_target_id', $target->id);
    }

    public function test_werewolves_turn_reconnexion_echo(): void
    {
        $game = $this->makeGame('werewolves_turn');
        $user = User::factory()->create();
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $user->id, 'is_inactive' => true]);

        $this->assertReconnectionRestoresSamePayload($game, $user, 'werewolves_turn');
    }

    // ── SORCIÈRE ─────────────────────────────────────────────────────────────

    public function test_witch_turn_refresh_sans_action_soumise(): void
    {
        $game   = $this->makeGame('witch_turn');
        $user   = User::factory()->create();
        GamePlayer::factory()->witch()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $victim = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $victim->id, 'type' => 'night_resolve',
            'target_player_id' => $victim->id, 'round' => 1, 'phase' => 'night',
        ]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertJsonPath('data.night_action.phase', 'witch_turn');
        $response->assertJsonPath('data.night_action.already_acted', false);
        $response->assertJsonPath('data.night_action.payload.victim.id', $victim->id);
        $response->assertJsonPath('data.night_action.payload.heal_available', true);
        $response->assertJsonPath('data.night_action.payload.kill_available', true);
    }

    public function test_witch_turn_refresh_avec_action_deja_soumise(): void
    {
        $game  = $this->makeGame('witch_turn');
        $user  = User::factory()->create();
        $witch = GamePlayer::factory()->witch()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $witch->id, 'type' => 'witch_pass',
            'target_player_id' => null, 'round' => 1, 'phase' => 'night',
        ]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertJsonPath('data.night_action.already_acted', true);
    }

    public function test_witch_turn_reconnexion_echo(): void
    {
        $game = $this->makeGame('witch_turn');
        $user = User::factory()->create();
        GamePlayer::factory()->witch()->create(['game_id' => $game->id, 'user_id' => $user->id, 'is_inactive' => true]);

        $this->assertReconnectionRestoresSamePayload($game, $user, 'witch_turn');
    }

    // ── CHASSEUR ─────────────────────────────────────────────────────────────

    public function test_hunter_turn_refresh_sans_action_soumise(): void
    {
        $game = $this->makeGame('hunter_turn');
        $user = User::factory()->create();
        GamePlayer::factory()->hunter()->dead()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertJsonPath('data.night_action.phase', 'hunter_turn');
        $response->assertJsonPath('data.night_action.already_acted', false);
    }

    public function test_hunter_turn_refresh_avec_tir_deja_effectue(): void
    {
        $game   = $this->makeGame('hunter_turn');
        $user   = User::factory()->create();
        $hunter = GamePlayer::factory()->hunter()->dead()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $hunter->id, 'type' => 'hunter_shot',
            'target_player_id' => $target->id, 'round' => 1, 'phase' => 'night',
        ]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertJsonPath('data.night_action.already_acted', true);
    }

    public function test_hunter_turn_reconnexion_echo(): void
    {
        $game = $this->makeGame('hunter_turn');
        $user = User::factory()->create();
        GamePlayer::factory()->hunter()->dead()->create(['game_id' => $game->id, 'user_id' => $user->id, 'is_inactive' => true]);

        $this->assertReconnectionRestoresSamePayload($game, $user, 'hunter_turn');
    }

    // ── CAS NÉGATIFS ─────────────────────────────────────────────────────────

    public function test_night_action_null_si_role_ne_correspond_pas_a_la_sous_phase_active(): void
    {
        $game = $this->makeGame('werewolves_turn');
        $user = User::factory()->create();
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertJsonPath('data.night_action', null);
    }

    public function test_night_action_null_hors_phase_nuit_meme_si_night_sub_phase_stale(): void
    {
        $game = Game::factory()->create([
            'status'          => 'day',
            'max_players'     => 6,
            'round'           => 1,
            'phase_deadline'  => now()->addSeconds(30),
            'night_sub_phase' => 'werewolves_turn', // valeur non purgée d'un round précédent
        ]);
        $user = User::factory()->create();
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson("/game/{$game->code}/state");

        $response->assertJsonPath('data.night_action', null);
    }

    // ── JOBS : persistance de night_sub_phase + phase_deadline ────────────────

    public function test_process_witch_turn_persiste_night_sub_phase_et_deadline(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeGame(null);
        GamePlayer::factory()->witch()->create(['game_id' => $game->id]);
        $wolf   = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $victim = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf->id, 'type' => 'night_vote',
            'target_player_id' => $victim->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessWitchTurn($game->id, $game->round))->handle(app(VoteService::class));

        $game->refresh();
        $this->assertEquals('witch_turn', $game->night_sub_phase);
        $this->assertNotNull($game->phase_deadline);
        $this->assertTrue($game->phase_deadline->isFuture());
    }

    public function test_process_hunter_turn_persiste_night_sub_phase_et_deadline(): void
    {
        Event::fake();
        Queue::fake();

        $game   = $this->makeGame(null);
        $hunter = GamePlayer::factory()->hunter()->dead()->create(['game_id' => $game->id]);
        // Effectif vivant équilibré (1 loup, 2 villageois) : sans lui, WinConditionChecker::check()
        // (désormais appelé en tout premier par ProcessHunterTurn, voir DECISIONS.md) lirait un
        // effectif vivant dégénéré (0 loup, ou loups >= autres) et déclarerait une victoire à
        // tort — un état de partie irréaliste (une partie réelle a toujours plus de villageois
        // que de loups vivants pendant un tour de Chasseur), pas un cas que ce test cible.
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        (new ProcessHunterTurn($game->id, $game->round, $hunter->id))
            ->handle(app(\App\Services\PhaseManager::class), app(\App\Services\WinConditionChecker::class));

        $game->refresh();
        $this->assertEquals('hunter_turn', $game->night_sub_phase);
        $this->assertNotNull($game->phase_deadline);
    }

    public function test_process_cupidon_turn_persiste_night_sub_phase_et_deadline(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeGame(null);
        GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);

        (new ProcessCupidonTurn($game->id, $game->round))->handle();

        $game->refresh();
        $this->assertEquals('cupidon_turn', $game->night_sub_phase);
        $this->assertNotNull($game->phase_deadline);
    }

    public function test_process_seer_turn_persiste_night_sub_phase(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeGame(null);
        GamePlayer::factory()->seer()->create(['game_id' => $game->id]);

        (new ProcessSeerTurn($game->id, $game->round))->handle();

        $game->refresh();
        $this->assertEquals('seer_turn', $game->night_sub_phase);
    }

    public function test_process_werewolves_turn_persiste_night_sub_phase(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeGame(null);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        (new ProcessWerewolvesTurn($game->id, $game->round))->handle();

        $game->refresh();
        $this->assertEquals('werewolves_turn', $game->night_sub_phase);
    }

    public function test_start_night_purge_night_sub_phase_du_round_precedent(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create([
            'status'          => 'day',
            'max_players'     => 6,
            'round'           => 1,
            'night_sub_phase' => 'hunter_turn',
        ]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        app(\App\Services\PhaseManager::class)->startNight($game);

        $this->assertNull($game->fresh()->night_sub_phase);
    }
}
