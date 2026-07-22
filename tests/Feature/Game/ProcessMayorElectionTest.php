<?php

namespace Tests\Feature\Game;

use App\Events\Game\MayorElected;
use App\Jobs\ProcessMayorElection;
use App\Jobs\ProcessSeerTurn;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessMayorElectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_processseerturn_dispatche_meme_si_broadcast_mayorelected_echoue(): void
    {
        Queue::fake();

        // Simule un incident réseau/Reverb : le broadcast MayorElected lève une
        // exception, les autres broadcasts (NightStarted, etc.) restent no-op.
        $factory = \Mockery::mock(BroadcastFactory::class);
        $factory->shouldReceive('queue')->andReturnUsing(function ($event) {
            if ($event instanceof MayorElected) {
                throw new \RuntimeException('Reverb indisponible (simulation test)');
            }

            return null;
        });
        $factory->shouldReceive('event')->andReturnUsing(
            fn ($event) => new \Illuminate\Broadcasting\PendingBroadcast(app('events'), $event)
        );
        $this->app->instance(BroadcastFactory::class, $factory);

        $game = Game::factory()->create(['status' => 'electing_mayor']);
        GamePlayer::factory()->count(4)->create(['game_id' => $game->id]);

        (new ProcessMayorElection($game->id))->handle(app(\App\Services\VoteService::class));

        // Malgré l'échec du broadcast MayorElected, le flux doit se poursuivre :
        // la partie transite vers 'night' et ProcessSeerTurn est bien dispatché.
        $this->assertDatabaseHas('games', [
            'id'     => $game->id,
            'status' => 'night',
        ]);

        Queue::assertPushed(ProcessSeerTurn::class, function ($job) use ($game) {
            return $job->gameId === $game->id;
        });
    }
}
