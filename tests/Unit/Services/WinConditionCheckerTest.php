<?php

namespace Tests\Unit\Services;

use App\Models\Game;
use App\Models\GameAction;
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

    // ------------------------------------------------------------------
    // Tir du Chasseur en attente (voir DECISIONS.md "Victoire Loups
    // déclarée avant résolution du tir du Chasseur", partie MGUIVJ) :
    // la victoire Loups/Village ne doit jamais être tranchée tant qu'un
    // hunter_pending existe pour le round courant — un tir peut encore
    // changer la parité. La victoire Amoureux reste, elle, immédiate.
    // ------------------------------------------------------------------

    public function test_parite_loups_avec_hunter_pending_en_attente_reporte_la_victoire(): void
    {
        $game = $this->makeGame('night');

        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        $hunter = GamePlayer::factory()->hunter()->create(['game_id' => $game->id, 'is_alive' => false]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);

        // 2 loups vs 2 autres : parité atteinte, mais le Chasseur n'a pas encore tiré.
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $hunter->id, 'type' => 'hunter_pending',
            'round' => 1, 'phase' => 'night',
        ]);

        $result = app(WinConditionChecker::class)->check($game);

        $this->assertFalse($result, 'La victoire Loups ne doit pas être déclarée tant que le hunter_pending du round n\'est pas résolu.');
        $this->assertSame('night', $game->fresh()->status);
        $this->assertNull($game->fresh()->winner_team);
    }

    public function test_awaiting_hunter_id_reporte_la_victoire_meme_sans_hunter_pending_en_base(): void
    {
        // Reproduit l'état exact vu par ProcessHunterTurn::handle() : l'appelant
        // (ProcessNightEnd / VoteService::dispatchDayVoteConsequences) a déjà supprimé
        // le hunter_pending pour éviter un double dispatch, avant de dispatcher ce Job.
        $game = $this->makeGame('processing_night');

        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        $hunter = GamePlayer::factory()->hunter()->create(['game_id' => $game->id, 'is_alive' => false]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);

        $this->assertDatabaseMissing('game_actions', ['game_id' => $game->id, 'type' => 'hunter_pending']);

        $result = app(WinConditionChecker::class)->check($game, awaitingHunterId: $hunter->id);

        $this->assertFalse($result, 'awaitingHunterId doit reporter la victoire même sans ligne hunter_pending en base.');
        $this->assertSame('processing_night', $game->fresh()->status);
    }

    public function test_tir_du_chasseur_resolu_sur_un_loup_annule_la_victoire_loups_en_attente(): void
    {
        $game = $this->makeGame('night');

        $wolfA = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->hunter()->create(['game_id' => $game->id, 'is_alive' => false]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);

        // Le Chasseur a tiré sur un loup : hunter_pending consommé (comme le fait
        // réellement ProcessNightEnd/dispatchDayVoteConsequences avant de dispatcher
        // ProcessHunterTurn), et la cible du tir est éliminée.
        $wolfA->update(['is_alive' => false]);

        $result = app(WinConditionChecker::class)->check($game);

        // 1 loup vs 2 autres : le tir a fait basculer la parité, la partie continue.
        $this->assertFalse($result);
        $this->assertSame('night', $game->fresh()->status);
    }

    public function test_chasseur_n_a_pas_change_la_parite_confirme_la_victoire_loups_apres_resolution(): void
    {
        $game = $this->makeGame('night');

        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->hunter()->create(['game_id' => $game->id, 'is_alive' => false]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);

        // Le Chasseur a expiré son timer sans tirer (ProcessHunterAutoAction) : hunter_pending
        // déjà consommé, aucune élimination supplémentaire — la parité loups reste inchangée.

        $result = app(WinConditionChecker::class)->check($game);

        $this->assertTrue($result, 'Une fois le tir du Chasseur résolu (tiré ou non), la victoire Loups en attente doit être confirmée.');
        $this->assertSame('finished', $game->fresh()->status);
        $this->assertSame('werewolves', $game->fresh()->winner_team);
    }

    public function test_victoire_amoureux_reste_immediate_malgre_hunter_pending_en_attente(): void
    {
        $game = $this->makeGame('night');

        $lover1 = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true]);
        $lover2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true]);
        $hunter = GamePlayer::factory()->hunter()->create(['game_id' => $game->id, 'is_alive' => false]);

        $this->linkLovers($lover1, $lover2);

        // Le Chasseur vient de mourir (tué par les loups le même round que la mort qui a
        // fait tomber l'effectif à 2 amoureux mutuels) — hunter_pending toujours en attente.
        GameAction::factory()->create([
            'game_id' => $game->id, 'player_id' => $hunter->id, 'type' => 'hunter_pending',
            'round' => 1, 'phase' => 'night',
        ]);

        $result = app(WinConditionChecker::class)->check($game);

        $this->assertTrue($result, 'La victoire Amoureux (SPEC_CUPIDON.md §6) doit rester prioritaire et immédiate, jamais reportée par un hunter_pending.');
        $this->assertSame('finished', $game->fresh()->status);
        $this->assertSame('lovers', $game->fresh()->winner_team);
    }
}
