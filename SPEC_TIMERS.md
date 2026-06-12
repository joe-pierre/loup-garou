# SPEC_TIMERS.md — Timers et actions automatiques (v1.1 → v1.2)

Fichier de contexte pour Claude Code. Lire intégralement avant toute modification des timers ou des phases.

---

## 1. PRINCIPE GÉNÉRAL

Dans le jeu Loup-Garou, chaque phase active (voyante, loups, futurs rôles spéciaux) repose sur un **timer serveur** qui agit comme filet de sécurité.

L’action volontaire du joueur (POST sur un endpoint dédié) termine la phase immédiatement et déclenche la transition vers l’étape suivante.

**Règle absolue :** une action volontaire ne doit jamais attendre la fin du timer.  
Le timer ne s’exécute que si le joueur n’a pas agi dans le délai imparti.

---

## 2. ARCHITECTURE GÉNÉRIQUE POUR UNE PHASE ACTIVE

Chaque phase active suit le même pattern, applicable à tous les rôles (voyante, loups, sorcière, chasseur, …).

### 2.1 Composants

| Rôle        | Endpoint action volontaire              | Job de fallback (timer)          | Événement déclenché après résolution |
|------------|------------------------------------------|-----------------------------------|----------------------------------------|
| Voyante     | POST /game/{id}/seer/done               | ProcessSeerAutoAction            | WerewolvesTurnStarted                 |
| Loups       | POST /game/{id}/vote/night (déjà existant) | ProcessNightAutoAction (v1.2)   | NightEnd / DayStarted                 |
| Sorcière    | POST /game/{id}/witch/act               | ProcessWitchAutoAction           | transition vers nuit / jour           |
| Chasseur    | POST /game/{id}/hunter/shoot            | ProcessHunterAutoAction          | fin de partie ou jour suivant         |

---

### 2.2 Flux type (exemple voyante)

PhaseManager::startNight() dispatche `ProcessSeerTurn` avec un délai de 0 (immédiat).

#### ProcessSeerTurn::handle()

- Vérifie que le jeu est bien en night et que le round correspond.
- Broadcast `SeerTurnStarted` sur canal privé de la voyante.
- Dispatche deux jobs :
  - `ProcessWerewolvesTurn` avec délai = `$game->timer('seer_turn')` (fallback si la voyante n’agit pas)
  - `ProcessSeerAutoAction` avec le même délai (§3.2)

---

### Cas normal (action volontaire)

La voyante agit via `POST /game/{id}/seer/done` :

- Vérifie qu’elle n’a pas déjà agi ce round.
- Enregistre son choix (`seer_check` dans game_actions).
- Supprime (ou ignore) le job `ProcessSeerAutoAction` en attente (optionnel).
- Dispatche `ProcessWerewolvesTurn` immédiatement (`delay = 0`).

---

### Cas d’inactivité

`ProcessSeerAutoAction` s’exécute après `seer_turn` secondes :

- Vérifie qu’aucun `seer_check` n’existe pour ce (game_id, round).
- Choisit une cible aléatoire parmi les joueurs vivants (hors voyante).
- Crée un `GameAction` de type `seer_check`.
- Broadcast `SeerResult` (privé voyante).
- Dispatche `ProcessWerewolvesTurn` (`delay = 0`).

---

## 3. IMPLÉMENTATION DÉTAILLÉE (VOYANTE)

---

### 3.1 Nouvel endpoint

- Route : `POST /game/{id}/seer/done`
- Controller : `ActionController@seerDone`
- Request : `SeerDoneRequest` (contient `target_player_id`)
- Service : `VoteService::registerSeerCheck($game, $player, $targetPlayer)`

#### Comportement

- Vérifie `game.status === 'night'` et tour voyante actif.
- Empêche auto-inspection.
- Enregistre l’action (`GameAction::create`, type `seer_check`).
- Dispatche `ProcessWerewolvesTurn::dispatch(...)->delay(0)`.
- Broadcast `SeerResult`.
- Ne touche pas encore à `ProcessSeerAutoAction`.

---

### 3.2 Job ProcessSeerAutoAction

```php
<?php

namespace App\Jobs;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Services\VoteService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSeerAutoAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(protected int $gameId, protected int $round) {}

    public function handle(VoteService $voteService): void
    {
        $game = Game::find($this->gameId);
        if (!$game || $game->round !== $this->round) {
            return;
        }

        $seer = $game->players()
            ->where('role', 'seer')
            ->where('is_alive', true)
            ->first();

        if (!$seer) {
            return;
        }

        $existingCheck = $game->actions()
            ->where('player_id', $seer->id)
            ->where('round', $this->round)
            ->where('type', 'seer_check')
            ->exists();

        if ($existingCheck) {
            return;
        }

        $target = $game->players()
            ->where('is_alive', true)
            ->where('id', '!=', $seer->id)
            ->inRandomOrder()
            ->first();

        if (!$target) {
            return;
        }

        \DB::transaction(function () use ($game, $seer, $target) {
            GameAction::create([
                'game_id' => $game->id,
                'player_id' => $seer->id,
                'type' => 'seer_check',
                'target_player_id' => $target->id,
                'round' => $this->round,
                'phase' => 'night',
            ]);
        });

        broadcast(new SeerResult($game, $seer, $target, $target->role));

        ProcessWerewolvesTurn::dispatch($game->id, $game->round)->delay(0);
    }
}