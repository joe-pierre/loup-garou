<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function mockSocialiteUser(string $googleId, string $email, string $name): void
    {
        $googleUser = \Mockery::mock();
        $googleUser->shouldReceive('getId')->andReturn($googleId);
        $googleUser->shouldReceive('getEmail')->andReturn($email);
        $googleUser->shouldReceive('getName')->andReturn($name);

        Socialite::shouldReceive('driver->user')->andReturn($googleUser);
    }

    public function test_crée_un_nouvel_utilisateur_si_google_id_inconnu(): void
    {
        $this->mockSocialiteUser('google-999', 'nouveau@example.com', 'Nouveau Joueur');

        $this->get(route('auth.google.callback'));

        $this->assertDatabaseHas('users', [
            'google_id' => 'google-999',
            'email'     => 'nouveau@example.com',
            'name'      => 'Nouveau Joueur',
        ]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_met_à_jour_utilisateur_existant_si_google_id_connu(): void
    {
        User::factory()->create([
            'google_id' => 'google-123',
            'email'     => 'ancien@example.com',
            'name'      => 'Ancien Nom',
        ]);

        $this->mockSocialiteUser('google-123', 'nouveau@example.com', 'Nouveau Nom');

        $this->get(route('auth.google.callback'));

        $this->assertDatabaseHas('users', [
            'google_id' => 'google-123',
            'email'     => 'nouveau@example.com',
            'name'      => 'Nouveau Nom',
        ]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_authentifie_le_joueur_après_callback(): void
    {
        $this->mockSocialiteUser('google-456', 'auth@example.com', 'Joueur Auth');

        $this->get(route('auth.google.callback'));

        $this->assertAuthenticated();
    }

    public function test_exception_socialite_redirige_vers_login_avec_erreur(): void
    {
        Socialite::shouldReceive('driver->user')->andThrow(new \Exception('OAuth échoué'));

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
    }
}
