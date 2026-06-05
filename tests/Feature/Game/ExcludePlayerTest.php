<?php

namespace Tests\Feature\Game;

use App\Events\Game\PlayerExcluded;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ExcludePlayerTest extends TestCase
{
    use RefreshDatabase;

    private function makeWaitingGame(): array
    {
        $game   = Game::factory()->create(['status' => 'waiting']);
        $host   = GamePlayer::factory()->host()->create(['game_id' => $game->id]);
        $target = GamePlayer::factory()->create(['game_id' => $game->id]);
        return [$game, $host, $target];
    }

    public function test_host_peut_exclure_un_joueur(): void
    {
        Event::fake();
        [$game, $host, $target] = $this->makeWaitingGame();

        $response = $this->actingAs($host->user)
            ->postJson("/game/{$game->id}/exclude/{$target->id}", [
                'reason' => 'Comportement irrespectueux',
            ]);

        $response->assertStatus(200)->assertJsonPath('success', true);

        $this->assertDatabaseMissing('game_players', ['id' => $target->id]);
        $this->assertDatabaseHas('exclusions', [
            'game_id' => $game->id,
            'reason'  => 'Comportement irrespectueux',
        ]);
    }

    public function test_non_host_ne_peut_pas_exclure_retourne_403(): void
    {
        Event::fake();
        [$game, $host, $target] = $this->makeWaitingGame();

        $nonHost = GamePlayer::factory()->create(['game_id' => $game->id]);

        $this->actingAs($nonHost->user)
            ->postJson("/game/{$game->id}/exclude/{$target->id}", ['reason' => 'Motif'])
            ->assertStatus(403);
    }

    public function test_exclusion_hors_phase_waiting_retourne_409(): void
    {
        Event::fake();
        $game   = Game::factory()->inProgress()->create();
        $host   = GamePlayer::factory()->host()->create(['game_id' => $game->id]);
        $target = GamePlayer::factory()->create(['game_id' => $game->id]);

        $this->actingAs($host->user)
            ->postJson("/game/{$game->id}/exclude/{$target->id}", ['reason' => 'Motif'])
            ->assertStatus(409);
    }

    public function test_motif_vide_retourne_422(): void
    {
        [$game, $host, $target] = $this->makeWaitingGame();

        $this->actingAs($host->user)
            ->postJson("/game/{$game->id}/exclude/{$target->id}", ['reason' => ''])
            ->assertStatus(422);
    }

    public function test_joueur_exclu_ne_peut_plus_rejoindre(): void
    {
        Event::fake();
        [$game, $host, $target] = $this->makeWaitingGame();

        // Charger l'user avant la suppression du player par exclude
        $targetUser = $target->user;

        $this->actingAs($host->user)
            ->postJson("/game/{$game->id}/exclude/{$target->id}", ['reason' => 'Test exclusion']);

        $response = $this->actingAs($targetUser)
            ->postJson("/game/{$game->code}/join", ['pseudo' => 'Revenant']);

        $response->assertStatus(403);
    }

    public function test_player_excluded_broadcasté_après_exclusion(): void
    {
        Event::fake();
        [$game, $host, $target] = $this->makeWaitingGame();

        $this->actingAs($host->user)
            ->postJson("/game/{$game->id}/exclude/{$target->id}", ['reason' => 'Test']);

        Event::assertDispatched(PlayerExcluded::class);
    }
}
