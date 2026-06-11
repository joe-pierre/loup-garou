<?php

namespace Tests\Feature\Game;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RaceConditionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Simule 7 tentatives séquentielles de join sur une partie max_players=6.
     * La 6e déclenche startGame() (status → electing_mayor).
     * Les suivantes sont rejetées → exactement 6 joueurs, jamais 7.
     */
    public function test_join_race_max_6_joueurs_jamais_dépassé(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create(['max_players' => 6]);

        $responses = [];
        for ($i = 1; $i <= 7; $i++) {
            $user      = User::factory()->create();
            $responses[] = $this->actingAs($user)->postJson("/game/{$game->code}/join", [
                'pseudo' => "Joueur{$i}",
            ])->status();
        }

        $successCount = count(array_filter($responses, fn ($s) => $s === 200));
        $this->assertSame(6, $successCount);
        $this->assertSame(6, GamePlayer::where('game_id', $game->id)->count());
    }

    /**
     * Appeler startGame() deux fois ne doit pas changer la partie deux fois :
     * le double-fire guard (lockForUpdate sur status='waiting') absorbe le second appel.
     */
    public function test_start_game_race_double_appel_idempotent(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create(['max_players' => 6]);
        GamePlayer::factory()->count(6)->create(['game_id' => $game->id]);

        $gameService = app(\App\Services\GameService::class);

        // Premier appel : démarre la partie
        $gameService->startGame($game);

        $statusAfterFirst = $game->fresh()->status;
        $this->assertNotSame('waiting', $statusAfterFirst);

        // Second appel : doit être silencieusement ignoré
        $gameService->startGame($game->fresh());

        // Le statut ne doit pas régresser
        $this->assertSame($statusAfterFirst, $game->fresh()->status);
    }

    /**
     * Un même joueur qui vote deux fois pour l'élection du maire doit obtenir 409
     * sur la seconde tentative — un seul vote inséré.
     */
    public function test_mayor_vote_race_double_vote_même_joueur_retourne_409(): void
    {
        Event::fake();

        $game   = Game::factory()->create(['status' => 'electing_mayor', 'max_players' => 6, 'round' => 0]);
        $userA  = User::factory()->create();
        $voter  = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $userA->id]);
        $target = GamePlayer::factory()->create(['game_id' => $game->id]);

        // Premier vote : succès
        $this->actingAs($userA)->postJson("/game/{$game->id}/vote/mayor", [
            'target_player_id' => $target->id,
        ])->assertStatus(200);

        // Deuxième vote identique : 409
        $this->actingAs($userA)->postJson("/game/{$game->id}/vote/mayor", [
            'target_player_id' => $target->id,
        ])->assertStatus(409);

        // Un seul vote enregistré
        $this->assertSame(1, GameAction::where('game_id', $game->id)
            ->where('player_id', $voter->id)
            ->where('type', 'mayor_vote')
            ->count());
    }

    /**
     * Quand le maire vote en phase jour, weight=2 doit être lu au moment de l'insertion,
     * même si is_mayor a été positionné juste avant (pas de lecture stale).
     */
    public function test_day_vote_mayor_weight_2_même_si_maire_assigné_en_cours(): void
    {
        Event::fake();

        $game  = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $user  = User::factory()->create();
        $mayor = GamePlayer::factory()->villager()->create([
            'game_id'  => $game->id,
            'user_id'  => $user->id,
            'is_mayor' => true,
        ]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $this->actingAs($user)->postJson("/game/{$game->id}/vote/day", [
            'target_player_id' => $target->id,
        ])->assertStatus(200);

        $action = GameAction::where('game_id', $game->id)
            ->where('player_id', $mayor->id)
            ->where('type', 'day_vote')
            ->first();

        $this->assertNotNull($action);
        $this->assertSame(2, (int) $action->weight);
    }

    /**
     * Deux déclenchements simultanés de ProcessDayVote (job en double + retry) ne doivent
     * résoudre le vote jour qu'une seule fois : le second appel à resolveDayVote() trouve
     * status='processing_day' (posé par le premier) et est ignoré silencieusement.
     */
    public function test_resolve_day_vote_double_fire_processing_day_guard_ignore_second_appel(): void
    {
        Event::fake();
        Queue::fake();

        $game  = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $voter1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $voter2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $voter1->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $target->id, 'round' => 1, 'phase' => 'day',
        ]);
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $voter2->id, 'type' => 'day_vote',
            'weight' => 1, 'target_player_id' => $target->id, 'round' => 1, 'phase' => 'day',
        ]);

        // startNight() ne doit être appelé qu'une seule fois, même si resolveDayVote()
        // est invoquée deux fois (simule deux exécutions concurrentes de ProcessDayVote).
        $this->mock(\App\Services\PhaseManager::class, function ($mock) {
            $mock->shouldReceive('startNight')->once();
        });

        $voteService = app(\App\Services\VoteService::class);

        // Premier déclenchement : élimine $target, statut → processing_day
        $voteService->resolveDayVote($game->fresh());

        $this->assertFalse($target->fresh()->is_alive);
        $this->assertSame('processing_day', $game->fresh()->status);

        // Second déclenchement concurrent : status n'est plus 'day' → ignoré silencieusement
        $voteService->resolveDayVote($game->fresh());

        $this->assertSame('processing_day', $game->fresh()->status);
        $this->assertSame(1, $game->fresh()->round);
    }
}
