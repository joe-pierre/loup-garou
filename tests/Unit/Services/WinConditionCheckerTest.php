<?php

namespace Tests\Unit\Services;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\WinConditionChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WinConditionCheckerTest extends TestCase
{
    use RefreshDatabase;

    private function makeGame(string $status = 'day', int $round = 1): Game
    {
        return Game::factory()->create([
            'status'      => $status,
            'max_players' => 6,
            'round'       => $round,
        ]);
    }

    private function linkLovers(GamePlayer $a, GamePlayer $b): void
    {
        $a->update(['lover_player_id' => $b->id]);
        $b->update(['lover_player_id' => $a->id]);
    }

    public function test_deux_derniers_survivants_amoureux_villageois_declenche_victoire_amoureux(): void
    {
        $game = $this->makeGame();

        $lover1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        $lover2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => false]);

        $this->linkLovers($lover1, $lover2);

        $result = app(WinConditionChecker::class)->check($game);

        $this->assertTrue($result);
        $this->assertSame('finished', $game->fresh()->status);
        $this->assertSame('lovers', $game->fresh()->winner_team);
    }

    public function test_deux_derniers_survivants_amoureux_loup_et_villageois_declenche_victoire_amoureux(): void
    {
        $game = $this->makeGame();

        $lover1 = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        $lover2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => false]);

        $this->linkLovers($lover1, $lover2);

        $result = app(WinConditionChecker::class)->check($game);

        $this->assertTrue($result);
        $this->assertSame('lovers', $game->fresh()->winner_team);
    }

    public function test_deux_derniers_survivants_amoureux_loups_declenche_victoire_amoureux_et_pas_loups(): void
    {
        $game = $this->makeGame();

        $lover1 = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        $lover2 = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => false]);

        $this->linkLovers($lover1, $lover2);

        $result = app(WinConditionChecker::class)->check($game);

        $this->assertTrue($result);
        $this->assertSame('lovers', $game->fresh()->winner_team);
    }

    public function test_deux_derniers_survivants_non_amoureux_comportement_loups_village_inchange(): void
    {
        $game = $this->makeGame();

        // Deux villageois vivants, non amoureux → village gagne (aucune régression).
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => false]);

        $result = app(WinConditionChecker::class)->check($game);

        $this->assertTrue($result);
        $this->assertSame('villagers', $game->fresh()->winner_team);
    }

    public function test_deux_derniers_survivants_non_amoureux_loup_contre_villageois_les_loups_gagnent(): void
    {
        $game = $this->makeGame();

        // Un loup et un villageois vivants, non amoureux → loups gagnent (comportement existant).
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => false]);

        $result = app(WinConditionChecker::class)->check($game);

        $this->assertTrue($result);
        $this->assertSame('werewolves', $game->fresh()->winner_team);
    }

    public function test_plus_de_deux_survivants_amoureux_ne_declenche_pas_victoire_amoureux(): void
    {
        $game = $this->makeGame('night');

        $lover1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        $lover2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);

        $this->linkLovers($lover1, $lover2);

        $result = app(WinConditionChecker::class)->check($game);

        // 3 vivants dont 2 loups vs autres : ni amoureux (nb_vivants !== 2), ni fin de partie ici (1 loup >= 2 autres ? non).
        $this->assertFalse($result);
        $this->assertSame('night', $game->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Idempotence : ne jamais écraser une partie déjà terminée (ex. annulée
    // entre-temps par GameService::cancelGame() — voir DECISIONS.md, anomalie
    // "Annulée" vs "Les Loups ont gagné" de la partie SFICZ8).
    // ------------------------------------------------------------------

    public function test_ne_reecrit_pas_une_partie_deja_annulee(): void
    {
        $game = $this->makeGame('night');

        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);

        // La partie a déjà été annulée pour inactivité entre-temps (GameService::cancelGame()).
        $game->update(['status' => 'finished', 'winner_team' => null, 'finished_at' => now()]);

        // 1 loup vs 1 autre vivant : sans le guard d'idempotence, ceci déclarerait les loups
        // vainqueurs et écraserait l'annulation déjà persistée.
        $result = app(WinConditionChecker::class)->check($game);

        $this->assertFalse($result);
        $this->assertSame('finished', $game->fresh()->status);
        $this->assertNull($game->fresh()->winner_team, 'Le résultat de cancelGame() (partie annulée) ne doit jamais être écrasé par un check() tardif.');
    }
}
