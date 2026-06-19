# Audit complet — Loup-Garou Undu
> Généré le 2026-06-18

---

## Passe 1 — Vue d'ensemble

### Ce qui est observé dans le code

L'architecture Controllers/Services/Jobs est respectée sur ~90% du codebase. Les controllers sont minces (valident + appellent service + broadcastent), les services contiennent la logique métier. Les guards d'idempotence DB sont présents partout où c'est nécessaire.

### Écarts entre conventions annoncées et code réel

**1. `PhaseAnnouncement` non implémenté**
SPEC_TRANSITIONS.md et TODO.md décrivent un event `PhaseAnnouncement` à broadcaster avant chaque transition de phase. Ni la classe `App\Events\Game\PhaseAnnouncement`, ni les appels dans `PhaseManager`/`WinConditionChecker` n'existent. Le store Alpine ne contient pas les propriétés `announcements[]` et `isAnnouncing`. C'est une feature entière manquante, documentée en ROADMAP.

**2. `ActionController::witchAct()` dispatche un Job depuis le Controller**
`app/Http/Controllers/Game/ActionController.php` ~ligne 76 : `ProcessWitchAutoAction::dispatch(...)->delay(2s)`. Compromis assumé pour une race condition (DECISIONS.md), pas une violation grave.

**3. `GameService::witchAct()` crée `hunter_pending` hors transaction**
Après le `DB::transaction` de `witch_kill`, la création du `GameAction` de type `hunter_pending` est hors transaction. Si le process crash entre les deux, le chasseur perd son tour.

**4. Dépendance circulaire `VoteService` ↔ `PhaseManager`**
`PhaseManager::endNight()` appelle `app(VoteService::class)->resolveNightVote()` via le container. Fonctionnel mais fragile pour les tests.

**5. `ProcessSeerAutoAction` diverge de SPEC_TIMERS §3.2**
Le job crée un seer_check aléatoire + broadcaste SeerResult au lieu de dispatcher directement ProcessWerewolvesTurn en cas d'inactivité. Documenté dans DECISIONS.md mais la spec n'a pas été mise à jour.

### Points forts notables

- Idempotence des Jobs via guards DB : très solide (seer_check, wolves_vote, witch_act, hunter_pending)
- `TimerCalculator::NON_CONFIGURABLE` correctement appliqué partout
- Séparation des canaux WebSocket sans fuite de rôles
- `scopeAnonymized()` correctement implémenté (select sans player_id, pas de whereNotIn)
- Guard `_initialized` présent dans `dayScreen.init()` et `nightScreen.init()`
- Pattern `hunter_pending` en DB (résiste aux restarts worker)

---

## Passe 2 — Audit par domaine

---

### Architecture & séparation des responsabilités

**🟠 `GameService::witchAct()` crée `hunter_pending` hors transaction**

Fichier : `app/Services/GameService.php`, après la fermeture du `DB::transaction` (~ligne 395)

```php
// Problème : hors transaction
if ($action === 'kill' && $result['target']?->isHunter()) {
    GameAction::create(['type' => 'hunter_pending', ...]);
}
```

Si le process PHP crash après le commit de `witch_kill` mais avant ce `create`, le chasseur meurt sans que son tour soit jamais déclenché.

**Correctif** : déplacer la création dans le bloc `elseif ($action === 'kill')` à l'intérieur de la transaction.

---

**🟠 Double chemin d'accès à `PhaseManager` dans `VoteService`**

`PhaseManager::endNight()` fait `app(VoteService::class)->resolveNightVote($game)` via le container, créant une dépendance circulaire résolue dynamiquement. Fonctionnel mais fragile pour les tests unitaires.

---

**🟡 `ActionController::hunterShoot()` dispatche `ProcessHunterAutoAction` depuis le Controller**

Fichier : `app/Http/Controllers/Game/ActionController.php` ~ligne 92

Violation mineure de la règle "Jobs = timers uniquement". La logique de dispatch devrait être dans `GameService::hunterShoot()`.

---

**🟡 `GameController::history()` exécute des requêtes Eloquent directement**

Fichier : `app/Http/Controllers/Game/GameController.php` ~lignes 130–155

`GameAction::where(...)` et `$game->players()->get()` exécutés dans le Controller. Ce sont des lectures — borderline acceptable mais idéalement dans `HistoryService`.

---

### Cohérence des données & concurrence

**🔴 Résolution du vote loups appelée deux fois — victimes potentiellement différentes**

`app/Jobs/ProcessNightActions.php` et `app/Jobs/ProcessWitchTurn.php` appellent chacun `$voteService->resolveNightVote($game)`. En cas d'égalité des loups, `inRandomOrder()` peut retourner deux victimes différentes. La sorcière voit une victime qui n'est pas celle effectivement résolue.

**Correctif** : persister le résultat dans une `GameAction` de type `night_resolve` lors de la résolution dans `ProcessNightActions`, et lire cette action dans `ProcessWitchTurn` au lieu de recalculer.

```php
// Dans ProcessNightActions, après resolveNightVote():
if ($victim) {
    GameAction::create([
        'game_id'          => $game->id,
        'player_id'        => $victim->id,
        'type'             => 'night_resolve',
        'target_player_id' => $victim->id,
        'round'            => $game->round,
        'phase'            => 'night',
    ]);
}

// Dans ProcessWitchTurn:
$victim = GameAction::where('game_id', $game->id)
    ->where('type', 'night_resolve')
    ->where('round', $game->round)
    ->first()?->target;
```

Ce pattern est cohérent avec `hunter_pending` déjà en place.

---

**🟠 `ProcessWitchAutoAction` : guard sans `lockForUpdate`**

Fichier : `app/Jobs/ProcessWitchAutoAction.php` ~ligne 55

```php
$game->refresh();
$alreadyActed = $game->actions()->where(...)->exists(); // pas de lockForUpdate
if (!$alreadyActed) {
    GameAction::create(['type' => 'witch_pass', ...]);
}
ProcessNightEnd::dispatch(...)->delay(0);
```

Deux instances concurrentes du job pourraient créer deux `witch_pass`. Improbable avec un seul worker database, mais non idempotent par construction.

**Correctif** :

```php
DB::transaction(function () use ($game, $witch) {
    $alreadyActed = GameAction::where('game_id', $game->id)
        ->where('round', $this->round)
        ->whereIn('type', ['witch_heal', 'witch_kill', 'witch_pass'])
        ->lockForUpdate()
        ->exists();
    if (!$alreadyActed && $witch) {
        GameAction::create([...]);
    }
});
ProcessNightEnd::dispatch(...)->delay(0);
```

---

**🟠 Race condition `castNightVote()` : délai 1s insuffisant avec workers multiples**

Fichier : `app/Services/VoteService.php` ~ligne 385

Le `delay(now()->addSecond())` est documenté. Avec Redis et plusieurs workers, un loup votant dans la même seconde peut obtenir un 409 si le statut est déjà `processing_night`. Acceptable en prod database driver, à surveiller si migration vers Redis multi-workers.

---

**🟡 `WinConditionChecker::check()` sans lock**

Fichier : `app/Services/WinConditionChecker.php` ~ligne 25

`$game->refresh()` puis `$game->update(['status' => 'finished'])` sans `lockForUpdate`. En cas de deux appels concurrents, la partie pourrait être marquée `finished` deux fois. Hypothèse : improbable avec un seul worker database.

---

### Machine à états (Symfony Workflow)

**🟠 Statuts intermédiaires hors Workflow créent une fragilité pour les devs futurs**

`app/Jobs/ProcessNightEnd.php` ~ligne 48 : `processing_night` bypass complètement le Workflow. Si un dev futur ajoute `applyTransition()` depuis `processing_night`, il obtiendra une exception silencieuse (catchée par le queue worker).

**Recommandation** : documenter explicitement dans un commentaire de code que `applyTransition()` est interdit sur les statuts `processing_*` et `wolves_turn`.

---

**🟡 `canTransition()` appelé conditionnellement dans `ProcessNightEnd`**

Fichier : `app/Jobs/ProcessNightEnd.php` ~ligne 52

```php
if ($game->status === 'night' && !$game->canTransition('start_day')) {
    Log::warning(...); return;
}
```

`processing_night` bypass silencieusement le guard Workflow. Comportement voulu mais non commenté dans le code.

---

**🟡 Workflow ne valide pas les pré-conditions métier**

`app/Providers/WorkflowServiceProvider.php` : `canTransition('start_election')` retourne `true` même pour une partie à 1 joueur. Les pré-conditions sont dans `GameService::startGame()`. Correct par design (Workflow = transitions d'état, pas règles métier) mais peut surprendre.

---

### Temps réel (Reverb / channels / events)

**🔴 `PhaseAnnouncement` non implémenté — spec non respectée**

SPEC_TRANSITIONS.md décrit en détail le système d'overlays. Ni `App\Events\Game\PhaseAnnouncement`, ni les appels dans `PhaseManager`, ni `announcements[]`/`isAnnouncing` dans `game-state.js` n'existent. Feature entière manquante.

---

**🟠 `NightStarted` broadcaste le timer de la voyante comme "durée de nuit"**

Fichier : `app/Events/Game/NightStarted.php`

```php
'timer' => $this->game->timer('seer'),
```

Si la voyante est morte, `ProcessSeerTurn` dispatche immédiatement `ProcessWerewolvesTurn` (0s), mais le client affiche une barre de progression basée sur `seer_timer`. Désynchronisation UX sans impact logique.

---

**🟡 Messages `dead` visibles par les clients WebSocket interceptant le trafic**

`app/Services/ChatService.php` + `app/Events/Game/ChatMessageSent.php` : les messages du canal `dead` transitent sur le canal public. Un client malveillant interceptant WebSocket voit les messages des morts. Documenté et assumé dans DECISIONS.md. Pas de fuite gameplay mais à noter.

---

**🟡 Loups morts peuvent rester abonnés au canal loups**

`routes/channels.php` ~ligne 22 : `$player->isWerewolf()` sans vérification `is_alive`. Comportement voulu (lecture seule pour les ex-loups) mais non documenté dans le code.

---

### Sécurité & autorisations

**🔴 `DayVoteRequest` ne valide pas que la cible est vivante et dans la partie**

Fichier : `app/Http/Requests/DayVoteRequest.php`

```php
// Actuel — insuffisant
'target_player_id' => 'required|integer',
```

La vérification est déportée dans `VoteService::castDayVote()`. Violation de la convention "FormRequest valide tout".

**Correctif** :

```php
'target_player_id' => [
    'required',
    'integer',
    Rule::exists('game_players', 'id')
        ->where('game_id', $this->route('id'))
        ->where('is_alive', true),
],
```

---

**🟠 `MayorSuccessionRequest` ne vérifie pas que le demandeur est le maire mort**

Fichier : `app/Http/Requests/MayorSuccessionRequest.php`

La FormRequest valide seulement que la cible est vivante. La vérification `$mayor->is_mayor && !$mayor->is_alive` est dans le Service. Un joueur vivant non-maire peut soumettre la requête (rejetée par le service en 403, mais pas filtrée en amont).

---

**🟠 `GameController::state()` expose `seer_turn_active` à tous les joueurs de la partie**

Fichier : `app/Http/Controllers/Game/GameController.php` ~ligne 220

Un villageois peut savoir si la voyante est active en interrogeant `/state`. Il ne peut rien faire avec cette info (tour voyante sur canal privé), mais c'est une fuite d'état interne.

---

**🟡 `/disconnect` et `/reconnect` non throttlés**

Fichier : `routes/web.php`

Les endpoints de déconnexion/reconnexion sont hors du groupe `throttle:60,1`. Un attaquant peut envoyer des centaines de déconnexions sur un joueur. Le guard `Cache::has($cacheKey)` dans `handleDisconnection()` absorbe les doublons sur `/disconnect`, mais pas les appels répétés sur `/reconnect`.

**Correctif** : inclure ces routes dans le groupe throttle, ou créer un sous-groupe dédié `throttle:30,1`.

---

### Règles métier

**🟠 Victimes potentiellement différentes entre `ProcessNightActions` et `ProcessWitchTurn`**

Voir section "Cohérence des données" ci-dessus. En cas d'égalité des loups, `resolveNightVote()` appelé deux fois peut retourner deux victimes différentes via `inRandomOrder()`.

---

**🟡 Mort pendant `electing_mayor` : aucun canal de chat accessible**

Fichier : `app/Services/ChatService.php` ~ligne 30

Un joueur mort pendant la phase `electing_mayor` ne peut ni écrire en `general` (vivants seulement), ni en `dead` (`canChatDead()` exige la phase `day`). Comportement probablement voulu mais non documenté.

---

**🟡 `GameService::quitGame()` ne réévalue pas la condition d'annulation (>50% inactifs)**

Fichier : `app/Services/GameService.php` ~ligne 300

Après `quitGame()`, la condition d'annulation n'est pas réévaluée. Un départ volontaire ne peut pas déclencher l'annulation de partie. Hypothèse : comportement voulu (quitter = élimination définitive, pas déconnexion temporaire).

---

### Front Alpine.js

**🟠 `players[]` du store central jamais populé dans les vues de jeu**

Fichier : `resources/js/game-state.js` ~ligne 20

`players: []` dans le store, mais `/state` ne retourne pas la liste des joueurs. `dayScreen()` et `nightScreen()` maintiennent leur propre copie locale (`PLAYERS_DATA` injecté en Blade). La propriété `players[]` du store est inutilisée dans les vues principales — violation de la règle "game-state.js = seul store source de vérité pour players[]".

---

**🟠 `mayor-election.blade.php` et `role-reveal.blade.php` s'abonnent à Echo directement**

Les deux vues montent un abonnement `window.Echo.channel()` en parallèle du store `gameState` qui souscrit au même canal. Risque de double-traitement des events `.mayor.elected`, `.night.started`, `.player.ready`, `.mayor.election.started`.

---

**🟡 `waiting-room.blade.php` utilise `window.Echo` directement**

La salle d'attente a son propre store Alpine local et ses propres abonnements Echo. Incohérent avec le pattern général, mais acceptable car c'est hors du cycle de jeu.

---

**🟡 `sessionStorage` partagé entre onglets pour l'état `dead`**

`resources/views/game/day.blade.php` : `sessionStorage.getItem('dead_' + MY_PLAYER_ID)`. Si le joueur a deux onglets ouverts (rare en prod, fréquent en dev), l'état peut être incohérent.

---

### Qualité & maintenabilité

**🟠 `GameService.php` : God Service (500+ lignes, 15+ méthodes)**

Orchestration, connexions, actions de rôles (seer, witch, hunter), succession maire, validation des settings. L'ajout de Loup Blanc (v1.3) nécessitera encore plus de méthodes ici.

---

**🟠 `VoteService.php` : 400+ lignes, mélange vote + résolution + notifications**

Gère les votes, leur résolution, les broadcasts (MayorSuccessionStarted, NoElimination, RandomElimination), les notifications push, et appelle PhaseManager. La méthode `resolveDayVote()` fait 80+ lignes.

---

**🟡 `playerAvatarColor()` dupliquée dans 3 vues Blade**

`day.blade.php`, `mayor-election.blade.php`, `waiting-room.blade.php` définissent chacun localement la même fonction. Commentaire TODO présent mais non résolu.

---

**🟡 `ProcessSeerAutoAction` crée un seer_check même pour une voyante inactive**

Fichier : `app/Jobs/ProcessSeerAutoAction.php` ~ligne 72

Si `$seer->is_inactive`, le job crée quand même un seer_check aléatoire et broadcaste SeerResult. Divergence avec SPEC_TIMERS §3.2, documentée dans DECISIONS.md mais la spec n'est pas à jour.

---

## Passe 3 — Architecture cible

### Verdict : ✅ Architecture de base solide

La séparation Controllers/Services/Jobs est maintenue, les guards DB sont présents partout où c'est nécessaire, les canaux WebSocket sont correctement isolés. **Aucun refactor structurant recommandé.**

Deux faiblesses à adresser progressivement pour v1.3+ :

---

### Faiblesse 1 : `GameService` est un God Service

**Solution progressive** : extraire les actions de rôles dans des handlers dédiés, sans tout réécrire.

```
app/Services/
  GameService.php          ← orchestration seule (joinGame, startGame, markReady, etc.)
  RoleActions/
    SeerAction.php          ← seerCheck() extrait de GameService
    WitchAction.php         ← witchAct() extrait de GameService
    HunterAction.php        ← hunterShoot() extrait de GameService
```

`GameService` délègue :
```php
public function seerCheck(GamePlayer $seer, int $targetId): GamePlayer
{
    return app(SeerAction::class)->check($seer, $targetId);
}
```

Migration en 3 PR indépendants, sans risque de régression car les tests existants couvrent chaque action.

---

### Faiblesse 2 : Résolution nocturne sans état partagé

`resolveNightVote()` appelé plusieurs fois peut retourner des résultats différents. Pour v1.3+ (rôles actifs supplémentaires après les loups), ce pattern est dangereux.

**Solution** : `GameAction` de type `night_resolve` créée dans `ProcessNightActions`, lue par les jobs suivants — cohérent avec le pattern `hunter_pending` déjà en place. Voir correctif dans la section "Cohérence des données".

---

## Passe 4 — Plan de remédiation priorisé

| # | Action | Fichiers | Impact | Effort | Risque | Stratégie test |
|---|--------|----------|--------|--------|--------|----------------|
| 1 | Déplacer création `hunter_pending` dans la transaction `witch_kill` | `GameService::witchAct()` | 🔴 Cohérence | Faible | Faible | `test_sorciere_peut_empoisonner_un_joueur()` existant |
| 2 | Ajouter validation `target_player_id` dans `DayVoteRequest` | `DayVoteRequest.php` | 🟠 Sécurité | Faible | Nul | Nouveau : `test_day_vote_request_rejette_cible_morte()` |
| 3 | Persister `night_resolve` dans `ProcessNightActions`, lire dans `ProcessWitchTurn` | `ProcessNightActions.php`, `ProcessWitchTurn.php`, migration ENUM | 🟠 Règle métier | Moyen | Moyen | Nouveau : `test_victime_sorciere_identique_a_victime_loups` |
| 4 | Ajouter `lockForUpdate` dans le guard de `ProcessWitchAutoAction` | `ProcessWitchAutoAction.php` | 🟠 Concurrence | Faible | Faible | `test_witch_turn_non_double_dispatche_meme_round()` existant |
| 5 | Throttle sur `/disconnect` et `/reconnect` | `routes/web.php` | 🟠 Sécurité | Faible | Nul | Manuel |
| 6 | Extraire `SeerAction`, `WitchAction`, `HunterAction` de `GameService` | `GameService.php` + nouveaux fichiers | 🟡 Maintenabilité | Élevé | Moyen | Tous les tests existants (non-régression) |
| 7 | Unifier `playerAvatarColor()` en helper JS partagé | 3 vues Blade | 🟡 DRY | Faible | Nul | Visuel |
| 8 | Implémenter `PhaseAnnouncement` | Nouvelle classe Event + PhaseManager + game-state.js | 🟡 Spec | Élevé | Élevé | Nouveau : `PhaseAnnouncementTest.php` (SPEC_TRANSITIONS.md §10) |

---

## Passe 5 — Tests

### Zones non testées ou mal testées

**Non testées :**
- Annulation de partie (>50% inactifs) : `GameService::cancelGame()` sans aucun test Feature
- `CleanOldGames` command
- `HistoryService::buildTimeline()` avec successions et rounds multiples
- Authentification des canaux WebSocket privés (`routes/channels.php`)
- `ChatService` avec canal `dead` pendant les différentes phases
- `GameController::quit()` pendant une partie en cours

**Partiellement testées :**
- Race condition sur `castNightVote()` simultané (test présent mais ne simule pas la vraie concurrence)
- `ProcessWitchAutoAction` sans lock (idempotence non prouvée sous charge)

---

### Tests prioritaires à ajouter

```php
// 1. Annulation si >50% inactifs
test_annulation_si_plus_50_pourcent_inactifs()
// → GameService::cancelGame() via CheckReconnectionTimeout
// → Vérifie: status=finished, winner_team=null, GameFinished broadcasté sans rôles

// 2. Victime sorcière = victime loups (même en cas d'égalité)
test_victime_sorciere_identique_a_victime_loups_en_cas_egalite()
// → 2 loups votent pour 2 cibles différentes
// → WitchTurnStarted reçoit la même victime que celle résolue par ProcessNightActions

// 3. Canal loups inaccessible aux non-loups
test_canal_werewolves_inaccessible_aux_villageois()
// → Tenter de souscrire au canal privé loups avec un user non-loup
// → routes/channels.php retourne false

// 4. ProcessWitchAutoAction idempotent si appelé deux fois
test_witch_auto_action_idempotent_si_appele_deux_fois()
// → Appeler ProcessWitchAutoAction::handle() deux fois
// → Un seul witch_pass créé

// 5. hunter_pending créé atomiquement avec witch_kill
test_hunter_pending_dans_transaction_witch_kill()
// → Vérifier que les deux GameAction sont créées ensemble
// → Test d'intégration : GameAction::where('type','witch_kill')->count() === 1
//   ET GameAction::where('type','hunter_pending')->count() === 1

// 6. Historique avec successions et rounds multiples
test_history_with_multiple_successions_and_rounds()
// → Partie avec 3 rounds, 2 successions, sorcière active
// → buildTimeline() retourne night[1].succession, day[1].result, night[2].succession

// 7. DayVoteRequest rejette une cible morte
test_day_vote_request_rejette_cible_morte()
// → POST /vote/day avec target_player_id d'un joueur is_alive=false
// → Retourne 422 (actuellement géré par le Service, pas la FormRequest)

// 8. SeerResult contient le bon rôle pour un loup
test_seer_check_sur_loup_retourne_role_werewolf()
// → Voyante inspecte un loup
// → SeerResult::broadcastWith() → role === 'werewolf'

// 9. Pas de jobs multiples CheckReconnectionTimeout sur déconnexions répétées
test_reconnect_throttle_ne_cree_pas_jobs_multiples()
// → POST /disconnect 5 fois sur le même joueur
// → Seul 1 CheckReconnectionTimeout en queue

// 10. GameFinished ne révèle pas les rôles si partie annulée
test_game_finished_ne_revele_pas_roles_si_annule()
// → cancelGame()
// → GameFinished::broadcastWith() → players[*].role === null
```
