<?php

namespace Tests\Feature\Game;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateGameTest extends TestCase
{
    use RefreshDatabase;

    public function test_création_réussie_retourne_201_avec_code_et_statut_waiting(): void
    {
        Event::fake();
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/game', [
            'pseudo'      => 'MonPseudo',
            'max_players' => 6,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['game_id', 'code']]);

        $code = $response->json('data.code');

        $this->assertSame(6, strlen($code));

        $this->assertDatabaseHas('games', [
            'code'        => $code,
            'status'      => 'waiting',
            'max_players' => 6,
        ]);

        $this->assertDatabaseHas('game_players', [
            'user_id' => $user->id,
            'pseudo'  => 'MonPseudo',
            'is_host' => true,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMaxPlayersProvider')]
    public function test_max_players_invalide_retourne_422(int $value): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/game', ['pseudo' => 'Host', 'max_players' => $value])
            ->assertStatus(422);
    }

    public static function invalidMaxPlayersProvider(): array
    {
        return [[5], [7], [13]];
    }

    public function test_pseudo_vide_retourne_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/game', ['pseudo' => '', 'max_players' => 6])
            ->assertStatus(422);
    }

    public function test_pseudo_trop_court_retourne_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/game', ['pseudo' => 'X', 'max_players' => 6])
            ->assertStatus(422);
    }

    public function test_code_collision_retente_jusqu_à_code_unique(): void
    {
        Event::fake();
        Queue::fake();

        Game::factory()->create(['code' => 'TAKEN1']);

        $attempt = 0;
        Str::createRandomStringsUsing(function (int $length) use (&$attempt): string {
            $attempt++;
            return $attempt === 1 ? 'TAKEN1' : 'UNIQUE';
        });

        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/game', [
            'pseudo'      => 'Host',
            'max_players' => 6,
        ]);

        Str::createRandomStringsNormally();

        $response->assertStatus(201);
        $this->assertSame('UNIQUE', $response->json('data.code'));
        $this->assertGreaterThan(1, $attempt);
    }

    public function test_non_authentifié_redirigé_vers_login(): void
    {
        $this->post('/game', ['pseudo' => 'Host', 'max_players' => 6])
            ->assertRedirect('/login');
    }
}
