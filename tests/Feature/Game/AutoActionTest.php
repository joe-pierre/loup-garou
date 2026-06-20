<?php

namespace Tests\Feature\Game;

use App\Events\Game\SeerResult;
use App\Jobs\ProcessSeerAutoAction;
use App\Jobs\ProcessWerewolvesTurn;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutoActionTest extends TestCase
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

    public function test_seer_auto_action_skipped_if_already_acted(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();
        $seer = GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        $other = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $seer->id, 'type' => 'seer_check',
            'target_player_id' => $other->id, 'round' => $game->round, 'phase' => 'night',
        ]);

        (new ProcessSeerAutoAction($game->id, $seer->id, $game->round))->handle();

        $this->assertSame(1, GameAction::where('game_id', $game->id)->where('type', 'seer_check')->count());
        Event::assertNotDispatched(SeerResult::class);
        Queue::assertNotPushed(ProcessWerewolvesTurn::class);
    }

    /**
     * Divergence avec SPEC_TIMERS.md §3.2 documentée dans DECISIONS.md :
     * en prod, ProcessSeerAutoAction effectue une inspection de "consolation"
     * (création seer_check + broadcast SeerResult) plutôt que de dispatcher
     * ProcessWerewolvesTurn — ce dernier est dispatché indépendamment et
     * systématiquement par ProcessSeerTurn (seerTimer + 2).
     */
    public function test_seer_auto_action_passes_to_wolves_if_inactive(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();
        $seer = GamePlayer::factory()->seer()->create(['game_id' => $game->id, 'is_inactive' => true]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessSeerAutoAction($game->id, $seer->id, $game->round))->handle();

        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $seer->id, 'type' => 'seer_check', 'round' => $game->round,
        ]);
        Event::assertDispatched(SeerResult::class, fn ($e) => $e->seer->id === $seer->id);
        // ProcessWerewolvesTurn n'est pas dispatché par ce job — voir DECISIONS.md
        Queue::assertNotPushed(ProcessWerewolvesTurn::class);
    }

    public function test_seer_auto_action_skipped_if_wrong_round(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame(round: 2);
        $seer = GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessSeerAutoAction($game->id, $seer->id, 1))->handle();

        $this->assertDatabaseMissing('game_actions', [
            'game_id' => $game->id, 'type' => 'seer_check',
        ]);
        Event::assertNotDispatched(SeerResult::class);
    }

    public function test_seer_auto_action_skipped_if_wrong_status(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create([
            'status'      => 'day',
            'max_players' => 6,
            'round'       => 1,
        ]);
        $seer = GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessSeerAutoAction($game->id, $seer->id, $game->round))->handle();

        $this->assertDatabaseMissing('game_actions', [
            'game_id' => $game->id, 'type' => 'seer_check',
        ]);
        Event::assertNotDispatched(SeerResult::class);
    }

    public function test_seer_check_endpoint_dispatches_wolves_immediately(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();
        $user = User::factory()->create();
        $seer = GamePlayer::factory()->seer()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $target->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $seer->id, 'type' => 'seer_check',
            'target_player_id' => $target->id, 'round' => $game->round,
        ]);

        Event::assertDispatched(SeerResult::class, fn ($e) => $e->target->id === $target->id);
        Queue::assertPushed(ProcessWerewolvesTurn::class, fn ($job) => $job->gameId === $game->id);
    }

    public function test_seer_check_rejected_if_already_acted(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();
        $user = User::factory()->create();
        $seer = GamePlayer::factory()->seer()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $target1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        $first = $this->actingAs($user)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $target1->id,
        ]);
        $first->assertStatus(200);

        $second = $this->actingAs($user)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $target2->id,
        ]);

        $second->assertStatus(409);
    }

    public function test_seer_check_rejected_if_self_target(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();
        $user = User::factory()->create();
        $seer = GamePlayer::factory()->seer()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $seer->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_seer_check_sur_loup_retourne_role_werewolf(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();
        $user = User::factory()->create();
        $seer = GamePlayer::factory()->seer()->create([
            'game_id' => $game->id, 'user_id' => $user->id,
        ]);
        $wolf = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $wolf->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data'    => [
                'target_player_id' => $wolf->id,
                'role'             => 'werewolf',
            ],
        ]);

        Event::assertDispatched(SeerResult::class, fn ($e) => $e->target->role === 'werewolf');
    }
}
