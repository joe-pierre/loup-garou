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

## [CHOIX] Vote nuit — delete+insert pour permettre le changement de cible

**Contexte :** Tâche 14 — `VoteService::castNightVote()`, `app/Models/GameAction`
**Problème :** Un loup doit pouvoir changer sa cible jusqu'à expiration du timer. `updateOrCreate` exige une contrainte unique en base, absente sur `game_actions`. `UPDATE` direct est fragile (dépend d'un ID existant inconnu).
**Alternatives :** (1) Ajouter une contrainte unique `(game_id, player_id, round, type)` + `updateOrCreate` — trop invasif, les autres types d'action autorisent plusieurs lignes. (2) `whereFirst()->update()` — race condition entre lecture et écriture. (3) `lockForUpdate()->delete()` + `create()` dans une transaction.
**Décision :** Option 3 retenue : `lockForUpdate()->delete()` puis `GameAction::create()` dans une transaction. Simple, safe, cohérent avec l'absence de contrainte unique.
**Leçon :** Ne pas ajouter de contrainte unique sur `game_actions` pour "faciliter" updateOrCreate — d'autres types d'action (seer_check, ready) n'ont qu'un enregistrement par design mais sans contrainte DB. Utiliser delete+insert en transaction pour toute action "remplaçable".
**Statut :** 🔵 Choix assumé

---

## [CHOIX] SeerResult broadcasté sur canal privé joueur, jamais sur canal public

**Contexte :** Tâche 13 — `SeerTurnStarted`, `SeerResult`
**Problème :** Le résultat d'inspection de la voyante ne doit jamais fuiter aux autres joueurs, même en cas d'erreur de routing.
**Décision :** Les deux events voyante passent exclusivement par `PrivateChannel("game.{id}.player.{seer->id}")` — jamais sur `game.{id}`.
**Leçon :** Toute information de rôle privée (résultat voyante, composition loups) → canal privé individuel obligatoire. Canal public = informations visibles par tous.
**Statut :** 🔵 Choix assumé