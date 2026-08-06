<?php

namespace Tests\Unit\Services\Admin;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\Admin\LeaderboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaderboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaderboardService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeaderboardService();
        $this->travelTo(Carbon::parse('2026-08-06 12:00:00'));
    }

    private function finishedGameAt(Carbon $finishedAt, string $winnerTeam): Game
    {
        return Game::factory()->finished()->create([
            'winner_team' => $winnerTeam,
            'finished_at' => $finishedAt,
        ]);
    }

    public function test_top_wins_compte_les_victoires_par_camp_et_ignore_les_defaites(): void
    {
        $winner = User::factory()->create();
        $loser  = User::factory()->create();

        $game = $this->finishedGameAt(now(), 'villagers');
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $winner->id]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'user_id' => $loser->id]);

        $rows = $this->service->topWins('current_month');

        // Les deux participants apparaissent (classement, pas un filtre) ; le
        // gagnant est classé premier avec 1 victoire, le perdant avec 0.
        $this->assertCount(2, $rows);
        $this->assertSame($winner->id, $rows->first()->id);
        $this->assertEquals(1, $rows->first()->wins_count);
        $this->assertEquals(0, $rows->last()->wins_count);
    }

    public function test_top_parties_jouees_compte_toutes_les_participations_gagnees_ou_perdues(): void
    {
        $user = User::factory()->create();

        $gameWon  = $this->finishedGameAt(now(), 'villagers');
        $gameLost = $this->finishedGameAt(now(), 'werewolves');
        GamePlayer::factory()->villager()->create(['game_id' => $gameWon->id, 'user_id' => $user->id]);
        GamePlayer::factory()->villager()->create(['game_id' => $gameLost->id, 'user_id' => $user->id]);

        $rows = $this->service->topGamesPlayed('current_month');

        $this->assertCount(1, $rows);
        $this->assertEquals(2, $rows->first()->games_count);
    }

    public function test_parties_annulees_exclues_de_tous_les_classements(): void
    {
        $user = User::factory()->create();

        $cancelled = Game::factory()->finished()->create([
            'winner_team' => null,
            'finished_at' => now(),
        ]);
        GamePlayer::factory()->villager()->create(['game_id' => $cancelled->id, 'user_id' => $user->id]);

        $this->assertCount(0, $this->service->topWins('current_month'));
        $this->assertCount(0, $this->service->topGamesPlayed('current_month'));
    }

    public function test_top_taux_de_victoire_applique_le_seuil_minimum_de_parties(): void
    {
        $belowThreshold = User::factory()->create();
        $aboveThreshold = User::factory()->create();

        // 1 seule partie gagnée : 100% mais sous le seuil, doit être exclu.
        $game1 = $this->finishedGameAt(now(), 'villagers');
        GamePlayer::factory()->villager()->create(['game_id' => $game1->id, 'user_id' => $belowThreshold->id]);

        // 3 parties, 2 victoires : au seuil, doit apparaître avec un taux de 66.7%.
        for ($i = 0; $i < 3; $i++) {
            $winnerTeam = $i < 2 ? 'villagers' : 'werewolves';
            $game = $this->finishedGameAt(now(), $winnerTeam);
            GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'user_id' => $aboveThreshold->id]);
        }

        $rows = $this->service->topWinRate('current_month');

        $this->assertCount(1, $rows);
        $this->assertSame($aboveThreshold->id, $rows->first()->id);
        $this->assertEquals(66.7, (float) $rows->first()->win_rate);
    }

    public function test_filtre_periode_isole_le_mois_courant_du_mois_precedent(): void
    {
        $currentMonthUser  = User::factory()->create();
        $previousMonthUser = User::factory()->create();

        $gameCurrent  = $this->finishedGameAt(now(), 'villagers');
        $gamePrevious = $this->finishedGameAt(now()->subMonthNoOverflow(), 'villagers');
        GamePlayer::factory()->villager()->create(['game_id' => $gameCurrent->id, 'user_id' => $currentMonthUser->id]);
        GamePlayer::factory()->villager()->create(['game_id' => $gamePrevious->id, 'user_id' => $previousMonthUser->id]);

        $currentRows  = $this->service->topWins('current_month');
        $previousRows = $this->service->topWins('previous_month');
        $allRows      = $this->service->topWins('all');

        $this->assertSame([$currentMonthUser->id], $currentRows->pluck('id')->all());
        $this->assertSame([$previousMonthUser->id], $previousRows->pluck('id')->all());
        $this->assertCount(2, $allRows);
    }
}
