<?php

namespace Tests\Unit\Services;

use App\Models\GamePlayer;
use App\Services\PlayerEliminationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerEliminationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_eliminer_un_joueur_pose_is_alive_false(): void
    {
        $player = GamePlayer::factory()->create(['is_alive' => true]);

        app(PlayerEliminationService::class)->eliminate($player);

        $this->assertFalse($player->fresh()->is_alive);
    }

    public function test_eliminer_un_amoureux_cascade_sur_lautre_amoureux(): void
    {
        $playerA = GamePlayer::factory()->create(['is_alive' => true]);
        $playerB = GamePlayer::factory()->create(['is_alive' => true]);

        $playerA->update(['lover_player_id' => $playerB->id]);
        $playerB->update(['lover_player_id' => $playerA->id]);

        app(PlayerEliminationService::class)->eliminate($playerA);

        $this->assertFalse($playerA->fresh()->is_alive);
        $this->assertFalse($playerB->fresh()->is_alive);
    }

    public function test_cascade_ne_re_elimine_pas_un_amoureux_deja_mort(): void
    {
        $playerA = GamePlayer::factory()->create(['is_alive' => true]);
        $playerB = GamePlayer::factory()->create(['is_alive' => false]);

        $playerA->update(['lover_player_id' => $playerB->id]);
        $playerB->update(['lover_player_id' => $playerA->id]);

        app(PlayerEliminationService::class)->eliminate($playerA);

        $this->assertFalse($playerA->fresh()->is_alive);
        $this->assertFalse($playerB->fresh()->is_alive);
    }
}
