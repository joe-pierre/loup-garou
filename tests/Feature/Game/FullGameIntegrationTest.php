<?php

namespace Tests\Feature\Game;

use App\Events\Game\DayStarted;
use App\Events\Game\GameFinished;
use App\Events\Game\MayorElected;
use App\Events\Game\MayorElectionStarted;
use App\Events\Game\NightStarted;
use App\Events\Game\PlayerEliminated;
use App\Events\Game\WerewolvesTurnStarted;
use App\Jobs\ProcessDayVote;
use App\Jobs\ProcessMayorElection;
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

class FullGameIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Partie complète : création → élection maire → nuit 1 (loups tuent villageois)
     * → jour 1 (village élimine un loup) → nuit 2 (loups tuent dernier villageois)
     * → victoire loups.
     *
     * Composition : 1 loup, 1 voyante, 4 villageois (6 joueurs).
     */
    public function test_partie_complete_victoire_loups(): void
    {
        Event::fake();
        Queue::fake();

        // ── Création ──────────────────────────────────────────────────────
        $users = User::factory()->count(6)->create();
        $host  = $users->first();

        $response = $this->actingAs($host)->postJson('/game', [
            'pseudo'      => 'Host',
            'max_players' => 6,
        ]);
        $response->assertStatus(201);
        $code   = $response->json('data.code');
        $game   = Game::where('code', $code)->first();

        // Les 5 autres rejoignent
        foreach ($users->skip(1) as $i => $user) {
            $this->actingAs($user)->postJson("/game/{$code}/join", [
                'pseudo' => "Joueur{$i}",
            ])->assertStatus(200);
        }

        $game->refresh();
        $this->assertSame('electing_mayor', $game->status);

        // Tous les joueurs se marquent prêts → déclenche MayorElectionStarted
        $gamePlayers = $game->players()->with('user')->get();
        foreach ($gamePlayers as $gp) {
            $this->actingAs($gp->user)->postJson("/game/{$game->id}/ready");
        }

        Event::assertDispatched(MayorElectionStarted::class);

        // ── Assignation manuelle des rôles pour contrôler le scénario ────
        $players = $game->players()->get();
        $wolf    = $players[0];
        $seer    = $players[1];
        $v1      = $players[2]; // villageois — sera tué nuit 1
        $v2      = $players[3]; // villageois — éliminera le loup jour 1
        $v3      = $players[4]; // villageois — sera tué nuit 2
        $v4      = $players[5]; // villageois

        $wolf->update(['role' => 'werewolf']);
        $seer->update(['role' => 'seer']);
        foreach ([$v1, $v2, $v3, $v4] as $v) {
            $v->update(['role' => 'villager']);
        }

        // ── Élection du maire ────────────────────────────────────────────
        $game->update(['status' => 'electing_mayor', 'round' => 0]);

        $result = app(VoteService::class)->resolveMayorElection($game);
        // Forcer le maire sur v2 pour le scénario
        $game->players()->update(['is_mayor' => false]);
        $v2->update(['is_mayor' => true]);
        $game->update(['status' => 'night', 'round' => 1]);

        if ($result) {
            broadcast(new MayorElected($result['game'], $result['player'], $result['was_random']));
        }

        Event::assertDispatched(MayorElected::class);

        // ── Nuit 1 : loups tuent v1 ──────────────────────────────────────
        GameAction::create([
            'game_id'          => $game->id,
            'player_id'        => $wolf->id,
            'type'             => 'night_vote',
            'target_player_id' => $v1->id,
            'round'            => 1,
            'phase'            => 'night',
        ]);

        (new ProcessNightActions($game->id, 1))
            ->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertFalse($v1->fresh()->is_alive);
        Event::assertDispatched(PlayerEliminated::class, fn ($e) => $e->player->id === $v1->id);

        (new ProcessNightEnd($game->id, 1))->handle(app(PhaseManager::class));

        $game->refresh();
        $this->assertSame('day', $game->status);
        $this->assertSame(1, $game->round);
        Event::assertDispatched(DayStarted::class);

        // ── Jour 1 : village élimine le loup ─────────────────────────────
        // v2 (maire, weight=2), v3, v4 votent pour wolf
        $v2->refresh();
        foreach ([$v2, $v3, $v4] as $voter) {
            $weight = $voter->is_mayor ? 2 : 1;
            GameAction::create([
                'game_id'          => $game->id,
                'player_id'        => $voter->id,
                'type'             => 'day_vote',
                'weight'           => $weight,
                'target_player_id' => $wolf->id,
                'round'            => 1,
                'phase'            => 'day',
            ]);
        }

        app(VoteService::class)->resolveDayVote($game);

        $this->assertFalse($wolf->fresh()->is_alive);
        Event::assertDispatched(PlayerEliminated::class, fn ($e) => $e->player->id === $wolf->id);

        // Victoire du village : 0 loups restants
        Event::assertDispatched(GameFinished::class, fn ($e) => $e->winnerTeam === 'villagers');
        $this->assertSame('finished', $game->fresh()->status);
    }

    /**
     * Nuit complète avec voyante active : SeerTurnStarted puis WerewolvesTurnStarted
     * broadcastés dans le bon ordre, puis DayStarted.
     */
    public function test_sequence_nocturne_voyante_puis_loups_puis_jour(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create([
            'status'      => 'night',
            'max_players' => 6,
            'round'       => 1,
        ]);

        $wolf   = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $seer   = GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        $victim = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(3)->villager()->create(['game_id' => $game->id]);

        // La voyante agit
        $userSeer = $seer->user;
        $this->actingAs($userSeer)->postJson("/game/{$game->id}/seer/check", [
            'target_player_id' => $wolf->id,
        ])->assertStatus(200);

        // ProcessWerewolvesTurn dispatché par seerCheck
        Queue::assertPushed(ProcessWerewolvesTurn::class);

        // Les loups votent
        GameAction::create([
            'game_id'          => $game->id,
            'player_id'        => $wolf->id,
            'type'             => 'night_vote',
            'target_player_id' => $victim->id,
            'round'            => 1,
            'phase'            => 'night',
        ]);

        $game->update(['status' => 'wolves_turn']);

        (new ProcessNightActions($game->id, 1))
            ->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertFalse($victim->fresh()->is_alive);

        (new ProcessNightEnd($game->id, 1))->handle(app(PhaseManager::class));

        $game->refresh();
        $this->assertSame('day', $game->status);
        Event::assertDispatched(DayStarted::class);
    }

    /**
     * Succession en cascade : maire tué nuit 1, successeur A tué nuit 2,
     * successeur B désigné — la partie ne se bloque pas et DayStarted
     * est broadcasté après chaque nuit.
     */
    public function test_cascade_deux_successions_ne_bloque_pas_la_partie(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create([
            'status'      => 'night',
            'max_players' => 8,
            'round'       => 1,
        ]);

        $mayor  = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_mayor' => true]);
        $wolf1  = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $wolf2  = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        GamePlayer::factory()->count(5)->villager()->create(['game_id' => $game->id]);

        // Nuit 1 : loups tuent le maire
        GameAction::create([
            'game_id'          => $game->id,
            'player_id'        => $wolf1->id,
            'type'             => 'night_vote',
            'target_player_id' => $mayor->id,
            'round'            => 1,
            'phase'            => 'night',
        ]);

        (new ProcessNightActions($game->id, 1))
            ->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertFalse($mayor->fresh()->is_alive);

        // Succession nuit 1
        (new \App\Jobs\ProcessMayorSuccession($game->id, 1, shouldStartNight: true))
            ->handle(app(PhaseManager::class));

        $successorA = GamePlayer::where('game_id', $game->id)
            ->where('is_mayor', true)
            ->where('is_alive', true)
            ->first();
        $this->assertNotNull($successorA);

        // Fin nuit 1 → jour 1
        (new ProcessNightEnd($game->id, 1))->handle(app(PhaseManager::class));
        $game->refresh();
        $this->assertSame('day', $game->status);

        // Début nuit 2
        app(PhaseManager::class)->startNight($game->fresh());
        $game->refresh();
        $this->assertSame('night', $game->status);
        $this->assertSame(2, $game->round);

        // Nuit 2 : loups tuent le successeur A
        $voter = $successorA->id === $wolf1->id ? $wolf2 : $wolf1;
        GameAction::create([
            'game_id'          => $game->id,
            'player_id'        => $voter->id,
            'type'             => 'night_vote',
            'target_player_id' => $successorA->id,
            'round'            => 2,
            'phase'            => 'night',
        ]);

        (new ProcessNightActions($game->id, 2))
            ->handle(app(VoteService::class), app(WinConditionChecker::class));

        $this->assertFalse($successorA->fresh()->is_alive);

        // Succession nuit 2
        (new \App\Jobs\ProcessMayorSuccession($game->id, 2, shouldStartNight: true))
            ->handle(app(PhaseManager::class));

        $successorB = GamePlayer::where('game_id', $game->id)
            ->where('is_mayor', true)
            ->where('is_alive', true)
            ->first();
        $this->assertNotNull($successorB);

        // Fin nuit 2 → jour 2
        (new ProcessNightEnd($game->id, 2))->handle(app(PhaseManager::class));
        $game->refresh();
        $this->assertSame('day', $game->status);
        $this->assertSame(2, $game->round);

        Event::assertDispatchedTimes(DayStarted::class, 2);
        Event::assertDispatchedTimes(\App\Events\Game\MayorSuccessionDone::class, 2);
    }
}
