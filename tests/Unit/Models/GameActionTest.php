<?php

namespace Tests\Unit\Models;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_anonymized_exclut_player_id_des_colonnes(): void
    {
        $game   = Game::factory()->inProgress()->create(['max_players' => 6]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id'   => $game->id,
            'player_id' => $player->id,
            'type'      => 'day_vote',
            'round'     => 1,
            'phase'     => 'day',
        ]);

        $action = GameAction::anonymized()->first();

        $this->assertArrayNotHasKey('player_id', $action->getAttributes());
    }

    public function test_scope_anonymized_retourne_toutes_les_lignes_sans_filtre(): void
    {
        $game = Game::factory()->inProgress()->create(['max_players' => 6]);

        GameAction::factory()->count(3)->create([
            'game_id' => $game->id,
            'type'    => 'day_vote',
            'round'   => 1,
            'phase'   => 'day',
        ]);

        GameAction::factory()->count(2)->create([
            'game_id' => $game->id,
            'type'    => 'night_vote',
            'round'   => 1,
            'phase'   => 'night',
        ]);

        $this->assertCount(5, GameAction::anonymized()->get());
    }

    public function test_scope_anonymized_retourne_target_player_id_type_weight_round_phase(): void
    {
        $game   = Game::factory()->inProgress()->create(['max_players' => 6]);
        $target = GamePlayer::factory()->create(['game_id' => $game->id]);

        GameAction::factory()->create([
            'game_id'          => $game->id,
            'type'             => 'mayor_vote',
            'weight'           => 2,
            'target_player_id' => $target->id,
            'round'            => 1,
            'phase'            => 'election',
        ]);

        $action = GameAction::anonymized()->first();

        $this->assertNotNull($action->target_player_id);
        $this->assertNotNull($action->type);
        $this->assertNotNull($action->weight);
        $this->assertNotNull($action->round);
        $this->assertNotNull($action->phase);
    }
}
