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
}
