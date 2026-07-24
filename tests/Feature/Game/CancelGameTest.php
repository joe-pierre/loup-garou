<?php

namespace Tests\Feature\Game;

use App\Events\Game\GameFinished;
use App\Events\Game\PhaseAnnouncement;
use App\Jobs\CheckReconnectionTimeout;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Services\GameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CancelGameTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────────
    // Annulation directe via GameService::cancelGame()
    // ──────────────────────────────────────────────────────────────────────────

    public function test_annulation_si_plus_50_pourcent_inactifs_via_cancel_game_direct(): void
    {
        Event::fake();

        $game = Game::factory()->create([
            'status'      => 'day',
            'max_players' => 6,
            'round'       => 1,
        ]);

        // 6 joueurs : 4 inactifs = 66% > 50%
        GamePlayer::factory()->count(4)->villager()->create([
            'game_id'     => $game->id,
            'is_inactive' => true,
            'is_alive'    => true,
        ]);
        GamePlayer::factory()->count(2)->werewolf()->create([
            'game_id'     => $game->id,
            'is_inactive' => false,
            'is_alive'    => true,
        ]);

        app(GameService::class)->cancelGame($game);

        $fresh = $game->fresh();
        $this->assertSame('finished', $fresh->status);
        $this->assertNull($fresh->winner_team);
        $this->assertNotNull($fresh->finished_at);

        Event::assertDispatched(GameFinished::class, function (GameFinished $e) {
            return $e->winnerTeam === null;
        });
    }

    public function test_annulation_si_plus_50_pourcent_inactifs_via_check_reconnection_timeout(): void
    {
        Event::fake();
        Queue::fake();

        $game = Game::factory()->create([
            'status'      => 'night',
            'max_players' => 6,
            'round'       => 1,
        ]);

        // 3 joueurs inactifs sur 6 vivants = exactement 50% → pas d'annulation
        // 4 joueurs inactifs sur 6 vivants = 66% → annulation
        $users = User::factory()->count(6)->create();

        $players = collect();
        foreach ($users as $i => $user) {
            $players->push(GamePlayer::factory()->villager()->create([
                'game_id'     => $game->id,
                'user_id'     => $user->id,
                'is_inactive' => $i < 3, // les 3 premiers déjà inactifs
                'is_alive'    => true,
            ]));
        }

        // Le 4e joueur vient de se déconnecter ; on simule le timeout
        $triggerPlayer = $players->get(3);
        $token = 'test-token-xyz';
        Cache::put("player_disconnected.{$triggerPlayer->id}", $token, now()->addSeconds(60));

        $job = new CheckReconnectionTimeout($triggerPlayer->id, $token);
        $job->handle(app(GameService::class));

        // Le 4e joueur est maintenant inactif → 4/6 inactifs → annulation
        $this->assertTrue($triggerPlayer->fresh()->is_inactive);

        $fresh = $game->fresh();
        $this->assertSame('finished', $fresh->status);
        $this->assertNull($fresh->winner_team);

        Event::assertDispatched(GameFinished::class, fn (GameFinished $e) => $e->winnerTeam === null);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GameFinished ne révèle pas les rôles si annulée
    // ──────────────────────────────────────────────────────────────────────────

    public function test_game_finished_ne_revele_pas_roles_si_annule(): void
    {
        Event::fake();

        $game = Game::factory()->create([
            'status'      => 'day',
            'max_players' => 6,
            'round'       => 1,
        ]);

        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_inactive' => true, 'is_alive' => true]);
        GamePlayer::factory()->seer()->create(['game_id' => $game->id, 'is_inactive' => true, 'is_alive' => true]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_inactive' => true, 'is_alive' => true]);
        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_inactive' => true, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_inactive' => false, 'is_alive' => true]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_inactive' => false, 'is_alive' => true]);

        app(GameService::class)->cancelGame($game);

        Event::assertDispatched(GameFinished::class, function (GameFinished $event) {
            // Annulation : winner_team null → tous les rôles doivent être null
            foreach ($event->players as $playerData) {
                if ($playerData['role'] !== null) {
                    return false;
                }
            }
            return $event->winnerTeam === null;
        });
    }

    public function test_game_finished_revele_roles_si_victoire_normale(): void
    {
        // Contraste avec test_game_finished_ne_revele_pas_roles_si_annule() :
        // quand winner_team !== null, les rôles DOIVENT être révélés dans GameFinished
        Event::fake();

        $game = Game::factory()->create([
            'status'      => 'finished',
            'winner_team' => 'villagers',
            'round'       => 1,
        ]);

        $players = GamePlayer::factory()->count(2)->villager()->create(['game_id' => $game->id]);
        $players = $game->players()->get();

        // Simuler la diffusion d'un GameFinished de victoire normale
        broadcast(new GameFinished($game, $players, 'villagers'));

        Event::assertDispatched(GameFinished::class, function (GameFinished $event) {
            // Victoire : winner_team non null → les rôles doivent être présents
            foreach ($event->players as $playerData) {
                if ($playerData['role'] === null) {
                    return false;
                }
            }
            return $event->winnerTeam === 'villagers';
        });
    }

    public function test_cancel_game_no_op_si_partie_deja_terminee(): void
    {
        Event::fake();

        $game = Game::factory()->create([
            'status'      => 'finished',
            'winner_team' => 'werewolves',
            'finished_at' => now(),
        ]);

        app(GameService::class)->cancelGame($game);

        // Pas de second broadcast — la partie est déjà terminée (guard whereNotIn)
        Event::assertNotDispatched(PhaseAnnouncement::class);
        Event::assertNotDispatched(GameFinished::class);
    }

    public function test_cancel_game_no_op_si_partie_en_attente(): void
    {
        Event::fake();

        $game = Game::factory()->create(['status' => 'waiting']);

        app(GameService::class)->cancelGame($game);

        // Guard : whereNotIn('status', ['finished', 'waiting']) → no-op
        Event::assertNotDispatched(GameFinished::class);
        $this->assertSame('waiting', $game->fresh()->status);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Reproduction bug prod RIQPAZ (2026-07-24) : course entre cancelGame() et
    // une résolution de vote/nuit déjà en cours (statut intermédiaire
    // 'processing_day'/'processing_night'), voir DECISIONS.md.
    //
    // CheckReconnectionTimeout::handle() ne se déclenche QUE si le statut lu
    // (hors verrou) est dans ['night', 'day', 'electing_mayor'] — 'processing_day'
    // et 'processing_night' en sont explicitement exclus, signe que l'intention est
    // bien de ne jamais annuler une partie en cours de résolution. Mais ce guard est
    // lu AVANT le verrou, et cancelGame() lui-même ne le revalide pas dans sa propre
    // transaction lockForUpdate() — son guard n'exclut que ['finished', 'waiting'].
    // Un CheckReconnectionTimeout dont la lecture initiale a eu lieu pendant que le
    // statut était encore 'day'/'night' peut donc atteindre cancelGame() APRÈS que
    // la résolution en cours soit passée à 'processing_day'/'processing_night' (ex.
    // VoteService::resolveDayVoteWinner() a déjà éliminé la victime et posé ce
    // statut, mais WinConditionChecker::check() n'a pas encore tourné) — et
    // cancelGame() l'annule quand même, avant même que la victoire (ici : des
    // amoureux) n'ait eu la moindre chance d'être détectée.
    // ──────────────────────────────────────────────────────────────────────────

    public function test_cancel_game_annule_a_tort_une_partie_en_cours_de_resolution_vote_jour(): void
    {
        Event::fake();

        // Simule l'état exact juste après que VoteService::resolveDayVoteWinner()
        // a éliminé la victime et posé 'processing_day' — mais avant que
        // dispatchDayVoteConsequences() n'ait eu la moindre chance d'appeler
        // WinConditionChecker::check() (partie RIQPAZ : il ne reste alors plus
        // que les deux amoureux, restés vivants et actifs).
        $game = Game::factory()->create([
            'status'      => 'processing_day',
            'max_players' => 6,
            'round'       => 1,
        ]);

        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true, 'is_inactive' => false]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true, 'is_inactive' => false]);

        // cancelGame() ne devrait jamais pouvoir agir ici : la résolution est en
        // cours (le statut intermédiaire l'atteste), exactement le cas que
        // CheckReconnectionTimeout::handle() exclut lui-même explicitement via
        // in_array($game->status, ['night', 'day', 'electing_mayor']).
        app(GameService::class)->cancelGame($game);

        $fresh = $game->fresh();
        $this->assertSame('processing_day', $fresh->status, "cancelGame() ne doit jamais pouvoir annuler une partie dont la résolution est déjà en cours ('processing_day'/'processing_night') — c'est exactement la course qui a produit l'annulation à tort de la partie RIQPAZ (victoire des amoureux jamais détectée).");
        $this->assertNull($fresh->winner_team);
        Event::assertNotDispatched(GameFinished::class);
    }

    public function test_cancel_game_annule_a_tort_une_partie_en_cours_de_resolution_nuit(): void
    {
        Event::fake();

        // Même course, côté nuit : ProcessNightActions a déjà posé 'processing_night'
        // avant que WinConditionChecker::check() n'ait tourné.
        $game = Game::factory()->create([
            'status'      => 'processing_night',
            'max_players' => 6,
            'round'       => 1,
        ]);

        GamePlayer::factory()->werewolf()->create(['game_id' => $game->id, 'is_alive' => true, 'is_inactive' => false]);
        GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'is_alive' => true, 'is_inactive' => false]);

        app(GameService::class)->cancelGame($game);

        $fresh = $game->fresh();
        $this->assertSame('processing_night', $fresh->status);
        $this->assertNull($fresh->winner_team);
        Event::assertNotDispatched(GameFinished::class);
    }
}
