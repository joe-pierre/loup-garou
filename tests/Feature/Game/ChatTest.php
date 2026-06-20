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

    // ──────────────────────────────────────────────────────────────────────────
    // Canal 'dead' — couverture des phases et droits d'accès
    // ──────────────────────────────────────────────────────────────────────────

    public function test_mort_peut_ecrire_sur_canal_dead_en_phase_day(): void
    {
        Event::fake();

        $game = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $user = User::factory()->create();
        GamePlayer::factory()->villager()->dead()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/chat", [
            'message' => 'je parle depuis l\'au-delà',
            'channel' => 'dead',
        ]);

        $response->assertStatus(200);
    }

    public function test_mort_ne_peut_pas_ecrire_sur_canal_dead_en_phase_night(): void
    {
        Event::fake();

        $game = Game::factory()->create(['status' => 'night', 'max_players' => 6, 'round' => 1]);
        $user = User::factory()->create();
        GamePlayer::factory()->villager()->dead()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/chat", [
            'message' => 'les fantômes se taisent la nuit',
            'channel' => 'dead',
        ]);

        // PhaseGuard::canChatDead() retourne false pour 'night' → 409
        $response->assertStatus(409);
    }

    public function test_mort_ne_peut_pas_ecrire_sur_canal_dead_en_phase_electing_mayor(): void
    {
        Event::fake();

        $game = Game::factory()->create(['status' => 'electing_mayor', 'max_players' => 6, 'round' => 0]);
        $user = User::factory()->create();
        GamePlayer::factory()->villager()->dead()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/chat", [
            'message' => 'pas pendant l\'élection non plus',
            'channel' => 'dead',
        ]);

        // PhaseGuard::canChatDead() retourne false pour 'electing_mayor' → 409
        $response->assertStatus(409);
    }

    public function test_vivant_ne_peut_pas_ecrire_sur_canal_dead(): void
    {
        Event::fake();

        $game = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $user = User::factory()->create();
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $user->id]); // vivant

        $response = $this->actingAs($user)->postJson("/game/{$game->id}/chat", [
            'message' => 'je suis vivant, je ne peux pas parler aux morts',
            'channel' => 'dead',
        ]);

        // ChatService : if ($player->is_alive) → abort(403)
        $response->assertStatus(403);
    }

    public function test_mort_pendant_electing_mayor_ne_peut_ecrire_sur_aucun_canal(): void
    {
        Event::fake();

        $game = Game::factory()->create(['status' => 'electing_mayor', 'max_players' => 6, 'round' => 0]);
        $user = User::factory()->create();
        GamePlayer::factory()->villager()->dead()->create(['game_id' => $game->id, 'user_id' => $user->id]);

        // Canal 'general' : mort → 403 (! $player->is_alive && $channel !== 'dead' → abort(403))
        $generalResponse = $this->actingAs($user)->postJson("/game/{$game->id}/chat", [
            'message' => 'canal général pendant élection',
            'channel' => 'general',
        ]);

        // Canal 'dead' : phase 'electing_mayor' → PhaseGuard::canChatDead() = false → 409
        $deadResponse = $this->actingAs($user)->postJson("/game/{$game->id}/chat", [
            'message' => 'canal dead pendant élection',
            'channel' => 'dead',
        ]);

        // Les deux canaux doivent être inaccessibles à un joueur mort pendant electing_mayor.
        // Codes observés : 403 pour 'general', 409 pour 'dead' (comportement attendu documenté).
        $this->assertNotSame(200, $generalResponse->status(),
            "Un mort ne devrait pas pouvoir écrire sur 'general' pendant electing_mayor");
        $this->assertNotSame(200, $deadResponse->status(),
            "Un mort ne devrait pas pouvoir écrire sur 'dead' pendant electing_mayor");

        // Documenter les codes précis pour figer le comportement actuel
        $this->assertSame(403, $generalResponse->status(),
            "Code attendu pour 'general' (mort, electing_mayor) : 403");
        $this->assertSame(409, $deadResponse->status(),
            "Code attendu pour 'dead' (electing_mayor) : 409 — PhaseGuard::canChatDead() = false");
    }
}
