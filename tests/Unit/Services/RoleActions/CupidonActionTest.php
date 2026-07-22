<?php

namespace Tests\Unit\Services\RoleActions;

use App\Events\Game\LoverRevealed;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\RoleActions\CupidonAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CupidonActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeNightRoundOneGame(): Game
    {
        return Game::factory()->create([
            'status'      => 'night',
            'max_players' => 6,
            'round'       => 1,
        ]);
    }

    public function test_lien_pose_lover_player_id_symetrique_et_historique(): void
    {
        Event::fake();

        $game = $this->makeNightRoundOneGame();
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        $target1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        app(CupidonAction::class)->link($cupidon, $target1->id, $target2->id);

        $this->assertSame($target2->id, $target1->fresh()->lover_player_id);
        $this->assertSame($target1->id, $target2->fresh()->lover_player_id);

        $this->assertSame(2, GameAction::where('game_id', $game->id)
            ->where('type', 'cupidon_link')
            ->where('player_id', $cupidon->id)
            ->where('round', 1)
            ->where('phase', 'night')
            ->count());

        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'type' => 'cupidon_link', 'target_player_id' => $target1->id,
        ]);
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'type' => 'cupidon_link', 'target_player_id' => $target2->id,
        ]);

        Event::assertDispatched(LoverRevealed::class, 2);
    }

    public function test_cupidon_peut_se_choisir_lui_meme_un_seul_broadcast(): void
    {
        Event::fake();

        $game = $this->makeNightRoundOneGame();
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        $other = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        app(CupidonAction::class)->link($cupidon, $cupidon->id, $other->id);

        $this->assertSame($other->id, $cupidon->fresh()->lover_player_id);
        $this->assertSame($cupidon->id, $other->fresh()->lover_player_id);

        // Cupidon connaît déjà le résultat via la réponse de son action — un seul
        // broadcast, destiné à l'autre amoureux uniquement (SPEC_CUPIDON.md §7).
        Event::assertDispatched(LoverRevealed::class, 1);
        Event::assertDispatched(LoverRevealed::class, fn ($e) => $e->lover->id === $other->id && $e->partner->id === $cupidon->id);
    }

    public function test_role_invalide_rejete_403(): void
    {
        $game = $this->makeNightRoundOneGame();
        $notCupidon = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        try {
            app(CupidonAction::class)->link($notCupidon, $target1->id, $target2->id);
            $this->fail('Une exception 403 était attendue.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_phase_invalide_round_2_rejete_409(): void
    {
        $game = Game::factory()->create(['status' => 'night', 'max_players' => 6, 'round' => 2]);
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        $target1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        try {
            app(CupidonAction::class)->link($cupidon, $target1->id, $target2->id);
            $this->fail('Une exception 409 était attendue.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_phase_invalide_hors_nuit_rejete_409(): void
    {
        $game = Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1]);
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        $target1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        try {
            app(CupidonAction::class)->link($cupidon, $target1->id, $target2->id);
            $this->fail('Une exception 409 était attendue.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_double_action_rejetee_409(): void
    {
        $game = $this->makeNightRoundOneGame();
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        $target1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target3 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        app(CupidonAction::class)->link($cupidon, $target1->id, $target2->id);

        try {
            app(CupidonAction::class)->link($cupidon, $target1->id, $target3->id);
            $this->fail('Une exception 409 était attendue.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_meme_cible_deux_fois_rejetee_422(): void
    {
        $game = $this->makeNightRoundOneGame();
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        try {
            app(CupidonAction::class)->link($cupidon, $target->id, $target->id);
            $this->fail('Une exception 422 était attendue.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_cible_introuvable_404(): void
    {
        $game = $this->makeNightRoundOneGame();
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        $target = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        try {
            app(CupidonAction::class)->link($cupidon, $target->id, 999999);
            $this->fail('Une exception 404 était attendue.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_cible_morte_rejetee_422(): void
    {
        $game = $this->makeNightRoundOneGame();
        $cupidon = GamePlayer::factory()->cupidon()->create(['game_id' => $game->id]);
        $target1 = GamePlayer::factory()->villager()->dead()->create(['game_id' => $game->id]);
        $target2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        try {
            app(CupidonAction::class)->link($cupidon, $target1->id, $target2->id);
            $this->fail('Une exception 422 était attendue.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }
}
