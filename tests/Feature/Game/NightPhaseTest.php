<?php

namespace Tests\Feature\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
}
