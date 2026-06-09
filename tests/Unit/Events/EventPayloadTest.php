<?php

namespace Tests\Unit\Events;

use App\Events\Game\DayVoteCast;
use App\Events\Game\MayorVoteCast;
use App\Events\Game\SeerResult;
use App\Events\Game\WerewolfChatMessage;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_mayor_vote_cast_payload_ne_contient_pas_player_id(): void
    {
        $game = Game::factory()->create(['status' => 'electing_mayor']);
        $votes = [
            ['target_player_id' => 1, 'pseudo' => 'Alice', 'vote_count' => 2],
        ];

        $event   = new MayorVoteCast($game, $votes);
        $payload = $event->broadcastWith();

        $this->assertArrayNotHasKey('player_id', $payload);
        $this->assertArrayHasKey('votes', $payload);
    }

    public function test_day_vote_cast_payload_ne_contient_pas_player_id(): void
    {
        $game    = Game::factory()->inProgress()->create();
        $summary = [5 => 3, 6 => 1];

        $event   = new DayVoteCast($game, $summary);
        $payload = $event->broadcastWith();

        $this->assertArrayNotHasKey('player_id', $payload);
        $this->assertArrayHasKey('votes', $payload);
        // Vérifie l'absence de player_id dans les entrées de votes
        foreach ($payload['votes'] as $entry) {
            $this->assertArrayNotHasKey('player_id', $entry);
        }
    }

    public function test_seer_result_broadcasté_sur_channel_privé_uniquement(): void
    {
        $game   = Game::factory()->inProgress()->create(['max_players' => 6]);
        $seer   = GamePlayer::factory()->seer()->create(['game_id' => $game->id]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $event    = new SeerResult($game, $seer, $target);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertStringContainsString("game.{$game->id}.player.{$seer->id}", $channels[0]->name);
    }

    public function test_werewolf_chat_message_broadcasté_sur_channel_werewolves_uniquement(): void
    {
        $game  = Game::factory()->inProgress()->create(['max_players' => 6]);
        $wolf  = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);

        $event    = new WerewolfChatMessage($game, $wolf, 'on attaque Alice', now()->toISOString());
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertStringContainsString("game.{$game->id}.werewolves", $channels[0]->name);
    }
}
