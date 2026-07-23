<?php

namespace Tests\Feature\Game;

use App\Events\Game\CupidonTurnStarted;
use App\Events\Game\MayorElected;
use App\Events\Game\SeerTurnStarted;
use App\Jobs\ProcessCupidonTurn;
use App\Jobs\ProcessMayorElection;
use App\Jobs\ProcessSeerTurn;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\PhaseManager;
use App\Services\VoteService;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

        (new ProcessMayorElection($game->id))->handle(app(VoteService::class), app(PhaseManager::class));

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

    /**
     * Round 1 (electing_mayor → night) est le seul round où Cupidon agit
     * (SPEC_CUPIDON.md §3). Ce chemin passe par ProcessMayorElement plutôt que
     * PhaseManager::startNight() (rounds 2+) — la même décision Cupidon vs Voyante
     * doit s'appliquer ici aussi, via PhaseManager::dispatchNightOpeningTurn().
     */
    public function test_cupidon_turn_started_des_election_du_maire_si_cupidon_distribue(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create(['status' => 'electing_mayor']);
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        (new ProcessMayorElection($game->id))->handle(app(VoteService::class), app(PhaseManager::class));

        $this->assertDatabaseHas('games', ['id' => $game->id, 'status' => 'night', 'round' => 1]);

        Queue::assertPushed(ProcessCupidonTurn::class, fn ($job) => $job->gameId === $game->id && $job->round === 1);
        Queue::assertNotPushed(ProcessSeerTurn::class);

        (new ProcessCupidonTurn($game->id, 1))->handle();

        Event::assertDispatched(CupidonTurnStarted::class, fn ($e) => $e->cupidon->id === $cupidon->id);
        Event::assertNotDispatched(SeerTurnStarted::class);
    }

    /**
     * Zéro régression : sans Cupidon distribué, l'élection du maire déclenche
     * directement ProcessSeerTurn, exactement comme avant l'ajout de Cupidon.
     */
    public function test_seer_turn_started_des_election_du_maire_si_pas_de_cupidon(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create(['status' => 'electing_mayor']);
        GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        (new ProcessMayorElection($game->id))->handle(app(VoteService::class), app(PhaseManager::class));

        $this->assertDatabaseHas('games', ['id' => $game->id, 'status' => 'night', 'round' => 1]);

        Queue::assertNotPushed(ProcessCupidonTurn::class);
        Queue::assertPushed(ProcessSeerTurn::class, fn ($job) => $job->gameId === $game->id && $job->round === 1);

        (new ProcessSeerTurn($game->id, 1))->handle();

        Event::assertDispatched(SeerTurnStarted::class);
        Event::assertNotDispatched(CupidonTurnStarted::class);
    }

    /**
     * Round 2+ : même avec Cupidon distribué, son tour ne doit jamais être
     * redéclenché (déjà couvert côté PhaseManager::startNight(), voir
     * CupidonNightIntegrationTest) — vérification croisée de la méthode factorisée
     * dispatchNightOpeningTurn() elle-même, partagée par les deux points d'entrée.
     */
    public function test_pas_de_cupidon_turn_started_si_round_deja_superieur_a_1(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create(['status' => 'night', 'round' => 2]);
        GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(4)->villager()->create(['game_id' => $game->id]);

        app(PhaseManager::class)->dispatchNightOpeningTurn($game->fresh(), 'mayor_reveal');

        Queue::assertNotPushed(ProcessCupidonTurn::class);
        Queue::assertPushed(ProcessSeerTurn::class, fn ($job) => $job->gameId === $game->id && $job->round === 2);
    }
}
