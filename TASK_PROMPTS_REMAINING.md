# TASK_PROMPTS_REMAINING.md — Tâches v1.2

> Tâches v1.1 (1→40 + bugfixes E→K) terminées et taggées v1.1.1.
> Ce fichier contient uniquement les Étapes v1.2.
> Coller un prompt à la fois dans Claude Code.
> Respecter impérativement l'ordre des étapes — chaque étape s'appuie sur la précédente.

---

## ÉTAPE 2 — State machine Symfony Workflow (refactoring partiel)

```
AVANT DE COMMENCER :
git checkout -b refactor/workflow-state-machine

Contexte :
- Lis SPEC.md §3 (modèle de données, enum games.status), §5 (architecture).
- Lis DECISIONS.md : toutes les entrées mentionnant lockForUpdate(), startNight(),
  startDay(), processing_day, processing_night.
- Lis SPEC_TIMERS.md §2 (architecture générique des phases actives).
- CODE_SNAPSHOT.md pour cibler les fichiers concernés.

Objectif : refactoring pur — comportement identique, architecture améliorée.
Ne pas modifier la logique métier. Ne pas ajouter de fonctionnalité.
Ne pas toucher aux statuts intermédiaires (processing_night, processing_day,
wolves_turn, processing_wolves, role_reveal) — ils restent gérés manuellement
dans les jobs. Seuls les statuts principaux migrent vers Workflow.
```

### Périmètre exact

Statuts qui passent sous Symfony Workflow (transitions principales) :

```
waiting → electing_mayor → night → day → finished
                        ↗         ↘
               (loop night/day)    (processing_day → night)
```

Statuts qui restent hors Workflow (gérés manuellement, inchangés) :
`role_reveal`, `processing_night`, `processing_wolves`, `wolves_turn`, `processing_day`

### Installation

```bash
composer require symfony/workflow
```

Pas de bundle Symfony — uniquement le composant standalone.
Pas de provider tiers — configuration via `AppServiceProvider`.

### 1. Configuration du Workflow

Créer `app/Providers/WorkflowServiceProvider.php` :

```php
<?php

namespace App\Providers;

use App\Models\Game;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;

class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registry::class, function () {
            $builder = new DefinitionBuilder();

            $builder->addPlaces([
                'waiting',
                'electing_mayor',
                'night',
                'day',
                'finished',
            ]);

            $builder->addTransitions([
                new Transition('start_election',  'waiting',        'electing_mayor'),
                new Transition('start_night',     'electing_mayor', 'night'),
                new Transition('start_day',       'night',          'day'),
                new Transition('continue_night',  'day',            'night'),
                new Transition('finish',          'night',          'finished'),
                new Transition('finish',          'day',            'finished'),
            ]);

            $definition = $builder->build();
            $marking    = new MethodMarkingStore(true, 'status');
            $workflow   = new Workflow($definition, $marking, null, 'game');
            $registry   = new Registry();
            $registry->addWorkflow($workflow, new InstanceOfSupportStrategy(Game::class));

            return $registry;
        });
    }
}
```

Enregistrer dans `config/app.php` (section `providers`) :
```php
App\Providers\WorkflowServiceProvider::class,
```

### 2. Méthode helper sur le modèle Game

Ajouter dans `app/Models/Game.php` :

```php
public function canTransition(string $transitionName): bool
{
    $registry = app(\Symfony\Component\Workflow\Registry::class);
    return $registry->get($this)->can($this, $transitionName);
}

public function applyTransition(string $transitionName): void
{
    $registry = app(\Symfony\Component\Workflow\Registry::class);
    $registry->get($this)->apply($this, $transitionName);
    $this->save();
}
```

⚠️ `applyTransition()` ne doit jamais être appelé à l'intérieur d'un
`lockForUpdate()`. Utiliser `canTransition()` comme guard de validation
uniquement dans ce contexte, puis écrire `$locked->status` manuellement.

### 3. Modifications dans PhaseManager

Remplacer les `$game->update(['status' => 'X'])` par `$game->applyTransition('X')`
dans les méthodes principales. Les guards `lockForUpdate()` et `whereIn('status', [...])`
existants sont conservés.

Mapping :

| Méthode                   | Transition            |
|---------------------------|-----------------------|
| `startMayorElection()`    | `start_election`      |
| `startNight()` (1er round)| `start_night`         |
| `startNight()` (loop)     | `continue_night`      |
| `startDay()`              | `start_day`           |
| `finishGame()`            | `finish`              |

`startNight()` doit détecter le contexte :
```php
$transitionName = $locked->status === 'electing_mayor'
    ? 'start_night'
    : 'continue_night';

if (!$locked->canTransition($transitionName)) {
    \Log::warning("Transition '$transitionName' refusée depuis status={$locked->status}");
    return;
}
// Écriture manuelle dans le lockForUpdate() uniquement :
$locked->status = 'night';
$locked->save();
```

### 4. Modifications dans les Jobs

Ajouter `canTransition()` comme double-check uniquement dans les jobs
qui déclenchent une transition principale :

- `ProcessNightEnd::handle()` → vérifier `canTransition('start_day')` avant `endNight()`
- `ProcessMayorElection::handle()` → vérifier `canTransition('start_night')`
- `ProcessDayVote::handle()` → vérifier via `resolveDayVote()`

Jobs non concernés : `ProcessSeerTurn`, `ProcessWerewolvesTurn`,
`ProcessNightActions`, `ProcessMayorSuccession`, `CheckReconnectionTimeout`.

### 5. Créer WorkflowTransitionTest.php

```php
// tests/Feature/Game/WorkflowTransitionTest.php
// Transitions autorisées :
test_waiting_peut_passer_a_electing_mayor()
test_electing_mayor_peut_passer_a_night()
test_night_peut_passer_a_day()
test_day_peut_passer_a_night()
test_night_peut_passer_a_finished()
test_day_peut_passer_a_finished()

// Transitions interdites :
test_waiting_ne_peut_pas_passer_directement_a_night()
test_finished_ne_peut_faire_aucune_transition()
test_day_ne_peut_pas_faire_start_night()
test_electing_mayor_ne_peut_pas_faire_continue_night()

// Persistance :
test_apply_transition_persiste_le_nouveau_status()
```

### Vérification et commit

```bash
php artisan test --filter=WorkflowTransitionTest
php artisan test  # suite complète — aucune régression
```

Ajouter dans `DECISIONS.md` : choix `canTransition()` dans `lockForUpdate()`
vs `applyTransition()` hors transaction.
Cocher `[x]` l'Étape 2 dans `TODO.md`.

```
git add -A && git commit -m "refactor(workflow): add Symfony Workflow for main game status transitions"
```

---

## ÉTAPE 3 — Timers configurables par partie

```
AVANT DE COMMENCER :
git checkout -b feature/timers-configurables

Contexte :
- Lis SPEC_TIMERS.md §4 (tableau timers v1.2), §5 (stockage et validation).
- Lis DECISIONS.md pour les choix sur TimerCalculator et $game->timer().
- Lis CODE_SNAPSHOT.md pour cibler TimerCalculator, PhaseManager,
  LobbyController, waiting-room.blade.php.
- L'Étape 2 est mergée sur dev.

Objectif : permettre au host de configurer les timers depuis la waiting-room.
TIMER_RECONNECTION, TIMER_READY_TIMEOUT et TIMER_NIGHT_START_DELAY restent
fixes — toujours ignorés même si présents dans settings.
```

### 1. config/game.php — ajout des limites

```php
'limits' => [
    'mayor_election'   => ['min' => 20,  'max' => 60,  'host_configurable' => true],
    'seer'             => ['min' => 15,  'max' => 60,  'host_configurable' => true],
    'werewolves'       => ['min' => 15,  'max' => 60,  'host_configurable' => true],
    'witch'            => ['min' => 15,  'max' => 60,  'host_configurable' => true],
    'hunter'           => ['min' => 10,  'max' => 30,  'host_configurable' => true],
    'mayor_succession' => ['min' => 10,  'max' => 30,  'host_configurable' => true],
    'day_vote'         => ['min' => 60,  'max' => 180, 'host_configurable' => true],
    'reconnection'     => ['min' => 30,  'max' => 30,  'host_configurable' => false],
    'ready_timeout'    => ['min' => 60,  'max' => 60,  'host_configurable' => false],
    'night_start_delay'=> ['min' => 4,   'max' => 4,   'host_configurable' => false],
    'mayor_reveal'     => ['min' => 5,   'max' => 5,   'host_configurable' => false],
],
```

### 2. TimerCalculator — lecture de settings['timers']

```php
public function get(Game $game, string $key): int
{
    $fixed = ['reconnection', 'ready_timeout', 'night_start_delay', 'mayor_reveal'];
    if (in_array($key, $fixed)) {
        return config("game.timers.{$key}");
    }
    $settings = $game->settings['timers'] ?? [];
    if (isset($settings[$key]) && is_int($settings[$key])) {
        return $settings[$key];
    }
    return config("game.timers.{$key}", 30);
}
```

### 3. GameService::validateTimerSettings() et updateTimerSettings()

Voir SPEC_TIMERS.md §5 pour l'implémentation complète.

### 4. Endpoint POST /game/{id}/settings/timers

- Route, FormRequest `UpdateTimersRequest`, `LobbyController@updateTimers`
- Policy `GamePolicy::updateSettings()` — is_host + status = waiting

### 5. UI waiting-room.blade.php — panneau timers host

Voir ETAPE3_PROMPT.md §5 pour le HTML et le composant Alpine `timerSettings()`.

### 6. Tests TimerSettingsTest.php

```
test_host_peut_modifier_les_timers()
test_joueur_non_host_ne_peut_pas_modifier_les_timers()
test_timer_hors_plage_est_rejeté()
test_timer_non_configurable_est_rejeté()
test_game_timer_lit_settings_en_priorite()
test_game_timer_fallback_sur_config()
test_timers_fixes_ignorent_settings()
test_modification_impossible_hors_waiting()
```

### Commit

```
git add -A && git commit -m "feat(timers): host-configurable timers from waiting-room"
```

---

## ÉTAPE 4 — Rôles v1.2 : Sorcière et Chasseur

```
AVANT DE COMMENCER :
git checkout -b feature/roles-v1-2

Contexte :
- Lis SPEC.md §3 (enum game_players.role), §4 (règles métier), §8 (extensibilité).
- Lis SPEC_TIMERS.md en entier — pattern endpoint volontaire / job auto.
- Lis SPEC_TRANSITIONS.md §4 (messages privés rôles actifs).
- Lis DECISIONS.md — entrées mentionnant RoleDistributor, isVillagerSide(),
  processeurs de nuit.
- Lis CODE_SNAPSHOT.md pour cibler les fichiers concernés.
- Les Étapes 2 et 3 sont mergées sur dev.

Objectif : ajouter Sorcière et Chasseur.
Ne pas implémenter Loup Blanc, Cupidon, Petite Fille (v1.3+).
```

### Ordre nocturne v1.2 (⚠️ différent de v1.1)
1. Voyante → `/seer/done` OU `ProcessSeerAutoAction`
2. Loups → résolution anticipée OU `ProcessNightAutoAction`
3. Sorcière → `/witch/act` OU `ProcessWitchAutoAction`

La Sorcière agit après les loups pour connaître la victime.

### Éléments à créer

- Migration : ajout `witch` et `hunter` à l'enum `game_players.role`
- `isVillagerSide()` mis à jour
- `RoleDistributor` : lire `settings['roles']` (witch, hunter optionnels)
- `ProcessWitchTurn`, `ProcessWitchAutoAction`
- `ProcessHunterTurn`, `ProcessHunterAutoAction`
- `POST /game/{id}/witch/act` (SeerDoneRequest pattern)
- `POST /game/{id}/hunter/shoot`
- `POST /game/{id}/settings/roles`
- Events `WitchTurnStarted`, `WitchActed`, `HunterTurnStarted`, `HunterShot`
- UI `night.blade.php` — sections Sorcière et Chasseur conditionnelles
- UI `waiting-room.blade.php` — panneau composition rôles (host)

### Règles métier Sorcière
- 1 potion de soin + 1 potion de mort, une fois chacune par partie
- État des potions dans `game_players.settings['witch_heal_used']` / `['witch_kill_used']`
- Pas d'auto-sauvetage si elle est la victime
- Pas de double action la même nuit

### Règles métier Chasseur
- Agit uniquement à sa mort (nuit ou jour), timer 15s
- Si inactif → pas d'élimination supplémentaire
- Son tir vérifie `WinConditionChecker` après élimination

### Tests WitchTest.php et HunterTest.php

Voir SPEC_TIMERS.md pour les noms de tests à écrire.

### Commit

```
git add -A && git commit -m "feat(roles): add Witch and Hunter for v1.2"
```

---

## ÉTAPE 5 — Tests d'intégration v1.2

```
AVANT DE COMMENCER :
git checkout -b feature/tests-v1-2

Contexte :
- Lis SPEC_TIMERS.md §8 (notes Claude Code — tests auto_action).
- Lis SPEC_TRANSITIONS.md §10 (tests à écrire).
- Les Étapes 2, 3 et 4 sont mergées sur dev.
- Lancer php artisan test avant de commencer — tous les tests existants passent.

Objectif : couverture complète v1.2. Aucune modification de code applicatif.
```

### Fichiers à créer

1. `tests/Feature/Game/AutoActionTest.php`
   - Voyante : `test_seer_auto_action_skipped_if_already_acted()`,
     `test_seer_auto_action_passes_to_wolves_if_inactive()`,
     `test_seer_done_endpoint_dispatches_wolves_immediately()`,
     `test_seer_done_rejected_if_already_acted()`,
     `test_seer_done_rejected_if_self_target()`
   - Sorcière : `test_witch_auto_action_skipped_if_already_acted()`,
     `test_witch_auto_action_passes_if_inactive()`
   - Chasseur : `test_hunter_auto_action_skipped_if_already_shot()`,
     `test_hunter_auto_action_no_elimination_if_inactive()`

2. `tests/Feature/Game/PhaseAnnouncementTest.php`
   - `test_night_fall_broadcasted_on_start_night()`
   - `test_day_break_broadcasted_on_end_night()`
   - `test_seer_turn_not_broadcasted_publicly()`
   - `test_phase_announcement_uses_public_channel()`
   - `test_public_phase_duration_is_constant()`

3. `tests/Feature/Game/ReconnectionTest.php`
   - `test_state_endpoint_retourne_phase_courante()`
   - `test_state_traduit_wolves_turn_en_night()`
   - `test_state_traduit_processing_day_en_day()`

4. `tests/Feature/Game/RoleSettingsTest.php`
   - `test_host_peut_activer_sorciere()`
   - `test_host_peut_activer_chasseur()`
   - `test_role_distributor_inclut_sorciere_si_configuree()`
   - `test_role_distributor_remplit_villageois_automatiquement()`
   - `test_deux_sorcieres_impossibles()`
   - `test_villageois_residuels_toujours_positifs()`

### Exécution

```bash
php artisan test --filter=AutoActionTest
php artisan test --filter=PhaseAnnouncementTest
php artisan test --filter=ReconnectionTest
php artisan test --filter=RoleSettingsTest
php artisan test  # suite complète
```

### Commit et tag final

```bash
git add -A && git commit -m "test: integration tests for v1.2"

git checkout dev
git merge refactor/workflow-state-machine --no-ff
git merge feature/timers-configurables --no-ff
git merge feature/roles-v1-2 --no-ff
git merge feature/tests-v1-2 --no-ff
git tag v1.2.0
git push origin v1.2.0
```