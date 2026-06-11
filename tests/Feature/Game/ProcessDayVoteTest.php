<?php

namespace Tests\Feature\Game;

use App\Events\Game\NightStarted;
use App\Jobs\ProcessDayVote;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\VoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessDayVoteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Deux exécutions du job pour le même round : la première résout le vote
     * (élimination + transition vers la nuit, round incrémenté), la seconde
     * doit être ignorée silencieusement (status n'est plus 'day').
     */
    public function test_double_fire_does_not_execute_twice(): void
    {
        Event::fake();
        Queue::fake();

        $game   = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $voter1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $voter2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $voter1->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $target->id, 'round' => 1, 'phase' => 'day',
        ]);
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $voter2->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $target->id, 'round' => 1, 'phase' => 'day',
        ]);

        $voteService = app(VoteService::class);

        // Premier déclenchement : élimine $target, transition vers la nuit (round 2)
        (new ProcessDayVote($game->id, 1))->handle($voteService);

        $this->assertFalse($target->fresh()->is_alive);
        $this->assertSame('night', $game->fresh()->status);
        $this->assertSame(2, $game->fresh()->round);

        // Second déclenchement avec le même round (stale) : guard rejette, rien ne change
        (new ProcessDayVote($game->id, 1))->handle($voteService);

        $this->assertSame('night', $game->fresh()->status);
        $this->assertSame(2, $game->fresh()->round);
        Event::assertDispatchedTimes(NightStarted::class, 1);
    }

    /**
     * Le guard d'entrée du job ('status' !== 'day') doit rejeter 'processing_day'
     * comme état initial — ce statut est un état intermédiaire, jamais un point de départ.
     */
    public function test_day_vote_does_not_accept_processing_day_as_initial_state(): void
    {
        Event::fake();
        Queue::fake();

        $game   = Game::factory()->create(['status' => 'processing_day', 'max_players' => 6, 'round' => 1]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $voteService = app(VoteService::class);

        (new ProcessDayVote($game->id, 1))->handle($voteService);

        $this->assertSame('processing_day', $game->fresh()->status);
        $this->assertTrue($target->fresh()->is_alive);
        Event::assertNotDispatched(NightStarted::class);
    }
}
