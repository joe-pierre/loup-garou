## [CHOIX] Suppression du nom Google des payloads broadcast publics

**Contexte :** `fix/remove-google-name-from-broadcast` — `app/Events/Game/PlayerEliminated.php`, `HunterShot.php`, `RandomElimination.php`, `resources/js/game-state.js`.

**Symptôme / Problème :** Le champ `google_name` (issu de `users.name`, nom réel récupéré via OAuth Google) était inclus dans trois événements broadcast émis sur le canal public `game.{gameId}`. N'importe quel joueur connecté pouvait donc lire l'identité réelle de ses adversaires, y compris leur vrai nom.

**Cause / Alternatives :** Le champ avait été ajouté pour construire un toast `"💀 Jean aka Pseudo était le Loup-Garou"` côté client. Deux alternatives envisagées : (1) hasher ou tronquer le nom — rejeté, trop complexe et toujours partiel ; (2) supprimer purement et simplement — retenu.

**Fix / Décision :** Suppression de `google_name` et `target_google_name` des trois `broadcastWith()`. Toast réduit à `"💀 Pseudo était le Rôle"`. `users.name` reste stocké en base pour un futur dashboard admin (usage serveur uniquement).

**Leçon :** Ne jamais émettre de données d'authentification (email, nom OAuth, avatar) dans un canal broadcast public. Les pseudos de jeu suffisent côté client — les données d'identité réelle n'ont leur place que côté serveur ou dans des canaux privés authentifiés.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Toast "Reconnecté !" parasite sur connexion WebSocket initiale

**Contexte :** `fix/toast-reconnecte-ws-initial` — `resources/js/game-state.js`, section "Reconnexion WebSocket (Pusher/Reverb)".

**Symptôme / Problème :** À chaque navigation interne (nuit→jour, jour→nuit, etc.), le toast "Reconnecté !" s'affichait alors que le joueur n'avait jamais perdu la connexion. Le comportement survenait à chaque chargement de page, rendant le toast inutile et trompeur.

**Cause / Alternatives :** `conn.bind('connected', ...)` se déclenche à toute connexion Pusher/Reverb — y compris la connexion initiale établie par Echo au chargement de la page. Il n'existe pas de distinction native entre "première connexion" et "reconnexion après coupure" dans l'API Pusher. Alternative envisagée : utiliser l'état `conn.state` avant la connexion pour détecter une reconnexion réelle — rejetée, l'état change de façon asynchrone et est peu fiable au moment du binding.

**Fix / Décision :** Flag `_wsEverConnected` initialisé implicitement à `undefined` (falsy) sur `this`. Au premier `connected` : flag posé à `true`, return sans toast. Aux connexions suivantes : vérification du guard `sessionStorage.__internalNavigation` (cohérent avec `_handlePlayerDisconnected`, `_handlePlayerReconnected`, `_handlePlayerInactive`) puis toast affiché. Chaque page étant un nouveau contexte JS, `_wsEverConnected` est réinitialisé à chaque chargement — la première connexion par page est toujours silencieuse.

**Leçon :** Tout handler sur `conn.bind('connected', ...)` doit distinguer la connexion initiale (silencieuse) des reconnexions réelles (toast utile). Le pattern `_wsEverConnected` est applicable à tout futur binding Pusher sur `connected`. Ne pas supposer que `connected` implique une coupure préalable.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Chasseur Maire — tir avant succession du maire (inversion priorité hunter_pending > is_mayor)

**Contexte :** `fix/bug-chasseur-maire-ordre-succession` — `app/Services/VoteService.php`, `app/Jobs/ProcessHunterTurn.php`, `app/Jobs/ProcessHunterAutoAction.php`, `app/Jobs/ProcessNightEnd.php`, `app/Http/Controllers/Game/ActionController.php`.

**Symptôme / Problème :** Quand le Chasseur était aussi Maire et qu'il était éliminé (jour OU nuit), la succession du Maire était déclenchée immédiatement avant que le Chasseur ait pu tirer (ou renoncer). L'ordre correct (SPEC.md) est : tir du Chasseur (ou renoncement/timer) → succession ensuite.

**Cause / Alternatives :**
Dans `VoteService::resolveDayVote()`, le bloc `if ($eliminated->is_mayor)` prenait la priorité absolue via un `if/elseif` : si `is_mayor === true`, la branche `hunter_pending` n'était jamais atteinte. `ProcessHunterTurn` et `ProcessHunterAutoAction` ne transmettaient pas l'information `$isMayor` et ne pouvaient donc pas déclencher la succession après le tir. `ProcessNightEnd` dispatche `ProcessHunterTurn` sans `$isMayor`, même bug pour la mort nocturne. Alternative envisagée : déclencher la succession depuis `ProcessHunterTurn` directement — rejeté, ce job ne sait pas quand le tir a lieu (il déclenche juste le tour du chasseur, pas la résolution).

**Fix / Décision :**
1. `VoteService::resolveDayVote()` : capturer `$isMayor = $eliminated->is_mayor` avant le bloc conditionnel. Inversion de priorité : `if ($hunterPending)` en premier, `elseif ($isMayor)` en fallback. `$isMayor` passé à `ProcessHunterTurn::dispatch`.
2. `ProcessHunterTurn` : `bool $isMayor = false` ajouté au constructeur, transmis à `ProcessHunterAutoAction::dispatch`.
3. `ProcessHunterAutoAction` : `bool $isMayor = false` ajouté au constructeur. Quand `$isMayor=true`, après le check victoire : guard `hunter_shot` en DB (tir volontaire → ActionController a déjà broadcasté la succession → return). Si pas de hunter_shot (timer expiré) → `broadcast(MayorSuccessionStarted)` + `ProcessMayorSuccession::dispatch`. Pour le cas nuit, dispatch supplémentaire de `ProcessNightEnd` (délai succession + 5s) pour terminer la nuit après la succession (ProcessMayorSuccession avec `shouldStartNight=true` ne termine pas la nuit lui-même).
4. `ActionController::hunterShoot()` : lit `$isMayor = $hunter->is_mayor` avant le tir. Si `$isMayor=true` : broadcast `MayorSuccessionStarted` + dispatch `ProcessMayorSuccession` (+ `ProcessNightEnd` si contexte nuit). Passe `$isMayor` à `ProcessHunterAutoAction::dispatch` — ce dernier voit `hunter_shot` en DB et retourne sans re-déclencher (évite la double succession).
5. `ProcessNightEnd` : après extraction de `$hunterId`, lit `$hunter->is_mayor` et passe `$isMayor` à `ProcessHunterTurn::dispatch`.

**Leçon :** Quand un joueur cumule deux rôles avec des comportements post-mort (Chasseur tir + Maire succession), toujours traiter les deux comportements en séquence stricte : le plus actif (tir) d'abord, le passif (succession) ensuite. Ne jamais tester `is_mayor` avant `hunter_pending` dans un `if/elseif`. Transmettre le contexte `$isMayor` à travers tous les jobs de la chaîne pour éviter de perdre l'information entre les dispatches.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Messages sorcière non différenciés au matin — DayStarted enrichi + logique sessionStorage

**Contexte :** `fix/bug-messages-sorciere-matin` — `app/Events/Game/DayStarted.php`, `app/Services/PhaseManager.php`, `resources/js/game-state.js`.

**Symptôme / Problème :** Tous les joueurs recevaient le même toast générique "🧙 La sorcière a agi cette nuit." au matin, quelle que soit leur position dans l'action de la sorcière (sorcière elle-même, joueur sauvé par auto-soin, joueur empoisonné, spectateurs). La sorcière et le joueur empoisonné ne recevaient aucun message personnalisé.

**Cause / Alternatives :** `DayStarted::broadcastWith()` ne transportait que `witch_acted` (booléen) et `saved_player_id` — impossible d'identifier la sorcière ou le joueur empoisonné côté client. Le toast "☠️ La sorcière t'a empoisonné" était dans `handlePlayerEliminated()` (reason `witch_kill`), mais cette fonction s'exécute sur `/night` dont la page est détruite par la redirection GSAP avant que le composant toast puisse rendre — le toast était donc invisible dans tous les cas.

**Fix / Décision :** Trois champs ajoutés à `DayStarted` : `witch_player_id` (id de la sorcière ayant agi), `poisoned_player_id` (id du joueur empoisonné), `poisoned_player_pseudo` (pseudo du joueur empoisonné). `PhaseManager::endNight()` les calcule depuis les `game_actions` du round courant, hors de toute transaction `lockForUpdate()`. `game-state.js::_applyDayStarted()` : le bloc `witch_acted` générique est remplacé par une logique différenciée — sorcière (auto-soin ou poison), joueur empoisonné, et autres joueurs reçoivent chacun leur message via sessionStorage ou toast direct. Le bloc `if (e.reason === 'witch_kill')` dans `handlePlayerEliminated()` est supprimé : le toast n'était jamais visible côté client (page `/night` détruite avant rendu), et le sessionStorage sur `/day` est le seul canal fiable pour ce cas.

**Leçon :** Tout toast destiné à un joueur spécifique lors d'une transition de phase (redirection GSAP) doit passer par `sessionStorage` (`pending_toast_{playerId}`), jamais par `_dispatchToast()` dans le handler de l'event déclencheur de la redirection. Les toasts dans `handlePlayerEliminated()` ne sont fiables que si la page n'est pas détruite immédiatement après — vérifier systématiquement si la transition implique une navigation avant d'y placer un toast personnalisé.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Alpine v3 @event.window non déclenché pour les event names avec tirets dans les composants Blade

**Contexte :** `fix/bug-toast-show-toast-window-listener` — `resources/views/components/toast.blade.php`.

**Symptôme / Problème :** `window.dispatchEvent(new CustomEvent('show-toast', { detail: ... }))` ne déclenchait jamais `add()` dans le composant toast, malgré la présence du binding `@show-toast.window="add($event.detail)"` sur le `<div x-data>`. Le composant était bien dans le DOM, Alpine l'initialisait correctement (`window.__toastReady = true` était positionné, le buffer était consommé), mais les toasts ultérieurs dispatchés via `_dispatchToast()` n'apparaissaient jamais.

**Cause / Alternatives :** Alpine v3 traite les event names avec tirets dans la syntaxe `@event.window` de façon inconsistante lorsque le composant est rendu via un composant Blade anonyme (`<x-toast />`). La conversion camelCase/kebab-case peut échouer silencieusement — le listener `window.addEventListener` n'est jamais enregistré. Ce comportement ne se reproduit pas systématiquement avec des event names sans tiret (ex. `@keydown.window`).

Alternative : renommer l'event en `showtoast` ou `toast` (sans tiret) et mettre à jour tous les appelants — rejeté, risque de régression sur `_dispatchToast()` dans `game-state.js`.

**Fix / Décision :** Suppression de `@show-toast.window="add($event.detail)"` du `<div>`. Ajout de `window.addEventListener('show-toast', (e) => this.add(e.detail))` dans `init()` immédiatement après `window.__toastReady = true`. L'enregistrement impératif dans `init()` est garanti, indépendant du parser de syntaxe Alpine, et cohérent avec le pattern déjà utilisé dans `dayScreen.init()` / `nightScreen.init()` pour tous les CustomEvents.

**Leçon :** En Alpine v3, ne pas utiliser `@event.window` pour les event names contenant des tirets sur des composants Blade. Toujours préférer `window.addEventListener('event-name', ...)` dans `init()` pour les CustomEvents cross-composants. Le binding déclaratif `@event` reste valide pour les events Alpine natifs (ex. `@click`, `@keydown`), pas pour les `CustomEvent` dispatchés via `window.dispatchEvent()`.

**Statut :** ✅ Résolu

---

## [CHOIX] Votes maire publics — voter_pseudo + target_pseudo dans MayorVoteCast

**Contexte :** `fix/bug-votes-maire-temps-reel` — `app/Events/Game/MayorVoteCast.php`, `app/Http/Controllers/Game/VoteController.php`, `resources/js/game-state.js`, `tests/Unit/Events/EventPayloadTest.php`.

**Symptôme / Problème :** Pendant l'élection du Maire, les joueurs voyaient les totaux par candidat mis à jour en temps réel mais n'avaient aucune indication de qui venait de voter pour qui. L'expérience était opaque : impossible de suivre la dynamique de l'élection au moment où elle se déroule.

**Cause / Alternatives :**
1. Continuer à n'exposer que les totaux (`votes[]`) — résout l'anonymat mais sacrifie la lisibilité de l'élection.
2. Exposer `voter_pseudo` et `target_pseudo` dans `MayorVoteCast` (sans `player_id`) — choix retenu.

**Fix / Décision :** Changement de spec assumé : les votes maire sont désormais **publics** (auteur + cible visibles via leurs pseudos). Les votes JOUR restent entièrement anonymes (`DayVoteCast` non touché). `MayorVoteCast` : deux nouveaux champs `voterPseudo`/`targetPseudo` dans le constructeur et `broadcastWith()`. `VoteController::mayor()` : `$targetPseudo` extrait des totaux retournés (`collect($votes)->firstWhere('target_player_id', $targetId)['pseudo']`) — aucune requête DB supplémentaire. `game-state.js` : toast "👑 X a voté pour Y" (ou "pour lui-même") dans `_handleMayorVoteCast()`. La règle anti-spoofing est préservée : `player_id` toujours absent du payload.

**Leçon :** Le vote maire et le vote jour ont des régimes de visibilité distincts — ne jamais les traiter de manière identique. `MayorVoteCast` est public par nature (l'identité du maire est connue de tous), `DayVoteCast` est anonyme pour protéger les joueurs d'une pression sociale. La règle anti-spoofing (`player_id` toujours absent) s'applique aux deux : exposer un pseudo n'est pas la même chose qu'exposer un identifiant interne qui permettrait de manipuler les votes.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Conflit Alpine `:style` string / GSAP dans les barres de progression

**Contexte :** `fix/bug-progress-bar-role-reveal-mayor-election` — `resources/views/game/role-reveal.blade.php`, `resources/views/game/mayor-election.blade.php`.

**Symptôme / Problème :** Les barres de progression (`#game-timer-fill`, `#election-timer-fill`) restaient pleines et ne s'animaient pas, même si GSAP était correctement appelé.

**Cause / Alternatives :**
En Alpine.js v3, un binding `:style` avec une valeur de type *string* (ex. `':style="'background-color:' + color"`) appelle `el.style.cssText = value` en interne. Cela remplace l'*intégralité* du style inline — y compris la propriété `width` animée par GSAP — à chaque tick réactif (toutes les secondes ici). GSAP animait la largeur, Alpine la remettait à 100% une seconde plus tard.

Alternative envisagée : utiliser le composant `<x-game-timer>` — rejeté car ce composant a le même défaut (`:style="'width:100%; background-color:' + barColor"` inclut `width` dans la chaîne).

**Fix / Décision :** Suppression du binding `:style` Alpine sur les éléments de remplissage (`#reveal-timer-bar`, `#game-timer-fill`, `#election-timer-fill`). GSAP prend en charge à la fois `width` (via `gsap.to(bar, { width: '0%', ... })`) et `backgroundColor` (via `gsap.to(bar, { backgroundColor: '...', duration: 0.3 })`). Guard `prefers-reduced-motion` ajouté avant tout appel GSAP sur les barres. Pattern identique à `night.blade.php` (`#seer-timer-bar`).

**Leçon :** Ne jamais inclure `width` dans un binding `:style` Alpine sur un élément dont la largeur est animée par GSAP. Pour les changements de couleur conditionnels sur une barre de timer, utiliser `gsap.to(bar, { backgroundColor: ... })` dans le `setInterval` au passage des seuils (ex. 10s, 5s), pas un binding réactif Alpine.

**Statut :** ✅ Résolu

---

## [CHOIX] Persistance de random_elimination en DB plutôt que reconstruction depuis le broadcast

**Contexte :** `fix/add-random-elimination-history` — `app/Services/VoteService.php`, `app/Http/Controllers/Game/GameController.php`, `app/Services/HistoryService.php`.

**Symptôme / Problème :** L'historique de partie n'affichait pas la victime lors d'une élimination aléatoire (0 votes jour). `buildTimeline()` n'avait aucune source de données pour retrouver la victime dans ce cas — `random_elimination` n'était pas dans le `whereIn` et aucune `GameAction` ne représentait cet événement.

**Cause / Alternatives :**
1. Reconstruire la victime depuis le broadcast `RandomElimination` (événement WebSocket) — rejeté : les broadcasts ne sont pas persistés ; l'historique deviendrait dépendant de données éphémères non disponibles après rechargement ou reconnexion.
2. Persister l'élimination aléatoire en DB via un `GameAction random_elimination` au moment de l'élimination — retenu : cohérent avec les patterns `hunter_pending`, `night_resolve`, `mayor_succession` déjà en place.

**Fix / Décision :** `GameAction random_elimination` créé dans la branche 0-votes de `resolveDayVote()` avec `player_id = target_player_id = victim->id`. `random_elimination` ajouté au `whereIn` de `GameController::history()`. `HistoryService::buildTimeline()` lit l'action dans la branche `else` (aucun vote) et peuple `$dayEntry['eliminated']`.

**Leçon :** Tout événement devant apparaître dans l'historique doit être persisté en `game_actions` au moment où il se produit. Ne jamais reconstruire l'historique depuis les broadcasts WebSocket — ils sont éphémères et ne survivent pas aux reconnexions ou rechargements de page.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Maire en sursis — succession différée si sorcière avec soin disponible

**Contexte :** Phase 26 — `ProcessNightActions.php`, `WitchAction.php`.

**Symptôme / Problème :** Quand les loups tuaient le maire, `ProcessNightActions` broadcastait `MayorSuccessionStarted` et dispatchait `ProcessMayorSuccession` immédiatement après avoir marqué le maire mort — avant que la sorcière ait pu agir. Si la sorcière utilisait ensuite son soin, la succession était déjà partie et le maire était simultanément vivant et en cours de succession.

**Cause / Alternatives :**
1. Annuler la succession depuis `WitchAction::heal()` si en cours — rejeté : `ProcessMayorSuccession` est déjà en file, annulation complexe et non atomique.
2. Différer la mort du maire dans `ProcessNightActions`, symétrique au sursis sorcière — retenu : pattern déjà validé, cohérent.

**Fix / Décision :** `ProcessNightActions` résout `$witch` avant le bloc victime. Flag `$victimIsMayorWithWitchAvailable` (victime est maire + sorcière vivante avec soin). Si vrai : mort et succession différées (is_alive reste true, pas de broadcast). `WitchAction::kill()` et `::pass()` : après le check witch-as-victim, vérifier si le night_resolve cible un maire encore vivant (en sursis) ; si oui, marquer mort + broadcast `PlayerEliminated` + `MayorSuccessionStarted` + dispatch `ProcessMayorSuccession` hors transaction. `WitchAction::heal()` : rien de spécial — le maire est sauvé (`is_alive` reste true). Cas edge witch = maire : géré dans le bloc `$witchDiedFromWolves` (dispatch succession si `$witch->is_mayor`).

**Leçon :** Tout joueur dont la mort peut être annulée par une action ultérieure doit être mis "en sursis" dans le job de résolution automatique. La règle est identique pour la sorcière elle-même et pour le maire — généraliser le pattern à tout rôle protégeable.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Sorcière en sursis — ne pas marquer morte immédiatement si potion de soin disponible

**Contexte :** `feat/witch-self-heal-and-notifications` — `ProcessNightActions.php`, `ProcessWitchTurn.php`, `WitchAction.php`, `WitchActedPublic.php`, `PlayerEliminated.php`, `HunterShot.php`, `ActionController.php`, `night.blade.php`, `game-state.js`.

**Symptôme / Problème :** Règle officielle Loup-Garou : la sorcière PEUT se sauver elle-même si les loups la ciblent. L'implémentation précédente la marquait morte dans `ProcessNightActions` avant qu'elle voie son panel, et `WitchAction` refusait le `heal` quand la victime était la sorcière elle-même (`abort(403)`).

**Cause / Alternatives :**
1. Marquer la sorcière morte puis la ressusciter si elle utilise son soin — rejeté : enverrait un `PlayerEliminated` avant le tour sorcière, côté client le joueur serait affiché mort pendant qu'il joue.
2. Ne pas marquer la sorcière morte immédiatement si elle a encore son soin — retenu : elle est "en sursis". La mort est reportée à l'action `pass` ou `kill` dans `WitchAction`.

**Fix / Décision :** `ProcessNightActions` vérifie `victimIsWitchWithHeal` (is_alive + isWitch + heal non utilisée) avant de marquer morte. Si vrai : skip mort + skip `PlayerEliminated` + skip `hunter_pending` + skip notification push — mais `night_resolve` est créé dans tous les cas. `ProcessWitchTurn` autorise `healAvailable = true` quand `victim.id === witch.id` (suppression du guard `victim->id !== $witch->id`). `WitchAction::act()` : guard `abort(403)` supprimé pour l'auto-heal ; dans `pass` et `kill`, la sorcière est marquée morte si elle était la victime (`night_resolve.target_player_id === witch.id`), broadcast `PlayerEliminated` hors transaction via référence `&$witchDiedFromWolves`. `game-state.js` : guard dans `handlePlayerEliminated` pour ne pas traiter l'élimination personnelle si `nightPhase === 'witch_turn' && myRole === 'witch'` (elle sera gérée via `DayStarted`).

**Leçon :** Quand un joueur peut choisir de mourir ou survivre (action volontaire), ne jamais marquer le statut final dans le job de résolution automatique — reporter la mort à l'action du joueur lui-même. Le pattern `night_resolve` (persisté dans tous les cas) permet aux jobs suivants de reconnaître la victime sans re-calculer. Broadcast `PlayerEliminated` toujours hors transaction (`&$flag` + `broadcast` après `DB::transaction`).

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Extraction SeerAction / WitchAction / HunterAction de GameService (God Service)

**Contexte :** `refactor/extract-role-actions-from-game-service` — `app/Services/GameService.php`, `app/Services/RoleActions/SeerAction.php`, `app/Services/RoleActions/WitchAction.php`, `app/Services/RoleActions/HunterAction.php`

**Symptôme / Problème :** `GameService` était un God Service concentrant à la fois l'orchestration générale (joinGame, startGame, markReady, …) et les actions de rôles spéciaux (seerCheck, witchAct, hunterShoot). Audit Passe 3 "Faiblesse 1" : manque de maintenabilité, chaque nouveau rôle v1.3+ aurait alourdi ce fichier déjà de 835 lignes.

**Cause / Alternatives :** (1) Extraction complète avec mise à jour de tous les appelants (ActionController, Jobs, Services) — risque élevé, change l'API publique. (2) Pattern Facade/délégation : `GameService` conserve ses méthodes publiques identiques (zéro changement pour les appelants) et délègue vers des classes dédiées `RoleActions/*.php` — risque zéro de régression API. (3) Extraction en une seule passe sur les 3 actions — risque de régression accumulé non détectable par les tests intermédiaires.

**Fix / Décision :** Option 2 + extraction progressive en 3 étapes indépendantes (A→B→C), avec validation des tests entre chaque étape. `GameService::seerCheck/witchAct/hunterShoot` délèguent via `app(RoleActions\XxxAction::class)->method(...)`. Les nouvelles classes sont instanciées par le container Laravel — cohérent avec `app(VoteService::class)` déjà utilisé dans le projet. `WitchAction` reçoit `VoteService` en injection de constructeur (résolu automatiquement par Laravel). Aucun appelant externe (ActionController, Jobs) n'a été modifié — la signature publique de `GameService` reste identique.

**Leçon :** Pour extraire des méthodes d'un service central sans changer l'API publique, le pattern "délégation par le container" (`app(Xxx::class)->method(...)`) est le chemin de migration à risque minimal. Il permet de faire coexister l'ancienne signature et la nouvelle implémentation, et de valider chaque extraction indépendamment. Pour v1.3+, chaque nouveau rôle ajoute sa propre classe `RoleActions/XxxAction.php` sans toucher `GameService`.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] PhaseAnnouncement — adaptation `.announcement-text` et point d'ancrage startDay()

**Contexte :** `feat/phase-announcement-overlay` — `app/Events/Game/PhaseAnnouncement.php`, `app/Services/PhaseManager.php`, `resources/js/game-state.js`, `resources/views/components/announcement-overlay.blade.php`

**Symptôme / Problème :** Deux écarts mineurs entre la spec littérale (SPEC_TRANSITIONS.md) et le code réel.

**Cause / Alternatives :**
1. La spec §5.4 montre `gsap.fromTo('.announcement-text', ...)` mais le `<p>` du composant n'a pas la classe `announcement-text`. Sans elle, le sélecteur GSAP ne trouve rien et l'animation est silencieusement ignorée.
2. La spec §7 dit "broadcast `day_break` dans `PhaseManager::endNight()`" mais `DayStarted` est broadcasté dans `startDay()` (appelé par `endNight()`). Ajouter le broadcast dans `endNight()` aurait séparé `PhaseAnnouncement` de `DayStarted` par l'appel `startDay()`, risquant une inversion d'ordre selon les timings.

**Fix / Décision :**
1. `announcement-text` ajouté sur le `<p>` du composant overlay pour que GSAP trouve son sélecteur.
2. `PhaseAnnouncement(day_break)` ajouté dans `startDay()` juste avant `broadcast(new DayStarted(...))` — respecte la règle HORS transaction et l'ordre garanti PhaseAnnouncement → DayStarted.

**Leçon :** Quand la spec nomme une méthode comme point d'ancrage mais que le broadcast réel est dans une méthode appelée, toujours suivre l'emplacement réel du broadcast dans le code.

**Statut :** ✅ Résolu

---

## [CHOIX] PLAYERS_DATA conservé comme amorçage initial dans day.blade.php

**Contexte :** `fix/game-state-players-source-of-truth` — `resources/js/game-state.js`, `app/Http/Controllers/Game/GameController.php`, `resources/views/game/day.blade.php`

**Symptôme / Problème :** `game-state.js` déclarait `players: []` mais ne le peuplait jamais — `GameController::state()` ne retournait pas la liste des joueurs. Les vues maintenaient leur propre copie locale via `PLAYERS_DATA` (injection Blade). Violation de la règle "game-state.js = seul store source de vérité pour players[]".

**Cause / Alternatives :** Deux voies envisagées : (A) Supprimer `PLAYERS_DATA` et forcer la vue à attendre `_loadState()` avant d'afficher quoi que ce soit — risque de flash vide si l'appel XHR est lent. (B) Garder `PLAYERS_DATA` comme valeur d'amorçage initiale fournie par Blade (rendu serveur cohérent), et peupler le store via `_loadState()` pour que les futures vues puissent s'y brancher sans dupliquer leur logique.

**Fix / Décision :** Option B retenue. `PLAYERS_DATA` reste l'amorçage de `dayScreen.players` : c'est le rendu serveur initial, cohérent avec la page HTML livrée, sans flash vide. Ce n'est pas une duplication de source de vérité car `PLAYERS_DATA` n'est lu qu'une seule fois en bootstrap — toutes les mises à jour ultérieures passent par les mêmes CustomEvents (`player-eliminated`, `mayor-elected`, `mayor-succession-done`) que ceux qui mettent à jour `gameState.players`. L'audit a confirmé que `day.blade.php` écoute déjà ces events via `window.addEventListener` — aucune logique de mise à jour dupliquée. `GameController::state()` retourne maintenant `players` dans son payload, et `_loadState()` peuple `this.players` pour toute future vue qui voudra s'y brancher sans PLAYERS_DATA (ex. spectator.blade.php). `night.blade.php` et `spectator.blade.php` restent hors périmètre de cette tâche — voir ROADMAP.

**Leçon :** Le bootstrap Blade (PLAYERS_DATA) et le store central (gameState.players) ne sont pas en conflit si : (1) le bootstrap n'est lu qu'une fois en init(), (2) toutes les mises à jour en cours de vie passent par les CustomEvents du store central, (3) le store est également peuplé côté XHR pour les futures vues qui veulent s'y brancher directement.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Double abonnement Echo — mayor-election.blade.php et role-reveal.blade.php

**Contexte :** `fix/double-echo-subscription-election-views` — `resources/js/game-state.js`, `resources/views/game/mayor-election.blade.php`, `resources/views/game/role-reveal.blade.php`

**Symptôme / Problème :** Les deux vues ouvraient un second `window.Echo.channel()` sur `game.{gameId}` en parallèle de `game-state.js`. `mayor-election.blade.php` écoutait `.mayor.vote.cast`, `.mayor.elected` et `.night.started` — chaque event Reverb déclenchait deux handlers simultanés, dont deux redirections concurrentes vers `/night` (un `setTimeout` brut et l'animation GSAP coordonnée de `game-state.js`). `role-reveal.blade.php` écoutait `.player.ready` (absent de game-state.js) et `.mayor.election.started` (présent dans `_handleMayorElectionStarted` mais sans CustomEvent).

**Cause / Alternatives :** `_handleMayorVoteCast`, `handleMayorElected` et `_handleMayorElectionStarted` ne dispatchaient aucun `CustomEvent` window — les vues ne pouvaient pas se brancher sur game-state.js et devaient s'abonner directement à Echo. `.player.ready` n'était tout simplement pas géré dans le store central.

**Fix / Décision :** Pattern identique aux vues `day.blade.php` et `night.blade.php` déjà corrigées. Trois `window.dispatchEvent(new CustomEvent(...))` ajoutés dans les handlers concernés de `game-state.js`. Nouveau listener `.listen('.player.ready', e => this._handlePlayerReady(e))` + handler `_handlePlayerReady` ajoutés au canal public. Blocs `window.Echo.channel()` supprimés des deux vues, remplacés par `window.addEventListener('mayor-vote-cast' | 'mayor-elected' | 'mayor-election-started' | 'player-ready', ...)`. Guard `_initialized` ajouté dans les deux `init()`. Redirect `.night.started` dans `mayor-election.blade.php` supprimée sans remplacement — `handleNightStarted` dans `game-state.js` est monté dans le layout commun et couvre toutes les vues.

**Leçon :** Quand game-state.js gère un event Echo, il DOIT aussi dispatcher un CustomEvent window pour que les vues locales puissent réagir sans second abonnement. Vérifier systématiquement `_handleMayorVoteCast`, `handleMayorElected`, `_handleMayorElectionStarted` lors de tout ajout de vue en phase d'élection.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Victime loups et sorcière potentiellement différentes en cas d'égalité

**Contexte :** `fix/night-resolve-shared-victim` — `ProcessNightActions.php`, `ProcessWitchTurn.php`, `GameService::witchAct()`, `VoteService.php`

**Symptôme / Problème :** `ProcessNightActions::handle()` et `ProcessWitchTurn::handle()` appelaient chacun indépendamment `VoteService::resolveNightVote($game)`. En cas d'égalité parfaite entre deux cibles, cette méthode tire aléatoirement via `inRandomOrder()`. Les deux appels pouvaient retourner deux joueurs différents : la sorcière voyait une victime qui n'était pas celle effectivement éliminée par les loups.

**Cause / Alternatives :** Deux appels indépendants à une méthode non-déterministe. Alternative envisagée : rendre `resolveNightVote()` déterministe (ex. tri par ID) — rejetée, car changerait le comportement de tirage au sort sans garantie réelle d'unicité entre jobs en cas de re-dispatch.

**Fix / Décision :** Pattern `night_resolve` aligné sur `hunter_pending` déjà en place. `ProcessNightActions` crée un `GameAction` de type `night_resolve` (player_id = target_player_id = victime résolue) immédiatement après la résolution du vote et avant le dispatch de `ProcessWitchTurn`. `VoteService::resolveNightVoteFromAction()` lit cette action. `ProcessWitchTurn` et `GameService::witchAct(heal)` utilisent `resolveNightVoteFromAction()`. `resolveNightVote()` original conservé uniquement dans `ProcessNightActions` (source de vérité unique au moment de la résolution) et `PhaseManager::endNight()` (les night_vote ne changent plus à ce stade, le résultat est stable).

**Leçon :** Toute méthode non-déterministe appelée plusieurs fois dans la même séquence doit persister son résultat en base dès la première résolution. Les lectures ultérieures dans le même round lisent cette action, jamais la méthode brute.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Race condition : vote d'un second loup rejeté 409 si le premier déclenche ProcessNightActions

**Contexte :** `fix/wolf-vote-simultaneous` — `app/Services/VoteService.php` méthode `castNightVote()`

**Symptôme / Problème :** Avec 2 loups votant quasi-simultanément, le premier vote déclenchait `ProcessNightActions::dispatch()` sans délai. En quelques ms, le job transitait le statut de `wolves_turn` vers `processing_night`. Le POST du second loup arrivait alors avec `status = 'processing_night'`, exclu du guard `in_array($game->status, ['night', 'wolves_turn'])` → abort(409). Le second vote était silencieusement ignoré côté client (`wolfVoteLocked` déjà `true` pour le premier loup).

**Cause / Alternatives :** La résolution anticipée (tous loups ont voté) dispatchait le job immédiatement. En conditions réseau normales (quelques ms entre deux clics simultanés), la transaction `ProcessNightActions` pouvait s'exécuter avant l'arrivée du second POST. Alternative envisagée : mutex Redis — rejeté (sur-ingénierie, fragile si Redis redémarre). Le dispatch différé absorbe la fenêtre de réseau sans changer la logique.

**Fix / Décision :** `->delay(now()->addSecond())` sur `ProcessNightActions::dispatch()` dans `castNightVote()`. Le guard `whereIn('status', ['night', 'wolves_turn'])` de `ProcessNightActions` reste le verrou anti-double-fire (inchangé). Un second dispatch éventuel du timer loups trouvera `status = 'processing_night'` et retournera null.

**Leçon :** Toute résolution anticipée déclenchée depuis un endpoint HTTP (plusieurs joueurs peuvent appuyer quasi-simultanément) doit laisser une fenêtre d'au moins 1s avant que le job ne verrouille le statut. Le pattern : dispatch anticipé + délai minimal, timer auto + délai complet, guard atomique dans le job.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Nuits sans action absentes de la timeline + succession maire sans filtre de phase

**Contexte :** fix/history-and-death-banner — `app/Services/HistoryService.php`, `resources/views/game/history.blade.php`

**Symptôme / Problème :** (B) Les nuits sans victime (loups sans consensus) n'apparaissaient pas dans la timeline historique. (C) Un joueur mort par poison sorcière lors d'une nuit "silencieuse" n'avait aucune entrée dans l'historique. (D) La succession de maire n'indiquait pas l'ancien maire, et une succession nocturne se retrouvait dans le bloc jour du même round.

**Cause / Alternatives :** (B+C) `buildTimeline()` n'entrait dans le bloc `nightEntry` que si `$nightVotes->isNotEmpty() || $witchHeal || $witchKill || $hunterNight`. Une nuit où seul le poison sorcière agit mais où les loups n'ont pas voté tombait hors condition si `$witchKill` ne déclenchait pas l'entrée (cas de variable null vs collection vide). Les nuits sans aucune action étaient silencieusement omises. (D) La recherche `mayor_succession` ne filtrait pas par `phase`, donc une succession nocturne (`phase = 'night'`) était placée dans `$dayEntry['succession']`.

**Fix / Décision :** (B+C) Supprimer la condition `if (...)` qui enveloppait la création du `$nightEntry` — l'entrée est désormais créée et ajoutée pour **chaque round**, avec `wolf_no_agreement = true` si `$nightVotes->isEmpty()`. (D) Deux variables distinctes : `$successionDay` (`->where('phase','day')`) dans le bloc jour, `$successionNight` (`->where('phase','night')`) dans le bloc nuit. Le format de succession est enrichi avec `former_mayor` + `new_mayor` (deux snapshots) pour afficher "X a désigné Y comme successeur."

**Leçon :** Toute entrée de timeline représentant une phase de jeu doit être créée inconditionnellement pour chaque round — l'absence d'actions est une information métier valide ("les loups ne se sont pas mis d'accord"). Les actions liées à une phase doivent toujours être filtrées par `phase` pour éviter les placements incorrects dans la timeline.

**Statut :** ✅ Résolu

---

## [CHOIX] setInterval parallèle à GSAP pour wolfTimerSeconds dans nightScreen()

**Contexte :** feat/chat-toggle-ux — `resources/views/game/night.blade.php`

**Symptôme / Problème :** Le timer visuel du tour des loups (barre rouge) est animé par `gsap.to('#wolf-timer-bar', { width:'0%', duration: WOLVES_TIMER })`. GSAP n'expose pas de callback tick accessible par Alpine — impossible de vérifier les seuils 75s et 15s pour l'auto-affichage du chat loups directement depuis l'animation.

**Cause / Alternatives :** (1) Utiliser `gsap.ticker.add()` — coupling fort entre GSAP et Alpine, difficile à nettoyer. (2) Dériver le temps écoulé depuis `Date.now()` au moment du `werewolves-turn-started` — fragile si l'onglet est en arrière-plan. (3) Ajouter un `setInterval` de 1s dédié au tracking de `wolfTimerSeconds`, lancé et nettoyé conjointement avec l'animation GSAP.

**Fix / Décision :** Option 3 retenue : `_wolfTimerInterval` lancé dans le listener `werewolves-turn-started`, décrémente `wolfTimerSeconds` chaque seconde et appelle `_checkWolfChatAuto()`. Nettoyé quand `wolfTimerSeconds <= 0`. Pattern identique à `_startDayTimer()` dans `dayScreen()` où le setInterval gère à la fois l'UI et la logique auto-chat.

**Leçon :** Quand GSAP anime une barre visuelle pure, ne pas lui faire porter la logique métier temporelle — utiliser un `setInterval` séparé pour le tracking de secondes. Les deux coexistent sans conflit : GSAP pilote le DOM, l'interval pilote l'état Alpine.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] canChatWolves excluait wolves_turn — chat loups muet pendant le tour actif

**Contexte :** fix/wolf-chat-reception — `app/Services/PhaseGuard.php`, `tests/Feature/Game/ChatTest.php`, `tests/Unit/Services/PhaseGuardTest.php`.

**Symptôme / Problème :** Les messages envoyés par les loups pendant leur tour disparaissaient silencieusement côté client et n'étaient jamais broadcastés. `ChatService::sendMessage()` retournait 409 ; `sendWolfChat()` dans `night.blade.php` ne vérifiait pas le code HTTP (`fetch` sans `res.ok`) et vidait l'input avant l'appel, rendant l'échec invisible.

**Cause / Alternatives :** `PhaseGuard::canChatWolves()` retournait `$game->status === 'night'`. Mais `ProcessWerewolvesTurn` transite le statut `night → wolves_turn` en début de tour (pour garantir l'idempotence du job). Pendant tout le tour actif des loups, le statut est `wolves_turn` — le guard rejetait donc tous les messages loups exactement quand ils étaient censés être autorisés. `PhaseGuard::isNight()` couvrait déjà `wolves_turn` mais `canChatWolves` avait un périmètre plus restrictif sans raison documentée.

**Fix / Décision :** `canChatWolves` étendu à `in_array($game->status, ['night', 'wolves_turn'])`. `'night'` conservé pour couvrir le bref intervalle entre la transition seer→wolves avant que `ProcessWerewolvesTurn` démarre. Tests ajoutés pour couvrir les deux statuts autorisés et les statuts refusés.

**Leçon :** Toute méthode `canXxx()` de `PhaseGuard` doit couvrir TOUS les statuts où l'action est sémantiquement autorisée, pas seulement le statut « canonique ». Quand un job transite le statut avant d'émettre l'event déclencheur (pattern atomique), le statut intermédiaire doit être inclus dans le guard correspondant. Ajouter un test Feature avec `status = wolves_turn` en plus de `status = night` pour chaque action liée à la phase nuit.

**Statut :** ✅ Résolu

---

## [CHOIX] Roadmap Code Propre v1.2 — stratégie de refactoring sans régression

**Contexte :** Étapes 1→10 — refactoring progressif post-v1.2 de l'ensemble des fichiers PHP, JS, Blade. Audit final Étape 10 — `app/Http/Controllers/Game/VoteController.php`, `app/Services/VoteService.php`.

**Symptôme / Problème :** Code fonctionnel mais avec documentation insuffisante, magic strings, logique dupliquée. En particulier, `VoteController` contenait trois méthodes privées `_checkAllMayorVotesCast()`, `_checkAllDayVotesCast()`, `_checkAllNightVotesCast()` qui sont de la logique métier (requêtes DB + dispatch de jobs) au lieu de rester dans les Services.

**Cause / Alternatives :** (1) Refactoring global en un seul commit → risque élevé de régression sur ~40 fichiers. (2) Refactoring nul → dette technique croissante. (3) Pour les `_checkAll*` : `_checkAllDayVotesCast` et `_checkAllNightVotesCast` étaient de la logique dupliquée (déjà implémentée dans `VoteService::castDayVote()` et `VoteService::castNightVote()`). `_checkAllMayorVotesCast` était de la logique métier orpheline dans le Controller (non couverte par le Service).

**Fix / Décision :** 10 étapes progressives, uniquement additives dans les 8 premières (doc, Enums, helpers, extraction de service) — aucune modification de logique métier. Étape 9 = documentation routes/README. Étape 10 = audit final de conformité CLAUDE.md + correction des non-conformités résiduelles. VoteController nettoyé : logique "tous les joueurs ont voté → dispatch ProcessMayorElection" déplacée dans `VoteService::castMayorVote()` (pattern identique à `castDayVote()`/`castNightVote()`), méthodes dupliquées supprimées.

**Leçon :** Un refactoring sans tests à 100% vert à chaque étape est un refactoring risqué. La règle "php artisan test après chaque modification de fichier existant" a été respectée tout au long. Les méthodes privées d'un Controller ne doivent pas contenir de requêtes DB ou de dispatch de jobs — même "cachées" dans des méthodes privées, elles violent CLAUDE.md "Controllers : valider request + appeler Service, rien d'autre".

**Statut :** ✅ Résolu

---

## [CHOIX] Centralisation des guards de phase dans PhaseGuard.php

**Contexte :** Refactor `refactor/phase-guard` — `app/Services/PhaseGuard.php`, 6+ fichiers Services/Jobs/Controllers

**Symptôme / Problème :** Le pattern `in_array($game->status, ['night', 'wolves_turn', 'processing_night'])` et ses variantes apparaissaient dans 10+ fichiers. Tout ajout d'un statut intermédiaire (ex: `hunter_turn` en v1.3) nécessitait une chasse manuelle garantie d'être incomplète.

**Cause / Alternatives :** (1) Continuer les magic strings — risque d'oubli lors de l'ajout de v1.3. (2) Centraliser dans une classe statique `PhaseGuard` avec méthodes nommées par sémantique métier — un seul point de modification. (3) Utiliser les Enums déjà créés en Étape 4 — prématuré, les Enums sont encore en intégration progressive.

**Fix / Décision :** `PhaseGuard` créé avec 7 méthodes statiques : `isNight()`, `isNightOrProcessing()`, `isDay()`, `canWitchAct()`, `canHunterShoot()`, `canChatGeneral()`, `canChatWolves()`, `canChatDead()`. Les occurrences directement substituables remplacées dans 10 fichiers. Les 8 occurrences restantes (ensembles custom ou contraintes Workflow) documentées avec `// PhaseGuard ne couvre pas ce cas : [raison]`.

**Leçon :** Pour v1.3+, ajouter les nouveaux statuts intermédiaires uniquement dans `PhaseGuard.php`. Les méthodes `Game::isNightPhase()` et `Game::isDayPhase()` sont intentionnellement conservées (méthodes d'instance, évitent dépendance service → modèle).

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Enums créés sans migration immédiate du code existant — stratégie progressive

**Contexte :** ROADMAP Étape 4 — `app/Enums/GameStatus.php`, `app/Enums/PlayerRole.php`, `app/Enums/ActionType.php`, `app/Enums/ChatChannel.php`, `app/Enums/WinnerTeam.php`

**Symptôme / Problème :** Les magic strings (`'waiting'`, `'night'`, `'werewolf'`, `'day_vote'`, etc.) sont répétées dans les Services, Jobs et Models sans constante partagée — risque de typo silencieuse et manque de lisibilité.

**Cause / Alternatives :** (1) Remplacer toutes les occurrences immédiatement (find/replace global) — risque de régression élevé : la surface de changement couvre ~20 fichiers (Services, Jobs, Models, FormRequests, Factories), et chaque remplacement devrait être testé. (2) Créer les Enums et les intégrer progressivement, fichier par fichier, à chaque prochaine tâche qui touche le fichier concerné — risque zéro de régression à ce stade, les Enums sont rétrocompatibles (backed string, même valeur).

**Fix / Décision :** Option 2 retenue. Les 5 Enums sont créés dans `app/Enums/` avec leurs méthodes helper (`isNightPhase()`, `isDayPhase()`, `isWerewolfSide()`, `isVillagerSide()`). Aucune modification du code applicatif existant. Chaque Enum porte un commentaire `// TODO : intégration progressive dans les Services, Jobs et Models (Étape 5+)`.

**Leçon :** Créer les Enums avant de les intégrer permet de vérifier leur exhaustivité (valeurs, helpers) sans risquer de régression. L'intégration progressive au fil des tâches suivantes est préférable à un big-bang de find/replace sur l'ensemble du codebase.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] hunter_pending en GameAction au lieu de Cache volatile

**Contexte :** fix/hunter-pending-action — ProcessNightActions, ProcessNightEnd, GameService::witchAct(), VoteService::resolveDayVote()

**Symptôme / Problème :** `Cache::put("hunter_must_shoot_...")` perdu si Redis redémarre entre ProcessNightActions et ProcessNightEnd — le chasseur perdait son pouvoir silencieusement.

**Cause / Alternatives :** Cache volatile non persistant entre les redémarrages de workers. Alternative cache avec TTL plus long ne réglerait pas le problème de fond.

**Fix / Décision :** `GameAction` de type `hunter_pending` — persiste en DB, résiste aux redémarrages. Supprimé atomiquement par ProcessNightEnd/VoteService après lecture. Suppression du bloc `Cache::forget` pour le cas `heal` (inutile : si la sorcière sauve le chasseur, `hunter_pending` n'a pas été créé).

**Leçon :** Tout état intermédiaire qui doit survivre à un restart worker doit être en DB, jamais en cache volatile.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] ProcessWitchTurn guards skip — double ProcessNightEnd cassait la séquence nocturne

**Contexte :** fix/night-sequence-timing — app/Jobs/ProcessWitchTurn.php,
app/Jobs/ProcessNightActions.php, tests/Feature/Game/WitchTest.php.

**Symptôme / Problème :** quand les loups étaient en égalité et la sorcière sans
action disponible, la nuit entière était skippée. Voyante, loups et
sorcière ne voyaient jamais leur tour.

**Cause / Alternatives :** les guards "skip silencieux" de ProcessWitchTurn dispatchaient
ProcessNightEnd::delay(0). Ce job arrivait immédiatement, voyait
status='processing_night' (posé par ProcessNightActions), passait le
guard de ProcessNightEnd et appelait endNight(). La voyante et les loups
n'avaient pas encore joué car leurs timers n'étaient pas écoulés
(8s + seer_timer + wolves_timer).

Bug associé découvert : le délai du ProcessNightEnd dispatché par
ProcessNightActions était mayor_succession + 5s ≈ 20s. Ce délai ne
couvre pas witch_timer (jusqu'à 60s). Si witch_timer > 20s, ProcessNightEnd
arrivait avant la fin du tour sorcière et le coupait. Calculé désormais
dynamiquement : witch_timer + mayor_succession + 5s.

Alternative rejetée : ajouter un guard dans ProcessNightEnd vérifiant
qu'une action sorcière existe en base avant d'appeler endNight(). Rejeté
car cela rendrait ProcessNightEnd dépendant de la présence de la sorcière
— il ne passerait jamais si la sorcière est absente ou morte.

**Fix / Décision :** suppression des ProcessNightEnd::delay(0) dans les
guards de ProcessWitchTurn. Ces guards font un simple return. ProcessWitchAutoAction
conserve son ProcessNightEnd::delay(0) car il n'est déclenché qu'après
expiration complète du timer sorcière.

**Leçon :** tout dispatch ProcessNightEnd depuis un job
"skip" est dangereux si des jobs de phase antérieurs ont encore leurs
timers en cours. Le seul dispatcher légitime de ProcessNightEnd dans le
chemin principal est ProcessNightActions, avec un délai couvrant tous
les tours restants. ProcessWitchAutoAction est l'unique exception car
il est déclenché après son propre timer complet.

Dette technique identifiée : night_start_delay et les buffers de
timing arbitraires sont un symptôme d'une limitation architecturale plus
profonde — le backend ne sait pas si les clients sont prêts. Voir
BUGS_AND_ROADMAP.md section "Refactoring architectural planifié" pour
le plan de refactor complet (pattern ready acknowledgment).

**Statut :** ✅ Résolu

---

## [CHOIX] Toasts narratifs serveur — WitchActedPublic conditionné à action !== 'pass', calcul witchActed/savedPlayerId hors transaction

**Contexte :** feat/narrative-toasts-server — `app/Events/Game/WitchActedPublic.php` (nouveau), `app/Events/Game/DayStarted.php`, `app/Http/Controllers/Game/ActionController.php`, `app/Services/PhaseManager.php`, `resources/js/game-state.js`.
**Symptôme / Problème :** Deux décisions à figer pour ces toasts narratifs : (1) faut-il broadcaster `WitchActedPublic` quand la sorcière passe son tour (`action === 'pass'`) ? (2) où calculer `witchActed`/`savedPlayerId` pour enrichir `DayStarted`, sachant que `PhaseManager::startDay()` exécute son `DB::transaction(lockForUpdate())` et que `endNight()` l'appelle juste après avoir résolu `resolveNightVote()`.
**Cause / Alternatives :** (1) Broadcaster `WitchActedPublic` dans tous les cas (y compris `pass`) — rejeté, un "pass" n'est pas une action observable et révélerait indirectement que la sorcière a délibérément choisi de ne rien faire (information narrative inutile). (2) Calculer `witchActed`/`savedPlayerId` à l'intérieur du `DB::transaction(lockForUpdate())` de `startDay()` — rejeté, ce sont des lectures `game_actions` indépendantes du verrou sur `games`, et RISK_GUARDS Guard #5 interdit d'alourdir une transaction `lockForUpdate()` avec des requêtes non nécessaires à la cohérence du verrou.
**Fix / Décision :** `WitchActedPublic` broadcasté uniquement si `$result['action'] !== 'pass'`, juste après les broadcasts existants `WitchActed`/`PlayerEliminated` dans `ActionController::witchAct()`. `witchActed` et `savedPlayerId` calculés dans `PhaseManager::endNight()` via `$game->actions()` (relation Eloquent, cohérent avec le reste du codebase — pas de `DB::table()`), AVANT l'appel à `startDay($game, $victim, $witchActed, $savedPlayerId)` — donc hors de toute transaction `lockForUpdate()`. `DayStarted` reçoit ces deux nouveaux paramètres avec valeurs par défaut (`false`/`null`), aucun appelant existant à modifier.
**Leçon :** Quand une donnée à broadcaster nécessite une lecture `game_actions` supplémentaire pour enrichir un event existant (`DayStarted`), la calculer dans la méthode appelante (`endNight()`) avant le `DB::transaction(lockForUpdate())` de la méthode appelée (`startDay()`), jamais à l'intérieur — même si la lecture elle-même ne modifierait rien. Pour les events "narratifs publics" (qui ne révèlent aucune info de jeu), exclure explicitement les actions "neutres" (`pass`) du déclenchement du broadcast.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Double window.addEventListener dans init() — cause racine définitive des doublons chat

**Contexte :** fix/chat-double-listeners — `resources/views/game/day.blade.php`,
`resources/views/game/night.blade.php`, `resources/js/game-state.js`.

**Symptôme / Problème :** messages chat affichés deux fois pour tous les joueurs
sur /day. Persistait après tous les fixes précédents (suppression double abonnement
Echo dans les vues, tentative `['wss']` seul).

**Diagnostic exhaustif des pistes :**
- Double abonnement Echo dans les vues Blade → écarté, déjà corrigé.
- Double transport Pusher `['ws','wss']` → écarté définitivement. Test `['wss']` seul
  en prod : régression critique (temps réel cassé). Confirmé via `ss -tnp` : une seule
  connexion WebSocket active côté serveur. `enabledTransports: ['ws', 'wss']` est
  obligatoire dans cette config Nginx/Reverb et ne doit plus jamais être modifié.
- Cause racine confirmée via DevTools prod :
  `getEventListeners(window)['chat-message']?.length === 2`

**Cause :** Alpine.js appelle `init()` deux fois sur le composant `dayScreen()`.
`window.addEventListener()` s'accumule sans `removeEventListener()` correspondant.
Deux listeners actifs → chaque CustomEvent dispatché une fois est capturé deux fois.

**Fix :** guard `_initialized` en tête de chaque `init()` qui enregistre des listeners
sur `window`. Pattern à respecter sur tout futur composant Alpine qui utilise
`window.addEventListener()` dans son `init()` :

```js
init() {
    if (this._initialized) return;
    this._initialized = true;
    // window.addEventListener(...) ici
},
```

**Leçon :** Ne jamais enregistrer `window.addEventListener()` dans un `init()` Alpine
sans guard `_initialized`. Alpine peut appeler `init()` plusieurs fois sur le même
composant selon le cycle de vie du DOM. `window.addEventListener()` est cumulatif —
contrairement aux bindings Alpine (`@event`), il ne se remplace pas, il s'empile.
Tout composant qui écoute des CustomEvents sur window doit se protéger contre
les appels multiples de `init()`.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Double transport Pusher (ws + wss) — cause racine définitive des doublons WebSocket

**Contexte :** fix/pusher-double-transport — `resources/js/echo.js`.
**Symptôme / Problème :** tous les events WebSocket (chat général, chat loups, votes, etc.) étaient reçus deux fois côté client, malgré les fixes précédents qui avaient éliminé tous les doubles abonnements Echo applicatifs (cf. décisions "Double abonnement Echo game.{gameId}" et "Double abonnement Echo — modale succession fantôme").
**Cause / Alternatives :** `enabledTransports: ['ws', 'wss']` dans la config Echo/Pusher autorisait Pusher-js à ouvrir DEUX connexions simultanées (une non-TLS sur ws, une TLS sur wss), alors que `forceTLS: (VITE_REVERB_SCHEME ?? 'https') === 'https'` vaut `true` et qu'un seul port (443, via `wssPort`) est configuré. Chaque connexion active reçoit indépendamment tous les events broadcastés sur les canaux souscrits → chaque listener Echo se déclenche une fois par connexion, soit deux fois au total. Les fixes précédents (suppression des abonnements Echo dupliqués dans les vues) ont réduit le nombre de listeners par event de 4 à 2, masquant partiellement le symptôme sans l'éliminer.
**Fix / Décision :** `enabledTransports: ['wss']` — une seule connexion TLS, cohérente avec `forceTLS: true`.
**Leçon :** Avec `forceTLS: true`, `enabledTransports` ne doit contenir que `['wss']`. Conserver `['ws', 'wss']` permet à Pusher-js d'ouvrir deux connexions en parallèle, chacune recevant tous les broadcasts — un doublon "au niveau transport", indépendant de tout double abonnement applicatif. Si un bug de "tous les events arrivent en double" persiste après avoir vérifié qu'aucune vue ne s'abonne deux fois au même canal (cf. règle "un seul composant par canal"), vérifier `enabledTransports` en premier.
**Statut :** ✅ Résolu

---

## [CHOIX] Sorcière — tour maintenu si poison disponible même sans victime des loups

**Contexte :** fix/witch-turn-no-victim — ProcessWitchTurn.php, WitchTurnStarted.php,
night.blade.php, WitchTest.php, RISK_GUARDS.md Guard #3.
**Symptôme / Problème :** la sorcière ne pouvait pas utiliser son poison les nuits
où les loups étaient en égalité (pas de victime). Son tour était skippé silencieusement
via ProcessNightEnd, sans qu'elle soit informée ni qu'elle puisse agir. C'était
contraire aux règles officielles du Loup-Garou (la sorcière se réveille toujours)
et non intuitif pour les joueurs.
**Cause / Alternatives :** Guard #3 de RISK_GUARDS.md protégeait contre un blocage
de la nuit quand resolveNightVote() retourne null. Le guard était trop large — il
skippait le tour entier au lieu de distinguer les cas selon les potions disponibles.
(1) Conserver le skip total — rejeté, non conforme aux règles et frustrant.
(2) Toujours afficher le tour sorcière même potions épuisées — rejeté, inutile
et potentiellement bloquant si ProcessWitchAutoAction ne gère pas ce cas.
(3) Skip uniquement si deux potions épuisées OU (pas de victime ET soin seul
disponible) — retenu.
**Fix / Décision :** Option 3. ProcessWitchTurn distingue trois cas : (a) deux
potions épuisées → skip, (b) pas de victime + poison disponible → WitchTurnStarted
avec victim:null et heal_available:false, (c) victim présente → comportement
inchangé. WitchTurnStarted accepte désormais victim nullable. Le panel sorcière
côté client affiche un message adapté selon le cas. Les tests WitchTest.php
mis à jour pour couvrir les trois cas.
**Leçon :** un guard de sécurité contre un blocage (null check) ne doit pas
empêcher une action légitime du joueur. Toujours distinguer "pas de données"
(null victime) de "action impossible" (potions épuisées). RISK_GUARDS.md mis
à jour pour refléter le nouveau comportement attendu.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Double abonnement Echo game.{gameId} — cause racine des messages en doublon

**Contexte :** Phase 18 Prompt 1 — game-state.js, day.blade.php
**Symptôme / Problème :** messages chat doublés sur /day malgré un premier fix.
**Cause / Alternatives :** deux appels Echo.channel() sur le même canal déclenchent
tous les listeners deux fois côté Pusher/Reverb. Le premier fix avait supprimé un
listener .chat.message.sent explicite dans day.blade.php, mais day.blade.php
conservait un appel Echo.channel() pour .day.vote.cast — suffisant pour réactiver
le double déclenchement.
**Fix / Décision :** règle absolue — un seul composant s'abonne à Echo par canal
(game-state.js). Toute vue qui a besoin d'un event du canal public doit passer par
window.addEventListener sur un CustomEvent dispatché par game-state.js.
**Leçon :** ne jamais appeler window.Echo.channel() dans une vue Blade si game-state.js
s'abonne déjà au même canal. Même un seul .listen() supplémentaire sur le même canal
suffit à déclencher tous les handlers deux fois.
**Statut :** ✅ Résolu

---

## [CHOIX] Canal des fantômes ('dead') — broadcast public tagué, pas de canal privé dédié

**Contexte :** Prompt 6 (Phase 18) — `app/Services/ChatService.php`, `app/Events/Game/ChatMessageSent.php`, `app/Http/Controllers/Game/ChatController.php`, `app/Http/Requests/SendMessageRequest.php`, `database/migrations/2026_06_14_000000_add_dead_to_chat_messages_channel_enum.php`, `resources/views/game/day.blade.php`, `app/Http/Controllers/Game/GameController.php`.
**Symptôme / Problème :** Les joueurs éliminés doivent pouvoir discuter entre eux pendant la phase jour (canal "fantômes"), sans pouvoir écrire dans le chat général, et sans que les vivants puissent y répondre. Fallait choisir entre un canal WebSocket privé dédié (`game.{id}.dead`, type `PrivateChannel`) ou une diffusion sur le canal public existant `game.{id}` avec un tag de routage côté client.
**Cause / Alternatives :** (1) Canal privé dédié — nécessiterait une nouvelle entrée dans `routes/channels.php`, une policy d'autorisation (`is_alive === false`), et un abonnement Echo distinct côté client pour chaque joueur mort. Plus "propre" en isolation réseau mais ajoute de la complexité d'infrastructure pour un canal qui n'a pas besoin de confidentialité forte (les vivants voir les messages des fantômes n'est pas un problème de sécurité, juste un problème d'UX/règle du jeu). (2) Diffusion sur `game.{id}` (canal public déjà utilisé par tous), avec `ChatMessage.channel = 'dead'` et `ChatMessageSent.channel` dynamique : tous les clients reçoivent l'event, mais `dayScreen()` route le message vers `deadChatMessages[]` (affiché uniquement si `!isAlive`) plutôt que `chatMessages[]`. Les vivants ne voient jamais le panneau fantôme (masqué via `x-show="!isAlive"`), donc ne "répondent" jamais — l'écriture est bloquée côté serveur (`ChatService::sendMessage` : `is_alive === true` + `channel === 'dead'` → 403).
**Fix / Décision :** Option 2 retenue. `ChatMessageSent` reçoit un paramètre `channel` dynamique (défaut `'general'`) au lieu de la valeur hardcodée précédente — `ChatController::send()` passe `$chatMessage->channel`. `ChatService::sendMessage()` : guard `is_alive` modifié pour autoriser `channel === 'dead'` aux morts uniquement (inversion : vivant + `dead` → 403 ; mort + canal ≠ `dead` → 403 comme avant). `chat_messages.channel` ENUM étendu à `'dead'` via migration dédiée. `SendMessageRequest` accepte `dead` dans `Rule::in`.
**Leçon :** Un canal "réservé à un sous-ensemble de joueurs" ne nécessite pas systématiquement un `PrivateChannel` Reverb — si la fuite d'information vers les autres joueurs n'est pas un problème de sécurité (juste une règle d'affichage/écriture), le routage côté client sur le canal public existant évite une policy + un abonnement Echo supplémentaires. Réserver les `PrivateChannel` aux cas où l'information elle-même doit rester secrète (ex. résultat voyante, composition loups — cf. décision "SeerResult broadcasté sur canal privé joueur").
**Statut :** 🔵 Choix assumé

---

## [CHOIX] fix/timers-broadcast-sync — welcome.blade.php conservé (route '/' ne pointe pas sur landing)

**Contexte :** `fix/timers-broadcast-sync` — MODIFICATION 8 (nettoyage fichiers morts), `routes/web.php`, `resources/views/welcome.blade.php`, `resources/views/landing.blade.php`.
**Symptôme / Problème :** Le prompt de tâche demandait de supprimer `resources/views/welcome.blade.php` en présupposant que la route `/` pointe sur `landing` (Alpine via Vite) et que `welcome.blade.php` est une ancienne landing page Alpine CDN/Tailwind CDN obsolète, à condition de vérifier au préalable que la route `/` ne référence pas `welcome`.
**Cause / Alternatives :** La vérification demandée (`grep` sur `routes/web.php`) montre que `Route::get('/', fn () => view('welcome'))->name('home')` est toujours la route active — `landing.blade.php` existe mais n'est référencé par aucune route ni controller. Le présupposé de la tâche est donc inversé : `welcome.blade.php` est la vue active, et c'est potentiellement `landing.blade.php` qui serait le fichier mort. (1) Supprimer `welcome.blade.php` comme demandé — rejeté, casserait la page d'accueil en production. (2) Supprimer `landing.blade.php` à la place — rejeté, hors périmètre de la tâche et nécessite une décision produit (laquelle des deux landing pages garder). (3) Ne rien supprimer et documenter — retenu.
**Fix / Décision :** `resources/views/lobby/waiting-room.blade.php` supprimé (aucune référence trouvée, conforme à la tâche). `welcome.blade.php` conservé intact. Ajout d'une question ouverte : décider si `landing.blade.php` doit remplacer `welcome.blade.php` via la route `/`, ou être supprimé s'il est un brouillon abandonné.
**Leçon :** Quand une instruction de suppression est conditionnée par une vérification (`grep`/`route`), exécuter la vérification AVANT de supprimer et inverser la décision si le résultat contredit le présupposé — ne jamais supprimer "parce que c'est écrit dans la consigne" si la condition de garde échoue.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Étape 5 — AutoActionTest adapté au comportement réel de ProcessSeerAutoAction (divergence avec SPEC_TIMERS.md §3.2)

**Contexte :** Étape 5 — `tests/Feature/Game/AutoActionTest.php::test_seer_auto_action_passes_to_wolves_if_inactive`, `app/Jobs/ProcessSeerAutoAction.php`, `app/Jobs/ProcessSeerTurn.php`, `SPEC_TIMERS.md §3.2`.
**Symptôme / Problème :** Le prompt de tâche attend que `ProcessSeerAutoAction::handle()` dispatche `ProcessWerewolvesTurn` quand la voyante n'a pas encore agi (voyante inactive). Or le code réel en prod fait l'inverse : il effectue une inspection « de consolation » (création d'un `seer_check` aléatoire + broadcast `SeerResult`) et ne dispatche jamais `ProcessWerewolvesTurn`. C'est `ProcessSeerTurn` qui dispatche systématiquement `ProcessWerewolvesTurn` (à `seerTimer + 2`), indépendamment du résultat de `ProcessSeerAutoAction` (dispatché lui à `seerTimer / 2`).
**Cause / Alternatives :** Le prompt de tâche et `SPEC_TIMERS.md §3.2` décrivent un design où `ProcessSeerAutoAction` est le seul déclencheur du passage aux loups (créer rien + dispatcher `ProcessWerewolvesTurn` si voyante inactive). Le code livré en prod (Étape 4, déjà mergé) a divergé vers un design « double dispatch » par `ProcessSeerTurn` (auto-inspection à mi-timer + passage aux loups au timer complet, dans tous les cas). Consigne explicite de la tâche : aucune modification de code applicatif. (1) Modifier `ProcessSeerAutoAction` pour matcher la spec — rejeté (hors périmètre, régression possible sur le flux actuel). (2) Écrire le test contre le comportement réel — retenu.
**Fix / Décision :** `test_seer_auto_action_passes_to_wolves_if_inactive()` (nom conservé tel que spécifié) vérifie le comportement réel : voyante `is_inactive=true`, appel direct de `ProcessSeerAutoAction::handle()` → un `seer_check` est créé en base, `SeerResult` est broadcasté, et `ProcessWerewolvesTurn` n'est PAS dispatché par ce job (assertion explicite avec commentaire renvoyant à cette décision).
**Leçon :** Quand une spec (`SPEC_TIMERS.md`) décrit un pattern « intentionnel » mais que le code livré a évolué différemment, les tests d'intégration doivent documenter et figer le comportement RÉEL (source de vérité = code en prod), pas la spec. Mettre à jour `SPEC_TIMERS.md §3.2` reste à faire (non bloquant, ajouté à la ROADMAP si besoin).
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Refus de merge fix/mayor-succession-cascade dans dev — fix déjà intégré, branche obsolète

**Contexte :** Tâche demandée — merger `fix/mayor-succession-cascade` (commits `7708271`, `e35e404`) dans `dev` pour appliquer `ProcessMayorSuccession::dispatch(..., shouldStartNight: true)` dans `app/Jobs/ProcessNightActions.php`, et documenter un incident prod (`Data truncated` sur `game_actions.phase`, 32 jobs échoués).
**Symptôme / Problème :** Avant tout `git merge`, vérification de `app/Jobs/ProcessMayorSuccession.php` et `app/Jobs/ProcessNightActions.php` sur `dev` : le paramètre `shouldStartNight`, la variable `$phaseToStart` utilisée pour la colonne `phase` de `GameAction`, et l'appel `ProcessMayorSuccession::dispatch($game->id, $game->round, shouldStartNight: true)` (ligne 82) sont **déjà présents** sur `dev`. `git log --all` montre un merge antérieur `8bbc308 Merge branch 'fix/mayor-succession-cascade' into dev` contenant le commit `778f94c` (même message que `e35e404`). La branche `fix/mayor-succession-cascade` visée par la tâche est un reliquat obsolète, 31 commits derrière `dev` (antérieur à `feature/roles-v1-2`, `feature/timers-configurables`, `feat/settings-modal`) — `git diff dev fix/mayor-succession-cascade` montre ~5000 lignes de suppressions (witch/hunter, timers configurables, modale settings) qui seraient réintroduites par un merge naïf.
**Cause / Alternatives :** (1) Exécuter le merge tel que demandé — rejeté : violerait la règle CLAUDE.md « jamais travailler directement sur dev », pour un merge qui n'apporte rien de réel, avec un fort risque de revert de fonctionnalités v1.2 via la résolution de conflits. (2) Documenter dans `BUGS_AND_ROADMAP.md` l'incident prod décrit dans la consigne — rejeté en l'état : la cause indiquée (« fix jamais mergé sur dev ») est contredite par le code actuel de `dev`. (3) **Retenu** : ne rien merger, consigner la décision ici, demander confirmation à l'utilisateur avant toute action.
**Fix / Décision :** Aucune modification de code ni de branche. Question posée à l'utilisateur (3 options : ne rien faire / branche différente visée / investiguer un éventuel bug prod réel sous une autre cause) — réponse : ne rien faire, le fix est déjà sur `dev`.
**Leçon :** Avant tout `git merge --no-ff` sur `dev`/`main`, toujours vérifier l'état réel des fichiers cibles ET l'historique (`git log --all -- <fichier>`, `git merge-base`) — une consigne de tâche peut référencer une branche stale dont le contenu a déjà été intégré sous un autre nom de commit. Ne jamais écrire d'entrée `BUGS_AND_ROADMAP.md` décrivant un incident dont la cause contredit le code actuellement présent sur `dev`.
**Statut :** ✅ Résolu

---

## [CHOIX] Timers configurables (Étape 3) — games.settings['timers'] remplace games.timers pour la lecture, GamePolicy::updateSettings() limitée au rôle host

**Contexte :** Étape 3 — `database/migrations/2026_06_12_120000_add_settings_to_games_table.php`, `app/Services/TimerCalculator.php`, `app/Models/Game.php`, `app/Services/GameService.php`, `app/Policies/GamePolicy.php`, `config/game.php`.
**Symptôme / Problème :** Deux points d'architecture non tranchés par l'énoncé. (1) `SPEC_TIMERS.md` §5 décrit un stockage dans `games.settings['timers']`, mais `games.timers` (colonne JSON existante, alimentée par `TimerCalculator::forPlayerCount()` dans `GameService::startGame()`, commit `87906e8`) est déjà lu par `Game::timer()`. Faire coexister les deux sources sans préséance claire aurait pu faire relire silencieusement `games.timers` (valeurs calculées par nb joueurs à `startGame()`, jamais mises à jour par le host) après une sauvegarde host via `settings['timers']`. (2) L'énoncé demande `GamePolicy::updateSettings()` = `is_host + status === 'waiting'`, et `GameService::updateTimerSettings()` = abort(409) si `status !== 'waiting'` — les deux retournent un code HTTP différent (403 vs 409) pour la même condition `status !== 'waiting'`, le Policy gagnant toujours (évalué avant le Service).
**Cause / Alternatives :** (1) Discuté avec l'utilisateur via clarification directe : Option A (garder `games.timers` comme source, `settings['timers']` non lu — rejetée, contredit la spec), Option B (les deux colonnes fusionnées par priorité), **Option C retenue** (`settings['timers']` remplace entièrement `games.timers` pour la lecture via `Game::timer()`/`TimerCalculator::get()` ; `games.timers` reste en base, alimentée par `startGame()`, mais n'est plus lue pour les clés configurables). (2) Pour le conflit policy/service : soit dupliquer le guard `status === 'waiting'` uniquement dans le Policy (alors `test_modification_impossible_hors_waiting` reçoit 403 et non 409, contredisant le nom du test) ; soit restreindre le Policy au seul rôle (`is_host`), laissant `GameService::updateTimerSettings()` seul juge de l'état de la partie (409) — cohérent avec le pattern déjà utilisé par `excludePlayer()` (403 = mauvais rôle, 409 = mauvais état).
**Fix / Décision :** (1) Option C. `Game::timer($key)` délègue entièrement à `TimerCalculator::get($game, $key)` : priorité `settings['timers'][$key]` (si entier) → `config('game.timers.$key')`. La colonne `games.timers` est conservée (régression non bloquante : elle devient une donnée morte pour `mayor_election`, `seer`, `werewolves`, `mayor_succession`, `day_vote` — ces clés sont désormais pilotées uniquement par `settings['timers']`/config). (2) `GamePolicy::updateSettings()` ne vérifie que `$player->is_host` ; `GameService::updateTimerSettings()` garde seul le guard `status === 'waiting'` (abort 409).
**Leçon :** Quand une nouvelle fonctionnalité introduit un second mécanisme de stockage/lecture qui se substitue à un mécanisme existant pour les mêmes clés, ne jamais les faire coexister « par priorité implicite » — choisir une source de vérité unique et documenter explicitement la colonne devenue inerte. Pour les codes HTTP, garder la règle : Policy = qui a le droit (rôle → 403), Service = état métier actuel (→ 409) ; ne jamais dupliquer un guard d'état dans le Policy si le Service le fait déjà, sous peine de masquer le 409 attendu par un 403.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Symfony Workflow (Étape 2) — guards canTransition() limités aux statuts canoniques, accessors getStatus()/setStatus()

**Contexte :** Étape 2 — `app/Providers/WorkflowServiceProvider.php`, `app/Models/Game.php`, `PhaseManager`, `GameService::startGame()`, `VoteService::resolveMayorElection()`/`resolveDayVote()`, `WinConditionChecker::check()`, `ProcessNightEnd`, `ProcessMayorElection`.
**Symptôme / Problème :** Trois points à trancher pour intégrer `symfony/workflow` sans régression : (1) `MethodMarkingStore(true, 'status')` lève `LogicException` à l'exécution car les attributs Eloquent dynamiques ne sont pas détectables par réflexion comme une propriété publique `$status`. (2) Le Workflow ne définit que 5 places (`waiting`, `electing_mayor`, `night`, `day`, `finished`), alors que le statut réel en base peut aussi valoir `processing_night`, `processing_day`, `wolves_turn`, `role_reveal` (Tâches E-K) — un guard `canTransition()` appliqué sans condition sur ces statuts intermédiaires retournerait toujours `false` et casserait les flux existants (ex. `PhaseManager::startDay()` appelé avec `status='processing_night'`). (3) `applyTransition()` appelle `->save()`, ce qui est interdit à l'intérieur d'un `DB::transaction()->lockForUpdate()`.
**Cause / Alternatives :** Pour (1) : soit déclarer une vraie propriété `$status` (impossible proprement avec Eloquent), soit ajouter les méthodes `getStatus()`/`setStatus()` attendues par `MethodMarkingStore::getGetter()/getSetter()`. Pour (2) : soit étendre le Workflow avec des places supplémentaires pour `processing_*` (contredit l'énoncé qui les exclut explicitement du périmètre v1.2), soit restreindre les guards aux seuls statuts canoniques. Pour (3) : soit appeler `applyTransition()` après le `lockForUpdate()` (risque de double écriture / status désynchronisé entre la ligne verrouillée et l'instance `$game`), soit garder le pattern existant `canTransition()` en guard + écriture manuelle de `$locked->status` dans la transaction.
**Fix / Décision :** (1) Ajout de `Game::getStatus(): string` / `Game::setStatus(string $status, array $context = [])` — wrappers triviaux autour de l'attribut Eloquent `status`, requis uniquement par l'infrastructure `MethodMarkingStore`. (2) Chaque guard `canTransition()` n'est évalué que lorsque le statut verrouillé est l'un des 5 statuts canoniques concernés par la transition (ex. `$locked->status === 'day' && !canTransition('continue_night')` dans `startNight()`, bypass total si `processing_day`). Comme une transition canonique part toujours d'un statut canonique valide, ces guards sont aujourd'hui toujours vrais en fonctionnement normal — ils servent de double-vérification défensive (cohérente avec l'esprit de l'énoncé) sans jamais bloquer les chemins `processing_*` déjà couverts par les Tâches E-K. (3) `applyTransition()` n'est utilisé nulle part à l'intérieur d'un `lockForUpdate()` ; tous les guards en transaction utilisent `canTransition()` suivi d'une écriture manuelle `$locked->update([...])` (comme avant), conformément à la règle CLAUDE.md.
**Leçon :** Quand un Workflow externe est introduit sur une state machine déjà étendue par des statuts intermédiaires ad-hoc (Tâches E-K), ne jamais étendre le Workflow pour « rattraper » ces statuts — cela transformerait une infrastructure de validation en source de vérité concurrente. Restreindre les guards aux transitions entre places canoniques, et laisser les statuts intermédiaires hors périmètre, gérés comme avant.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] MayorSuccessionTest — cascade avec 2 loups pour éviter une victoire prématurée liée au successeur aléatoire

**Contexte :** Tâche K — `tests/Feature/Game/MayorSuccessionTest.php::test_cascade_succession_nuit_puis_nouveau_maire_tue_la_nuit_suivante`.
**Symptôme / Problème :** Premier jet du test avec 1 seul loup et 6 joueurs : `ProcessMayorSuccession::handle()` désigne le successeur via `alivePlayers()->inRandomOrder()->first()`, sans exclure les loups. Quand le successeur tiré au hasard pour le round 1 était le loup unique, le round 2 (le loup tue "le nouveau maire", c'est-à-dire lui-même) faisait passer `nb_loups_vivants` à 0 → `WinConditionChecker` déclenchait la victoire des villageois (`status='finished'`) au lieu de laisser la partie en `processing_night`. Le test échouait de manière non déterministe (flaky), selon le tirage aléatoire du successeur.
**Cause / Alternatives :** La succession peut légitimement désigner un loup comme maire (aucune règle métier ne l'interdit). (1) Mocker `inRandomOrder()`/forcer le successeur — invasif, dépend de l'implémentation interne. (2) Ajouter un second loup : quelle que soit l'issue du tirage aléatoire (successeur = loup ou villageois), la mort du successeur au round 2 ne fait jamais passer `nb_loups_vivants` à 0 ni `nb_loups_vivants >= nb_autres_vivants`, donc la partie ne se termine jamais prématurément.
**Fix / Décision :** Option 2 retenue. Partie à 8 joueurs (1 maire villageois + 2 loups + 5 villageois). Pour le vote du round 2, le votant est choisi dynamiquement (`$voter = $successorA->id === $wolf1->id ? $wolf2 : $wolf1`) pour ne jamais faire voter un loup contre lui-même. Testé sur 10 exécutions consécutives sans échec.
**Leçon :** Tout test impliquant une désignation de successeur aléatoire (`inRandomOrder()`) parmi les joueurs vivants doit prévoir un nombre de loups suffisant pour qu'aucune issue du tirage ne déclenche `WinConditionChecker` de façon imprévue. Avec 1 seul loup, toute mort du loup termine la partie — incompatible avec un scénario de cascade sur plusieurs rounds.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] ProcessMayorSuccession — flag shouldStartNight au lieu d'un calcul dynamique depuis $game->status

**Contexte :** Tâche J — `app/Jobs/ProcessMayorSuccession.php`, `app/Jobs/ProcessNightActions.php`.
**Symptôme / Problème :** Si le maire meurt la nuit, qu'un successeur est désigné, et que ce successeur (nouveau maire) meurt lui-même à la nuit suivante, la partie pouvait rester bloquée sur la modale "Succession du Maire" / sauter intégralement la phase jour du round concerné.
**Cause / Alternatives :** `ProcessMayorSuccession` et `ProcessNightEnd` sont dispatchés tous les deux par `ProcessNightActions` quand le maire meurt la nuit, avec des délais proches (`mayor_succession` pour le premier, `mayor_succession + 5` pour le second). Laravel ne garantit pas l'ordre strict d'exécution de deux jobs en queue avec des délais différents (worker occupé, plusieurs workers). Si `ProcessNightEnd` s'exécute AVANT `ProcessMayorSuccession` pour le même round : `endNight()` transitionne déjà `processing_night` → `day` (via `startDay`). Quand `ProcessMayorSuccession` s'exécute ensuite, l'ancien calcul `$phaseToStart = in_array($game->status, ['night','processing_night']) ? 'night' : 'day'` lit `status='day'` (déjà transitionné) et conclut à tort `phaseToStart='day'` — alors que la mort du maire avait bien eu lieu en contexte nuit. Cela déclenche un appel erroné à `startNight()` qui fait passer le statut de `day` à `night` (round+1) quelques instants après son entrée en phase jour, sautant tout le vote jour du round (le `ProcessDayVote` déjà dispatché par `startDay` deviendra un no-op silencieux). (1) Garder le calcul dynamique et ajouter un guard supplémentaire dans `ProcessMayorSuccession` pour détecter ce cas précis — fragile, demande de retrouver après-coup le contexte d'origine. (2) Faire porter le contexte ("nuit" ou "jour") explicitement par le dispatcher (`ProcessNightActions` / `VoteService::resolveDayVote`), qui le connaît au moment du dispatch, via un paramètre du job.
**Fix / Décision :** Option 2 retenue. Ajout du paramètre `public readonly bool $shouldStartNight = false` au constructeur de `ProcessMayorSuccession` (visibilité `public readonly` conservée, pas `protected` — `tests/Feature/Game/NightPhaseTest.php` accède à `$job->gameId`/`$job->round` directement). `$phaseToStart = $this->shouldStartNight ? 'night' : 'day'` remplace le calcul depuis `$game->status`. `ProcessNightActions::dispatch` passe désormais `shouldStartNight: true`. `VoteService::resolveDayVote` ne change pas (défaut `false` correct : aucun job concurrent ne transitionne le statut entre le dispatch en contexte jour et l'exécution de `ProcessMayorSuccession`, donc pas de race équivalente côté jour). `PhaseManager::endNight()` faisait déjà `$game->refresh()` en première ligne — aucune modification nécessaire.
**Leçon :** Quand deux jobs sont dispatchés ensemble pour des délais proches mais différents depuis le même point du code, ne jamais supposer que l'ordre d'exécution respectera l'ordre des délais. Si un job a besoin de connaître le contexte ("pourquoi suis-je déclenché ?"), faire porter ce contexte explicitement en paramètre par le dispatcher plutôt que de le re-déduire de l'état courant en base — l'état courant peut avoir déjà été modifié par l'autre job de la paire.
**Statut :** ✅ Résolu

---

## [RÉSOLU] ProcessMayorSuccession::handle() — phase invalide insérée dans game_actions pour les statuts processing_*

**Contexte :** Tâche I — `app/Jobs/ProcessMayorSuccession.php`, `tests/Feature/Game/NightPhaseTest.php::test_mayor_succession_triggered_at_night`.
**Symptôme / Problème :** En écrivant le test de non-régression de la Tâche G (succession du maire déclenchée la nuit), `ProcessMayorSuccession::handle()` lève `QueryException: SQLSTATE[01000]: Warning: 1265 Data truncated for column 'phase'` lors de l'insertion du `GameAction` de type `mayor_succession`.
**Cause / Alternatives :** La ligne `'phase' => $locked->status` insère la valeur brute du statut de la partie (`night`, `processing_night`, `day` ou `processing_day` depuis les Tâches F/G) dans `game_actions.phase`, dont l'ENUM est limité à `election|night|day` (migration `2026_06_03_000003_create_game_actions_table.php`). Pour `status='night'` ou `status='day'`, la valeur passait par coïncidence ; pour `processing_night`/`processing_day` (statuts intermédiaires introduits/élargis aux Tâches F/G), l'insertion échoue. (1) Étendre l'ENUM `game_actions.phase` pour accepter les statuts `processing_*` — invasif, casse la sémantique « phase » (election/night/day) de la table et impacte `scopeAnonymized`/historique. (2) Réutiliser `$phaseToStart` (déjà calculé juste avant la transaction, valant `'night'` ou `'day'` selon `in_array($game->status, ['night','processing_night'])`) pour la colonne `phase`.
**Fix / Décision :** Option 2 retenue. `'phase' => $locked->status` remplacé par `'phase' => $phaseToStart`, et `$phaseToStart` ajouté au `use()` de la closure `DB::transaction()`. Comportement inchangé pour `status='night'`/`'day'` (valeur identique), corrige les cas `processing_night`/`processing_day`.
**Leçon :** Quand une tâche élargit la liste des statuts `games.status` acceptés par un guard (Tâches F/G : ajout de `processing_night`/`processing_day`), vérifier toute valeur dérivée de `$game->status` réutilisée ailleurs dans la même méthode (ici une colonne ENUM distincte avec un domaine de valeurs plus restreint) — pas seulement les guards de transition de phase. Ce genre de bug ne se révèle qu'à l'exécution (écriture en base), jamais à l'analyse statique.
**Statut :** ✅ Résolu

---

## [CHOIX] ProcessMayorSuccession en contexte nuit — ne démarre plus aucune phase, guard processing_day conservé

**Contexte :** Tâche G — `app/Jobs/ProcessMayorSuccession.php`, `app/Jobs/ProcessNightActions.php`.
**Symptôme / Problème :** Avant cette tâche, quand le maire mourait la nuit, `ProcessMayorSuccession` élisait bien un successeur mais appelait ensuite `$phaseManager->startDay($game, $victim)` (cas `$phaseToStart === 'night'`) — ce qui terminait la nuit après seulement 15s (délai succession) et faisait sauter le vote jour du round (`ProcessDayVote` jamais dispatché, cf. décision « Mort du maire la nuit — vote jour du round sacrifié »). L'énoncé de la tâche G demandait de supprimer cet appel et de garder `in_array($game->status, ['night', 'processing_night', 'day'])` comme guard — mais ce dernier point omet `processing_day`, ajouté en Tâche F.
**Cause / Alternatives :** (1) Suivre l'énoncé littéralement et retirer `processing_day` du guard d'entrée et de la transaction — mais `VoteService::resolveDayVote()` dispatche `ProcessMayorSuccession::dispatch($game->id, $game->round)` alors que `$game->status` vaut `processing_day` (Tâche F) ; sans `processing_day` dans le guard, ce dispatch serait silencieusement ignoré et la succession de jour casserait à nouveau. (2) Conserver `processing_day` dans le guard (comme en Tâche F) et ne modifier que la branche `$phaseToStart === 'night'`.
**Fix / Décision :** Option 2 retenue. Guard et `whereIn` de la transaction inchangés (`['night', 'processing_night', 'day', 'processing_day']`). `$phaseToStart` reste calculé via `in_array($game->status, ['night', 'processing_night'])`. Pour `$phaseToStart === 'night'` : après le broadcast `MayorSuccessionDone`, le job `return` immédiatement — aucun appel à `startDay()`/`startNight()`. Pour `$phaseToStart === 'day'` (y compris `processing_day`) : comportement inchangé, appel à `startNight()`. Suppression du paramètre `$victimId` du constructeur (devenu inutile) et de l'import `GamePlayer` ; `ProcessNightActions::dispatch` mis à jour pour ne plus passer `$victim->id`.
**Leçon :** ⚠️ Régression intermédiaire assumée : après cette tâche seule, une mort du maire la nuit élit un successeur mais laisse la partie bloquée en `processing_night` (plus aucun job ne déclenche la suite). C'est voulu — `ProcessNightEnd` (Tâche H) doit être livré avec/juste après cette tâche pour fermer la boucle via un délai buffer couvrant la succession. Ne pas merger Tâche G seule sur `dev` sans Tâche H. Plus généralement : quand un énoncé de tâche liste un guard de statut sans mentionner un statut intermédiaire introduit par une tâche précédente, vérifier l'historique (DECISIONS.md) avant de réduire le guard.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] processing_day — guards de PhaseManager::startNight() et ProcessMayorSuccession étendus

**Contexte :** Tâche F — `app/Services/VoteService.php` (`resolveDayVote`), `app/Services/PhaseManager.php` (`startNight`), `app/Jobs/ProcessMayorSuccession.php`.
**Symptôme / Problème :** L'énoncé de la tâche F demandait de faire passer `resolveDayVote()` au statut `processing_day` (lockForUpdate sur `status='day'`) dès l'entrée en transaction, pour bloquer un second appel concurrent. Mais une fois le statut changé en `processing_day`, le code "hors transaction" de `resolveDayVote()` appelle `PhaseManager::startNight($game)` (cas normal/égalité/random) ou dispatche `ProcessMayorSuccession` (cas maire éliminé) — `$game->refresh()` y verrait alors `status='processing_day'`. Or `startNight()` gardait `where('status', 'day')->lockForUpdate()` et `ProcessMayorSuccession::handle()` gardait `in_array($game->status, ['night','processing_night','day'])` — aucun des deux n'aurait trouvé le jeu, et la transition nuit / la succession du maire auraient été silencieusement bloquées (partie figée en `processing_day`).
**Cause / Alternatives :** (1) Ne changer le statut qu'à `night` directement dans `resolveDayVote()` au lieu de `processing_day`, en supprimant l'appel à `startNight()` — mais cela duplique la logique de `startNight()` (broadcast `NightStarted`, dispatch `ProcessSeerTurn` avec délai, incrément `round`) et casse le pattern "chaque méthode de transition garde son propre guard" (cf. décision Tâche 16). (2) Étendre les guards de `startNight()` et `ProcessMayorSuccession` pour accepter `processing_day` en plus de `day`.
**Fix / Décision :** Option 2 retenue. `startNight()` : `whereIn('status', ['day', 'processing_day'])->lockForUpdate()`. `ProcessMayorSuccession::handle()` : `in_array($game->status, ['night','processing_night','day','processing_day'])` (guard d'entrée) et `whereIn('status', [...])` (transaction). Le calcul de `$phaseToStart` n'a pas besoin d'être modifié : `processing_day` n'étant pas dans `['night','processing_night']`, il vaut `'day'`, ce qui déclenche bien `startNight()` (comportement identique au cas `status='day'`).
**Leçon :** Tout changement de statut "guard atomique" introduit dans une méthode doit être tracé jusqu'aux méthodes/jobs appelés APRÈS ce changement (hors transaction) — leurs propres guards de statut doivent être étendus en conséquence, sinon la transition suivante est silencieusement bloquée. Ne pas se limiter au périmètre littéral de l'énoncé de tâche quand un nouveau statut intermédiaire est introduit.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Migration enum games.status (processing_day) — préservation de role_reveal

**Contexte :** Tâche E — `database/migrations/2026_06_11_191937_add_processing_day_to_games_status_enum.php`, suppression de `database/migrations/2026_06_10_004809_add_processing_night_to_games_status_enum.php` (migration fantôme, up()/down() vides).
**Symptôme / Problème :** L'énoncé de la tâche E décrivait l'ENUM actuel de `games.status` comme `waiting, electing_mayor, night, day, finished, processing_night, processing_wolves, wolves_turn` — sans `role_reveal`. Or les migrations `2026_06_10_000001/000002/000003` (commit `4d258e0`, "Résolution bug 1") ont déjà ajouté `role_reveal` à l'ENUM réel. En appliquant le SQL fourni tel quel (up() ET down() omettant `role_reveal`), la nouvelle migration aurait silencieusement supprimé `role_reveal` de l'ENUM.
**Cause / Alternatives :** `role_reveal` n'est référencé dans aucun code applicatif actuel, mais CLAUDE.md mentionne un timer `TIMER_READY_TIMEOUT` (60s, fixe) pour « l'écran révélation rôle », ce qui suggère que ce statut est planifié/attendu. (1) Suivre l'énoncé tel quel et supprimer `role_reveal`. (2) Conserver `role_reveal` dans l'ENUM et n'ajouter que `processing_day`.
**Fix / Décision :** Option 2 retenue (validée avec l'utilisateur). La nouvelle migration reproduit l'ENUM réel actuel (`waiting, role_reveal, electing_mayor, night, processing_night, processing_wolves, wolves_turn, day, finished`) et y ajoute `processing_day` (inséré entre `day` et `finished`). Le `down()` restaure exactement l'état issu de la migration `000003`.
**Leçon :** Avant d'appliquer un SQL d'ALTER TABLE ENUM fourni dans un énoncé de tâche, comparer la liste de valeurs avec l'ENUM réel (`SHOW COLUMNS FROM games` ou dernière migration `MODIFY COLUMN status ENUM(...)`) — un énoncé de tâche peut décrire un état de schéma obsolète/incomplet, et un `up()`/`down()` qui omet une valeur existante la supprime silencieusement.
**Statut :** 🔵 Choix assumé

---

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

---

## [RÉSOLU] Bouton "Tuer" inactif — castNightVote rejetait wolves_turn

**Contexte :** `VoteService::castNightVote()`, `ProcessWerewolvesTurn`
**Symptôme :** Les loups sélectionnaient une cible mais le bouton "Tuer" restait disabled. Le POST `/vote/night` retournait 409.
**Cause :** `ProcessWerewolvesTurn` passe le status à `wolves_turn` avant de broadcaster `WerewolvesTurnStarted`. `castNightVote` vérifiait `$game->status !== 'night'` → 409 car status est `wolves_turn`. Le JS ne recevant pas `json.success = true`, `wolfVoteLocked` restait `false` et le bouton restait disabled.
**Fix :** Remplacer le guard par `! in_array($game->status, ['night', 'wolves_turn'])`.
**Leçon :** Toute action métier liée à une phase doit accepter tous les statuts intermédiaires légitimes de cette phase, pas seulement le statut "initial". Documenter les statuts intermédiaires dans `config/game.php` ou dans un commentaire.
**Statut :** ✅ Résolu

---

## [RÉSOLU] SeerTurnStarted manqué — ProcessSeerTurn dispatché sans délai

**Contexte :** `PhaseManager::startNight()`, `ProcessSeerTurn`, `night.blade.php`
**Symptôme :** Voyante et loups ne voyaient jamais leur écran. Après un vote jour, les joueurs étaient redirigés vers `/night` mais aucun tour ne démarrait. `ProcessSeerTurn` tournait immédiatement et broadcastait `SeerTurnStarted` sur le canal privé avant que les clients soient abonnés.
**Cause :** `startNight()` dispatchait `ProcessSeerTurn::dispatch($id)` sans délai. Le job s'exécutait en quelques dizaines de ms, soit avant que les clients aient eu le temps de : (1) recevoir `NightStarted`, (2) exécuter la redirection GSAP (1.5s d'animation), (3) charger `/night`, (4) initialiser Echo et s'abonner au canal privé `game.{id}.player.{playerId}`.
**Fix :** `ProcessSeerTurn::dispatch($id)->delay(now()->addSeconds(config('game.timers.night_start_delay', 4)))`. Ajout de `night_start_delay = 4` dans `config/game.php`.
**Leçon :** Tout broadcast sur un canal privé qui suit immédiatement une redirection de page doit être retardé d'au moins la durée de l'animation de transition + le temps de chargement de la page. 4 secondes couvrent l'animation GSAP (1.5s) + chargement page + init Echo.
**Statut :** ✅ Résolu

---

## [RÉSOLU] scope Alpine parent polluait nightScreen/dayScreen — confirmQuit not defined

**Contexte :** `resources/views/layouts/game.blade.php`, `night.blade.php`, `day.blade.php`
**Symptôme :** `Alpine Expression Error: confirmQuit is not defined` sur `/night`. Bouton "Quitter" non fonctionnel. Erreur console ligne ~936 du bundle compilé.
**Cause :** Le layout `game.blade.php` avait `x-data="gameState(...)"` sur le `<main>` qui englobe tout le contenu des vues. Alpine fusionne les scopes imbriqués : expressions dans `nightScreen()` (scope enfant) remontaient vers `gameState` (scope parent) quand une propriété n'était pas trouvée. `gameState` ne définit pas `confirmQuit` → erreur. Effet secondaire : double abonnement Echo possible car `gameState` et les stores locaux souscrivaient tous les deux au même canal.
**Fix :** Déplacer `x-data="gameState(...)"` sur un `div` fantôme invisible (`visibility:hidden; position:absolute; width:0; height:0`) hors du `<main>`. Alpine initialise le composant normalement mais son scope n'englobe plus les vues enfants. `display:none` aurait empêché Alpine d'initialiser le composant.
**Leçon :** Ne jamais monter un store Alpine global sur un élément parent d'autres `x-data`. Utiliser un élément dédié hors du flux principal, ou passer par `Alpine.store()` (accès via `$store.name`).
**Statut :** ✅ Résolu

---

## [RÉSOLU] Double abonnement Echo — modale succession fantôme

**Contexte :** `game-state.js`, `night.blade.php`, `day.blade.php`
**Symptôme :** La modale "Succession du Maire" s'affichait parfois quand le maire était encore en vie. `i-was-eliminated` ne déclenchait pas `showDeathBanner`. Events WebSocket traités deux fois.
**Cause :** `game-state.js` s'abonnait à `echo.channel(game.X)` ET les vues locales (`night.blade.php`, `day.blade.php`) s'abonnaient au même canal via `window.Echo.channel(...)`. Même chose pour les canaux privés (voyante, loups). Chaque event se déclenchait deux fois → modale s'ouvrait, se fermait sur `done`, puis se ré-ouvrait sur le deuxième trigger. Deuxième bug : `game-state.js` utilisait `this.$dispatch('i-was-eliminated')` qui dispatch sur `this.$el` et bulle dans le DOM Alpine — les `window.addEventListener('i-was-eliminated')` dans les vues ne le recevaient jamais.
**Fix :** (1) Tous les events cross-composants passent par `window.dispatchEvent(new CustomEvent(...))` dans `game-state.js`. (2) Les vues suppriment leurs abonnements Echo directs pour tous les events gérés par `game-state.js` et écoutent uniquement via `window.addEventListener`. (3) `day.blade.php` garde un seul `Echo.channel` pour `.day.vote.cast` et `.chat.message.sent` (non propagés par `game-state.js`).
**Leçon :** `$dispatch()` d'Alpine dispatch sur `this.$el` (bulle dans le DOM Alpine uniquement). `window.dispatchEvent()` dispatch sur `window` (accessible partout, y compris dans les stores non-Alpine). Pour des events cross-composants sans relation parent-enfant Alpine directe, toujours utiliser `window.dispatchEvent`. Un seul abonnement Echo par canal et par event — jamais deux composants sur le même canal pour le même event.
**Statut :** ✅ Résolu

---

## [RÉSOLU] GameController::night() 404 sur wolves_turn et processing_night

**Contexte :** `GameController::night()`, `redirectToCurrentPhase()`
**Symptôme :** Joueurs redirigés vers `/role-reveal` en plein tour des loups ou lors d'un refresh de `/night`. Parfois 404 directe.
**Cause :** `night()` vérifiait `$game->status !== 'night'` → renvoyait vers `redirectToCurrentPhase()`. Cette méthode utilisait `match($game->status)` sans cas pour `wolves_turn` et `processing_night` → `default` → `game.role-reveal`. Idem dans `state()` : `$isNight = $game->status === 'night'` → `seerTurnActive` et `werewolvesTurnActive` retournaient `false` pendant `wolves_turn`.
**Fix :** `night()` accepte `['night', 'wolves_turn', 'processing_night']`. `redirectToCurrentPhase()` migré vers `match(true)` avec conditions explicites pour couvrir les statuts intermédiaires. `state()` corrigé : `$isNight = in_array($game->status, ['night', 'wolves_turn', 'processing_night'])`, `seerTurnActive` conditionné à `$game->status === 'night'` uniquement.
**Leçon :** Tout guard de phase doit accepter tous les statuts intermédiaires légitimes (définis dans `CLAUDE.md` §Statuts intermédiaires). Toujours lister explicitement les cas dans `redirectToCurrentPhase` — le `default` ne doit être qu'un vrai fallback, pas un attrape-tout pour des statuts légitimes non listés.
**Statut :** ✅ Résolu

---

## [RÉSOLU] DayStarted payload manquait player_id — _markPlayerDead silencieusement ignoré

**Contexte :** `DayStarted::broadcastWith()`, `game-state.js::handleDayStarted()`
**Symptôme :** Joueur tué la nuit restait marqué "vivant" côté client au début de la phase jour.
**Cause :** `DayStarted::broadcastWith()` envoyait `killed: { pseudo, role }` sans `player_id`. `handleDayStarted` appelait `_markPlayerDead(e.killed.player_id)` → `undefined` → aucun joueur marqué mort.
**Fix :** Ajouter `player_id` dans le payload `killed` de `DayStarted`.
**Leçon :** Toute référence à un joueur dans un payload WebSocket doit inclure son `id` (pas seulement son `pseudo`). Le `pseudo` peut changer, n'est pas indexable côté client, et ne permet pas de retrouver le joueur dans `players[]`.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Accumulation CheckReconnectionTimeout — handleDisconnection sans guard

**Contexte :** `GameService::handleDisconnection()`, `CheckReconnectionTimeout`
**Symptôme :** Des dizaines de jobs `CheckReconnectionTimeout` s'accumulaient en queue à chaque session. Le cache de déconnexion était écrasé à chaque appel, invalidant le token du job précédent qui restait orphelin en queue.
**Cause :** Reverb déclenche plusieurs événements de déconnexion WebSocket pour un même client (ping timeout, fermeture socket, etc.). Chaque appel à `handleDisconnection` créait un nouveau token UUID et un nouveau job, sans vérifier si un job était déjà en attente.
**Fix :** Guard `Cache::has($cacheKey)` en début de `handleDisconnection` — retour immédiat si un token actif existe déjà pour ce joueur.
**Leçon :** Toute action déclenchée par un événement réseau pouvant se produire plusieurs fois (déconnexion WebSocket, heartbeat, retry) doit être idempotente avec un guard d'entrée. Le cache est le mécanisme approprié pour ce type de guard éphémère.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Barre de timer jour affichée pleine quand temps = 0

**Contexte :** `day.blade.php`, `_startDayTimer()`
**Symptôme :** La barre de progression du timer restait partiellement ou totalement remplie alors que le compteur affichait 0s.
**Cause :** La barre HTML avait `width:100%` en dur. Si `PHASE_SECONDS = 0` (joueur arrivant après expiration), `_startDayTimer` retournait sans toucher la barre → elle restait à 100%. En cas normal, la barre partait toujours de 100% sans tenir compte du temps déjà écoulé depuis le chargement de la page.
**Fix :** La barre démarre à `width:0%` dans le HTML. `_startDayTimer` calcule `initialPct = (PHASE_SECONDS / totalSeconds) * 100` et set la largeur initiale correcte. Si `PHASE_SECONDS <= 0`, barre forcée à 0% immédiatement.
**Leçon :** Les barres de progression liées à un état serveur ne doivent jamais avoir une valeur initiale hardcodée en HTML. La valeur initiale doit être calculée depuis l'état réel (`phaseRemainingSeconds / totalSeconds`). Cela couvre aussi les joueurs qui arrivent en retard (refresh, reconnexion).
**Statut :** ✅ Résolu

---

## [CHOIX] ProcessNightEnd — délai via $game->timer() et récupération de la victime pour DayStarted

**Contexte :** Tâche H — `app/Jobs/ProcessNightActions.php`, `app/Services/PhaseManager.php::endNight()`.
**Symptôme / Problème :** L'énoncé de la tâche fournissait deux extraits à reproduire tels quels : (1) `ProcessNightEnd::dispatch(...)->delay(now()->addSeconds(config('game.timers.mayor_succession', 15) + 5))` ; (2) `endNight()` appelant `$this->startDay($game, null)`.
**Cause / Alternatives :** (1) `config('game.timers.mayor_succession', 15)` retourne en réalité `5` (valeur réellement définie dans `config/game.php` — le `15` de l'énoncé n'est qu'un défaut de fallback jamais atteint), soit un délai total de `5+5=10s`. Or `ProcessMayorSuccession` peut être dispatché avec un délai allant jusqu'à `$game->timer('mayor_succession')` = `15` (valeur posée par `TimerCalculator::FIXED` dans `$game->timers` au démarrage) : `10s` ne suffirait pas à couvrir la succession. CLAUDE.md interdit explicitement `config('game.timers.x')` au profit de `$game->timer('x')`. (2) `startDay($game, null)` ferait perdre l'info `killed` (player_id/pseudo/role de la victime de la nuit) du payload `DayStarted` pour tous les rounds — régression sur la décision « DayStarted payload manquait player_id ».
**Fix / Décision :** (1) Délai = `$game->timer('mayor_succession') + 5` → `15+5=20s` pour une partie réelle, conforme à l'exemple « 20s » de l'énoncé et à CLAUDE.md. (2) `endNight()` recalcule la victime via `app(VoteService::class)->resolveNightVote($game)` (lecture pure des `night_vote` du round, sans effet de bord — le `is_alive=false` a déjà été appliqué par `ProcessNightActions`) et la passe à `startDay($game, $victim)`. Point 4 de l'énoncé (retirer startDay/startNight de `ProcessSeerTurn`) vérifié sans objet : ce job ne contenait déjà aucun appel à ces méthodes.
**Leçon :** Ne jamais recopier littéralement un extrait de code d'énoncé sans vérifier (a) que les valeurs de config citées correspondent à la config réelle du projet, (b) qu'un paramètre `null`/omis ne casse pas un payload déjà documenté ailleurs dans DECISIONS.md. `resolveNightVote()` est sûr à rappeler car purement déclaratif.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] ProcessNightEnd job dédié plutôt qu'appel direct à startDay() depuis ProcessNightActions

**Contexte :** Tâche H — `ProcessNightActions`, `ProcessNightEnd`, `PhaseManager::endNight()`
**Symptôme / Problème :** `ProcessNightActions` appelait `startDay()` directement, créant plusieurs chemins de sortie (succession maire, voyante morte, cas normal) dont certains ne couvraient pas tous les scénarios — notamment voyante morte sans victime loups.
**Cause / Alternatives :** (1) Ajouter des branches conditionnelles supplémentaires dans `ProcessNightActions` pour couvrir chaque cas — complexité croissante, fragile. (2) Centraliser la fin de nuit dans un job unique `ProcessNightEnd` dispatché systématiquement avec un délai buffer couvrant la succession éventuelle.
**Fix / Décision :** Option 2 retenue. `ProcessNightActions` ne contient plus aucun appel à `startDay()`. Il dispatche toujours `ProcessNightEnd` avec `delay(mayor_succession + 5s)`. `PhaseManager::endNight()` contient la logique métier (guard status, WinConditionChecker, startDay). Le job n'est qu'un orchestrateur timer — conforme à CLAUDE.md.
**Leçon :** Tout job qui se termine par "et après, on passe à la phase suivante" doit déléguer cette transition à un job ou une méthode de service dédiée, jamais l'inliner. Cela permet de couvrir tous les chemins de sortie sans multiplier les branches.
**Statut :** 🔵 Choix assumé
---

## [RÉSOLU] Compteur successionDepth pour différer la redirection nuit pendant une cascade de successions

**Contexte :** `resources/js/game-state.js`, `resources/views/game/day.blade.php`
**Symptôme / Problème :** La modale "Succession du Maire" ne se fermait jamais quand le maire était éliminé le jour. `handleNightStarted()` redirigeait immédiatement vers `/night`, détruisant la page `/day` et tous ses `window.addEventListener` — dont celui qui ferme la modale sur `mayor-succession-done`. Un flag booléen simple (`successionInProgress`) aurait résolu le cas à une seule succession mais cassé la cascade (successeur élu éliminé à son tour le même jour → deuxième `MayorSuccessionStarted` reçu avant que le premier `done` n'ait remis le flag à `false`).
**Cause / Alternatives :** (1) Flag booléen — rejeté car non réentrant sur les cascades N successions consécutives. (2) Compteur `successionDepth` incrémenté sur chaque `.mayor.succession.started` et décrémenté (avec `Math.max(0, ...)` comme garde-fou) sur chaque `MayorSuccessionDone` — réentrant par construction.
**Fix / Décision :** `handleNightStarted()` vérifie `successionDepth > 0` : si oui, attend un event `mayor-succession-done` qui ramène le compteur à 0 avant d'appeler `_doNightRedirect()` (logique d'animation/redirection extraite en méthode dédiée). Garde-fou complémentaire côté `day.blade.php` : `openSuccessionModal()` arme un `setTimeout(20000)` qui force `closeSuccessionModal()` si `mayor-succession-done` n'arrive jamais (perte réseau, bug serveur).
**Leçon :** Pour tout état "N opérations asynchrones en cours" qui peut se déclencher en cascade (le même event de fin peut re-déclencher l'event de début avant d'être traité), utiliser un compteur réentrant plutôt qu'un flag booléen. Par ailleurs, toute redirection de page (`window.location.href`) détruit immédiatement tous les `window.addEventListener` de la page courante — une modale/état "en cours" doit être résolu (ou avoir un timeout de secours) AVANT toute redirection déclenchée par un autre handler.
**Statut :** ✅ Résolu

---

## [RÉSOLU] window.MY_ROLE absent du layout — canal loups jamais souscrit

**Contexte :** fix/window-my-role-layout — resources/views/layouts/game.blade.php,
resources/js/game-state.js.
**Symptôme / Problème :** le canal privé game.{gameId}.werewolves n'était jamais
souscrit pour certains joueurs loups (chat loups silencieux, votes non reçus).
game-state.js loge deux fallbacks vers window.MY_ROLE avant initWebSocket() — mais
window.MY_ROLE n'était défini nulle part dans le layout. Les vues night.blade.php
et day.blade.php définissaient MY_ROLE comme const locale sans l'exposer sur window.
**Cause / Alternatives :** (1) Ajouter window.MY_ROLE dans chaque vue Blade
(night, day, elect-mayor) — solution fragmentée, risque d'oubli sur les vues futures.
(2) Ajouter window.MY_ROLE dans le layout global, dans le @isset($player) existant —
centralisé, garanti pour toutes les vues sans duplication.
**Fix / Décision :** Option 2 retenue. Une ligne ajoutée dans layouts/game.blade.php :
window.MY_ROLE = '{{ $player->role ?? '' }}'. L'ordre d'exécution est garanti :
les scripts inline du layout s'exécutent avant les modules ES6 Vite (defer implicite),
donc window.MY_ROLE est défini avant que game-state.js::init() soit appelé.
**Leçon :** toute variable globale lue par game-state.js au moment de init() doit
être exposée dans le layout principal, pas dans les vues individuelles. Les vues
s'exécutent dans @push('scripts') qui est rendu après @stack('scripts') dans le layout,
mais les modules Vite sont defer — l'ordre réel est : layout script → vues @push →
bundle Vite. Pour les variables lues dès init() (avant le premier tick Alpine),
seul le layout garantit une disponibilité synchrone. Ne pas exposer sur window depuis
une const locale d'une vue Blade.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Empoisonnement sorcière ignoré — race condition ProcessWitchAutoAction vs transaction witchAct()

**Contexte :** `fix/witch-kill-timing` — `app/Http/Controllers/Game/ActionController.php`, `app/Jobs/ProcessWitchAutoAction.php`

**Symptôme / Problème :** La sorcière soumettait une action `kill`, recevait `success: true`, mais la nuit se terminait sans que l'empoisonnement soit pris en compte. L'interface affichait `witchActionDone = true` mais la victime n'était pas éliminée. Reproductible surtout avec le driver queue `database` en mode sync (tests) ou un worker très rapide en prod.

**Cause / Alternatives :** `ActionController::witchAct()` dispatchait `ProcessWitchAutoAction::delay(0)` immédiatement après le retour de `gameService->witchAct()`. Avec le driver sync (ou un worker ultra-rapide), le job s'exécutait avant que la transaction interne ait pu être vue par une requête DB ultérieure (isolation de lecture, Eloquent model non-rafraîchi). Le guard `$alreadyActed` ne trouvait pas d'action `witch_kill` → créait un `witch_pass` → dispatchait `ProcessNightEnd::delay(0)` → nuit terminée sans empoisonnement. En parallèle, `ProcessWitchAutoAction` lisait `$game->actions()` sur un modèle Eloquent potentiellement stale (pas de `refresh()`).

**Fix / Décision :** (1) `delay(0)` → `delay(now()->addSeconds(2))` dans `ActionController::witchAct()` pour laisser la transaction se propager. (2) `$game->refresh()` avant le guard `$alreadyActed` dans `ProcessWitchAutoAction::handle()` pour forcer la lecture des données fraîches. Les deux fixes sont complémentaires : le délai couvre la race condition en prod, le refresh couvre les lectures Eloquent stale en test.

**Leçon :** Toute action volontaire qui dispatche un job auto en `delay(0)` crée une fenêtre de race condition. Pattern à appliquer systématiquement : (a) délai minimal de 2s sur le dispatch auto depuis un Controller, (b) `$game->refresh()` avant chaque guard de double-fire dans les jobs qui succèdent à une action volontaire. Voir aussi DECISIONS.md "Race condition vote loups" pour le même pattern appliqué aux loups.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Broadcasts et race conditions dans les transactions lockForUpdate

**Contexte :** `fix/broadcasts-out-of-transaction` — `app/Services/GameService.php`, `app/Jobs/ProcessNightEnd.php`, `app/Jobs/ProcessSeerTurn.php`, `app/Jobs/ProcessMayorElection.php`, `app/Services/PhaseManager.php`

**Symptôme / Problème :** Plusieurs méthodes de `GameService` (`cancelGame`, `markReady`, `joinGame`, `startGame`, `excludePlayer`) contenaient des `broadcast()` et des `notify()` à l'intérieur de blocs `DB::transaction()` avec `lockForUpdate()`. En cas d'échec de rollback ou de lenteur du worker Reverb, le broadcast était émis avant que la transaction soit visible pour les autres connexions DB. Dans `ProcessNightEnd`, la lecture + suppression de `hunter_pending` était non atomique : deux exécutions simultanées du job pouvaient toutes deux voir l'enregistrement avant qu'il soit supprimé, déclenchant deux `ProcessHunterTurn`. Dans `ProcessSeerTurn`, aucun guard sur le round n'existait : un job stale d'une nuit précédente (en retard dans la queue) pouvait déclencher le tour voyante d'une nouvelle nuit.

**Cause / Alternatives :** Pattern anti-pattern : side-effects (broadcasts, notifications HTTP, dispatches delay(0)) dans une transaction DB ouverte. La transaction garantit l'atomicité des écritures, pas des effets de bord réseaux. Alternative pour les broadcasts : faire retourner les données nécessaires par le closure de transaction, puis broadcaster après.

**Fix / Décision :**
- `cancelGame`, `markReady`, `startGame`, `joinGame`, `excludePlayer` : la transaction retourne un tableau de données, tous les `broadcast()` et `notify()` sont appelés après, conditionnés par un check non-null.
- `markReady` : `ProcessMayorElection::dispatch()->delay(timer)` reste DANS la transaction (delay > 0, autorisé par Guard #5 — il ne s'exécute pas pendant la transaction).
- `excludePlayer` : `$targetUser` chargé avant le `DB::transaction()` ; `$target` reste en mémoire PHP avec ses attributs après `delete()` — les broadcasts peuvent l'utiliser sans aller en base.
- `ProcessNightEnd` : lecture + suppression de `hunter_pending` enveloppée dans `DB::transaction()` + `lockForUpdate()` ; `ProcessHunterTurn::dispatch()->delay(0)` dispatché hors transaction (Guard #5).
- `ProcessSeerTurn` : ajout du paramètre `$round` au constructeur ; guard `$game->round !== $this->round` dans `handle()`.

**Leçon :** Règle absolue : aucun `broadcast()`, `notify()` ou `dispatch()->delay(0)` dans un `DB::transaction()` qui contient un `lockForUpdate()`. Les dispatches avec `delay > 0` sont tolérés (ils ne s'exécutent pas pendant la transaction). Pour les jobs avec guard de double-fire, l'opération "lire + détruire" doit elle-même être atomique (transaction + lockForUpdate).

**Statut :** ✅ Résolu

---

## [CHOIX] applyTransition() interdit sur les statuts intermédiaires hors Workflow

**Contexte :** `docs/apply-transition-guard-warning` — `app/Models/Game.php`, `app/Jobs/ProcessNightEnd.php`

**Symptôme / Problème :** Le Workflow Symfony ne couvre que 5 places canoniques (`waiting`, `electing_mayor`, `night`, `day`, `finished`). Les statuts intermédiaires (`processing_night`, `processing_day`, `wolves_turn`, `role_reveal`) sont gérés manuellement hors Workflow via `$game->update(['status' => '...'])`. Appeler `Game::applyTransition()` depuis l'un de ces statuts lève une `LogicException` Symfony (`"The marking does not contain a place 'processing_night'."`) catchée silencieusement par le queue worker Laravel — le job est marqué `failed` sans message clair dans les logs applicatifs.

**Cause / Alternatives :** L'existence de statuts intermédiaires hors Workflow est un choix d'architecture v1.2 (granularité nécessaire pour les phases nocturnes séquentielles). Alternative — les intégrer comme places Workflow — rejetée : complexité disproportionnée et couplage fort aux Jobs internes.

**Fix / Décision :** Tâche purement documentaire. PHPDoc complet ajouté sur `Game::applyTransition()` listant les statuts interdits, le symptôme exact d'une erreur, et le pattern autorisé (retour au dernier statut canonique via `update()`, puis `applyTransition()`). Commentaire de rappel ajouté dans `ProcessNightEnd::handle()` au-dessus du bloc qui bypasse intentionnellement `canTransition()` pour `processing_night`.

**Leçon :** Ne jamais appeler `applyTransition()` si `$game->status` est l'un de : `processing_night`, `processing_day`, `wolves_turn`, `role_reveal`. Ces statuts sont écrits manuellement (`$game->update(['status' => '...'])`). Pour revenir dans le graphe Workflow, écrire d'abord le statut canonique source, puis appeler `applyTransition()`. Voir aussi Guard #5 de RISK_GUARDS.md pour la règle `lockForUpdate()` associée.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] PlayerEliminated broadcasté trop tôt pendant la nuit quand la sorcière a son soin

**Contexte :** `fix/bug-toast-elimination-premature-nuit` — `app/Jobs/ProcessNightActions.php`, `app/Services/RoleActions/WitchAction.php`.

**Symptôme / Problème :** `ProcessNightActions` broadcastait `PlayerEliminated` pour la victime ordinaire des loups dès la résolution du vote, avant que la sorcière ait eu le temps d'agir. Côté front, le toast d'élimination apparaissait pendant la nuit alors que la sorcière pouvait encore sauver la victime — ce qui créait un spoil prématuré de l'issue.

**Cause / Alternatives :** Les guards existants (`$victimIsWitchWithHeal`, `$victimIsMayorWithWitchAvailable`) différaient la mort et le broadcast pour deux cas précis (sorcière victime d'elle-même, maire en sursis), mais pas pour la victime ordinaire face à une sorcière avec soin disponible. La troisième condition manquante : "la sorcière est vivante et son soin n'a pas encore été utilisé".

**Fix / Décision :**
- `ProcessNightActions` : ajout d'un troisième flag `$witchCanSaveVictim` (`$witch !== null && !witch_heal_used`). Les deux conditions (mort en base + broadcast `PlayerEliminated`) incluent désormais `! $witchCanSaveVictim`.
- `WitchAction::act()` (kill/pass) : après les broadcasts `$witchDiedFromWolves` et `$mayorVictim`, bloc hors transaction qui détecte la victime ordinaire encore vivante (not witch, not mayor, `is_alive = true`), la marque morte et broadcast `PlayerEliminated`. Guarded par `$action !== 'heal'` pour ne pas tuer une victime que la sorcière vient de soigner.
- La mort et le broadcast sont donc différés ensemble : soit `ProcessNightActions` les gère (sorcière morte ou soin déjà épuisé), soit `WitchAction` les gère (sorcière agit dans ce round).

**Leçon :** Quand un événement doit être conditionné à l'action d'un autre joueur, différer les deux (mort DB + broadcast) ensemble — pas seulement le broadcast. Et prévoir le cas `ProcessWitchAutoAction` (timer expiré sans action manuelle) comme chemin de résolution alternatif qui n'appelle pas `WitchAction::act()` — c'est un gap résiduel à traiter dans un ticket dédié.

**Statut :** ✅ Résolu
