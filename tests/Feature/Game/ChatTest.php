<?php

namespace Tests\Feature\Game;

use App\Events\Game\ChatMessageSent;
use App\Events\Game\WerewolfChatMessage;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_loup_broadcasté_sur_channel_werewolves_uniquement(): void
    {
        Event::fake();

        $game = Game::factory()->create(['status' => 'night', 'max_players' => 6, 'round' => 1]);
        $user = User::factory()->create();
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $this->actingAs($user)->postJson("/game/{$game->id}/chat", [
            'message' => 'on attaque le villageois',
            'channel' => 'werewolves',
        ])->assertStatus(200);

        Event::assertDispatched(WerewolfChatMessage::class);
        Event::assertNotDispatched(ChatMessageSent::class);
    }

    public function test_message_loup_broadcasté_pendant_wolves_turn(): void
    {
        Event::fake();

        $game = Game::factory()->create(['status' => 'wolves_turn', 'max_players' => 6, 'round' => 1]);
        $user = User::factory()->create();
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $this->actingAs($user)->postJson("/game/{$game->id}/chat", [
            'message' => 'on attaque pendant notre tour',
            'channel' => 'werewolves',
        ])->assertStatus(200);

        Event::assertDispatched(WerewolfChatMessage::class);
        Event::assertNotDispatched(ChatMessageSent::class);
    }

    public function test_villageois_ne_peut_pas_écrire_sur_channel_werewolves_retourne_403(): void
    {
        Event::fake();

        $game = Game::factory()->create(['status' => 'night', 'max_players' => 6, 'round' => 1]);
        $user = User::factory()->create();
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/chat", [
            'message' => 'je suis un loup déguisé',
            'channel' => 'werewolves',
        ]);

        $response->assertStatus(403);
    }

    public function test_message_après_mort_retourne_403(): void
    {
        Event::fake();

        $game = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $user = User::factory()->create();
        GamePlayer::factory()->villager()->dead()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/chat", [
            'message' => 'je veux parler depuis l\'au-delà',
            'channel' => 'general',
        ]);

        $response->assertStatus(403);
    }
}
