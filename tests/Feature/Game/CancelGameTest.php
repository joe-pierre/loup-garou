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
}
