<?php

namespace Tests\Feature\Game;

use App\Events\Game\GameFinished;
use App\Events\Game\MayorSuccessionDone;
use App\Events\Game\MayorSuccessionStarted;
use App\Events\Game\SeerTurnStarted;
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
use App\Services\VoteService;
use App\Services\WinConditionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NightPhaseTest extends TestCase
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

    public function test_voyante_ne_peut_pas_sinspecter_elle_meme_retourne_422(): void
    {
        Event::fake();

        $game = $this->makeNightGame();
        $user = User::factory()->create();
        $seer = GamePlayer::factory()->seer()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $seer->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_loup_ne_peut_pas_voter_pour_un_autre_loup_retourne_422(): void
    {
        Event::fake();

        $game  = $this->makeNightGame();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $wolf1 = GamePlayer::factory()->werewolf()->create([
            'game_id' => $game->id,
            'user_id' => $user1->id,
        ]);

        $wolf2 = GamePlayer::factory()->werewolf()->create([
            'game_id' => $game->id,
            'user_id' => $user2->id,
        ]);

        $response = $this->actingAs($user1)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $wolf2->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_action_voyante_hors_phase_night_retourne_409(): void
    {
        Event::fake();

        $game = Game::factory()->create([
            'status'      => 'day',
            'max_players' => 6,
            'round'       => 1,
        ]);
        $user = User::factory()->create();
        $seer = GamePlayer::factory()->seer()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $target->id,
        ]);

        $response->assertStatus(409);
    }

    public function test_vote_nuit_hors_phase_night_retourne_409(): void
    {
        Event::fake();

        $game = Game::factory()->create([
            'status'      => 'day',
            'max_players' => 6,
            'round'       => 1,
        ]);
        $user = User::factory()->create();
        $wolf = GamePlayer::factory()->werewolf()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/vote/night", [
            'target_player_id' => $target->id,
        ]);

        $response->assertStatus(409);
    }

    /**
     * Le maire meurt la nuit (sans condition de victoire) : ProcessNightActions doit
     * broadcaster MayorSuccessionStarted et dispatcher ProcessMayorSuccession. Le job
     * de succession doit ensuite désigner un successeur sans démarrer de nouvelle phase
     * (status reste 'processing_night').
     */
    public function test_mayor_succession_triggered_at_night(): void
    {
        Event::fake();
        Queue::fake();

        $game  = $this->makeNightGame();
        $mayor = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id, 'is_mayor' => true, 'is_alive' => true,
        ]);
        $wolf = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf->id, 'type' => 'night_vote',
            'target_player_id' => $mayor->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessNightActions($game->id, 1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertFalse($mayor->fresh()->is_alive);
        $this->assertSame('processing_night', $game->fresh()->status);

        Event::assertDispatched(MayorSuccessionStarted::class);
        Queue::assertPushed(ProcessMayorSuccession::class, fn ($job) => $job->gameId === $game->id && $job->round === $game->round);
        Queue::assertPushed(ProcessNightEnd::class, fn ($job) => $job->gameId === $game->id && $job->round === $game->round);

        // Traitement de la succession : un successeur est désigné, aucune nouvelle phase démarrée
        (new ProcessMayorSuccession($game->id, $game->round))->handle(app(PhaseManager::class));

        Event::assertDispatched(MayorSuccessionDone::class);
        $this->assertFalse($mayor->fresh()->is_mayor);
        $this->assertTrue(GamePlayer::where('game_id', $game->id)->where('is_mayor', true)->where('is_alive', true)->exists());
        $this->assertSame('processing_night', $game->fresh()->status);
    }

    /**
     * Si la mort du maire la nuit déclenche immédiatement une condition de victoire,
     * la succession ne doit pas être déclenchée : ProcessNightActions retourne
     * avant le bloc succession ET avant le dispatch de ProcessNightEnd.
     */
    public function test_mayor_succession_not_triggered_if_victory_occurs_simultaneously(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create([
            'status'      => 'night',
            'max_players' => 4,
            'round'       => 1,
        ]);

        $mayor = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id, 'is_mayor' => true, 'is_alive' => true,
        ]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $wolf1 = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $wolf2 = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);

        // Les deux loups votent contre le maire : 2 loups vivants vs 1 villageois vivant après
        // élimination du maire → victoire des loups immédiate.
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf1->id, 'type' => 'night_vote',
            'target_player_id' => $mayor->id, 'round' => 1, 'phase' => 'night',
        ]);
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf2->id, 'type' => 'night_vote',
            'target_player_id' => $mayor->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessNightActions($game->id, 1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertFalse($mayor->fresh()->is_alive);
        $this->assertSame('finished', $game->fresh()->status);
        $this->assertSame('werewolves', $game->fresh()->winner_team);

        Event::assertDispatched(GameFinished::class);
        Event::assertNotDispatched(MayorSuccessionStarted::class);
        Queue::assertNotPushed(ProcessMayorSuccession::class);
        Queue::assertNotPushed(ProcessNightEnd::class);
    }

    /**
     * Si la voyante est morte, ProcessSeerTurn ne doit pas broadcaster SeerTurnStarted
     * et doit dispatcher ProcessWerewolvesTurn immédiatement pour que la nuit se poursuive.
     */
    public function test_night_ends_with_werewolves_turn_even_when_seer_dead(): void
    {
        Event::fake();
        Queue::fake();

        $game = $this->makeNightGame();
        GamePlayer::factory()->seer()->dead()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(5)->villager()->create(['game_id' => $game->id]);

        (new ProcessSeerTurn($game->id))->handle();

        Event::assertNotDispatched(SeerTurnStarted::class);
        Queue::assertPushed(ProcessWerewolvesTurn::class, fn ($job) => $job->gameId === $game->id);
    }

    /**
     * Le délai de ProcessNightEnd doit couvrir celui de ProcessMayorSuccession
     * (buffer) lorsque le maire meurt la nuit, pour éviter que la nuit se termine
     * avant que la succession ait pu se résoudre.
     */
    public function test_night_end_dispatched_after_mayor_succession_with_buffer(): void
    {
        Event::fake();
        Queue::fake();

        $game  = $this->makeNightGame();
        $mayor = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id, 'is_mayor' => true, 'is_alive' => true,
        ]);
        $wolf = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $wolf->id, 'type' => 'night_vote',
            'target_player_id' => $mayor->id, 'round' => 1, 'phase' => 'night',
        ]);

        (new ProcessNightActions($game->id, 1))->handle(app(VoteService::class), app(WinConditionChecker::class));

        $successionJob = collect(Queue::pushed(ProcessMayorSuccession::class))->first();
        $nightEndJob   = collect(Queue::pushed(ProcessNightEnd::class))->first();

        $this->assertNotNull($successionJob);
        $this->assertNotNull($nightEndJob);
        $this->assertNotNull($successionJob->delay);
        $this->assertNotNull($nightEndJob->delay);
        $this->assertTrue($nightEndJob->delay->greaterThan($successionJob->delay));
    }
}
