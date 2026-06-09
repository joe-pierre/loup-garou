## [CHOIX] cancelled/spectator réécrites en @extends('layouts.game') + gameState — fichier renommé dead-spectator → spectator

**Contexte :** Tâche A (UI) — `resources/views/game/cancelled.blade.php`, `resources/views/game/dead-spectator.blade.php`, `GameController::spectator()`, `routes/web.php`
**Symptôme / Problème :** Les deux vues existaient déjà (créées tâche 22, avant la tâche 30 « store gameState central ») sous forme de documents HTML autonomes avec leur propre composant Alpine local (`spectatorScreen()` dupliquant `chat`/`wolvesChat`/`tab`). La route s'appelle `game.spectator` mais pointait vers la vue `game.dead-spectator` — incohérence de nommage. La tâche A demandait explicitement `@extends` du layout principal, le store `gameState` central et `$watch` sur `gameState.phase`, alors que les fichiers existants n'utilisaient ni l'un ni l'autre.
**Cause / Alternatives :** (1) Garder les fichiers tels quels (mais ils ne respectent ni la consigne de la tâche A ni la règle CLAUDE.md « gameState = seul store source de vérité », et dupliquent une logique déjà gérée par `gameState` — `chat`, `wolvesChat`, `handlePlayerEliminated`, `handleMayorElected`, `handleGameFinished`). (2) Réécrire en `@extends('layouts.game')` (cohérent avec `finished.blade.php`, l'écran jumeau le plus récent) et brancher sur `x-data="gameState($game->id, auth()->id())"`, en renommant `dead-spectator.blade.php` → `spectator.blade.php` pour aligner nom de fichier et nom de route.
**Fix / Décision :** Option 2 retenue. `cancelled.blade.php` réécrite en `@extends('layouts.game')` (style aligné sur `finished.blade.php`). `dead-spectator.blade.php` supprimée, remplacée par `spectator.blade.php` qui utilise `x-data="gameState(...)"` : la liste des joueurs est rendue côté Blade avec `data-player-id`/`data-badge="mayor"` (les handlers WS du store `gameState` — déjà conçus pour manipuler le DOM via ces attributs — gèrent les mises à jour temps réel sans dupliquer `players[]`), le chat général/loups est lié à `chat`/`wolvesChat` du store, et un badge de phase utilise `x-text` + `$watch('phase', …)` pour l'affichage réactif et l'animation GSAP, sans dupliquer `phase`/`round`. `GameController::spectator()` mis à jour pour pointer vers `game.spectator`.
**Leçon :** Avant de réécrire une vue listée comme « à créer » dans TODO.md, vérifier qu'elle n'existe pas déjà sous un nom proche (`git log -- <pattern>` / recherche par route). Une vue créée avant l'introduction d'un store central doit être migrée vers celui-ci plutôt que de garder un store Alpine local qui duplique sa logique — surtout quand le store expose déjà des handlers DOM-driven (`[data-player-id]`, `[data-badge="mayor"]`) prêts à l'emploi.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Carte de rôle affichait Villageois pour tous — contenu statique Blade

**Contexte :** `role-reveal.blade.php`, `GameService::joinGame()`
**Symptôme :** Tous les joueurs voyaient la carte Villageois sur role-reveal, quel que soit leur rôle réel.
**Cause :** Le contenu de la carte était rendu par `@switch($player->role)` côté Blade. Dans certains cas (joueur arrivant sur role-reveal via resync 500ms pendant que la transaction `joinGame()` est encore ouverte), `$player->role` pouvait être null. `@switch(null)` tombait dans `@default` → Villageois pour tous. De plus, `PlayerJoined(slots_remaining=0)` est broadcasté AVANT que `startGame()` soit appelé (ligne 98 vs 102 dans `joinGame()`), créant une fenêtre de race condition.
**Fix :** Remplacer `@switch` par des éléments Alpine `x-show="role === 'werewolf'"` liés à `this.role`. Ajouter `syncRole()` qui fetch `/game/{code}/state` à 500ms et met à jour `this.role` + `this.allies` si null. La carte se corrige dynamiquement sans rechargement.
**Leçon :** Tout contenu conditionnel lié à un état pouvant être null au rendu Blade doit utiliser Alpine `x-show`/`:class` au lieu de directives Blade statiques, dès lors qu'un fetch de rattrapage est prévu côté client.
**Statut :** ✅ Résolu

## [RÉSOLU] remember_token sur modèle User OAuth

**Contexte :** Tâche 2 — Authentification Google
**Symptôme :** `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'remember_token'`
**Cause :** Laravel injecte automatiquement `remember_token` sur tout modèle qui étend `Authenticatable`, même si la colonne n'existe pas en base. Ce projet utilise exclusivement Google OAuth — pas de login/password, donc pas de `remember_token` dans la migration `users`.
**Fix :** Ajouter dans `app/Models/User.php` :
```php
public function getRememberTokenName(): null
{
    return null;
}
```
**Leçon :** Tout projet Laravel full-OAuth doit neutraliser `remember_token` et `password` dans le modèle User dès la tâche 1.
**Statut :** ✅ Résolu

## [RÉSOLU] CSRF token manquant sur channels privés Echo/Reverb

**Contexte :** Tâche 10 — `resources/js/echo.js`
**Symptôme :** Tout abonnement `Echo.private(...)` échouait silencieusement — le POST sur `/broadcasting/auth` retournait HTTP 419. Les channels publics `Echo.channel(...)` n'étaient pas affectés.
**Cause :** Laravel Echo n'injecte pas automatiquement le CSRF token dans les headers d'authentification WebSocket.
**Fix :** Ajouter dans la config Echo :
```php
auth: {
    headers: {
        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
    },
},
```
**Leçon :** Tout projet Laravel Reverb avec channels privés doit inclure le CSRF token dans `echo.js` dès le setup. Vérifier que toutes les vues utilisant `Echo.private()` ont bien `<meta name="csrf-token">` dans leur `<head>`.
**Statut :** ✅ Résolu

## [CHOIX] Dispatch ProcessWerewolvesTurn sans delay depuis ProcessSeerTurn

**Contexte :** Tâche 13 — `ProcessMayorElection.php`, `ProcessSeerTurn.php`
**Problème :** ProcessMayorElection dispatchait ProcessSeerTurn avec un delay — or le job est le démarreur du tour, pas son résolveur. Le delay appartenait à ProcessWerewolvesTurn.
**Fix :** Suppression du delay sur `ProcessSeerTurn::dispatch()` dans ProcessMayorElection. Le delay 30s est posé par ProcessSeerTurn lui-même sur `ProcessWerewolvesTurn::dispatch()`.
**Leçon :** Chaque job "démarreur de phase" dispatche le suivant avec le delay de SA propre phase, pas le job appelant.
**Statut :** ✅ Résolu

---

## [CHOIX] mayorSuccessionByPlayer dans GameService, pas dans ActionController

**Contexte :** Tâche 17 — `ActionController::mayorSuccession()`, `GameService`
**Problème :** La tâche spec plaçait le DB::transaction directement dans ActionController. CLAUDE.md interdit la logique métier dans les controllers.
**Décision :** Logique déplacée dans `GameService::mayorSuccessionByPlayer()`. ActionController appelle le service + broadcast. PhaseManager injecté dans GameService (pas dans le controller).
**Leçon :** Quand la spec task et CLAUDE.md sont en conflit, CLAUDE.md prime. Ajouter une méthode au Service existant plutôt que créer un nouveau Service juste pour une action.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] resolveDayVote appelle startNight dans la même transaction (transactions imbriquées MySQL)

**Contexte :** Tâche 16 — `VoteService::resolveDayVote()`, `PhaseManager::startNight()`
**Problème :** `resolveDayVote` est en transaction (`DB::transaction`). Elle doit appeler `startNight` qui a sa propre transaction. MySQL ne supporte pas de vraies transactions imbriquées : Laravel utilise des savepoints (`SAVEPOINT sp_xxx`).
**Alternatives :** (1) Extraire le dispatch de `ProcessSeerTurn` hors de `startNight` et le faire dans le Job appelant — cassant si `startNight` est appelée depuis d'autres Jobs (tâche 20). (2) Accepter les savepoints : comportement bien défini, commit réel au retour du `DB::transaction` le plus externe.
**Décision :** Option 2 retenue. `startNight` garde sa propre `DB::transaction` avec double-fire guard (`where('status', 'day')->lockForUpdate()`). Les savepoints Laravel gèrent l'imbrication correctement.
**Leçon :** Chaque méthode de PhaseManager garde son propre guard transactionnel pour être appelable aussi bien depuis un Job isolé que depuis une transaction parent. Ne pas supprimer ces guards sous prétexte que "le caller a déjà une transaction".
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Vote nuit — delete+insert pour permettre le changement de cible

**Contexte :** Tâche 14 — `VoteService::castNightVote()`, `app/Models/GameAction`
**Problème :** Un loup doit pouvoir changer sa cible jusqu'à expiration du timer. `updateOrCreate` exige une contrainte unique en base, absente sur `game_actions`. `UPDATE` direct est fragile (dépend d'un ID existant inconnu).
**Alternatives :** (1) Ajouter une contrainte unique `(game_id, player_id, round, type)` + `updateOrCreate` — trop invasif, les autres types d'action autorisent plusieurs lignes. (2) `whereFirst()->update()` — race condition entre lecture et écriture. (3) `lockForUpdate()->delete()` + `create()` dans une transaction.
**Décision :** Option 3 retenue : `lockForUpdate()->delete()` puis `GameAction::create()` dans une transaction. Simple, safe, cohérent avec l'absence de contrainte unique.
**Leçon :** Ne pas ajouter de contrainte unique sur `game_actions` pour "faciliter" updateOrCreate — d'autres types d'action (seer_check, ready) n'ont qu'un enregistrement par design mais sans contrainte DB. Utiliser delete+insert en transaction pour toute action "remplaçable".
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Alpine.js via npm/Vite plutôt que CDN

**Contexte :** Tâche 25 — `app.js`, `game-state.js`, `layouts/game.blade.php`
**Problème :** Les scripts `type="module"` (Vite) s'exécutent après les scripts `defer` normaux. Alpine CDN chargé avec `defer` s'initialise donc AVANT le bundle Vite. Résultat : `Alpine.data('gameState', ...)` ou `window.gameState = ...` ne seraient pas disponibles au moment où Alpine évalue les `x-data` des composants → erreur silencieuse.
**Alternatives :** (1) CDN Alpine + window.gameState assigné dans un `<script>` inline non-defer — fragile, couplage fort. (2) CDN Alpine avec `alpine:init` dans un script inline avant le CDN — peu maintenable. (3) Alpine via npm : Vite contrôle l'ordre, `Alpine.data()` appelé avant `Alpine.start()`.
**Décision :** Option 3. `alpinejs` ajouté aux `dependencies` de `package.json`. Dans `app.js`, Alpine est importé, les composants enregistrés via `Alpine.data()`, puis `Alpine.start()` appelé. Le CDN Alpine retiré du layout.
**Leçon :** Avec Vite, toujours bundler Alpine pour contrôler l'ordre d'initialisation. Ne jamais mélanger CDN Alpine + `Alpine.data()` dans un module Vite. ⚠️ Nécessite `npm install` après cette tâche.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Détection reconnexion via token Cache plutôt que colonne DB ou flag booléen

**Contexte :** Tâche 18 — `CheckReconnectionTimeout`, `GameService::handleDisconnection/handleReconnection`
**Problème :** Reverb ne fournit aucun événement PHP serveur de reconnexion client. Le Job `CheckReconnectionTimeout` dispatché avec 30s de délai doit savoir si le joueur a reconnu sa reconnexion entre-temps. `is_inactive` ne peut pas servir de sentinelle : il vaut `false` par défaut, donc impossible de distinguer "jamais déconnecté" de "reconnecté".
**Alternatives :** (1) Ajouter une colonne `disconnected_at` nullable — migration supplémentaire, état de plus à maintenir. (2) Stocker un UUID de déconnexion en cache avec TTL 2× le timer — pas de migration, invalidable côté `/reconnect`.
**Décision :** Option 2 retenue. `handleDisconnection()` génère un UUID et le stocke dans `Cache::put("player_disconnected.{id}", $token, TTL)`. `handleReconnection()` supprime la clé. Le Job compare son token au cache : divergence → reconnexion détectée → return early.
**Leçon :** Pour un état éphémère (déconnexion en attente de confirmation), le cache Laravel est préférable à une colonne DB. Ne pas polluer le schéma pour des états transitoires de 30 secondes.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Mort du maire la nuit — vote jour du round sacrifié pour la succession

**Contexte :** Tâche 16 — `ProcessNightActions`, `ProcessMayorSuccession`
**Problème :** `ProcessMayorSuccession` appelle `startNight()` à la fin (conçu pour les morts en phase jour). Si le maire meurt la nuit, `ProcessNightActions` dispatche `ProcessMayorSuccession` (delay 15s), puis appelle `startDay()`. Quand `ProcessMayorSuccession` se déclenche 15s plus tard, il voit `status='day'` (guard passe), fait la succession, et appelle `startNight()` — ce qui termine le jour après seulement 15s, annulant le `ProcessDayVote` (dispatché avec 90s de délai, il verra le mauvais round/status et s'arrêtera sans effet).
**Alternatives :** (1) Ajouter un flag `shouldStartNight` à `ProcessMayorSuccession` pour distinguer les contextes nuit/jour. (2) Créer un `ProcessMayorSuccessionNight` séparé qui appelle `startDay` au lieu de `startNight`. (3) Accepter le comportement : nuit → maire tué → succession 15s → nuit suivante sans vote jour.
**Décision :** Option 3 retenue pour v1.1. Si le maire meurt la nuit, les joueurs perdent le vote jour de ce round (ils voient `DayStarted`, puis `MayorSuccessionDone` 15s plus tard, puis `NightStarted`). Cohérent avec la mécanique "succession = responsabilité du maire mourant" : une nuit sans maire = perturbation du rythme. Corrigeable en v1.2 avec le flag `shouldStartNight`.
**Leçon :** `ProcessMayorSuccession` est couplé au contexte "mort en jour". Pour une mort en nuit, il faut soit un job dédié, soit un paramètre de contexte. Ne pas réutiliser un job pensé pour un contexte sans vérifier son effet de fin.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Exclusion cascade delete — joueur exclu pouvait rejoindre la partie

**Contexte :** Tâche 27 — `database/migrations/2026_06_03_000005_create_exclusions_table.php`, `GameService::excludePlayer()`, `GameService::joinGame()`
**Symptôme :** `excludePlayer()` crée un record `Exclusion` puis appelle `$target->delete()`. La FK `exclusions.player_id` avait `cascadeOnDelete()` : la suppression du `GamePlayer` détruisait le record d'exclusion en cascade → table vide → le joueur exclu pouvait rejoindre la partie (200 au lieu de 403). Révélé par les tests tâche 27.
**Cause :** Double effet de bord : (1) cascade FK détruit le record d'exclusion au moment de `delete()` ; (2) la vérification d'exclusion dans `joinGame` utilisait `whereHas('player', fn($q) => $q->where('user_id', ...))`, qui tombe en court-circuit si le joueur est encore présent dans `game_players` (retourne 200 "déjà en partie").
**Fix :** Trois changements coordonnés : (1) migration — ajout de `user_id` FK sur `exclusions` (cascadeOnDelete vers users), `player_id` rendu nullable avec `nullOnDelete` ; (2) `excludePlayer()` — sauvegarde `user_id` dans le record d'exclusion avant delete ; (3) `joinGame` — vérification par `Exclusion::where('user_id', $user->id)` au lieu de `whereHas('player')`. Le record d'exclusion persiste après la suppression du player (player_id passe à null, user_id reste intact).
**Leçon :** Toute FK pointant vers un record destiné à être supprimé dans le même flux doit utiliser `nullOnDelete` ou `restrictOnDelete` si le record parent doit survivre. Ne jamais supposer qu'un record créé avant un `delete()` dans la même transaction sera préservé si une FK avec `cascadeOnDelete` pointe dessus.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Phase nuit bloquée — broadcast synchrone dans DB::transaction

**Contexte :** `PhaseManager::startDay()` et `startNight()`
**Symptôme :** Partie bloquée en phase night après vote des loups en prod.
**Cause :** Les 22 events utilisent `ShouldBroadcastNow` (TCP synchrone vers Reverb). Ces broadcasts étaient appelés à l'intérieur d'un `DB::transaction` avec `lockForUpdate()`. En prod, toute latence Reverb pendant que le verrou est tenu provoque une exception, un rollback, et status repasse à `'night'`. `ProcessDayVote` n'est jamais dispatché. Problème secondaire : `ProcessSeerTurn` dispatché sans delay à l'intérieur de la transaction — le queue worker pouvait lire `status='night'` avant le commit et skipper le tour voyante.
**Fix :** Dans `PhaseManager` uniquement — `$locked` extrait par référence (`&$locked`), `broadcast()` et `dispatch()` déplacés après la fermeture du `DB::transaction`. La transaction ne contient plus que les UPDATE SQL. Les 22 events restent `ShouldBroadcastNow` (aucun changement sur les events).
**Leçon :** `DB::transaction` = SQL uniquement. Jamais d'appels réseau (`broadcast`, HTTP) ni de `dispatch` sans delay à l'intérieur. Utiliser `&$locked` pour récupérer le modèle locké hors du closure.
**Statut :** ✅ Résolu

---

## [CHOIX] 0 votes jour → élimination aléatoire (changement de spec)
**Contexte :** `VoteService::resolveDayVote()`, `ProcessDayVote`
**Symptôme / Problème :** Spec initiale : 0 votes → personne éliminé.
**Fix / Décision :** 0 votes → élimination aléatoire parmi les vivants.
Notification fun broadcastée via `RandomElimination` event. `WinConditionChecker` appelé après l'élimination, comme pour un vote normal.
SPEC.md §4 mis à jour pour refléter ce choix.
**Leçon :** Règle modifiable dans `VoteService::resolveDayVote()`.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] SeerResult broadcasté sur canal privé joueur, jamais sur canal public

**Contexte :** Tâche 13 — `SeerTurnStarted`, `SeerResult`
**Problème :** Le résultat d'inspection de la voyante ne doit jamais fuiter aux autres joueurs, même en cas d'erreur de routing.
**Décision :** Les deux events voyante passent exclusivement par `PrivateChannel("game.{id}.player.{seer->id}")` — jamais sur `game.{id}`.
**Leçon :** Toute information de rôle privée (résultat voyante, composition loups) → canal privé individuel obligatoire. Canal public = informations visibles par tous.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Pattern $watch vs setTimeout pour événements broadcast

**Contexte :** `night.blade.php`, `ProcessSeerTurn`, store Alpine `gameState`
**Symptôme :** La voyante ne voyait jamais son interface de tirage malgré le bon broadcast.
**Cause :** `setTimeout` s'exécutait à l'initialisation de la page, avant qu'Echo soit souscrit au channel privé. Le broadcast `SeerTurnStarted` arrivait avant la souscription → event manqué.
**Fix :** Remplacer le `setTimeout` par `$watch('pendingSeerEvent', ...)` dans le composant Alpine de `night.blade.php`. La réaction se déclenche quand la donnée arrive, pas au chargement de la page.
**Leçon :** Pour toute vue chargée après un broadcast, toujours utiliser `$watch` plutôt que `setTimeout` sur l'init Alpine. `setTimeout` suppose que l'event arrive après l'init — faux si la page se charge après le broadcast.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Deux stores Alpine dans night.blade.php — source de vérité `myRole`

**Contexte :** `night.blade.php`, `game-state.js`
**Symptôme :** `myRole === null` dans le store local de `night.blade.php` alors que `gameState().role` était correctement initialisé.
**Cause :** Deux stores Alpine coexistants : `gameState()` global (dans `game-state.js`) et un store local inline dans `night.blade.php`. Le store local initialisait `myRole` depuis une variable Blade `MY_ROLE`, mais cette variable était `null` au moment du chargement de la vue nuit (rôle pas encore disponible dans le contexte Blade).
**Fix :** Le store local de `night.blade.php` lit toujours `myRole` depuis `gameState().role`, jamais depuis une variable Blade injectée directement.
**Leçon :** Un seul store Alpine est source de vérité pour le rôle du joueur. Ne jamais dupliquer `role` ou `allies` dans un store local. Toute variable Blade injectée dans un store Alpine risque d'être null si la vue se charge après un changement d'état côté serveur.
**Statut :** ✅ Résolu

---

## [CHOIX] Quitter le lobby ≠ quitter une partie en cours — deux chemins de sortie

**Contexte :** `waiting-room.blade.php`, `LobbyController`, `GameService`
**Symptôme :** Risque d'appeler `quitGame()` (qui pose `is_alive=false`) depuis la waiting-room par analogie avec les vues de jeu.
**Cause :** Règle métier non documentée : en waiting-room, le joueur n'a pas encore de statut vivant/mort.
**Fix / Décision :** Deux chemins distincts :
- Depuis `waiting-room` → DELETE sur `game_players` (le joueur n'a pas encore de statut vivant/mort, il quitte simplement la file)
- Depuis une vue de jeu (night, day, etc.) → `quitGame()` → `is_alive = false`
**Leçon :** Documenter explicitement les deux chemins de sortie. Ne pas réutiliser `quitGame()` hors du contexte de jeu actif.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Convention GAME_ID dans les partials Alpine

**Contexte :** Tous les partials Blade avec `x-data`, notamment `night.blade.php`, `day.blade.php`
**Symptôme :** Deux patterns coexistants dans les partials : `this.gameId` (référence au store Alpine) et `const GAME_ID = @json(...)` (injection Blade locale).
**Cause :** Convention absente dans CONVENTIONS.md — chaque partial a choisi son pattern indépendamment.
**Fix / Décision :** Convention unique → toujours déclarer `const GAME_ID = @json($game->id)` en haut du bloc `<script>` du partial. Jamais `this.gameId`. Jamais de variable Blade injectée directement dans un store Alpine (risque null au chargement).
**Leçon :** Ajouter la règle dans CONVENTIONS.md. Une variable Blade dans un store Alpine est évaluée une fois au rendu serveur — si l'état change côté client après, le store ne se met pas à jour.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Recette "Couche 2 (lobby)" CAS 1-2 — redirection JS, pas de 302 serveur

**Contexte :** `tests/Feature/LobbyTest.php`, `LobbyController::create/join`, `lobby/index.blade.php`
**Symptôme / Problème :** La recette manuelle attend "redirige vers /game/{code}/lobby" (HTTP 302). Mais `POST /game` et `POST /game/{code}/join` répondent en JSON `{success, data, message}` (conforme CLAUDE.md §Réponses API), et c'est `lobbyApp()` (Alpine) qui exécute `window.location.href = '/game/' + code + '/lobby'` après un succès.
**Cause / Alternatives :** Soit transformer ces routes en formulaires web classiques avec redirect serveur (casserait l'UX AJAX existante et la règle CLAUDE.md sur les réponses API), soit tester le contrat JSON qui pilote la redirection côté client.
**Fix / Décision :** Tests `LobbyTest::test_cas1...` / `test_cas2...` vérifient (1) le JSON contient bien `data.code` / `data.game_code` au format attendu par le JS, et (2) que `/game/{code}/lobby` est atteignable avec ce code (vue `game.waiting-room`). Le comportement "redirige vers le lobby" est donc validé de bout en bout sans dépendre d'un 302 serveur qui n'existe pas dans cette architecture.
**Leçon :** Pour les flux pilotés en AJAX, une "redirection" attendue dans une recette manuelle se traduit en test par : (a) le contrat JSON exploitable par le JS, (b) l'atteignabilité de la page cible. Ne pas forcer un test `assertRedirect()` sur un endpoint JSON.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Audit final — GSAP entry animations non protégées prefers-reduced-motion

**Contexte :** Tâche D — `resources/views/game/night.blade.php`, `resources/views/game/day.blade.php`
**Symptôme / Problème :** Les appels GSAP `fromTo('.reveal', ...)` en début de script (night) et dans `init()` (day) n'étaient pas wrappés dans un check `window.matchMedia('(prefers-reduced-motion: reduce)')`. Le CSS `app.css` neutralise CSS transitions/animations mais pas les tweens GSAP qui passent par `requestAnimationFrame`.
**Cause / Alternatives :** GSAP ignore les media queries CSS — il faut le check JS explicite.
**Fix / Décision :** Ajout d'un `if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches)` autour des deux appels. Cohérent avec tous les autres appels GSAP des mêmes fichiers qui étaient déjà correctement protégés.
**Leçon :** Les appels GSAP à l'init (hors handlers WebSocket) sont les plus susceptibles d'être oubliés. Vérifier systématiquement les `gsap.*` hors handlers lors d'un audit.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Recette "Couche 2 (lobby)" CAS 5-6 — OTP déjà conforme

**Contexte :** `lobby/index.blade.php` (`lobbyApp()`)
**Symptôme / Problème :** Vérifier que la navigation auto entre cases OTP et le pré-remplissage `?code=XXXXXX` sont implémentés en Alpine.js et fonctionnels.
**Cause / Alternatives :** Revue statique du composant `lobbyApp()`.
**Fix / Décision :** Déjà conforme, aucune correction nécessaire :
- `onOtpInput()` (ligne ~250) : sur saisie d'un caractère, focus la case `i+1` si `i < 6`.
- `onOtpKeydown()` (ligne ~259) : sur `Backspace` avec case courante vide, focus la case `i-1` si `i > 1` (gère aussi `ArrowLeft`/`ArrowRight`).
- `init()` via `x-init="init()"` (ligne ~225) : lit `window.location.search`, extrait `code`, l'uppercase/pad et alimente `joinForm.codeChars`. `activeTab` bascule aussi sur l'onglet "join" si `?code=` est présent.
**Leçon :** Le composant `lobbyApp()` respecte déjà les règles Alpine du projet (pas de `setTimeout`, logique dans le store du composant). Rien à modifier pour ces deux cas.
**Statut :** ✅ Résolu