<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Services\GameService;
use App\Services\VoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class GameController extends Controller
{
    public function __construct(
        private GameService $gameService,
        private VoteService $voteService,
    ) {}


    public function mayorElection(Request $request, string $code): View|RedirectResponse
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        if ($game->status !== 'electing_mayor') {
            return redirect()->route('game.role-reveal', ['code' => $code]);
        }

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $players = $game->alivePlayers()->get();

        $myVote = GameAction::where('game_id', $game->id)
            ->where('player_id', $player->id)
            ->where('type', 'mayor_vote')
            ->where('round', $game->round)
            ->value('target_player_id');

        $currentVotes         = $this->voteService->getMayorVoteTotals($game);
        $phaseRemainingSeconds = max(0, $game->phaseRemainingSeconds());

        return view('game.mayor-election', compact(
            'game', 'player', 'players', 'myVote', 'currentVotes', 'phaseRemainingSeconds'
        ));
    }

    public function finished(Request $request, string $code): View
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        abort_unless($game->status === 'finished' && $game->winner_team !== null, 404);

        $player  = $game->players()->where('user_id', $request->user()->id)->firstOrFail();
        $players = $game->players()->get();

        return view('game.finished', compact('game', 'player', 'players'));
    }

    public function cancelled(Request $request, string $code): View
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        abort_unless($game->status === 'finished' && $game->winner_team === null, 404);

        $player     = $game->players()->where('user_id', $request->user()->id)->firstOrFail();
        $allPlayers = $game->players()->get();

        return view('game.cancelled', compact('game', 'player', 'allPlayers'));
    }

    public function spectator(Request $request, string $code): View
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->where('is_alive', false)
            ->firstOrFail();

        $allPlayers = $game->players()->get();

        return view('game.spectator', compact('game', 'player', 'allPlayers'));
    }

    public function day(Request $request, string $code): View|RedirectResponse
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        if ($game->status !== 'day') {
            return $this->redirectToCurrentPhase($game, $code);
        }

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $players     = $game->alivePlayers()->get();
        $nightVictim = null;

        return view('game.day', compact('game', 'player', 'players', 'nightVictim'));
    }

    public function night(Request $request, string $code): View|RedirectResponse
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        if (! in_array($game->status, ['night', 'wolves_turn', 'processing_night'])) {
            return $this->redirectToCurrentPhase($game, $code);
        }

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $players = $game->alivePlayers()->get();

        return view('game.night', compact('game', 'player', 'players'));
    }

    private function redirectToCurrentPhase(Game $game, string $code): RedirectResponse
    {
        return match (true) {
            $game->status === 'day'                                                    => redirect()->route('game.day', ['code' => $code]),
            in_array($game->status, ['night', 'wolves_turn', 'processing_night'])      => redirect()->route('game.night', ['code' => $code]),
            $game->status === 'electing_mayor'                                         => redirect()->route('game.mayor-election', ['code' => $code]),
            $game->status === 'finished' && $game->winner_team !== null                => redirect()->route('game.finished', ['code' => $code]),
            $game->status === 'finished'                                               => redirect()->route('game.cancelled', ['code' => $code]),
            default                                                                    => redirect()->route('game.role-reveal', ['code' => $code]),
        };
    }

    public function history(Request $request, string $code): mixed
    {
        $game = Game::where('code', strtoupper($code))->first();

        if (! $game) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Partie introuvable.'], 404);
            }
            abort(404);
        }

        if ($game->status !== 'finished') {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'L\'historique n\'est accessible qu\'après la fin de la partie.'], 403);
            }
            abort(403, 'L\'historique n\'est accessible qu\'après la fin de la partie.');
        }

        $this->authorize('viewHistory', $game);

        $players = $game->players()->get()->keyBy('id');

        $actions = GameAction::where('game_id', $game->id)
            ->anonymized()
            ->whereIn('type', ['mayor_vote', 'night_vote', 'day_vote', 'mayor_succession'])
            ->orderBy('round')
            ->get();

        $duration = ($game->started_at && $game->finished_at)
            ? (int) $game->started_at->diffInMinutes($game->finished_at)
            : null;

        $timeline = $this->buildTimeline($game, $players, $actions);

        $myPlayer = $players->first(fn (GamePlayer $p) => $p->user_id === $request->user()->id);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'code'         => $game->code,
                    'winner_team'  => $game->winner_team,
                    'started_at'   => $game->started_at?->toIso8601String(),
                    'finished_at'  => $game->finished_at?->toIso8601String(),
                    'duration_min' => $duration,
                    'players'      => $players->values()->map(fn (GamePlayer $p) => [
                        'id'       => $p->id,
                        'pseudo'   => $p->pseudo,
                        'role'     => $p->role,
                        'is_alive' => (bool) $p->is_alive,
                        'is_mayor' => (bool) $p->is_mayor,
                    ])->values(),
                    'timeline'     => $timeline,
                ],
            ]);
        }

        return view('game.history', compact('game', 'players', 'timeline', 'duration', 'myPlayer'));
    }

    /**
     * Construit la timeline de la partie à partir des actions anonymisées.
     * Chaque nuit/jour est dérivé du résultat des votes (pluralité pour les kills).
     *
     * @param  Collection<int, GamePlayer>  $players  keyed by id
     * @param  Collection<int, GameAction>  $actions  anonymized (no player_id)
     */
    private function buildTimeline(Game $game, Collection $players, Collection $actions): array
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

    public function state(Request $request, string $code): JsonResponse
    {
        $game = Game::where('code', strtoupper($code))->first();

        if (! $game) {
            return response()->json(['success' => false, 'message' => 'Partie introuvable.'], 404);
        }

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $player) {
            return response()->json(['success' => false, 'message' => 'Tu ne participes pas à cette partie.'], 403);
        }

        $isNight        = in_array($game->status, ['night', 'wolves_turn', 'processing_night']);
        $deadlineActive = $game->phase_deadline !== null && $game->phase_deadline->isAfter(now());

        $aliveSeerExists = $isNight && $game->players()
            ->where('role', 'seer')
            ->where('is_alive', true)
            ->where('is_inactive', false)
            ->exists();

        $seerCheckDone = $isNight && GameAction::where('game_id', $game->id)
            ->where('type', 'seer_check')
            ->where('round', $game->round)
            ->exists();

        // seer_turn_active : uniquement quand status = 'night' (pas encore passé aux loups)
        $seerTurnActive       = $game->status === 'night' && $aliveSeerExists && $deadlineActive && ! $seerCheckDone;
        // werewolves_turn_active : status = 'wolves_turn' ou ('night' sans tour voyante actif)
        $werewolvesTurnActive = $isNight && ! $seerTurnActive && $deadlineActive;

        $allies = [];
        if ($player->isWerewolf()) {
            $allies = $game->players()
                ->where('id', '!=', $player->id)
                ->whereIn('role', ['werewolf', 'white_wolf'])
                ->get()
                ->map(fn (GamePlayer $p) => ['id' => $p->id, 'pseudo' => $p->pseudo])
                ->values()
                ->toArray();
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'phase'                   => $game->status,
                'round'                   => $game->round,
                'my_role'                 => $player->role,
                'is_alive'                => (bool) $player->is_alive,
                'is_mayor'                => (bool) $player->is_mayor,
                'phase_remaining_seconds' => $game->phaseRemainingSeconds(),
                'seer_turn_active'        => $seerTurnActive,
                'werewolves_turn_active'  => $werewolvesTurnActive,
                'allies'                  => $allies,
            ],
        ]);
    }

    public function quit(Request $request, int $id): JsonResponse
    {
        $game   = Game::findOrFail($id);
        $player = $game->players()->where('user_id', auth()->id())->firstOrFail();
        $this->gameService->quitGame($game, $player);
        return response()->json(['success' => true]);
    }

    public function disconnect(Request $request, int $id): JsonResponse
    {
        $game = Game::findOrFail($id);

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->first();

        if ($player) {
            $this->gameService->handleDisconnection($player);
        }

        return response()->json(['success' => true]);
    }

    public function reconnect(Request $request, string $code): JsonResponse
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $this->gameService->handleReconnection($player);

        return response()->json(['success' => true]);
    }
}