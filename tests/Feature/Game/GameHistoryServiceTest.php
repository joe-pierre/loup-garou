<?php

namespace Tests\Feature\Game;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\HistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_timeline_retourne_election_plus_finish_pour_partie_sans_rounds(): void
    {
        $game    = Game::factory()->create(['status' => 'finished', 'winner_team' => 'villagers', 'round' => 0]);
        $players = GamePlayer::factory()->count(2)->create(['game_id' => $game->id])->keyBy('id');
        $actions = collect();

        $service  = new HistoryService();
        $timeline = $service->buildTimeline($game, $players, $actions);

        $types = array_column($timeline, 'type');
        $this->assertContains('finish', $types);
    }
}
