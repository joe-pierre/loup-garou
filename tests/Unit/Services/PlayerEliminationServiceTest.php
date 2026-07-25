<?php

namespace Tests\Unit\Services;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\PlayerEliminationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerEliminationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function linkLovers(GamePlayer $a, GamePlayer $b): void
    {
        $a->update(['lover_player_id' => $b->id]);
        $b->update(['lover_player_id' => $a->id]);
    }

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

    // ------------------------------------------------------------------
    // Chasseur mort de chagrin (cascade amoureux) : hunter_pending doit
    // être créé par le service lui-même, aucun appelant ne le fait à sa place.
    // ------------------------------------------------------------------

    public function test_cascade_amoureux_chasseur_cree_hunter_pending_en_phase_nuit(): void
    {
        $game    = Game::factory()->create(['status' => 'processing_night', 'round' => 3]);
        $villager = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $hunter   = GamePlayer::factory()->hunter()->create(['game_id' => $game->id]);

        $this->linkLovers($villager, $hunter);

        app(PlayerEliminationService::class)->eliminate($villager);

        $this->assertFalse($villager->fresh()->is_alive);
        $this->assertFalse($hunter->fresh()->is_alive);
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $hunter->id, 'type' => 'hunter_pending',
            'round' => 3, 'phase' => 'night',
        ]);
    }

    public function test_cascade_amoureux_chasseur_cree_hunter_pending_en_phase_jour(): void
    {
        $game     = Game::factory()->create(['status' => 'processing_day', 'round' => 2]);
        $villager = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $hunter   = GamePlayer::factory()->hunter()->create(['game_id' => $game->id]);

        $this->linkLovers($villager, $hunter);

        app(PlayerEliminationService::class)->eliminate($villager);

        $this->assertFalse($hunter->fresh()->is_alive);
        $this->assertDatabaseHas('game_actions', [
            'game_id' => $game->id, 'player_id' => $hunter->id, 'type' => 'hunter_pending',
            'round' => 2, 'phase' => 'day',
        ]);
    }

    public function test_cascade_amoureux_chasseur_maire_cree_hunter_pending_une_seule_fois(): void
    {
        $game     = Game::factory()->create(['status' => 'night', 'round' => 1]);
        $villager = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $hunter   = GamePlayer::factory()->hunter()->create(['game_id' => $game->id, 'is_mayor' => true]);

        $this->linkLovers($villager, $hunter);

        app(PlayerEliminationService::class)->eliminate($villager);

        $this->assertFalse($hunter->fresh()->is_alive);
        // Le hunter_pending prime, sans dispatch/décision de succession pris par le service
        // (aucune méthode de ce service ne broadcast ou ne dispatche quoi que ce soit —
        // c'est aux call sites/jobs consommant hunter_pending de gérer $isMayor).
        $this->assertSame(1, GameAction::where('game_id', $game->id)
            ->where('type', 'hunter_pending')
            ->where('round', 1)
            ->count());
        $this->assertTrue($hunter->fresh()->is_mayor);
    }

    public function test_cascade_amoureux_non_chasseur_ne_cree_aucun_hunter_pending(): void
    {
        $game    = Game::factory()->create(['status' => 'night', 'round' => 1]);
        $playerA = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $playerB = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $this->linkLovers($playerA, $playerB);

        app(PlayerEliminationService::class)->eliminate($playerA);

        $this->assertFalse($playerB->fresh()->is_alive);
        $this->assertSame(0, GameAction::where('game_id', $game->id)->where('type', 'hunter_pending')->count());
    }

    public function test_victime_directe_chasseur_sans_cascade_ne_cree_pas_de_hunter_pending_depuis_le_service(): void
    {
        // Le hunter_pending pour la victime directe est de la responsabilité de l'appelant
        // (ProcessNightActions, WitchAction, VoteService) — le service ne doit jamais en
        // créer un doublon pour le $player passé en paramètre initial, cascade ou pas.
        $game   = Game::factory()->create(['status' => 'night', 'round' => 1]);
        $hunter = GamePlayer::factory()->hunter()->create(['game_id' => $game->id]);

        app(PlayerEliminationService::class)->eliminate($hunter);

        $this->assertFalse($hunter->fresh()->is_alive);
        $this->assertSame(0, GameAction::where('game_id', $game->id)->where('type', 'hunter_pending')->count());
    }
}
