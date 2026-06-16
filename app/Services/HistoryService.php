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
            $electedId = count($topIds) === 1 ? $topIds[0] : null;

            $timeline[] = [
                'type'       => 'election',
                'label'      => 'Élection du Maire',
                'was_random' => count($topIds) > 1,
                'mayor'      => $electedId ? $this->playerSnapshot($players, $electedId) : null,
            ];
        }

        // ─── ROUNDS NUIT + JOUR ──────────────────────────────────────────────
        $maxRound = max((int) $actions->max('round'), $game->round);

        for ($round = 1; $round <= $maxRound; $round++) {
            // Nuit
            $nightVotes = $actions->where('type', 'night_vote')->where('round', $round);
            if ($nightVotes->isNotEmpty()) {
                $totals   = $nightVotes->groupBy('target_player_id')->map(fn ($g) => $g->count())->sortDesc();
                $maxVotes = $totals->first();
                $topIds   = $totals->filter(fn ($c) => $c === $maxVotes)->keys()->toArray();
                // En cas d'égalité le jeu tire au sort — on affiche le premier (ordre insertion)
                $killedId = $topIds[0];

                $timeline[] = [
                    'type'   => 'night',
                    'round'  => $round,
                    'label'  => "Nuit {$round}",
                    'killed' => $this->playerSnapshot($players, $killedId),
                ];
            }

            // Jour
            $dayVotes = $actions->where('type', 'day_vote')->where('round', $round);
            $dayEntry = ['type' => 'day', 'round' => $round, 'label' => "Jour {$round}"];

            if ($dayVotes->isNotEmpty()) {
                $totals    = $dayVotes->groupBy('target_player_id')->map(fn ($g) => $g->sum('weight'))->sortDesc();
                $maxWeight = $totals->first();
                $topIds    = $totals->filter(fn ($w) => $w === $maxWeight)->keys()->toArray();

                if (count($topIds) > 1) {
                    $dayEntry['result']     = 'equality';
                    $dayEntry['eliminated'] = null;
                } else {
                    $dayEntry['result']     = 'eliminated';
                    $dayEntry['eliminated'] = $this->playerSnapshot($players, $topIds[0]);
                }
            } else {
                $dayEntry['result']     = 'no_vote';
                $dayEntry['eliminated'] = null;
            }

            // Succession maire (même round)
            $succession = $actions->where('type', 'mayor_succession')->where('round', $round)->first();
            if ($succession?->target_player_id) {
                $dayEntry['succession'] = $this->playerSnapshot($players, $succession->target_player_id);
            }

            $timeline[] = $dayEntry;
        }

        // ─── FIN ─────────────────────────────────────────────────────────────
        $timeline[] = [
            'type'        => 'finish',
            'label'       => match ($game->winner_team) {
                'villagers'  => '🏆 Victoire du Village',
                'werewolves' => '🐺 Victoire des Loups',
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
