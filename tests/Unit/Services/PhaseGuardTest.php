<?php

namespace Tests\Unit\Services;

use App\Models\Game;
use App\Services\PhaseGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhaseGuardTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('nightStatusProvider')]
    public function test_is_night_retourne_true_pour_statuts_nocturnes(string $status): void
    {
        $game = Game::factory()->create(['status' => $status]);
        $this->assertTrue(PhaseGuard::isNight($game));
    }

    public static function nightStatusProvider(): array
    {
        return [
            ['night'],
            ['wolves_turn'],
            ['processing_night'],
        ];
    }

    #[DataProvider('dayStatusProvider')]
    public function test_is_day_retourne_true_pour_statuts_diurnes(string $status): void
    {
        $game = Game::factory()->create(['status' => $status]);
        $this->assertTrue(PhaseGuard::isDay($game));
    }

    public static function dayStatusProvider(): array
    {
        return [
            ['day'],
            ['processing_day'],
        ];
    }

    #[DataProvider('nonNightStatusProvider')]
    public function test_is_night_retourne_false_pour_statuts_non_nocturnes(string $status): void
    {
        $game = Game::factory()->create(['status' => $status]);
        $this->assertFalse(PhaseGuard::isNight($game));
    }

    public static function nonNightStatusProvider(): array
    {
        return [
            ['waiting'],
            ['electing_mayor'],
            ['day'],
            ['processing_day'],
            ['finished'],
        ];
    }

    public function test_can_witch_act_retourne_false_pour_wolves_turn(): void
    {
        $game = Game::factory()->create(['status' => 'wolves_turn']);
        $this->assertFalse(PhaseGuard::canWitchAct($game));
    }

    public function test_can_chat_wolves_retourne_true_pour_night_et_wolves_turn(): void
    {
        foreach (['night', 'wolves_turn'] as $status) {
            $game = Game::factory()->create(['status' => $status]);
            $this->assertTrue(PhaseGuard::canChatWolves($game), "Échec pour status={$status}");
        }
    }

    public function test_can_chat_wolves_retourne_false_hors_phase_loups(): void
    {
        foreach (['day', 'processing_day', 'electing_mayor', 'processing_night', 'finished'] as $status) {
            $game = Game::factory()->create(['status' => $status]);
            $this->assertFalse(PhaseGuard::canChatWolves($game), "Doit être false pour status={$status}");
        }
    }

    public function test_can_hunter_shoot_couvre_nuit_et_jour(): void
    {
        foreach (['night', 'processing_night', 'wolves_turn', 'day', 'processing_day'] as $status) {
            $game = Game::factory()->create(['status' => $status]);
            $this->assertTrue(PhaseGuard::canHunterShoot($game), "Échec pour status={$status}");
        }
    }
}
