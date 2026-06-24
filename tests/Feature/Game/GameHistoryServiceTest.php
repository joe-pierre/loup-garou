<?php

namespace Tests\Feature\Game;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\HistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_timeline_retourne_election_plus_finish_pour_partie_sans_rounds(): void
    {
        $game    = Game::factory()->create(['status' => 'finished', 'winner_team' => 'villagers', 'round' => 0]);
        $players = GamePlayer::factory()->count(2)->create(['game_id' => $game->id])->keyBy('id');
        $actions = collect();

        $service  = new HistoryService();
        $timeline = $service->buildTimeline($game, $players, $actions);

        $types = array_column($timeline, 'type');
        $this->assertContains('finish', $types);
    }

    /**
     * Régression : DECISIONS.md "Nuits sans action absentes de la timeline + succession maire
     * sans filtre de phase". Vérifie que :
     *   - une succession 'night' n'apparaît PAS dans le bloc 'day' du même round
     *   - une succession 'day' n'apparaît PAS dans le bloc 'night' du même round
     *   - deux successions sur des rounds différents n'interfèrent pas l'une avec l'autre
     *   - les actions de sorcière (witch_heal, witch_kill) sont correctement positionnées
     */
    public function test_history_with_multiple_successions_and_rounds(): void
    {
        $game = Game::factory()->create([
            'status'      => 'finished',
            'winner_team' => 'werewolves',
            'round'       => 3,
        ]);

        // 8 joueurs (on les keybe par id pour HistoryService::playerSnapshot())
        $wolf    = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);
        $mayor1  = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $mayor2  = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $mayor3  = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $witch   = GamePlayer::factory()->witch()->create(['game_id' => $game->id]);
        $target1 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target2 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);
        $target3 = GamePlayer::factory()->villager()->create(['game_id' => $game->id]);

        $players = $game->players()->get()->keyBy('id');

        $actions = collect([
            // Élection du maire (round 0)
            GameAction::factory()->create([
                'game_id'          => $game->id,
                'player_id'        => $mayor1->id,
                'type'             => 'mayor_vote',
                'target_player_id' => $mayor1->id,
                'round'            => 0,
                'phase'            => 'election',
            ]),

            // Round 1 — Nuit : loup tue target1
            GameAction::factory()->create([
                'game_id'          => $game->id,
                'player_id'        => $wolf->id,
                'type'             => 'night_vote',
                'target_player_id' => $target1->id,
                'round'            => 1,
                'phase'            => 'night',
            ]),
            // Round 1 — Nuit : succession 'night' (mayor1 mort la nuit → mayor2 prend le relais)
            GameAction::factory()->create([
                'game_id'          => $game->id,
                'player_id'        => $mayor1->id,
                'type'             => 'mayor_succession',
                'target_player_id' => $mayor2->id,
                'round'            => 1,
                'phase'            => 'night',
            ]),
            // Round 1 — Nuit : sorcière sauve target1
            GameAction::factory()->create([
                'game_id'          => $game->id,
                'player_id'        => $witch->id,
                'type'             => 'witch_heal',
                'target_player_id' => $target1->id,
                'round'            => 1,
                'phase'            => 'night',
            ]),

            // Round 1 — Jour : vote jour élimine target2
            GameAction::factory()->create([
                'game_id'          => $game->id,
                'player_id'        => $wolf->id,
                'type'             => 'day_vote',
                'target_player_id' => $target2->id,
                'weight'           => 1,
                'round'            => 1,
                'phase'            => 'day',
            ]),

            // Round 2 — Nuit : loup tue target3, sorcière tue wolf via potion
            GameAction::factory()->create([
                'game_id'          => $game->id,
                'player_id'        => $wolf->id,
                'type'             => 'night_vote',
                'target_player_id' => $target3->id,
                'round'            => 2,
                'phase'            => 'night',
            ]),
            GameAction::factory()->create([
                'game_id'          => $game->id,
                'player_id'        => $witch->id,
                'type'             => 'witch_kill',
                'target_player_id' => $wolf->id,
                'round'            => 2,
                'phase'            => 'night',
            ]),

            // Round 2 — Jour : succession 'day' (mayor2 éliminé au vote → mayor3 prend le relais)
            GameAction::factory()->create([
                'game_id'          => $game->id,
                'player_id'        => $mayor2->id,
                'type'             => 'mayor_succession',
                'target_player_id' => $mayor3->id,
                'round'            => 2,
                'phase'            => 'day',
            ]),

            // Round 3 — Nuit : pas d'accord entre les loups (aucun night_vote)
        ]);

        $service  = new HistoryService();
        $timeline = $service->buildTimeline($game, $players, $actions);

        // Extraction par type + round pour les assertions
        $election = collect($timeline)->firstWhere('type', 'election');
        $nights   = collect($timeline)->where('type', 'night')->keyBy('round');
        $days     = collect($timeline)->where('type', 'day')->keyBy('round');
        $finish   = collect($timeline)->firstWhere('type', 'finish');

        // Vérifications de structure générale
        $this->assertNotNull($election, 'Entrée election absente de la timeline');
        $this->assertNotNull($finish, 'Entrée finish absente de la timeline');

        // Round 1 nuit — succession NIGHT présente, avec former_mayor=mayor1 et new_mayor=mayor2
        $night1 = $nights->get(1);
        $this->assertNotNull($night1, 'Nuit round 1 absente');
        $this->assertNotNull($night1['succession'], 'Succession night round 1 absente');
        $this->assertSame($mayor1->id, $night1['succession']['former_mayor']['id']);
        $this->assertSame($mayor2->id, $night1['succession']['new_mayor']['id']);

        // Round 1 nuit — sorcière a soigné target1
        $this->assertNotNull($night1['witch_heal'], 'witch_heal round 1 absent');
        $this->assertSame($target1->id, $night1['witch_heal']['id']);

        // Round 1 jour — PAS de succession (elle était en phase 'night')
        $day1 = $days->get(1);
        $this->assertNotNull($day1, 'Jour round 1 absent');
        $this->assertArrayNotHasKey('succession', $day1, 'La succession night ne doit pas apparaître dans le bloc day');

        // Round 1 jour — contient un résultat de vote
        $this->assertSame('eliminated', $day1['result']);
        $this->assertSame($target2->id, $day1['eliminated']['id']);

        // Round 2 nuit — sorcière a tué wolf, PAS de succession night
        $night2 = $nights->get(2);
        $this->assertNotNull($night2, 'Nuit round 2 absente');
        $this->assertNull($night2['succession'], 'La succession day round 2 ne doit pas apparaître dans le bloc night');
        $this->assertNotNull($night2['witch_kill'], 'witch_kill round 2 absent');
        $this->assertSame($wolf->id, $night2['witch_kill']['id']);

        // Round 2 jour — succession DAY présente, avec former_mayor=mayor2 et new_mayor=mayor3
        $day2 = $days->get(2);
        $this->assertNotNull($day2, 'Jour round 2 absent');
        $this->assertNotNull($day2['succession'], 'Succession day round 2 absente');
        $this->assertSame($mayor2->id, $day2['succession']['former_mayor']['id']);
        $this->assertSame($mayor3->id, $day2['succession']['new_mayor']['id']);

        // Round 3 nuit — pas d'accord (wolf_no_agreement = true, aucun night_vote)
        $night3 = $nights->get(3);
        $this->assertNotNull($night3, 'Nuit round 3 absente (nuit sans action doit quand même figurer)');
        $this->assertTrue($night3['wolf_no_agreement'], 'wolf_no_agreement doit être true pour une nuit sans vote');
        $this->assertNull($night3['succession'], 'Pas de succession round 3 nuit');

        // Les deux successions ne doivent pas interférer : round 1 nuit ≠ round 2 jour
        $this->assertNotEquals(
            $night1['succession']['former_mayor']['id'],
            $day2['succession']['former_mayor']['id'],
            'Les deux successions ont le même ancien maire — elles interfèrent'
        );
    }

    /**
     * Le détail des voix par candidat (vote_totals) est présent dans l'entrée election
     * et respecte : triée par voix décroissantes, uniquement les candidats avec ≥ 1 voix.
     */
    public function test_election_timeline_contient_le_detail_des_votes_par_candidat(): void
    {
        $game = Game::factory()->create([
            'status'      => 'finished',
            'winner_team' => 'villagers',
            'round'       => 1,
        ]);

        $candidateA = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'pseudo' => 'Alice']);
        $candidateB = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'pseudo' => 'Bob']);
        $candidateC = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'pseudo' => 'Charlie']);
        $noVotes    = GamePlayer::factory()->villager()->create(['game_id' => $game->id, 'pseudo' => 'Dave']);
        $wolf       = GamePlayer::factory()->werewolf()->create(['game_id' => $game->id]);

        $players = $game->players()->get()->keyBy('id');

        // Alice reçoit 3 voix, Bob 2 voix, Charlie 1 voix, Dave 0 voix
        $votes = [];
        foreach ([$candidateA, $candidateA, $candidateA] as $target) {
            $votes[] = GameAction::factory()->create([
                'game_id'          => $game->id,
                'player_id'        => $wolf->id,
                'type'             => 'mayor_vote',
                'target_player_id' => $target->id,
                'round'            => 0,
                'phase'            => 'election',
            ]);
        }
        foreach ([$candidateB, $candidateB] as $target) {
            $votes[] = GameAction::factory()->create([
                'game_id'          => $game->id,
                'player_id'        => $candidateA->id,
                'type'             => 'mayor_vote',
                'target_player_id' => $target->id,
                'round'            => 0,
                'phase'            => 'election',
            ]);
        }
        GameAction::factory()->create([
            'game_id'          => $game->id,
            'player_id'        => $candidateB->id,
            'type'             => 'mayor_vote',
            'target_player_id' => $candidateC->id,
            'round'            => 0,
            'phase'            => 'election',
        ]);

        $actions = GameAction::where('game_id', $game->id)->get();

        $service  = new HistoryService();
        $timeline = $service->buildTimeline($game, $players, $actions);

        $election = collect($timeline)->firstWhere('type', 'election');

        $this->assertNotNull($election, 'Entrée election absente de la timeline');
        $this->assertArrayHasKey('vote_totals', $election, 'vote_totals absent de l\'entrée election');

        $voteTotals = $election['vote_totals'];

        // Exactement 3 candidats ayant reçu au moins 1 voix
        $this->assertCount(3, $voteTotals, 'vote_totals doit contenir exactement 3 candidats (Dave exclut)');

        // Triés par voix décroissantes
        $this->assertSame('Alice',   $voteTotals[0]['pseudo']);
        $this->assertSame(3,         $voteTotals[0]['vote_count']);
        $this->assertSame('Bob',     $voteTotals[1]['pseudo']);
        $this->assertSame(2,         $voteTotals[1]['vote_count']);
        $this->assertSame('Charlie', $voteTotals[2]['pseudo']);
        $this->assertSame(1,         $voteTotals[2]['vote_count']);

        // Dave n'apparaît pas dans vote_totals
        $pseudos = array_column($voteTotals, 'pseudo');
        $this->assertNotContains('Dave', $pseudos, 'Dave (0 voix) ne doit pas figurer dans vote_totals');

        // Alice est bien le maire élu (3 voix = majorité)
        $this->assertSame('Alice', $election['mayor']['pseudo']);
        $this->assertSame(3, $election['vote_count']);
        $this->assertFalse($election['was_random']);

        // vote_details présent avec le détail individuel de chaque vote
        $this->assertArrayHasKey('vote_details', $election, 'vote_details absent de l\'entrée election');
        $voteDetails = $election['vote_details'];

        // 6 votes au total : 3 (wolf→Alice) + 2 (Alice→Bob) + 1 (Bob→Charlie)
        $this->assertCount(6, $voteDetails, 'vote_details doit contenir 6 entrées (3+2+1)');

        // Alice a voté pour Bob
        $aliceVotes = array_values(array_filter($voteDetails, fn ($vd) => $vd['voter_pseudo'] === 'Alice'));
        $this->assertNotEmpty($aliceVotes, 'Aucun vote d\'Alice trouvé dans vote_details');
        $this->assertSame('Bob', $aliceVotes[0]['target_pseudo'], 'Alice doit avoir voté pour Bob');

        // Bob a voté pour Charlie
        $bobVotes = array_values(array_filter($voteDetails, fn ($vd) => $vd['voter_pseudo'] === 'Bob'));
        $this->assertNotEmpty($bobVotes, 'Aucun vote de Bob trouvé dans vote_details');
        $this->assertSame('Charlie', $bobVotes[0]['target_pseudo'], 'Bob doit avoir voté pour Charlie');
    }
}
