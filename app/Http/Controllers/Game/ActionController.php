<?php

namespace App\Http\Controllers\Game;

use App\Events\Game\HunterShot;
use App\Events\Game\MayorSuccessionDone;
use App\Events\Game\MayorSuccessionStarted;
use App\Events\Game\PlayerEliminated;
use App\Events\Game\SeerResult;
use App\Events\Game\WitchActed;
use App\Events\Game\WitchActedPublic;
use App\Http\Controllers\Controller;
use App\Http\Requests\HunterShootRequest;
use App\Http\Requests\MayorSuccessionRequest;
use App\Http\Requests\SeerCheckRequest;
use App\Http\Requests\WitchActRequest;
use App\Jobs\ProcessHunterAutoAction;
use App\Jobs\ProcessMayorSuccession;
use App\Jobs\ProcessNightEnd;
use App\Jobs\ProcessWerewolvesTurn;
use App\Jobs\ProcessWitchAutoAction;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\GameService;
use App\Services\PhaseGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActionController extends Controller
{
    public function __construct(private GameService $gameService) {}

    public function ready(Request $request, int $id): JsonResponse
    {
        $player = GamePlayer::where('game_id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $this->gameService->markReady($player);

        return response()->json(['success' => true, 'data' => []]);
    }

    public function seerCheck(SeerCheckRequest $request, int $id): JsonResponse
    {
        $seer = GamePlayer::where('game_id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $target = $this->gameService->seerCheck($seer, $request->validated('target_player_id'));

        broadcast(new SeerResult($seer->game, $seer, $target));

        // La voyante a agi manuellement → passer immédiatement aux loups
        // ProcessWerewolvesTurn a son propre guard status='night' + round → pas de double-fire
        ProcessWerewolvesTurn::dispatch($seer->game_id, $seer->game->round);

        return response()->json([
            'success' => true,
            'data'    => [
                'target_player_id' => $target->id,
                'pseudo'           => $target->pseudo,
                'role'             => $target->role,
            ],
        ]);
    }

    public function witchAct(WitchActRequest $request, int $id): JsonResponse
    {
        $witch = GamePlayer::where('game_id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $result = $this->gameService->witchAct(
            $witch,
            $request->validated('action'),
            $request->validated('target_player_id'),
        );

        broadcast(new WitchActed($witch->game, $witch, $result['action'], $result['target']));

        if ($result['action'] === 'kill' && $result['target']) {
            $result['target']->load('user');
            broadcast(new PlayerEliminated($witch->game, $result['target'], 'witch_kill'));
        }

        // Notification publique : révèle le pseudo de la cible pour l'empoisonnement
        if ($result['action'] !== 'pass') {
            $targetPseudo = $result['action'] === 'kill' ? $result['target']?->pseudo : null;
            broadcast(new WitchActedPublic($witch->game, $targetPseudo));
        }

        // Délai de 2s pour garantir que la transaction witchAct() est committée avant la lecture du guard
        ProcessWitchAutoAction::dispatch($witch->game_id, $witch->game->round)
            ->delay(now()->addSeconds(2));

        return response()->json([
            'success' => true,
            'data'    => $result['action'] === 'pass' ? [] : ['target_player_id' => $result['target']?->id],
        ]);
    }

    public function hunterShoot(HunterShootRequest $request, int $id): JsonResponse
    {
        $hunter = GamePlayer::where('game_id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $fromNight = PhaseGuard::isNightOrProcessing($hunter->game);
        // Lire is_mayor AVANT le tir — la succession doit avoir lieu après, pas avant.
        $isMayor = $hunter->is_mayor;

        $target = $this->gameService->hunterShoot($hunter, $request->validated('target_player_id'));
        $target->load('user');

        broadcast(new PlayerEliminated($hunter->game, $target, 'hunter_shot'));
        broadcast(new HunterShot($hunter->game, $hunter, $target));

        // Chasseur-Maire : déclencher la succession APRÈS le tir, avant la transition de phase.
        // ProcessHunterAutoAction::delay(0) verra hunter_shot en DB → guard l'empêche de re-déclencher.
        if ($isMayor) {
            $successionDelay = $hunter->is_inactive ? 0 : $hunter->game->timer('mayor_succession');
            broadcast(new MayorSuccessionStarted($hunter->game, $hunter->pseudo));

            if ($fromNight) {
                // Nuit : shouldStartNight=true (ProcessNightEnd prend le relais).
                ProcessMayorSuccession::dispatch($hunter->game_id, $hunter->game->round, true)
                    ->delay(now()->addSeconds($successionDelay));
                ProcessNightEnd::dispatch($hunter->game_id, $hunter->game->round)
                    ->delay(now()->addSeconds($successionDelay + 5));
            } else {
                // Jour : ProcessMayorSuccession appelle startNight() après la succession.
                ProcessMayorSuccession::dispatch($hunter->game_id, $hunter->game->round, false)
                    ->delay(now()->addSeconds($successionDelay));
            }
        }

        // Le tir est résolu immédiatement → ProcessHunterAutoAction voit hunter_shot en DB
        // et retourne sans re-déclencher la succession (guard "alreadyShot").
        ProcessHunterAutoAction::dispatch($hunter->game_id, $hunter->game->round, $hunter->id, $fromNight, $isMayor)->delay(0);

        return response()->json(['success' => true, 'data' => ['target_player_id' => $target->id]]);
    }

    public function mayorSuccession(MayorSuccessionRequest $request, int $id): JsonResponse
    {
        $mayor = GamePlayer::where('game_id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $target = $this->gameService->mayorSuccessionByPlayer($mayor, $request->validated('target_player_id'));

        broadcast(new MayorSuccessionDone($mayor->game->fresh(), $target, false));

        return response()->json(['success' => true, 'data' => []]);
    }

    public function roleReveal(Request $request, string $code): View
    {
        $game = Game::where('code', strtoupper($code))->firstOrFail();

        // PhaseGuard ne couvre pas ce cas : statuts canoniques uniquement (hors processing) pour roleReveal
        if (! in_array($game->status, ['electing_mayor', 'night', 'day'])) {
            abort(404, 'Rôles non encore distribués.');
        }

        $player = $game->players()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $allies = [];
        if ($player->isWerewolf()) {
            $allies = $game->players()
                ->where('id', '!=', $player->id)
                ->get()
                ->filter(fn (GamePlayer $p) => $p->isWerewolf())
                ->map(fn (GamePlayer $p) => ['id' => $p->id, 'pseudo' => $p->pseudo])
                ->values()
                ->toArray();
        }

        $nbReady = $game->players()->where('is_ready', true)->count();
        $total   = $game->players()->count();

        return view('game.role-reveal', compact('game', 'player', 'allies', 'nbReady', 'total'));
    }
}