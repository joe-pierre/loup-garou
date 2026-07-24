<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Collection;

class HistoryService
{
    public function buildTimeline(Game $game, Collection $players, Collection $actions): array
    {
        $timeline = [];

        // ─── ÉLECTION (round 0, phase election) ─────────────────────────────
        $mayorVotes = $actions->where('type', 'mayor_vote');
        if ($mayorVotes->isNotEmpty()) {
            $totals    = $mayorVotes->groupBy('target_player_id')->map(fn ($g) => $g->count())->sortDesc();
            $maxVotes  = $totals->first();
            $topIds    = $totals->filter(fn ($c) => $c === $maxVotes)->keys()->toArray();
            $electedId = count($topIds) === 1 ? (int) $topIds[0] : null;

            $electionVoteTotals = $totals->map(function ($count, $targetId) use ($players) {
                $snap = $this->playerSnapshot($players, (int) $targetId);
                return ['pseudo' => $snap['pseudo'], 'vote_count' => $count];
            })->values()->toArray();

            $voteDetails = $mayorVotes->map(function ($action) use ($players) {
                return [
                    'voter_pseudo'  => $this->playerSnapshot($players, $action->player_id)['pseudo'],
                    'target_pseudo' => $this->playerSnapshot($players, $action->target_player_id)['pseudo'],
                ];
            })->values()->toArray();

            $timeline[] = [
                'type'         => 'election',
                'label'        => 'Élection du Maire',
                'was_random'   => count($topIds) > 1,
                'mayor'        => $electedId ? $this->playerSnapshot($players, $electedId) : null,
                'vote_count'   => $electedId ? $totals->get($topIds[0]) : null,
                'vote_totals'  => $electionVoteTotals,
                'vote_details' => $voteDetails,
            ];
        }

        // ─── ROUNDS NUIT + JOUR ──────────────────────────────────────────────
        $maxRound = max((int) $actions->max('round'), $game->round);

        for ($round = 1; $round <= $maxRound; $round++) {
            // ── Nuit ────────────────────────────────────────────────────────
            $nightVotes      = $actions->where('type', 'night_vote')->where('round', $round);
            $witchHeal       = $actions->where('type', 'witch_heal')->where('round', $round)->first();
            $witchKill       = $actions->where('type', 'witch_kill')->where('round', $round)->first();
            $hunterNight     = $actions->where('type', 'hunter_shot')->where('round', $round)->where('phase', 'night')->first();
            $successionNight = $actions->where('type', 'mayor_succession')->where('round', $round)->where('phase', 'night')->first();

            $nightEntry = [
                'type'              => 'night',
                'round'             => $round,
                'label'             => "Nuit {$round}",
                'killed'            => null,
                'wolf_no_agreement' => false,
                'witch_heal'        => null,
                'witch_kill'        => null,
                'hunter_shot'       => null,
                'succession'        => null,
                'cupidon_couple'    => null,
            ];

            if ($nightVotes->isEmpty()) {
                $nightEntry['wolf_no_agreement'] = true;
            } else {
                $totals = $nightVotes->groupBy('target_player_id')->map(fn ($g) => $g->count())->sortDesc();
                $maxV   = $totals->first();
                $topIds = $totals->filter(fn ($c) => $c === $maxV)->keys()->toArray();

                if (count($topIds) > 1) {
                    $nightEntry['wolf_no_agreement'] = true;
                } else {
                    $nightEntry['killed'] = $this->playerSnapshot($players, (int) $topIds[0]);
                }
            }

            if ($witchHeal?->target_player_id) {
                $nightEntry['witch_heal'] = $this->playerSnapshot($players, $witchHeal->target_player_id);
            }
            if ($witchKill?->target_player_id) {
                $nightEntry['witch_kill'] = $this->playerSnapshot($players, $witchKill->target_player_id);
            }
            if ($hunterNight?->target_player_id) {
                $hunter = $players->firstWhere('role', 'hunter');
                $nightEntry['hunter_shot'] = [
                    'hunter_pseudo' => $hunter?->pseudo ?? '?',
                    'target'        => $this->playerSnapshot($players, $hunterNight->target_player_id),
                ];
            }
            if ($successionNight?->target_player_id) {
                $nightEntry['succession'] = [
                    'former_mayor' => $successionNight->player_id ? $this->playerSnapshot($players, $successionNight->player_id) : null,
                    'new_mayor'    => $this->playerSnapshot($players, $successionNight->target_player_id),
                ];
            }

            // Cupidon n'agit qu'au round 1 — 2 GameAction cupidon_link (une par amoureux) regroupées en une paire
            if ($round === 1) {
                $cupidonLinks = $actions->where('type', 'cupidon_link')->where('round', 1)->values();
                if ($cupidonLinks->count() === 2) {
                    $nightEntry['cupidon_couple'] = [
                        'player1' => $this->playerSnapshot($players, $cupidonLinks[0]->target_player_id),
                        'player2' => $this->playerSnapshot($players, $cupidonLinks[1]->target_player_id),
                    ];
                }
            }

            $timeline[] = $nightEntry;

            // ── Jour ─────────────────────────────────────────────────────────
            $dayVotes  = $actions->where('type', 'day_vote')->where('round', $round);
            $hunterDay = $actions->where('type', 'hunter_shot')->where('round', $round)->where('phase', 'day')->first();

            $dayEntry = [
                'type'         => 'day',
                'round'        => $round,
                'label'        => "Jour {$round}",
                'vote_totals'  => [],
                'vote_details' => [],
                'hunter_shot'  => null,
            ];

            if ($dayVotes->isNotEmpty()) {
                $totals    = $dayVotes->groupBy('target_player_id')->map(fn ($g) => $g->sum('weight'))->sortDesc();
                $maxWeight = $totals->first();
                $topIds    = $totals->filter(fn ($w) => $w === $maxWeight)->keys()->toArray();

                $dayEntry['vote_totals'] = $totals->map(function ($weight, $targetId) use ($players) {
                    $snap = $this->playerSnapshot($players, (int) $targetId);
                    return ['pseudo' => $snap['pseudo'], 'vote_count' => $weight];
                })->values()->toArray();

                $dayEntry['vote_details'] = $dayVotes->map(function ($action) use ($players) {
                    return [
                        'voter_pseudo'  => $this->playerSnapshot($players, $action->player_id)['pseudo'],
                        'target_pseudo' => $this->playerSnapshot($players, $action->target_player_id)['pseudo'],
                    ];
                })->values()->toArray();

                if (count($topIds) > 1) {
                    $dayEntry['result']     = 'equality';
                    $dayEntry['eliminated'] = null;
                } else {
                    $dayEntry['result']     = 'eliminated';
                    $dayEntry['eliminated'] = $this->playerSnapshot($players, (int) $topIds[0]);
                }
            } else {
                $randomElim = $actions->where('type', 'random_elimination')->where('round', $round)->first();
                $dayEntry['result']     = 'no_votes';
                $dayEntry['eliminated'] = $randomElim
                    ? $this->playerSnapshot($players, $randomElim->target_player_id)
                    : null;
            }

            // Succession maire (phase jour uniquement — la nuit est dans nightEntry)
            $successionDay = $actions->where('type', 'mayor_succession')->where('round', $round)->where('phase', 'day')->first();
            if ($successionDay?->target_player_id) {
                $dayEntry['succession'] = [
                    'former_mayor' => $successionDay->player_id ? $this->playerSnapshot($players, $successionDay->player_id) : null,
                    'new_mayor'    => $this->playerSnapshot($players, $successionDay->target_player_id),
                ];
            }

            if ($hunterDay?->target_player_id) {
                $hunter = $players->firstWhere('role', 'hunter');
                $dayEntry['hunter_shot'] = [
                    'hunter_pseudo' => $hunter?->pseudo ?? '?',
                    'target'        => $this->playerSnapshot($players, $hunterDay->target_player_id),
                ];
            }

            $timeline[] = $dayEntry;
        }

        // ─── FIN ─────────────────────────────────────────────────────────────
        $timeline[] = [
            'type'        => 'finish',
            'label'       => match ($game->winner_team) {
                'villagers'  => '🏆 Victoire du Village',
                'werewolves' => '🐺 Victoire des Loups',
                'lovers'     => '💞 Victoire des Amoureux',
                default      => '🏁 Partie annulée',
            },
            'winner_team' => $game->winner_team,
        ];

        return $timeline;
    }

    private function playerSnapshot(Collection $players, int $id): array
    {
        $p = $players->get($id);

        return [
            'id'     => $id,
            'pseudo' => $p?->pseudo ?? '?',
            'role'   => $p?->role ?? null,
        ];
    }
}
