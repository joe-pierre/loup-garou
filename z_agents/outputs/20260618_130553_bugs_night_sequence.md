J'ai maintenant tous les éléments nécessaires. Voici le rapport complet.

---

# Rapport de détection — GameService.php · VoteService.php · ProcessNightActions.php · ProcessNightEnd.php · ProcessWitchTurn.php · ProcessWitchAutoAction.php · ProcessHunterTurn.php · ProcessHunterAutoAction.php · ProcessSeerTurn.php · ProcessSeerAutoAction.php · ProcessWerewolvesTurn.php · ProcessMayorElection.php · ProcessMayorSuccession.php · ProcessDayVote.php · PhaseManager.php · WinConditionChecker.php · PhaseGuard.php · TimerCalculator.php — 2026-06-18

---

## Résumé
- **Bugs critiques (bloquants) : 4**
- **Bugs potentiels (à surveiller) : 3**
- **Conformités vérifiées : 16**

---

## Bugs détectés

---

### [CRITIQUE] — Broadcasts dans `DB::transaction()` + `lockForUpdate()` : GameService::cancelGame()

**Fichier :** `app/Services/GameService.php`
**Ligne approximative :** 770–789
**Description :**
`broadcast(new GameFinished(...))` est appelé à l'intérieur d'un `DB::transaction()` qui contient un `lockForUpdate()` sur la table `games`. Si Reverb est lent (>quelques secondes), le verrou exclusif sur la ligne reste tenu pendant toute la durée du broadcast. Tout autre job ayant besoin de `lockForUpdate` sur la même ligne (ex. `startNight`, `resolveDayVote`, `startDay`) attend indéfiniment — **deadlock MySQL effectif**.

**Scénario de reproduction :**
1. Jeu en cours, > 50% joueurs inactifs → `cancelGame()` est appelé.
2. Reverb connaît un pic de latence de 2-3 s.
3. Simultanément, un job `ProcessNightEnd` (ou `ProcessDayVote`) tente un `lockForUpdate` sur la même ligne `games`.
4. Les deux transactions se bloquent mutuellement.

**Règle violée :** `CLAUDE.md` — "Broadcasts (broadcast()) INTERDIT dans DB::transaction() — risque de rollback si Reverb lent" · `RISK_GUARDS.md` Guard #5 (esprit : ne pas tenir de lock pendant des I/O externes)

**Impact :** Partie bloquée en état intermédiaire. Tous les jobs suivants (`ProcessNightEnd`, `ProcessDayVote`, `startNight`) n'obtiennent jamais le verrou et expirent silencieusement.

---

### [CRITIQUE] — Broadcasts dans `DB::transaction()` + `lockForUpdate()` : GameService::markReady()

**Fichier :** `app/Services/GameService.php`
**Ligne approximative :** 220–244
**Description :**
`markReady()` tient un `lockForUpdate()` sur la ligne `games` (`status='electing_mayor'`) **pendant** deux broadcasts : `PlayerReady` (ligne 235) et, si tous prêts, `MayorElectionStarted` (ligne 242). Le broadcast `ProcessMayorElection::dispatch(...)->delay(timer)` dispose du délai réglementaire (>0s) — seul point conforme. Mais si les deux `broadcast()` prennent du temps (Reverb ralenti), le lock est tenu sur `games` pendant l'opération.

**Scénario de reproduction :**
1. Le dernier joueur appuie sur "Prêt".
2. `markReady()` tient le lock sur `games`.
3. Reverb met 3 s à accuser réception de `PlayerReady`.
4. Si un second joueur fait une action en parallèle (ex. `/game/{id}/state`), toute requête voulant lire `games` avec lock attend.

**Règle violée :** `CLAUDE.md` — "Broadcasts INTERDIT dans DB::transaction()"

**Impact :** Freeze de la transition `electing_mayor → night`. Autres appels sur la partie en attente jusqu'à expiration du lock MySQL.

---

### [CRITIQUE] — Broadcasts et notifications push dans `DB::transaction()` : GameService::joinGame() + startGame()

**Fichier :** `app/Services/GameService.php`
**Lignes approximatives :** 131 (joinGame) · 185, 198–201 (startGame)
**Description :**
`joinGame()` encapsule tout dans un `DB::transaction()` avec `lockForUpdate()` (ligne 93). À l'intérieur :
- `broadcast(new PlayerJoined(...))` (ligne 131)
- Appel à `startGame()` si `currentCount === max_players`, qui ouvre une **sous-transaction (savepoint)** contenant :
  - `broadcast(new GameStarted($locked))` (ligne 185)
  - `broadcast(new GameStarted($locked, $player, $allies))` × N joueurs (ligne 198)
  - `$player->user->notify(new RoleAssignedNotification(...))` × N joueurs (lignes 200–202) — la notification push est un appel réseau

Le lock MySQL sur `games` est tenu pendant tous ces I/O réseau.

**Scénario de reproduction :**
1. Le dernier joueur rejoint la partie (max_players atteint).
2. `joinGame()` démarre, lock tenu sur `games`.
3. `startGame()` broadcast N fois GameStarted + envoie N notifications push.
4. Si une notification push prend 2 s (réseau lent), le lock reste tenu 2 s.
5. Toute tentative concurrente de lire/modifier la partie est bloquée.

**Règle violée :** `CLAUDE.md` — "Broadcasts INTERDIT dans DB::transaction()"

**Impact :** Freeze du démarrage de partie. Si la notification échoue et déclenche une exception (malgré le `try-catch`, le délai est réel), la transaction pourrait rester ouverte. `startGame()` peut aussi être appelé directement (pas depuis `joinGame()`), donc le problème existe aussi hors du contexte imbriqué.

---

### [POTENTIEL] — hunter_pending lu et supprimé sans `lockForUpdate` : ProcessNightEnd

**Fichier :** `app/Jobs/ProcessNightEnd.php`
**Ligne approximative :** 73–81
**Description :**
La lecture + suppression de `hunter_pending` n'est pas atomique. Le pattern actuel :
```php
$hunterPending = GameAction::where(...)->first();   // lecture sans lock
if ($hunterPending) {
    $hunterPending->delete();                        // suppression sans transaction
    ProcessHunterTurn::dispatch(...)->delay(0);
    return;
}
```
Si deux instances de `ProcessNightEnd` s'exécutent quasi-simultanément (retry worker, ou `ProcessWitchAutoAction` et le buffer de `ProcessNightActions` arrivent en même temps), les deux lisent le record, les deux le suppriment (le deuxième `delete()` efface 0 lignes sans erreur), et **les deux dispatchent `ProcessHunterTurn`**.

**Scénario de reproduction :**
1. Chasseur tué la nuit → `hunter_pending` créé.
2. `ProcessWitchAutoAction` dispatche `ProcessNightEnd::delay(0)`.
3. Presque simultanément, `ProcessNightEnd` dispatché par `ProcessNightActions` avec un délai légèrement plus court qu'attendu (worker rapide).
4. Deux instances `ProcessNightEnd` passent le guard `isNightOrProcessing`.
5. Toutes deux trouvent `hunter_pending`, toutes deux dispatchent `ProcessHunterTurn`.
6. Double `HunterTurnStarted` broadcasté → UI chasseur affichée deux fois.

**Règle violée :** Pattern lockForUpdate manquant avant lecture conditionnant une écriture (RISK_GUARDS.md §1 — pattern général)

**Impact :** `HunterTurnStarted` broadcasté en double. L'UI du chasseur s'affiche deux fois. `ProcessHunterAutoAction` tiré deux fois (le second est no-op grâce au guard `isNightOrProcessing`, mais le premier appel à `endNight()` peut avoir déjà changé le statut). Probabilité faible mais non nulle avec des workers multiples.

---

### [POTENTIEL] — ProcessSeerTurn sans round guard

**Fichier :** `app/Jobs/ProcessSeerTurn.php`
**Ligne approximative :** 44–49
**Description :**
`ProcessSeerTurn` ne stocke pas de `$round` dans son constructeur. Le seul guard est :
```php
if (! $game || $game->status !== 'night') {
    return;
}
```
En cas de retry du job Laravel (exception en milieu d'exécution), la même instance se ré-exécute sur la nuit suivante si le statut est toujours `'night'` :
- `SeerTurnStarted` broadcasté deux fois sur le canal privé de la voyante → UI voyante dupliquée.
- Deux `ProcessSeerAutoAction` dispatchés pour le même round (le second est no-op grâce à `$alreadyActed`).
- Deux `ProcessWerewolvesTurn` dispatchés → seul le premier réussit (lockForUpdate `status='night'`).

**Scénario de reproduction :**
1. `ProcessSeerTurn` démarre (round N), broadcast `SeerTurnStarted`, puis lève une exception transient (connexion DB brève).
2. Laravel retry le job.
3. La nuit N est toujours en cours (`status='night'` — le statut n'a pas changé).
4. `SeerTurnStarted` broadcasté une seconde fois.

**Règle violée :** Guard de round manquant dans `handle()` — pratique établie pour tous les autres jobs (`ProcessNightEnd`, `ProcessWitchTurn`, `ProcessHunterTurn`, etc.)

**Impact :** Interface voyante dupliquée. Double dispatch de `ProcessSeerAutoAction` (no-op grâce au guard `alreadyActed`) et `ProcessWerewolvesTurn` (seul le premier réussit). Faible probabilité mais pattern incohérent avec les autres jobs.

---

### [POTENTIEL] — VoteService::castDayVote() — statut game non re-vérifié dans la transaction

**Fichier :** `app/Services/VoteService.php`
**Ligne approximative :** 434–492
**Description :**
Le statut `$voter->game->status !== 'day'` est vérifié **hors transaction**, sans `lockForUpdate`. La transaction interne ne re-vérifie pas le statut du jeu — elle ne pose de lock que sur `game_actions` (anti-double-vote). Entre le check initial et le début de la transaction, si `resolveDayVote()` est déjà en cours (game → `processing_day`), un vote peut être inséré après résolution :

```php
// Ligne 434 — HORS transaction, pas de lockForUpdate sur 'games'
if ($voter->game->status !== 'day') {
    abort(409, ...);
}
// ... (quelques ms)
DB::transaction(function () use (...) {
    // Pas de re-check status game ici
    GameAction::create([...]);  // vote inséré même si status='processing_day'
});
```

**Scénario de reproduction :**
1. Tous les joueurs ont voté → `ProcessDayVote::dispatch()` (sans délai).
2. `resolveDayVote()` tourne, transite status → `processing_day`.
3. Un joueur ultra-tardif passe le check `status !== 'day'` une milliseconde avant la transition.
4. Sa transaction insère un vote dans `game_actions` pour un round déjà résolu.

**Règle violée :** Lecture conditionnant une écriture sans lockForUpdate sur la ligne `games` (pattern etabli dans `resolveDayVote` via `where('status', 'day')->lockForUpdate()`)

**Impact :** Vote fantôme en DB (`game_actions`) pour le round déjà résolu. Aucun effet sur l'issue du vote (déjà calculée). Potentiellement visible dans l'historique (`HistoryService`). Probabilité très faible (fenêtre de ~1-2 ms).

---

## Fichiers concernés (corrections nécessaires)
- `app/Services/GameService.php` — déplacer tous les `broadcast()` et `notify()` **hors** des `DB::transaction()` dans `cancelGame()`, `markReady()`, `joinGame()`, `startGame()`, `excludePlayer()`
- `app/Jobs/ProcessNightEnd.php` — envelopper la lecture + suppression de `hunter_pending` dans un `DB::transaction()` avec `lockForUpdate()`
- `app/Jobs/ProcessSeerTurn.php` — ajouter un paramètre `$round` et un guard `$game->round !== $this->round`

---

## Conformités vérifiées

1. **Guard #5 (applyTransition dans lockForUpdate)** : `applyTransition()` n'est défini que dans `Game.php` (ligne 101) et n'est **jamais appelé** depuis l'intérieur d'un `DB::transaction()` avec `lockForUpdate`. Tous les guards utilisent `canTransition()` suivi d'un `$locked->update([...])` manuel. ✅
2. **Timers — règle absolue** : `config('game.timers.X')` n'est appelé directement que dans `TimerCalculator::get()` (fallback interne) et `GameService::validateTimerSettings()` (`config('game.timers.limits')` — exception explicitement autorisée). Tous les appels en contexte de partie passent par `$game->timer('key')`. ✅
3. **Broadcasts hors transactions dans PhaseManager** : `startDay()`, `startNight()`, `endNight()` placent leurs `broadcast()` et `dispatch()` systématiquement **après** le `DB::transaction()`. ✅
4. **Guard #4 — ProcessWitchTurn dispatché uniquement depuis ProcessNightActions** : aucun appel à `ProcessWitchTurn::dispatch()` dans `ProcessWerewolvesTurn`, `ProcessSeerAutoAction` ou `ProcessSeerTurn`. ✅
5. **Guard #6 — Double dispatch ProcessWitchTurn** : guard `$alreadyActed` (whereIn `witch_heal/witch_kill/witch_pass`) présent en entrée de `ProcessWitchTurn::handle()`. ✅
6. **Guard #3 révisé — Sorcière sans victime** : les trois cas (deux potions épuisées, pas de victime + poison épuisé, autres) correctement gérés dans `ProcessWitchTurn::handle()`. ✅
7. **Guard #2 — Chasseur déclenché après résolution nocturne** : `hunter_pending` créé en DB (non en cache) dans `ProcessNightActions`, lu par `ProcessNightEnd` avant `endNight()`. ✅
8. **shouldStartNight flag dans ProcessMayorSuccession** : paramètre présent, utilisé pour éviter la race condition avec `ProcessNightEnd`. ✅
9. **Broadcasts hors transaction dans ProcessNightActions** : `broadcast(PlayerEliminated)` émis après `DB::transaction()`, pas dedans. ✅
10. **Broadcast hors transaction dans ProcessMayorSuccession** : `broadcast(MayorSuccessionDone)` explicitement après `return ['game' => ..., 'successor' => ...]` du closure. ✅
11. **Round guards présents dans tous les Jobs paramétrés** : `ProcessNightActions`, `ProcessNightEnd`, `ProcessWitchTurn`, `ProcessWitchAutoAction`, `ProcessHunterTurn`, `ProcessHunterAutoAction`, `ProcessMayorSuccession`, `ProcessDayVote`, `ProcessSeerAutoAction` — tous vérifient `$game->round !== $this->round`. ✅
12. **canWitchAct guard** : `PhaseGuard::canWitchAct()` couvre `['night', 'processing_night']`, exclut `wolves_turn` (sorcière ne peut pas agir pendant le vote des loups). ✅
13. **canChatWolves couvre wolves_turn** : `['night', 'wolves_turn']` — corrigé selon DECISIONS.md. ✅
14. **Dispatch sans délai hors transaction** : `ProcessDayVote::dispatch()` dans `castDayVote()` et `ProcessMayorElection::dispatch()` dans `castMayorVote()` sont hors transaction — règle respectée. ✅
15. **TimerCalculator::get() — null-safe settings** : `$game->settings['timers'] ?? []` présent (Guard #1). ✅
16. **Pas de lockForUpdate autour de applyTransition** : `Game::applyTransition()` n'est jamais appelé dans aucun fichier applicatif — la méthode existe uniquement pour l'infrastructure Symfony Workflow si besoin. ✅

---

Rien à ajouter dans `DECISIONS.md` — ce rapport est une analyse externe, aucune modification de code n'a été effectuée.
