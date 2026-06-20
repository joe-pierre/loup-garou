<?php

namespace Tests\Feature\Console;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanOldGamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_supprime_parties_terminees_depuis_plus_de_7_jours(): void
    {
        $game = Game::factory()->create([
            'status'      => 'finished',
            'winner_team' => 'villagers',
            'finished_at' => now()->subDays(8),
        ]);

        $this->artisan('games:clean')->assertSuccessful();

        $this->assertDatabaseMissing('games', ['id' => $game->id]);
    }

    public function test_conserve_parties_terminees_depuis_moins_de_7_jours(): void
    {
        $game = Game::factory()->create([
            'status'      => 'finished',
            'winner_team' => 'villagers',
            'finished_at' => now()->subDays(3),
        ]);

        $this->artisan('games:clean')->assertSuccessful();

        $this->assertDatabaseHas('games', ['id' => $game->id]);
    }

    public function test_conserve_parties_terminees_exactement_7_jours_ago(): void
    {
        // Borne : finished_at < now() - 7j (strictement inférieur)
        // Une partie terminée il y a exactement 7 jours ne doit PAS être supprimée
        $game = Game::factory()->create([
            'status'      => 'finished',
            'winner_team' => 'werewolves',
            'finished_at' => now()->subDays(7),
        ]);

        $this->artisan('games:clean')->assertSuccessful();

        $this->assertDatabaseHas('games', ['id' => $game->id]);
    }

    public function test_conserve_parties_en_cours(): void
    {
        // Une partie active (finished_at = null) ne doit jamais être supprimée,
        // même si created_at est très ancien
        $game = Game::factory()->create([
            'status'      => 'night',
            'finished_at' => null,
        ]);

        // Force un created_at très ancien pour s'assurer que la commande ne s'appuie pas dessus
        $game->update(['created_at' => now()->subDays(30)]);

        $this->artisan('games:clean')->assertSuccessful();

        $this->assertDatabaseHas('games', ['id' => $game->id]);
    }

    public function test_ne_supprime_pas_parties_en_waiting(): void
    {
        $game = Game::factory()->create([
            'status'      => 'waiting',
            'finished_at' => null,
        ]);

        $this->artisan('games:clean')->assertSuccessful();

        $this->assertDatabaseHas('games', ['id' => $game->id]);
    }

    public function test_supprime_plusieurs_vieilles_parties_et_preserve_les_recentes(): void
    {
        $old1 = Game::factory()->create(['status' => 'finished', 'finished_at' => now()->subDays(10)]);
        $old2 = Game::factory()->create(['status' => 'finished', 'finished_at' => now()->subDays(8)]);
        $recent = Game::factory()->create(['status' => 'finished', 'finished_at' => now()->subDays(2)]);
        $active = Game::factory()->create(['status' => 'day', 'finished_at' => null]);

        $this->artisan('games:clean')->assertSuccessful();

        $this->assertDatabaseMissing('games', ['id' => $old1->id]);
        $this->assertDatabaseMissing('games', ['id' => $old2->id]);
        $this->assertDatabaseHas('games', ['id' => $recent->id]);
        $this->assertDatabaseHas('games', ['id' => $active->id]);
    }
}
