<?php

namespace Tests\Unit\Models;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamePlayerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_witch_heal_used_retourne_false_si_settings_null(): void
    {
        $game   = Game::factory()->create(['status' => 'night', 'max_players' => 6]);
        $player = GamePlayer::factory()->witch()->create(['game_id' => $game->id, 'settings' => null]);
        $this->assertFalse($player->witchHealUsed());
    }

    public function test_witch_kill_used_retourne_true_si_potion_utilisee(): void
    {
        $game   = Game::factory()->create(['status' => 'night', 'max_players' => 6]);
        $player = GamePlayer::factory()->witch()->create([
            'game_id'  => $game->id,
            'settings' => ['witch_kill_used' => true],
        ]);
        $this->assertTrue($player->witchKillUsed());
    }

    public function test_witch_has_potion_retourne_false_si_les_deux_epuisees(): void
    {
        $game   = Game::factory()->create(['status' => 'night', 'max_players' => 6]);
        $player = GamePlayer::factory()->witch()->create([
            'game_id'  => $game->id,
            'settings' => ['witch_heal_used' => true, 'witch_kill_used' => true],
        ]);
        $this->assertFalse($player->witchHasPotion());
    }

    public function test_is_active_and_alive_retourne_false_si_inactif(): void
    {
        $game   = Game::factory()->create(['status' => 'day', 'max_players' => 6]);
        $player = GamePlayer::factory()->villager()->create([
            'game_id' => $game->id, 'is_alive' => true, 'is_inactive' => true,
        ]);
        $this->assertFalse($player->isActiveAndAlive());
    }
}
