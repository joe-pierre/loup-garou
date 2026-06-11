# TASK_PROMPTS_REMAINING.md — Tâches restantes v1.1

> Tâches 1→37 terminées. Ce fichier contient uniquement ce qu'il reste à faire.
> Coller un prompt à la fois dans Claude Code.
> ⚠️ Bugfixes critiques (E→I) à traiter en priorité avant les tâches A→C.

---

## TÂCHE E — Correction de l’ENUM `games.status` (ajout de `processing_day`)

```
AVANT DE COMMENCER :
git checkout -b fix/enum-processing-day

Contexte :
- Une migration fantôme `2026_06_10_004809_add_processing_night_to_games_status_enum.php` existe avec `up()` vide → à supprimer.
- `processing_night` est déjà présent via `2026_06_10_000001_add_processing_night_to_games_status.php`.
- Seul `processing_day` manque.
- L'ENUM complet actuel en prod est : waiting, electing_mayor, night, day, finished, processing_night, processing_wolves, wolves_turn.
```
Étapes :

1. Supprimer la migration orpheline :
   rm database/migrations/2026_06_10_004809_add_processing_night_to_games_status_enum.php

2. Créer une nouvelle migration (timestamp) :
   php artisan make:migration add_processing_day_to_games_status_enum

3. Contenu de la migration :
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE games MODIFY status ENUM(
            'waiting',
            'electing_mayor',
            'night',
            'day',
            'finished',
            'processing_night',
            'processing_wolves',
            'wolves_turn',
            'processing_day'
        ) NOT NULL DEFAULT 'waiting'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE games MODIFY status ENUM(
            'waiting',
            'electing_mayor',
            'night',
            'day',
            'finished',
            'processing_night',
            'processing_wolves',
            'wolves_turn'
        ) NOT NULL DEFAULT 'waiting'");
    }
};
```

4. Vérifier le rollback :
   php artisan migrate:rollback --step=1
   php artisan migrate

Quand c’est fait :
- Commit : `git add -A && git commit -m "fix(db): remove orphan migration, add processing_day to games.status enum"`
- Ne pas merger.
```
```

## TÂCHE F — Correction du double‑fire `ProcessDayVote` avec atomicité dans `VoteService`

```
AVANT DE COMMENCER :
git checkout -b fix/dayvote-atomic

Contexte : Tâche E terminée. `processing_day` disponible.
```
Modifications :

1. Dans `app/Services/VoteService.php`, modifier `resolveDayVote(Game $game)` :

```php
public function resolveDayVote(Game $game)
{
    return DB::transaction(function () use ($game) {
        $locked = Game::where('id', $game->id)
            ->where('status', 'day')
            ->lockForUpdate()
            ->first();

        if (!$locked) {
            return; // déjà traité
        }

        $locked->update(['status' => 'processing_day']);

        // --- toute la logique existante de résolution du vote jour ---
        // (agrégation des votes, élimination, succession, etc.)
        // --- en conservant les appels à WinConditionChecker et PhaseManager ---
    });
}
```

2. Dans `app/Jobs/ProcessDayVote.php`, simplifier `handle()` :

```php
public function handle(VoteService $voteService)
{
    $game = Game::find($this->gameId);
    if (!$game || $game->status !== 'day' || $game->round !== $this->round) {
        return;
    }

    $voteService->resolveDayVote($game);
}
```

3. Vérifier que `PhaseManager::startDay()` ne peut pas être appelé depuis un état `processing_day` (normalement c’est impossible car `resolveDayVote` ne se termine pas sans changer le statut).

Test :
- Simuler deux déclenchements simultanés de `ProcessDayVote` → seul le premier passe, le second est ignoré.

Commit : `git add -A && git commit -m "fix(dayvote): atomic resolution with processing_day guard in VoteService"`
```
```

## TÂCHE F — Correction du double‑fire `ProcessDayVote` avec atomicité dans `VoteService`

```
AVANT DE COMMENCER :
git checkout -b fix/dayvote-atomic

Contexte : Tâche E terminée. `processing_day` disponible.
```
Modifications :

1. Dans `app/Services/VoteService.php`, modifier `resolveDayVote(Game $game)` :

```php
public function resolveDayVote(Game $game)
{
    return DB::transaction(function () use ($game) {
        $locked = Game::where('id', $game->id)
            ->where('status', 'day')
            ->lockForUpdate()
            ->first();

        if (!$locked) {
            return; // déjà traité
        }

        $locked->update(['status' => 'processing_day']);

        // --- toute la logique existante de résolution du vote jour ---
        // (agrégation des votes, élimination, succession, etc.)
        // --- en conservant les appels à WinConditionChecker et PhaseManager ---
    });
}
```

2. Dans `app/Jobs/ProcessDayVote.php`, simplifier `handle()` :

```php
public function handle(VoteService $voteService)
{
    $game = Game::find($this->gameId);
    if (!$game || $game->status !== 'day' || $game->round !== $this->round) {
        return;
    }

    $voteService->resolveDayVote($game);
}
```

3. Vérifier que `PhaseManager::startDay()` ne peut pas être appelé depuis un état `processing_day` (normalement c’est impossible car `resolveDayVote` ne se termine pas sans changer le statut).

Test :
- Simuler deux déclenchements simultanés de `ProcessDayVote` → seul le premier passe, le second est ignoré.

Commit : `git add -A && git commit -m "fix(dayvote): atomic resolution with processing_day guard in VoteService"`
```
```

## TÂCHE G — Succession du maire déclenchée la nuit, sans appel à `startDay`/`startNight` depuis le job de succession

```
AVANT DE COMMENCER :
git checkout -b fix/mayor-succession-night

Contexte :
- `ProcessNightActions` doit dispatcher `ProcessMayorSuccession` si la victime est le maire.
- `ProcessMayorSuccession` ne doit **pas** déclencher de nouvelle phase (ni `startDay`, ni `startNight`) en contexte nuit.
- La fin de nuit sera gérée par `ProcessNightEnd` (Tâche H) avec un délai suffisant.
```

Modifications :

1. Dans `app/Jobs/ProcessNightActions.php` (après avoir déterminé `$victim`) :

```php
if ($victim && $victim->is_mayor) {
    ProcessMayorSuccession::dispatch($game->id, $game->round, $victim->id)
        ->delay(now()->addSeconds(config('game.timers.mayor_succession', 15)));
}
```

2. Dans `app/Jobs/ProcessMayorSuccession.php` :

- Garder le guard `in_array($game->status, ['night', 'processing_night', 'day'])`.
- Calculer `$phaseToStart` (par exemple `$phaseToStart = $game->status === 'night' ? 'night' : 'day'`).
- **Ne pas appeler `startNight()` ou `startDay()` dans le cas `$phaseToStart === 'night'`.** Retourner simplement après avoir élu le nouveau maire.
- Laisser `$phaseToStart === 'day'` se comporter comme avant (appel à `startNight` après succession, ou `startDay` selon le contexte – à vérifier selon la logique existante).

3. S’assurer que `WinConditionChecker::check()` est appelé **avant** le dispatch de `ProcessMayorSuccession` dans `ProcessNightActions` (pour éviter une succession sur une partie déjà terminée).

Test :
- Partie avec un maire, le tuer la nuit → `ProcessMayorSuccession` est dispatché, un nouveau maire est élu, la nuit se termine normalement via `ProcessNightEnd`.

Commit : `git add -A && git commit -m "fix(night): trigger mayor succession at night without ending phase prematurely"`
```
```

## TÂCHE H — Garantir la fin de nuit même sans voyante, via `ProcessNightEnd` avec délai buffer

```
AVANT DE COMMENCER :
git checkout -b fix/night-end-universal

Contexte :
- `ProcessNightActions` ne doit plus appeler directement `startDay()`.
- Un job `ProcessNightEnd` est dispatché systématiquement à la fin de `ProcessNightActions` avec un délai = `TIMER_MAYOR_SUCCESSION + buffer` (ex: 20s).
- Ce délai couvre la succession du maire si elle a lieu.
- La méthode `PhaseManager::endNight()` contient la logique métier (vérification victoire + `startDay()`).
```

Modifications :

1. Dans `app/Services/PhaseManager.php`, ajouter :

```php
public function endNight(Game $game): void
{
    // Vérifier que la partie est toujours en nuit (ou processing_night)
    if (!in_array($game->status, ['night', 'processing_night'])) {
        return;
    }

    $winChecker = app(WinConditionChecker::class);
    if ($winChecker->check($game)) {
        return; // partie terminée
    }

    $this->startDay($game, null);
}
```

2. Créer `app/Jobs/ProcessNightEnd.php` :

```php
<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\PhaseManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessNightEnd implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(protected int $gameId, protected int $round) {}

    public function handle(PhaseManager $phaseManager)
    {
        $game = Game::find($this->gameId);
        if (!$game || $game->round !== $this->round || !in_array($game->status, ['night', 'processing_night'])) {
            return;
        }

        $phaseManager->endNight($game);
    }
}
```

3. Dans `app/Jobs/ProcessNightActions.php` :

- Retirer tout appel direct à `startDay()`.
- À la fin du `handle()`, **toujours** dispatcher `ProcessNightEnd` :

```php
ProcessNightEnd::dispatch($game->id, $game->round)
    ->delay(now()->addSeconds(config('game.timers.mayor_succession', 15) + 5)); // buffer 5s
```

4. Supprimer dans `ProcessSeerTurn.php` tout appel à `startDay()` ou `startNight()` ; il ne doit que dispatcher `ProcessWerewolvesTurn` ou `ProcessNightEnd`.

Test :
- Nuit normale (voyante vivante, pas de mort du maire) → `ProcessNightEnd` se déclenche après le délai et passe au jour.
- Nuit avec succession du maire → `ProcessNightEnd` attend que la succession soit terminée (délai 20s) puis passe au jour.
- Voyante morte dès le début → `ProcessNightEnd` est quand même dispatché → loups jouent → jour.

Commit : `git add -A && git commit -m "fix(night): add ProcessNightEnd to always conclude night phase"`
```
```

## TÂCHE I — Tests de non‑régression pour E, F, G, H

```
AVANT DE COMMENCER :
git checkout -b fix/tests-night-day

Contexte : Toutes les corrections précédentes sont en place.
```

Créer ou compléter les tests suivants :

1. `tests/Feature/Game/ProcessDayVoteTest.php` :
   - `test_double_fire_does_not_execute_twice()`
   - `test_day_vote_does_not_accept_processing_day_as_initial_state()`

2. `tests/Feature/Game/NightPhaseTest.php` :
   - `test_mayor_succession_triggered_at_night()`
   - `test_mayor_succession_not_triggered_if_victory_occurs_simultaneously()`
   - `test_night_ends_with_werewolves_turn_even_when_seer_dead()`
   - `test_night_end_dispatched_after_mayor_succession_with_buffer()`

3. `tests/Feature/Game/MigrationTest.php` (nouveau) :
   - `test_processing_day_added_to_enum()`
   - `test_rollback_of_processing_day_removes_it()`

Exécution :
```bash
php artisan test --filter=ProcessDayVoteTest
php artisan test --filter=NightPhaseTest
php artisan test --filter=MigrationTest
```

Commit : `git add -A && git commit -m "test: cover night fixes, dayvote atomicity, and enum migration"`
```
```

---

## TÂCHE A — UI : Police de corps + cancelled.blade.php + spectator.blade.php

```
AVANT DE COMMENCER :
git checkout -b feature/tache-A-ui-vues

Contexte : lis SPEC.md §10 (Design), CONVENTIONS.md §Partials Alpine, TODO.md Phase 10.
```

Fais dans l'ordre :

1. POLICE DE CORPS — choisir et uniformiser
   - Inspecte app.css et les vues Blade pour trouver toutes les occurrences de "EB Garamond" et "Crimson Text"
   - Décision : conserver EB Garamond (déjà en place côté CSS), supprimer toutes les références à Crimson Text
   - Mettre à jour SPEC.md §10 pour refléter ce choix

2. cancelled.blade.php
   - Créer resources/views/game/cancelled.blade.php
   - @extends layout principal
   - Affiche : titre "Partie annulée", message explicatif (trop de joueurs inactifs), bouton CTA retour vers /
   - Pas de données dynamiques nécessaires — vue statique
   - Style cohérent avec les autres vues (variables CSS existantes, pas de nouvelles classes)

3. spectator.blade.php
   - Créer resources/views/game/spectator.blade.php
   - @extends layout principal
   - Vue lecture seule pour joueurs morts : voit le chat village (channel general), ne voit pas le chat loups
   - Pas de formulaire de vote, pas d'action disponible
   - Affiche la liste des joueurs vivants/morts (depuis gameState store Alpine)
   - Écoute les events WebSocket game.{gameId} pour mise à jour temps réel (phase, éliminations)
   - Utilise $watch sur gameState.phase pour afficher la phase courante
   - Aucune propriété dupliquée depuis le store central gameState

CONTRAINTES :
- Aucune logique métier dans les vues
- Aucune variable Blade injectée dans un store Alpine local
- Respecter §Partials Alpine de CONVENTIONS.md

Quand c'est fait :
1. Liste les fichiers créés ou modifiés
2. Fais un commit : `git add -A && git commit -m "feat(ui): police EB Garamond unifiée, cancelled et spectator blade"`
3. Ne merge pas sur dev.
```
```

---

## TÂCHE B — Tests : Phases 5→9 (nuit, jour, chat, race conditions)

> Regroupe les tâches 38 et 39 de TASK_PROMPTS.md + couverture manquante phases 5→9.

```
AVANT DE COMMENCER :
git checkout -b feature/tache-B-tests

Contexte : lis SPEC.md §4 (votes, anonymat), §13 (race conditions), CONVENTIONS.md §Tests.
Tâches phases 5→9 terminées. Lis CODE_SNAPSHOT.md pour identifier les services et controllers concernés.

Crée les fichiers suivants dans l'ordre :

1. tests/Unit/Models/GameActionTest.php
   → scopeAnonymized() ne retourne pas player_id dans les colonnes
   → scopeAnonymized() retourne toutes les lignes (pas de filtre sur le type)
   → scopeAnonymized() retourne bien target_player_id, type, weight, round, phase

2. tests/Unit/Events/EventPayloadTest.php
   → MayorVoteCast payload ne contient pas player_id
   → DayVoteCast payload ne contient pas player_id
   → SeerResult broadcasté uniquement sur channel privé (pas Channel public)
   → WerewolfChatMessage broadcasté uniquement sur game.{id}.werewolves

3. tests/Feature/Game/NightPhaseTest.php
   → Voyante ne peut pas s'inspecter elle-même (403)
   → Loup ne peut pas voter pour un autre loup (403)
   → Action voyante après expiration timer → ignorée
   → Vote nuit hors phase night → 409

4. tests/Feature/Game/DayPhaseTest.php
   → Un joueur ne vote pas pour lui-même (403)
   → Égalité vote jour → aucun joueur éliminé, événement NoElimination broadcasté
   → Vote maire avec weight=2 correctement compté
   → Vote hors phase day → 409

5. tests/Feature/Game/ChatTest.php
   → Message loups visible uniquement sur channel werewolves
   → Joueur villageois ne peut pas écrire sur channel werewolves (403)
   → Message après mort → 403

6. tests/Feature/Game/RaceConditionTest.php
   → joinGame race : max_players=6, 6 insertions quasi-simultanées → exactement 6 joueurs, pas 7
   → startGame race : startGame() ne peut pas être appelé deux fois (status change atomique)
   → mayorVote race : deux votes simultanés du même joueur → un seul inséré
   → dayVote race mayor : weight=2 correct même si changement de maire concurrent

CONVENTIONS : factories, RefreshDatabase, pas de mocks sauf pour les broadcasts.

Quand c'est fait :
1. Liste les fichiers créés et le nombre de tests par fichier
2. Fais un commit : `git add -A && git commit -m "test: couverture phases 5→9 nuit, jour, chat, race conditions"`
3. Ne merge pas sur dev.
```

---

## TÂCHE C — Responsive

```
AVANT DE COMMENCER :
git checkout -b feature/tache-C-responsive

Contexte : lis SPEC.md §10 (Design, breakpoints), CONVENTIONS.md §CSS.
Lis CODE_SNAPSHOT.md pour identifier toutes les vues Blade.

Applique le responsive sur toutes les vues dans l'ordre de priorité :
1. waiting-room.blade.php
2. role-reveal.blade.php
3. mayor-election.blade.php
4. night.blade.php
5. day.blade.php
6. finished.blade.php
7. cancelled.blade.php
8. spectator.blade.php
9. Landing page (Écran 1)

Pour chaque vue :
- Mobile first (min-width breakpoints)
- Breakpoints : sm=640px, md=768px, lg=1024px (Tailwind standard)
- Aucune nouvelle classe CSS — utiliser uniquement les utilitaires Tailwind existants
- Tester mentalement : liste des joueurs, boutons de vote, chat loups, timer affiché

CONTRAINTES :
- Ne pas modifier la logique Alpine.js
- Ne pas modifier les controllers ni les services
- Modifier uniquement les classes HTML dans les vues Blade

Quand c'est fait :
1. Liste les vues modifiées
2. Fais un commit : `git add -A && git commit -m "feat(ui): responsive mobile-first toutes les vues"`
3. Ne merge pas sur dev.
```

---

## TÂCHE D — Recette finale et audit sécurité

> Correspond à la Tâche 40 de TASK_PROMPTS.md — à exécuter en dernier.

```
AVANT DE COMMENCER :
git checkout -b feature/tache-D-audit-final

Contexte : toutes les tâches précédentes terminées. Lis SPEC.md en entier une dernière fois.

Vérifie et corrige dans l'ordre :

SÉCURITÉ :
1. routes/channels.php — chaque channel privé a bien sa vérification d'appartenance
2. Chaque Controller vérifie game_id + player_id cohérents (anti-spoofing)
3. Aucune réponse publique n'expose un rôle de joueur vivant
4. Middleware 'auth' présent sur toutes les routes protégées

ACCESSIBILITÉ :
5. prefers-reduced-motion respecté dans app.css ET dans les handlers GSAP de game-state.js
6. Contraste WCAG AA vérifié sur : #c9a84c sur #0a0f1e, #e8e0d0 sur #111827, #ff4444 sur #1a0000
7. focus:ring-2 focus:ring-[#c9a84c] présent sur tous les éléments interactifs
8. aria-label sur tous les boutons d'action, role="log" sur les zones de chat

FONCTIONNEL :
9. Flux complet (landing → auth → lobby → partie 6 joueurs → fin) sans erreur console
10. Flux reconnexion : couper la connexion en cours de nuit et de jour, vérifier restauration via /state
11. Partage lien d'invite : copier le lien, l'ouvrir dans un autre navigateur, vérifier pré-remplissage code
12. Paste input 6 cases : coller un code seul (ABC123) + coller une URL complète (?code=ABC123)
13. Suppression en cascade : créer une partie, la terminer, attendre scheduler ou appeler games:clean manuellement

PRODUCTION :
14. config/queue.php → driver=redis en production
15. Supervisor : workers + Reverb configurés
16. php artisan config:cache && route:cache && view:cache en production
17. RoleDistributor extensible : ajouter un rôle fictif 'witch'=>1 dans config/game.php et vérifier que distribute() l'intègre sans modifier la logique core

Quand c'est fait :
1. Génère un rapport final : liste les points OK et ceux nécessitant une correction
2. Fais un commit : `git add -A && git commit -m "audit: recette finale sécurité, accessibilité, production"`
3. Ne merge pas sur dev.
```
