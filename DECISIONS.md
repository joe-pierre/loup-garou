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

## [CHOIX] SeerResult broadcasté sur canal privé joueur, jamais sur canal public

**Contexte :** Tâche 13 — `SeerTurnStarted`, `SeerResult`
**Problème :** Le résultat d'inspection de la voyante ne doit jamais fuiter aux autres joueurs, même en cas d'erreur de routing.
**Décision :** Les deux events voyante passent exclusivement par `PrivateChannel("game.{id}.player.{seer->id}")` — jamais sur `game.{id}`.
**Leçon :** Toute information de rôle privée (résultat voyante, composition loups) → canal privé individuel obligatoire. Canal public = informations visibles par tous.
**Statut :** 🔵 Choix assumé