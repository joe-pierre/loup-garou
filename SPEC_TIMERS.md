# SPEC_TIMERS.md — Timers et actions automatiques (v1.1 → v1.2)

Fichier de contexte pour Claude Code. Lire intégralement avant toute modification des timers ou des phases.

---

## 1. PRINCIPE GÉNÉRAL

Dans le jeu Loup-Garou, chaque phase active (voyante, loups, futurs rôles spéciaux) repose sur un **timer serveur** qui agit comme filet de sécurité.

L'action volontaire du joueur (POST sur un endpoint dédié) termine la phase immédiatement et déclenche la transition vers l'étape suivante.

**Règle absolue :** une action volontaire ne doit jamais attendre la fin du timer.
Le timer ne s'exécute que si le joueur n'a pas agi dans le délai imparti.

**Règle d'accès aux timers :** toujours via `$game->timer('nom_interne')` — jamais `config('game.timers.x')` directement. `TimerCalculator` lit `$game->settings['timers']` en priorité, fallback sur `config/game.php`.

---

## 2. ARCHITECTURE GÉNÉRIQUE POUR UNE PHASE ACTIVE

Chaque phase active suit le même pattern, applicable à tous les rôles (voyante, loups, sorcière, chasseur, …).

### 2.1 Composants

| Rôle     | Endpoint action volontaire                 | Job de fallback (timer)      | Événement déclenché après résolution |
|----------|--------------------------------------------|------------------------------|--------------------------------------|
| Voyante  | POST /game/{id}/seer/done                  | ProcessSeerAutoAction        | WerewolvesTurnStarted                |
| Loups    | POST /game/{id}/vote/night (déjà existant) | ProcessNightAutoAction (v1.2)| NightEnd / DayStarted                |
| Sorcière | POST /game/{id}/witch/act                  | ProcessWitchAutoAction       | transition vers nuit / jour          |
| Chasseur | POST /game/{id}/hunter/shoot               | ProcessHunterAutoAction      | fin de partie ou jour suivant        |

### 2.2 Flux type (exemple voyante)

`PhaseManager::startNight()` dispatche `ProcessSeerTurn` avec un délai de `night_start_delay` (4s — délai technique existant, inchangé).

#### ProcessSeerTurn::handle()

- Vérifie que le jeu est bien en `night` et que le round correspond.
- Broadcast `SeerTurnStarted` sur canal privé de la voyante.
- Dispatche **un seul job** : `ProcessSeerAutoAction` avec délai = `$game->timer('seer')`.
- **Ne dispatche pas** `ProcessWerewolvesTurn` directement — c'est le rôle exclusif de `ProcessSeerAutoAction` et de l'endpoint `/seer/done`.

#### Cas normal (action volontaire)

La voyante agit via `POST /game/{id}/seer/done` :

- Vérifie le guard (phase, round, pas déjà agi).
- Enregistre son choix via `VoteService::registerSeerCheck()`.
- Dispatche `ProcessWerewolvesTurn` immédiatement (`delay(0)`).
- Broadcast `SeerResult` (privé voyante).

#### Cas d'inactivité

`ProcessSeerAutoAction` s'exécute après `$game->timer('seer')` secondes :

- Guard 1 : vérifie `game.status === 'night'` et `game.round === $this->round`.
- Guard 2 : vérifie qu'aucun `seer_check` n'existe pour ce `(game_id, round)`. Si oui → return (la voyante a déjà agi via l'endpoint).
- Si aucun `seer_check` : **ne crée rien en base**, dispatche directement `ProcessWerewolvesTurn`.
- `SeerResult` n'est **jamais** broadcasté par le job auto (pas d'action, pas de résultat).

---

## 3. IMPLÉMENTATION DÉTAILLÉE (VOYANTE)

### 3.1 Nouvel endpoint POST /game/{id}/seer/done

- **Route :** `POST /game/{id}/seer/done`
- **Controller :** `ActionController@seerDone`
- **Request :** `SeerDoneRequest` (contient `target_player_id`)
- **Service :** `VoteService::registerSeerCheck($game, $player, $targetPlayer)`

**Comportement :**

- Vérifie `game.status === 'night'` et tour voyante actif.
- Empêche l'auto-inspection (`target_player_id !== $player->id`).
- Empêche de cibler un joueur mort.
- Vérifie qu'aucun `seer_check` n'existe déjà pour ce round (idempotence).
- Délègue à `VoteService::registerSeerCheck()` qui crée le `GameAction`.
- Broadcast `SeerResult` (privé voyante).
- Dispatche `ProcessWerewolvesTurn::dispatch($game->id, $game->round)->delay(0)`.

**Route à ajouter dans `routes/web.php` :**
```php
Route::post('/game/{id}/seer/done', [ActionController::class, 'seerDone'])
    ->middleware('auth');
```

**Policy `GamePolicy::seerDone()` :**
```php
public function seerDone(User $user, Game $game, GamePlayer $player): bool
{
    return $player->role === 'seer'
        && $player->is_alive
        && $game->status === 'night';
}
```

### 3.2 Job ProcessSeerAutoAction

```php
<?php

namespace App\Jobs;

use App\Models\Game;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSeerAutoAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(protected int $gameId, protected int $round) {}

    public function handle(): void
    {
        $game = Game::find($this->gameId);

        // Guard 1 : partie terminée, mauvaise phase ou mauvais round
        if (!$game || $game->status !== 'night' || $game->round !== $this->round) {
            return;
        }

        $seer = $game->players()
            ->where('role', 'seer')
            ->where('is_alive', true)
            ->first();

        // Guard 2 : voyante morte (ProcessNightEnd gère la suite)
        if (!$seer) {
            return;
        }

        // Guard 3 : la voyante a déjà agi via POST /seer/done
        $existingCheck = $game->actions()
            ->where('player_id', $seer->id)
            ->where('round', $this->round)
            ->where('type', 'seer_check')
            ->exists();

        if ($existingCheck) {
            return;
        }

        // Inactivité confirmée — passer directement aux loups, sans créer de seer_check
        // (pas de broadcast SeerResult : aucune action n'a eu lieu)
        ProcessWerewolvesTurn::dispatch($game->id, $game->round);
    }
}
```

### 3.3 Modification de ProcessSeerTurn (existant)

**Avant (v1.1) :**
```php
ProcessWerewolvesTurn::dispatch($game->id, $game->round)
    ->delay(now()->addSeconds($game->timer('seer')));
```

**Après (v1.2) :**
```php
// ProcessWerewolvesTurn n'est plus dispatché ici.
// ProcessSeerAutoAction en est le seul déclencheur côté timer.
ProcessSeerAutoAction::dispatch($game->id, $game->round)
    ->delay(now()->addSeconds($game->timer('seer')));
```

---

## 4. TABLEAU DES TIMERS

### v1.1 (valeurs actuelles, toutes fixes)

| Nom interne         | Durée | Configurable host | Description                        |
|---------------------|-------|-------------------|------------------------------------|
| `mayor_election`    | 30s   | non               | Vote d'élection du maire           |
| `seer`              | 30s   | non               | Tour de la Voyante                 |
| `werewolves`        | 30s   | non               | Tour des Loups                     |
| `mayor_succession`  | 15s   | non               | Succession du Maire                |
| `day_vote`          | 90s   | non               | Débat + vote jour                  |
| `reconnection`      | 30s   | non (technique)   | Délai avant is_inactive            |
| `ready_timeout`     | 60s   | non (technique)   | Attente écran révélation rôle      |
| `night_start_delay` | 4s    | non (technique)   | Délai avant ProcessSeerTurn        |
| `mayor_reveal`      | 5s    | non               | Affichage résultat élection        |

### v1.2 (valeurs cibles — Étape 3)

| Nom interne         | Défaut | Min  | Max   | Configurable host |
|---------------------|--------|------|-------|-------------------|
| `mayor_election`    | 30s    | 20s  | 60s   | oui               |
| `seer`              | 30s    | 15s  | 60s   | oui               |
| `werewolves`        | 30s    | 15s  | 60s   | oui               |
| `witch`             | 30s    | 15s  | 60s   | oui               |
| `hunter`            | 15s    | 10s  | 30s   | oui               |
| `mayor_succession`  | 15s    | 10s  | 30s   | oui               |
| `day_vote`          | 90s    | 60s  | 180s  | oui               |
| `reconnection`      | 30s    | —    | —     | non (technique)   |
| `ready_timeout`     | 60s    | —    | —     | non (technique)   |
| `night_start_delay` | 4s     | —    | —     | non (technique)   |
| `mayor_reveal`      | 5s     | —    | —     | non               |

**Timers non configurables** (`reconnection`, `ready_timeout`, `night_start_delay`) : ignorés si présents dans `settings['timers']`. `TimerCalculator` les retourne toujours depuis `config/game.php`.

---

## 5. TIMERS CONFIGURABLES — stockage et validation (Étape 3)

### Stockage

```json
// games.settings['timers']
{
  "timers": {
    "seer": 20,
    "werewolves": 25,
    "day_vote": 120
  }
}
```

### Validation (GameService::validateTimerSettings())

```php
// Appelée depuis LobbyController quand le host modifie les timers (status = waiting uniquement)
$rules = config('game.timers.limits');

foreach ($timers as $key => $value) {
    if (!isset($rules[$key]) || $rules[$key]['host_configurable'] === false) {
        throw new \InvalidArgumentException("Timer '$key' non configurable.");
    }
    if ($value < $rules[$key]['min'] || $value > $rules[$key]['max']) {
        throw new \InvalidArgumentException("Timer '$key' hors plage.");
    }
}
```

---

## 6. PATTERN GÉNÉRIQUE — extensibilité v1.2+

Pour chaque nouveau rôle actif, répliquer exactement ce pattern :

| Élément                            | Modèle (voyante)                      |
|------------------------------------|---------------------------------------|
| `POST /game/{id}/X/done`           | `POST /game/{id}/seer/done`           |
| `ProcessXAutoAction`               | `ProcessSeerAutoAction`               |
| `VoteService::registerXAction()`   | `VoteService::registerSeerCheck()`    |
| `GamePolicy::xDone()`              | `GamePolicy::seerDone()`              |

**Ordre d'exécution nocturne (v1.2) :**
1. Voyante → `/seer/done` OU `ProcessSeerAutoAction`
2. Sorcière → `/witch/act` OU `ProcessWitchAutoAction`
3. Loups → résolution anticipée si tous votés OU `ProcessNightAutoAction`
4. Chasseur → déclenché uniquement si mort (pas de timer propre)

Chaque étape dispatche la suivante, exactement comme Voyante → Loups en v1.1.

---

## 7. IMPACT SUR PhaseManager

### startNight() — purge des guards de round précédent

En début de `startNight()`, après le `lockForUpdate()`, purger les données de nuit qui ne doivent pas survivre d'un round à l'autre. Ici, pas de flag cache à purger (contrairement à la version avec Cache::put — décision : les guards reposent uniquement sur la présence de `seer_check` en base, qui est liée au round). Aucune modification nécessaire sur `startNight()`.

### endNight() — aucune modification

Le guard `in_array($game->status, ['night', 'processing_night'])` reste suffisant.

---

## 8. NOTES D'IMPLÉMENTATION POUR CLAUDE CODE

1. **`ProcessSeerAutoAction` est le seul dispatcher de `ProcessWerewolvesTurn`** côté timer. Supprimer tout dispatch direct de `ProcessWerewolvesTurn` depuis `ProcessSeerTurn`.

2. **Guard de round dans `ProcessSeerAutoAction`** : vérifier `$game->round !== $this->round` en premier — si le round a avancé, le job est obsolète.

3. **`SeerResult` n'est jamais broadcasté par le job auto** — uniquement par l'endpoint `/seer/done` après action volontaire.

4. **`ProcessNightEnd` reste dispatché systématiquement** par `ProcessNightActions` avec le délai buffer (`mayor_succession + 5s`). Ce mécanisme est indépendant du pattern endpoint / job auto.

5. **Tests** : chaque `ProcessXAutoAction` doit avoir un test `test_auto_action_skipped_if_already_acted()` vérifiant que le guard `seer_check` existant provoque un return immédiat.