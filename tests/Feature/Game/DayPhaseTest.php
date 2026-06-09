<?php

namespace Tests\Feature\Game;

use App\Events\Game\NoElimination;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DayPhaseTest extends TestCase
{
    use RefreshDatabase;

    private function makeDayGame(): Game
    {
        return Game::factory()->create([
            'status'      => 'day',
            'max_players' => 6,
            'round'       => 1,
        ]);
    }

    public function test_un_joueur_ne_peut_pas_voter_pour_lui_meme_retourne_422(): void
    {
        Event::fake();

        $game  = $this->makeDayGame();
        $user  = User::factory()->create();
        $voter = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/vote/day", [
            'target_player_id' => $voter->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_égalité_vote_jour_élimine_personne_et_broadcast_no_elimination(): void
    {
        Event::fake();

        $game   = $this->makeDayGame();
        $userA  = User::factory()->create();
        $userB  = User::factory()->create();
        $userC  = User::factory()->create();
        $playerA = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $userA->id]);
        $playerB = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $userB->id]);
        $playerC = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $userC->id]);

        // A vote pour B, B vote pour A → égalité
        GameAction::create([
            'game_id'          => $game->id,
            'player_id'        => $playerA->id,
            'type'             => 'day_vote',
            'weight'           => 1,
            'target_player_id' => $playerB->id,
            'round'            => 1,
            'phase'            => 'day',
        ]);
        GameAction::create([
            'game_id'          => $game->id,
            'player_id'        => $playerB->id,
            'type'             => 'day_vote',
            'weight'           => 1,
            'target_player_id' => $playerA->id,
            'round'            => 1,
            'phase'            => 'day',
        ]);

        app(\App\Services\VoteService::class)->resolveDayVote($game);

        // Aucun joueur éliminé
        $this->assertDatabaseMissing('game_players', [
            'game_id'  => $game->id,
            'is_alive' => false,
        ]);
        Event::assertDispatched(NoElimination::class, function ($event) {
            return $event->broadcastWith()['reason'] === 'equality';
        });
    }

    public function test_vote_maire_weight_2_correctement_compté(): void
    {
        Event::fake();

        $game   = $this->makeDayGame();
        $userM  = User::factory()->create();
        $userV  = User::factory()->create();
        $userT  = User::factory()->create();
        $mayor  = GamePlayer::factory()->villager()->create([
            'game_id'  => $game->id,
            'user_id'  => $userM->id,
            'is_mayor' => true,
        ]);
        $voter  = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $userV->id]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $userT->id]);

        $this->actingAs($userM)->postJson("/game/{$game->id}/vote/day", [
            'target_player_id' => $target->id,
        ])->assertStatus(200);

        $this->assertDatabaseHas('game_actions', [
            'game_id'          => $game->id,
            'player_id'        => $mayor->id,
            'type'             => 'day_vote',
            'weight'           => 2,
            'target_player_id' => $target->id,
        ]);
    }

    public function test_vote_hors_phase_day_retourne_409(): void
    {
        Event::fake();

        $game   = Game::factory()->create(['status' => 'night', 'max_players' => 6, 'round' => 1]);
        $user   = User::factory()->create();
        $voter  = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/vote/day", [
            'target_player_id' => $target->id,
        ]);

        $response->assertStatus(409);
    }
}
