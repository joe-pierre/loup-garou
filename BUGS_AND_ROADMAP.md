# BUGS CORRIGÉS

### [x] 2026-06-16 — Endpoints d'action sans rate limiting (saturation queue)

- **Symptôme :** un joueur malveillant pouvait envoyer des centaines de requêtes par seconde sur les endpoints de vote, de rôle et de chat, saturant la queue Laravel avec des transactions DB et des broadcasts WebSocket.
- **Cause :** aucun middleware `throttle` sur les 8 routes d'action POST dans `routes/web.php`.
- **Fix :** sous-groupe `Route::middleware('throttle:60,1')` regroupant les 8 endpoints concernés (vote/mayor, vote/day, vote/night, seer/check, witch/act, hunter/shoot, mayor/succession, chat). Les routes GET et les routes de navigation restent sans throttle additionnel.

---

### [x] 2026-06-16 — Appels config('game.timers.*') résiduels hors TimerCalculator

- **Symptôme :** si un host configurait un timer (ex. `mayor_succession=20s`), certains chemins (VoteService, ProcessNightActions, GameService, WaitForReadyPlayers, ProcessMayorElection, PhaseManager) utilisaient toujours la valeur `config()` hardcodée au lieu de la valeur personnalisée.
- **Cause :** 9 occurrences de `config('game.timers.*')` subsistaient en dehors de `TimerCalculator.php`, en violation de la règle CLAUDE.md.
- **Fix :** remplacement par `$game->timer('...')` / `$locked->timer('...')` / `$player->game->timer('...')` dans tous les fichiers concernés. Seul `config('game.timers.limits')` dans `validateTimerSettings()` conservé (accès au tableau de validation, pas une valeur de timer).

---

### [x] 2026-06-16 — redirectToCurrentPhase() ignorait processing_day et processing_wolves

- **Symptôme :** un joueur rafraîchissant la page pendant la résolution d'un vote (statuts `processing_day` ou `processing_wolves`) était redirigé vers `game.role-reveal` au lieu de `game.day` / `game.night`.
- **Cause :** le `match` de `redirectToCurrentPhase()` ne couvrait pas `processing_day` et `processing_wolves` ; ils tombaient dans le `default`.
- **Fix :** regroupement de `['day', 'processing_day']` → `game.day` et `['night', 'wolves_turn', 'processing_night', 'processing_wolves']` → `game.night`.

---

### [x] 2026-06-16 — Succession maire durait 5s au lieu de 15s (hosts sans timers custom)

- **Symptôme :** succession du maire durait 5s au lieu de 15s pour les hosts sans timers personnalisés.
- **Cause :** `config/game.php` avait `mayor_succession = 5` au lieu de 15 ; `TimerCalculator::FIXED` définissait bien 15 mais n'était plus la source de lecture depuis l'Étape 3 — le fallback `config()` s'appliquait donc avec la mauvaise valeur.
- **Fix :** `config/game.php → mayor_succession = 15`.

---

### [x] 2026-06-15 — Nuit entière skippée quand les loups sont en égalité

- **Symptôme :** quand les loups ne désignaient aucune victime (égalité),
  les tours voyante, loups et sorcière étaient silencieusement skippés.
  La partie passait directement au jour suivant.
- **Cause :** ProcessWitchTurn dispatchait ProcessNightEnd::delay(0) dans
  ses guards "skip silencieux" (potions épuisées, ou pas de victime + pas
  de poison disponible). Ce ProcessNightEnd anticipé s'exécutait
  immédiatement, voyait status='processing_night', passait le guard et
  appelait endNight() avant même que les timers voyante et loups (8s +
  seer_timer + wolves_timer) ne soient écoulés.
- **Fix :** suppression des dispatches ProcessNightEnd::delay(0) dans les
  guards de skip de ProcessWitchTurn. Ces guards font maintenant un simple
  return. Le ProcessNightEnd dispatché par ProcessNightActions (avec un
  délai calculé dynamiquement = witch_timer + mayor_succession + 5s) gère
  la fin de nuit dans tous les cas.
- **Fix associé :** le délai du ProcessNightEnd dispatché par ProcessNightActions
  était fixe (mayor_succession + 5s ≈ 20s) et ne couvrait pas witch_timer
  (jusqu'à 60s configurable). Remplacé par witch_timer + mayor_succession + 5s.
- **Fichiers modifiés :** app/Jobs/ProcessWitchTurn.php,
  app/Jobs/ProcessNightActions.php, tests/Feature/Game/WitchTest.php.

---

### [x] 2026-06-14 — Toasts dispatchés tôt dans le cycle de vie Alpine perdus

- **Symptôme :** certains toasts (ex. déclenchés très tôt après le chargement de la page) n'apparaissaient jamais.
- **Cause :** `_dispatchToast()` dispatche `show-toast` sur `window` avant que le composant `toast.blade.php` ait fini son `init()` Alpine — personne n'écoute encore l'event, il est perdu.
- **Fix :** buffer global `window.__toastBuffer` rempli par `_dispatchToast()` tant que `window.__toastReady` n'est pas vrai ; `toast.blade.php::init()` consomme ce buffer et passe `window.__toastReady = true`. Retry via `requestAnimationFrame` côté `_dispatchToast()`.

---

### [x] 2026-06-14 — Canal loups jamais souscrit (myRole non renseigné avant initWebSocket)

- **Symptôme :** le chat loups et les events du canal `game.{gameId}.werewolves`
  (votes de la meute, démarrage du tour loups) ne s'affichaient jamais pour
  certains loups.
- **Cause :** `isWerewolf` (getter sur `this.myRole`) pouvait valoir `false` au
  moment de `initWebSocket()` si `_loadState()` échouait silencieusement et que
  `window.MY_ROLE` n'était pas encore disponible au moment de l'appel.
- **Fix :** ajout d'un fallback `if (!this.myRole) this.myRole = window.MY_ROLE ?? null;`
  dans `init()`, juste avant `this.initWebSocket()`, garantissant que `myRole`
  est renseigné avant l'évaluation de `isWerewolf`.

---

### [x] 2026-06-14 — Sorcière ne pouvait pas utiliser son poison si égalité loups

- **Symptôme :** les nuits où les loups étaient en égalité (pas de victime),
  la sorcière ne voyait aucune interface et ne pouvait pas utiliser son poison.
  ProcessNightEnd s'exécutait directement.
- **Cause :** Guard #3 dans ProcessWitchTurn skippait le tour entier si
  resolveNightVote() retournait null, sans vérifier si des potions étaient
  encore disponibles.
- **Fix :** le tour n'est skippé que si les deux potions sont épuisées,
  ou si la seule potion disponible est le soin (inutilisable sans victime).
  WitchTurnStarted accepte victim:null et affiche un message adapté côté client.

---

### [x] 2026-06-14 — Chasseur ne pouvait pas exercer son pouvoir si éliminé le jour

- **Symptôme :** quand le chasseur était éliminé par vote jour, aucune interface
  ne s'affichait — ProcessHunterAutoAction s'exécutait sans que le joueur ait pu tirer.
- **Cause :** day.blade.php n'écoutait pas l'event hunter-turn-started dispatché
  par game-state.js sur window. Seul night.blade.php gérait cet event.
- **Fix :** ajout d'une modale chasseur dans day.blade.php déclenchée par
  window.addEventListener('hunter-turn-started'), avec liste des cibles vivantes,
  timer visuel et appel POST /hunter/shoot.

---

### [x] 2026-06-14 — Éliminations silencieuses pour les autres joueurs

- **Symptôme :** quand un joueur était éliminé, les autres joueurs ne voyaient
  aucune annonce visuelle — seule la liste des joueurs se mettait à jour silencieusement.
- **Cause :** handlePlayerEliminated() dans game-state.js ne dispatchait pas de toast.
- **Fix :** ajout d'un _dispatchToast() dans handlePlayerEliminated() avec le pseudo
  et le rôle du joueur éliminé, visible par tous les joueurs connectés.

---

### [x] 2026-06-14 — Timers UX trop courts (résultat maire, modale succession)

- **Symptôme :** le résultat de l'élection du maire disparaissait après 4s,
  la modale succession se fermait après 2.5s — trop rapide pour être lu confortablement.
- **Cause :** valeurs setTimeout codées en dur trop basses.
- **Fix :** résultat maire → 6000ms, fermeture modale succession → 5000ms.

---

### [x] 2026-06-14 — Messages de chat en doublon : cause racine (double abonnement Echo même canal)

- **Symptôme :** chaque message envoyé dans le chat général (jour) apparaissait deux fois.
- **Cause :** day.blade.php appelait window.Echo.channel(`game.${GAME_ID}`) pour écouter
  .day.vote.cast, en plus de game-state.js qui s'abonnait déjà au même canal. Laravel Echo
  réutilise la souscription Pusher/Reverb existante et déclenche tous les listeners deux fois,
  y compris .chat.message.sent. Un premier fix (branche fix/chat-double-messages) avait supprimé
  un listener .chat.message.sent dupliqué mais n'avait pas identifié ce second abonnement Echo.
- **Fix :** suppression de window.Echo.channel() dans day.blade.php. day-vote-cast est désormais
  dispatché sur window par game-state.js (_handleDayVoteCast) et écouté via
  window.addEventListener dans day.blade.php — cohérent avec le pattern chat-message,
  player-eliminated, etc.

---

### [x] 2026-06-14 — Fix doublons chat cause racine : vérification finale (aucune régression)

- **Symptôme :** N/A — vérification de non-régression du fix précédent.
- **Cause :** N/A.
- **Fix :** Vérifié que day.blade.php n'a aucun appel window.Echo.channel()/window.Echo.private(),
  écoute bien .day.vote.cast via window.addEventListener('day-vote-cast', ...) et les messages
  chat via window.addEventListener('chat-message', ...). Vérifié que game-state.js dispatche
  bien window.dispatchEvent(new CustomEvent('day-vote-cast', { detail: e })) dans
  _handleDayVoteCast() et window.dispatchEvent(new CustomEvent('chat-message', { detail: e }))
  dans _handleChatMessage(). Code conforme, aucune modification nécessaire.

---

### [x] 2026-06-14 — backdrop-filter blur sur la modale succession rendu flou/dégradé

- **Symptôme :** la modale "Succession du Maire" s'affichait avec un rendu visuellement flou/dégradé sur certains navigateurs.
- **Cause :** `backdrop-filter: blur(4px)` sur l'overlay de la modale succession dans `day.blade.php` et `night.blade.php`.
- **Fix :** suppression du `backdrop-filter`, opacité de l'overlay augmentée de 0.65 à 0.82 pour compenser visuellement.

---

### [x] 2026-06-14 — Messages de chat en doublon (canal général et canal loups)

- **Symptôme :** chaque message envoyé dans le chat général (jour) ou le canal loups (nuit) apparaissait deux fois.
- **Cause :** `day.blade.php` s'abonnait en plus à `.chat.message.sent` sur `game.${GAME_ID}` alors que `game-state.js` traite déjà cet event via `_handleChatMessage()`.
- **Fix :** suppression du listener Echo direct dans `day.blade.php` ; `_handleChatMessage()` dispatch désormais `window.dispatchEvent('chat-message')`, écouté par `day.blade.php`. Vérifié `night.blade.php` : aucun abonnement Echo dupliqué sur le canal loups, déjà conforme.

---

### [x] 2026-06-14 — welcome.blade.php renommé en home.blade.php, landing.blade.php supprimé

- welcome.blade.php était la vue active (route / → view('welcome')) mais
  landing.blade.php existait en parallèle sans être référencé par aucune route.
- Fix : renommage welcome → home, mise à jour de la route, suppression de landing.

---

### [x] 2026-06-14 — Timers erronés broadcastés aux clients (Events + night.blade.php)

- **Symptôme :** si le host configure des timers personnalisés (ex. seer=15s),
  le client affiche une barre de progression basée sur la valeur par défaut
  (30s) pendant que le vrai timer serveur est 15s — désynchronisation visible.
- **Cause :** MayorElectionStarted, MayorSuccessionStarted, SeerTurnStarted,
  WerewolvesTurnStarted utilisaient config('game.timers.x') directement dans
  broadcastWith() au lieu de $game->timer('x'). night.blade.php accédait aussi
  à $game->settings['timers']['x'] directement.
- **Fix :** remplacement par $game->timer('x') dans les 4 Events et dans
  night.blade.php — conforme à la règle CLAUDE.md et à TimerCalculator.

---

### [x] 2026-06-14 — Guard $alreadyDone bloquait la succession de jour après une succession de nuit au même round

- **Symptôme :** quand le maire mourait la nuit et son successeur était éliminé
  le jour du même round, la partie restait bloquée en processing_day. Aucun
  failed_job, aucune erreur — ProcessMayorSuccession retournait silencieusement null.
- **Cause :** le guard $alreadyDone cherchait mayor_succession par (game_id, round)
  sans distinguer la phase. L'action de nuit bloquait l'action de jour au même round.
- **Fix :** ajout de ->where('phase', $phaseToStart) dans le guard — une succession
  de nuit et une succession de jour au même round sont désormais indépendantes.

---

### [x] 2026-06-14 — Succession maire : is_mayor non retiré à l'ancien maire

- **Symptôme :** après une succession de nuit, l'ancien maire (mort) gardait
  is_mayor = true. Le successeur pouvait ne pas recevoir is_mayor = true.
  Quand le successeur était éliminé le jour, resolveDayVote() ne détectait pas
  qu'il était maire → pas de nouvelle succession → partie bloquée en processing_day.
- **Cause :** ProcessMayorSuccession::handle() n'appelait pas
  $locked->players()->update(['is_mayor' => false]) avant d'assigner le flag
  au successeur.
- **Fix :** reset global is_mayor = false sur tous les joueurs de la partie
  avant chaque assignation de nouveau maire, dans la transaction DB.

---

### [x] 2026-06-13 — Votes de la meute illisibles (cible non affichée)

- **Symptôme :** dans la section "Votes de la meute" du canal loups, seul un ✓/? indiquait si un loup avait voté, sans préciser pour qui.
- **Cause :** `VoteService::getNightVoteState()` ne renvoyait que `has_voted`, sans `target_player_id`/`target_pseudo`.
- **Fix :** `getNightVoteState()` enrichit chaque entrée avec `target_player_id` et `target_pseudo` ; `night.blade.php` affiche "Loup → Cible" ou "n'a pas encore voté".

---

### [x] 2026-06-13 — Chat village indisponible pendant la résolution du vote jour

- **Symptôme :** les joueurs ne pouvaient plus écrire dans le chat général pendant le statut `processing_day` (entre la fin du vote et le démarrage de la nuit/jour suivant).
- **Cause :** `ChatService::sendMessage()` n'autorisait le canal `general` que pour `['electing_mayor', 'day']`.
- **Fix :** ajout de `'processing_day'` à la liste des statuts autorisés pour le canal `general`.

---

### [x] 2026-06-11 — Double-fire ProcessDayVote bloque la phase jour

- **Symptôme :** la partie se bloque en phase jour — la résolution du vote est déclenchée deux fois
- **Cause :** ProcessDayVote dispatché plusieurs fois sans guard atomique ; VoteService::resolveDayVote() lockForUpdate() sur status='day' mais ne changeait jamais ce statut, donc un second appel concurrent retrouvait status='day' et retraitait les votes
- **Fix :** `resolveDayVote()` passe le statut à `processing_day` (lockForUpdate) dès l'entrée en transaction — un second appel ne trouve plus `status='day'` et est ignoré. `PhaseManager::startNight()` et `ProcessMayorSuccession` mis à jour pour accepter `processing_day` en plus de `day` (sinon la transition nuit / la succession du maire ne se déclenchait plus après ce changement)

---

### [x] 2026-06-11 — Succession du maire non déclenchée si mort la nuit

- **Symptôme :** quand le maire meurt la nuit, aucun nouveau maire n'est élu
- **Cause :** ProcessMayorSuccession appelait startDay() au lieu de ne rien faire en contexte nuit (sens inversé documenté dans DECISIONS.md)
- **Fix :** correction de l'inversion startDay/startNight dans ProcessMayorSuccession ; en contexte nuit, le job se termine après l'élection sans déclencher de transition de phase

---

### [x] 2026-06-11 — Nuit sans loups si la voyante est morte

- **Symptôme :** quand la voyante est morte, la nuit ne se termine pas et le jour ne démarre jamais
- **Cause :** ProcessNightActions appelait startDay() directement, ce chemin était court-circuité dans certains contextes (succession maire, voyante absente)
- **Fix :** création de ProcessNightEnd dispatché systématiquement en fin de ProcessNightActions ; PhaseManager::endNight() centralise la logique de fin de nuit

---

### [x] 2026-06-11 — SQL Data truncated sur games.status (ENUM incomplet)

- **Symptôme :** erreur SQL "Data truncated for column status" en production
- **Cause :** processing_day absent de l'ENUM games.status ; migration fantôme 004809 avec up() vide introduisant une fausse sécurité
- **Fix :** suppression de la migration orpheline, nouvelle migration ajoutant processing_day à l'ENUM complet

### [x] 2026-06-06 — AlpineJS non installé

- **Symptôme :** erreur Vite "Failed to resolve import alpinejs"
- **Cause :** package absent de node_modules
- **Fix :** `npm install alpinejs`

### [x] 2026-06-06 — Vite manifest not found

- **Symptôme :** page blanche après connexion Google
- **Cause :** `npm run build` jamais exécuté, `manifest.json` absent
- **Fix :** `@vite` dans le layout + `npm run build`

### [x] 2026-06-06 — FOUC Alpine.js

- **Symptôme :** flash du HTML brut pendant une fraction de seconde au chargement
- **Cause :** règle `[x-cloak]` chargée trop tard dans le DOM
- **Fix :** `<style>[x-cloak]{display:none!important}</style>` en premier enfant de `<head>`, `x-cloak` sur le `<main x-data>`

### [x] 2026-06-06 — Carte "Rejoindre" masquée sur desktop

- **Symptôme :** seule la carte "Créer" visible après init Alpine
- **Cause :** logique d'onglets mobile appliquée aussi sur desktop
- **Fix :** `md:!block` sur les deux panneaux

### [x] 2026-06-06 — Panneaux quasi-invisibles au chargement

- **Symptôme :** les deux cartes s'assombrissent après le flash initial
- **Cause :** `gsap.from()` pose `opacity:0` immédiatement sur les éléments sans état CSS initial
- **Fix :** `gsap.fromTo()` + `#panel-create, #panel-join { opacity: 0 }` en CSS

### [x] 2026-06-06 — gsap.from() opacity global (toutes les vues)

- **Symptôme :** éléments quasi-invisibles au chargement sur toutes les pages animées
- **Cause :** `gsap.from({ opacity: 0 })` sans état CSS initial crée un assombrissement visible
- **Fix :** `gsap.fromTo()` + `opacity:0` en CSS sur chaque élément animé — vues concernées : `lobby/index.blade.php`, `lobby/waiting-room.blade.php` + toutes les autres vues animées

### [x] 2026-06-06 — Phase nuit bloquée

- **Symptôme :** la phase nuit ne progressait pas après le vote des loups
- **Cause :** broadcast émis à l'intérieur d'une `DB::transaction` — le broadcast part avant que la transaction soit committée
- **Fix :** déplacer les broadcasts après la transaction dans `PhaseManager::startDay()` et `PhaseManager::startNight()`

---

### [x] 2026-06-10 — Bouton "Tuer" inactif côté loups

- **Symptôme :** sélection d'une cible possible mais bouton "Tuer" restait disabled, POST /vote/night retournait 409
- **Cause :** `VoteService::castNightVote` vérifiait `status !== 'night'` mais `ProcessWerewolvesTurn` passe le status à `wolves_turn` avant de broadcaster
- **Fix :** accepter `['night', 'wolves_turn']` dans le guard de `castNightVote`

---

### [x] 2026-06-10 — Tour voyante/loups jamais affiché après vote jour

- **Symptôme :** après un vote jour, joueurs redirigés vers /night mais aucun tour voyante ni loups ne démarrait
- **Cause :** `PhaseManager::startNight()` dispatchait `ProcessSeerTurn` sans délai — le job broadcastait `SeerTurnStarted` avant que les clients soient abonnés au canal privé
- **Fix :** `->delay(now()->addSeconds(config('game.timers.night_start_delay', 4)))` + ajout de `night_start_delay = 4` dans `config/game.php`

---

### [x] 2026-06-10 — confirmQuit not defined / erreur Alpine sur /night

- **Symptôme :** `Alpine Expression Error: confirmQuit is not defined` sur la page nuit, bouton Quitter non fonctionnel
- **Cause :** `x-data="gameState(...)"` sur le `<main>` du layout englobait les vues enfants — Alpine remontait dans le scope parent qui ne définit pas `confirmQuit`
- **Fix :** déplacer `gameState` sur un div fantôme invisible hors du `<main>`

---

### [x] 2026-06-10 — Modale succession affichée sans mort du maire / events doublés

- **Symptôme :** modale "Succession du Maire" s'ouvrait parfois quand le maire était vivant, events WebSocket traités deux fois
- **Cause :** double abonnement Echo sur le même canal dans `game-state.js` et dans les vues locales ; `$dispatch()` Alpine ne reach pas `window.addEventListener`
- **Fix :** supprimer tous les abonnements Echo locaux dans les vues, tout passer par `window.dispatchEvent` dans `game-state.js`

---

### [x] 2026-06-10 — 404 sur /night lors du tour des loups ou d'un refresh

- **Symptôme :** joueurs redirigés vers /role-reveal ou 404 en rafraîchissant /night pendant `wolves_turn` ou `processing_night`
- **Cause :** `GameController::night()` n'acceptait que `status = 'night'`, `redirectToCurrentPhase` tombait dans `default` pour les statuts intermédiaires
- **Fix :** `night()` accepte `['night', 'wolves_turn', 'processing_night']`, `redirectToCurrentPhase` migré vers `match(true)` avec cas explicites

---

### [x] 2026-06-10 — Accumulation de CheckReconnectionTimeout jobs

- **Symptôme :** dizaines de jobs `CheckReconnectionTimeout` en queue, un par événement de déconnexion WebSocket
- **Cause :** `handleDisconnection` sans guard — Reverb peut émettre plusieurs événements de déconnexion pour un même client
- **Fix :** guard `Cache::has($cacheKey)` en entrée de `handleDisconnection`

---

### [x] 2026-06-10 — Barre timer jour pleine quand temps = 0

- **Symptôme :** barre de progression restait remplie alors que le compteur affichait 0s
- **Cause :** `width:100%` hardcodé en HTML, `_startDayTimer` retournait sans toucher la barre si `PHASE_SECONDS = 0`
- **Fix :** barre initialisée à `width:0%`, largeur calculée depuis `PHASE_SECONDS / totalSeconds` au démarrage

---

### [x] 2026-06-09 — GSAP entry non protégé prefers-reduced-motion (night + day)

- **Symptôme :** utilisateurs `prefers-reduced-motion` voyaient quand même les animations d'entrée GSAP
- **Cause :** les appels `gsap.fromTo('.reveal', ...)` à l'init n'étaient pas wrappés dans un check JS matchMedia
- **Fix :** ajout de `if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches)` dans night.blade.php (script global) et day.blade.php (init())

---

### [x] 2026-06-09 — ProcessMayorSuccession bloque si Maire tué la nuit

- **Symptôme :** partie bloquée en phase nuit quand le Maire est la victime des loups
- **Cause :** guard `status = 'day'` empêchait le Job de s'exécuter en phase nuit
- **Fix :** accepter `night` et `day`, déduire la transition depuis `$game->status` avant transaction

---

### [x] 2026-06-12 — Modale succession bloquée si le successeur est tué la nuit suivante
 
- **Symptôme :** quand le maire est éliminé la nuit et qu'un successeur est désigné, si ce successeur est lui-même tué la nuit suivante, la partie restait bloquée sur la modale "Succession du Maire".
- **Cause :** `ProcessMayorSuccession` déduisait le contexte (nuit/jour) depuis `$game->status` au moment de son exécution — mais si `ProcessNightEnd` s'exécutait en premier, le statut était déjà `day` et le job concluait à tort à un contexte jour.
- **Fix :** flag `shouldStartNight` passé explicitement par le dispatcher (`ProcessNightActions` passe `true`, `VoteService::resolveDayVote` laisse le défaut `false`). Le contexte est porté par l'appelant, pas re-déduit depuis la DB.

---

### [x] 2026-06-12 — $this->authorize() indisponible dans les Controllers (trait AuthorizesRequests manquant)

- **Symptôme :** `LobbyController::updateTimers()` (nouvel endpoint Étape 3) plante avec une erreur "Call to undefined method" sur `$this->authorize(...)` ; `GameController::history()` était affecté par le même problème mais sans test couvrant ce chemin, le bug restait latent.
- **Cause :** `app/Http/Controllers/Controller.php` est une classe abstraite vide, sans le trait `Illuminate\Foundation\Auth\Access\AuthorizesRequests` ni extension de `Illuminate\Routing\Controller`.
- **Fix :** ajout de `use Illuminate\Foundation\Auth\Access\AuthorizesRequests;` dans `app/Http/Controllers/Controller.php`, appliqué à tous les controllers via l'héritage existant.

---

### [x] 2026-06-13 — Modale succession maire bloquée si NightStarted précède MayorSuccessionDone

- **Symptôme :** modale "Succession du Maire" ne se fermait jamais quand le maire
  était éliminé le jour — la partie semblait bloquée sur /day. En cascade (successeur
  éliminé à son tour), le bug se reproduisait à chaque succession.
- **Cause :** game-state.js ne trackait pas l'état de succession. handleNightStarted()
  redirigeait immédiatement vers /night en détruisant la page /day et tous ses
  listeners window — dont celui qui ferme la modale sur mayor-succession-done.
  Un flag booléen aurait cassé la cascade (N successions consécutives).
- **Fix :** compteur successionDepth dans le store central (incrémenté à chaque
  MayorSuccessionStarted, décrémenté à chaque MayorSuccessionDone). handleNightStarted()
  attend successionDepth === 0 avant de rediriger. Garde-fou 20s dans
  openSuccessionModal() côté day.blade.php.

---

### [x] 2026-06-13 — Timer GSAP désynchronisé en arrière-plan sur /day

- **Symptôme :** quand l'onglet passe en arrière-plan, le navigateur throttle `setInterval` mais GSAP poursuit son tween — le compteur affiche 0s alors que la barre de progression reste partiellement remplie.
- **Cause :** `gsap.to(el, { width: '0%', duration: PHASE_SECONDS, ease: 'none' })` tournait en parallèle et indépendamment du `setInterval` qui décrémente `dayTimerSeconds`.
- **Fix :** suppression du tween GSAP continu sur `width` ; la largeur de la barre est désormais recalculée à chaque tick du `setInterval` (`pct = dayTimerSeconds / totalSeconds`). GSAP conservé uniquement pour les transitions de couleur (or → orange ≤10s → rouge ≤5s).

---

### [x] 2026-06-13 — Pseudo de l'hôte visible par les autres joueurs en salle d'attente

- **Symptôme :** tous les joueurs voyaient le pseudo réel de l'hôte dans la liste de la salle d'attente, alors qu'il n'a pas de rôle de jeu particulier à cette étape.
- **Cause :** `waiting-room.blade.php` affichait `p.pseudo` sans distinction pour tous les joueurs, y compris l'hôte.
- **Fix :** affichage de "Hôte" à la place du pseudo pour les autres joueurs ; l'hôte continue de voir son propre pseudo, complété d'un badge "(Hôte)". `night_start_delay` et `mayor_reveal` passés de 4s/5s à 8s dans `config/game.php`.

---

### [x] 2026-06-13 — Bloc "Exclure un joueur" dupliqué dans waiting-room.blade.php

- **Symptôme :** le bouton "Exclure un joueur" était affiché deux fois dans la salle d'attente, avant et après la liste des joueurs.
- **Cause :** deux blocs identiques laissés en place lors d'itérations successives sur la vue.
- **Fix :** suppression du bloc dupliqué (avant la liste des joueurs) ; seul celui après la liste est conservé.

---

### [x] 2026-06-15 — Timeline de l'onglet "Déroulé" invisible dans history.blade.php

- **Symptôme :** l'onglet "Déroulé" s'affichait mais tous les `.timeline-item` restaient invisibles (opacity:0), de même que `#players-section`/`.player-row` si l'onglet "Joueurs" n'était pas actif au chargement.
- **Cause :** le `MutationObserver` censé déclencher l'animation GSAP sur `#timeline-section` ne détecte pas toujours les changements de `style` inline posés par Alpine (`x-show`), et `observer.disconnect()` empêchait toute ré-animation lors d'un retour sur l'onglet.
- **Fix :** suppression de l'`opacity:0` initiale sur `.timeline-item`, `.player-row` et `#players-section` (toujours visibles). Suppression du `MutationObserver` et des animations GSAP au chargement ; chaque bouton d'onglet ("Joueurs"/"Déroulé") déclenche désormais `gsap.fromTo(...)` au clic via `$nextTick`, avec garde `prefers-reduced-motion`.

---

### [x] 2026-06-15 — [RÉGRESSIF] Messages WebSocket reçus en double : transport ws+wss simultané (Pusher-js)

- **Symptôme :** tous les events WebSocket (chat, votes, etc.) arrivaient deux fois côté client, malgré les fixes précédents sur les doubles abonnements Echo.
- **Cause :** `enabledTransports: ['ws', 'wss']` dans `resources/js/echo.js` permettait à Pusher-js d'établir deux connexions simultanées (ws ET wss) alors que `forceTLS: true` et un seul port (443) sont configurés — chaque event WebSocket était donc livré une fois par connexion.
- **Fix :** `enabledTransports: ['wss']` — une seule connexion TLS.
- **RÉGRESSION (branche fix/pusher-double-transport) :** ce fix a cassé le temps réel — obligation de recharger la page pour voir l'état du jeu, messages chat ne s'affichant plus. La cause racine des doublons chat n'est PAS le double transport Pusher — `['wss']` seul casse le temps réel car Reverb/Nginx ne répond pas correctement en wss seul dans cette configuration. Revert vers `['ws', 'wss']` (branche revert/wss-only-transport). La vraie cause des doublons reste à identifier.

---

### [x] 2026-06-15 — Messages chat en doublon : double enregistrement window.addEventListener dans init()

- **Symptôme :** 1 message envoyé = 2 affichages pour tous les joueurs sur /day.
  Confirmé en prod avec vrais joueurs. Tous les window.addEventListener enregistrés
  dans dayScreen.init() (chat-message, player-eliminated, hunter-turn-started, etc.)
  étaient déclenchés deux fois.
- **Diagnostic exhaustif :**
  - ❌ Double abonnement Echo dans les vues Blade — écarté (corrigé précédemment)
  - ❌ Double transport Pusher ['ws','wss'] — écarté via `ss -tnp` (une seule connexion
    WebSocket active côté serveur). Test `['wss']` seul en prod : régression critique
    (temps réel cassé, obligation de recharger). `enabledTransports: ['ws', 'wss']`
    obligatoire dans cette config Nginx/Reverb et ne doit jamais être modifié.
  - ✅ Cause confirmée via DevTools prod :
    `getEventListeners(window)['chat-message']?.length === 2`
- **Cause :** Alpine.js appelle `init()` deux fois sur le composant `dayScreen()`.
  Chaque appel enregistre un nouveau `window.addEventListener('chat-message', ...)`
  sans jamais retirer le précédent. Résultat : 2 listeners actifs sur window,
  chaque CustomEvent capturé deux fois, chaque message affiché deux fois.
- **Fix :** guard `_initialized` en tête de `init()` dans `dayScreen()` et `nightScreen()`.
  Guard `_wsInitialized` en tête de `initWebSocket()` dans `game-state.js`.
  ```js
  if (this._initialized) return;
  this._initialized = true;
  ```

---

### [x] 2026-06-15 — window.MY_ROLE absent du layout global (canal loups non souscrit)

- **Symptôme :** certains joueurs loups ne recevaient pas les événements du canal
  game.{id}.werewolves (chat loups silencieux, votes de la meute non affichés,
  tour des loups sans interface), de façon non déterministe selon la vitesse
  de chargement du navigateur.
- **Cause :** game-state.js::init() lit window.MY_ROLE avant initWebSocket() pour
  que isWerewolf soit correct au moment de la souscription Echo. Or window.MY_ROLE
  n'était jamais défini dans layouts/game.blade.php — seules les vues night.blade.php
  et day.blade.php définissaient une const MY_ROLE locale, sans l'exposer sur window.
  Les modules Vite (type="module") étant defer implicite, ils s'exécutent après les
  scripts inline — mais window.MY_ROLE étant absent du layout, la variable restait
  undefined aux deux points de lecture dans init().
- **Fix :** ajout de window.MY_ROLE = '{{ $player->role ?? '' }}' dans le bloc
  @isset($player) du script global de layouts/game.blade.php. Centralisé dans le
  layout → toutes les vues (night, day, elect-mayor, spectator) en bénéficient.
  Le fallback ?? '' garantit qu'un rôle null retourne une chaîne vide, ce qui
  évalue isWerewolf à false (comportement identique à null).

---

### [x] 2026-06-15 — Bannière "Tu as été éliminé" persistante après sauvegarde sorcière

- **Symptôme :** un joueur sauvé par la sorcière voyait en permanence la bannière
  "Tu as été éliminé" sur /day, même en étant vivant. Le même bug pouvait se produire
  lors du rechargement de page si le flag sessionStorage subsistait d'un cycle précédent.
- **Cause :** `sessionStorage.getItem('dead_' + MY_PLAYER_ID)` persistait entre les
  phases et les rechargements. Aucun code ne supprimait ce flag quand un joueur vivant
  chargeait /day ou /night, ni quand `day.started` indiquait qu'il avait été sauvé.
- **Fix :** (1) Nettoyage du flag en tête d'`init()` si `MY_IS_ALIVE` est vrai.
  (2) Écoute de `i-was-saved` (nouveau CustomEvent dispatché par `game-state.js`
  dans `handleDayStarted` quand `saved_player_id === myId`) pour annuler la bannière
  en temps réel. (3) Fallback de 600ms dans `toast.blade.php::remove()` pour garantir
  la suppression même si l'animation GSAP échoue silencieusement.

---

### [x] 2026-06-15 — Toast "La sorcière t'a sauvé" invisible lors de la transition /night → /day

- **Symptôme :** le joueur sauvé par la sorcière ne voyait jamais le toast
  "🧙 La sorcière t'a sauvé cette nuit." malgré que l'event DayStarted soit
  bien reçu avec son `saved_player_id`.
- **Cause :** le toast était dispatché dans `handleDayStarted()` qui déclenche
  immédiatement une animation GSAP + redirection vers `/day`. La page `/night`
  était détruite avant que le composant toast Alpine ait pu rendre le message.
- **Fix :** persistance dans `sessionStorage` avant la redirection, consommation
  via `window.__toastBuffer` dans `dayScreen.init()` après le chargement de
  `/day`. Aucun `setTimeout` — le composant `toast.blade.php` vide le buffer
  dans son propre `init()`, ce qui garantit l'affichage quelle que soit la
  vitesse de la machine.

---

### [x] 2026-06-16 — NightStarted::broadcastWith() utilisait config() au lieu de $game->timer()

- **Symptôme :** le client animait la barre de progression de la nuit sur 30s (valeur par défaut config) même si le host configurait seer=45s — désynchronisation visible.
- **Cause :** `broadcastWith()` appelait `config('game.timers.seer', 30)` au lieu de `$this->game->timer('seer')`, contournant les settings configurés par le host.
- **Fix :** remplacement par `$this->game->timer('seer')` dans `NightStarted::broadcastWith()`.

---

# ROADMAP (idées / améliorations futures)
 
- [ ] Délai voyante : réduire de ~8s à ~5s via `$game->timer('seer')` configurable (couvert par Étape 3)
- [ ] Harmoniser les appels `config('game.timers.mayor_succession', 15)` restants avec `$game->timer()` (Étape 3)
- [ ] Rôles v1.3+ : Loup Blanc, Cupidon, Petite Fille
- [ ] State machine : étendre Symfony Workflow aux statuts intermédiaires (processing_night, wolves_turn) — post-Étape 4 si nécessaire
- [ ] Audit performance post-v1.2 : N+1 queries, temps réponse < 200ms (Laravel Telescope)
- [ ] Implémenter PhaseAnnouncement event + PhaseAnnouncementTest.php (SPEC_TRANSITIONS.md §3)
- [ ] `phase-header.blade.php` : `$roleLabel`/`$roleBg`/`$roleColor` ne couvrent pas encore witch/hunter et utilisent toujours 🏘 pour villageois (même pattern que `player-list.blade.php`)
- [ ] PhaseAnnouncement (SPEC_TRANSITIONS.md) — overlays de transition entre phases
      (nuit → jour, jour → nuit) non encore implémentés — prévu post-v1.2
- [ ] Révision timers par défaut config/game.php (day_vote, seer, werewolves)
      et valeurs minimales — prompt séparé après validation prod
- [ ] Double toast lors de MayorSuccessionDone : "👑 X est élu Maire" (via l'appel
      interne à handleMayorElected()) suivi de "👑 X est le nouveau Maire" —
      dédupliquer si jugé redondant côté UX (feat/narrative-toasts-client)

## Refactoring architectural planifié

### [ ] Refactor — Supprimer night_start_delay et les délais buffers artificiels

**Problème actuel :**

La séquence nocturne repose sur trois délais artificiels qui compensent
une limitation architecturale :

1. night_start_delay = 8s dans config/game.php : délai entre NightStarted
   broadcasté et le premier dispatch (ProcessSeerTurn). Ajouté pour laisser
   le temps aux clients de se rediriger vers /night et de s'abonner au
   canal Echo avant que SeerTurnStarted parte. Sans ce délai, l'event
   est émis dans le vide et la voyante ne voit jamais son tour.

2. Le +2s dans ProcessSeerTurn (delay = seer_timer + 2) : buffer de
   sécurité entre ProcessSeerAutoAction et ProcessWerewolvesTurn pour
   éviter une race condition.

3. mayor_succession + 5s dans ProcessNightActions pour ProcessNightEnd :
   buffer pour couvrir le tour sorcière. Amélioré en witch_timer +
   mayor_succession + 5s par le fix de ce jour, mais reste fragile car
   calculé côté serveur sans confirmation que le client a bien reçu et
   traité WitchTurnStarted.

**Cause racine commune :**

Le backend dispatche des events dans le vide et espère que les délais
en dur suffisent. Il n'existe aucun mécanisme permettant au backend de
savoir si les clients ont bien reçu et traité un event avant de passer
à l'étape suivante.

**Solution cible : pattern "ready acknowledgment"**

Principe : le client signale sa présence au backend après chargement de
la page et abonnement à Echo. Le backend ne commence les tours que quand
tous les joueurs vivants ont signalé leur présence (ou après un timeout
de sécurité).

Implémentation envisagée :

- Ajouter POST /game/{id}/night-ready : appelé par le client dans init()
  de nightScreen() après initWebSocket(), signale que le joueur est
  abonné et prêt.
- Backend stocke les confirmations dans Redis/Cache avec une clé
  "night_ready_{game_id}_{round}_{player_id}".
- ProcessSeerTurn attend que tous les joueurs vivants aient confirmé,
  ou démarre après un timeout (ex: 6s) si certains joueurs ne confirment
  pas (déconnectés, lents).
- Supprimer night_start_delay de config/game.php et de
  PhaseManager::startNight().
- Supprimer le +2s arbitraire dans ProcessSeerTurn.
- Le délai de ProcessNightEnd peut redevenir majority_succession + 5s
  car la sorcière, ayant elle aussi confirmé sa présence, a reçu
  WitchTurnStarted à coup sûr. On peut conserver witch_timer +
  majority_succession + 5s comme garde-fou.

**Ce que ça règle :**
- Suppression complète des race conditions dues à la vitesse de connexion.
- Fin de partie correcte même sur connexion lente (mobile 3G, etc.).
- Plus aucun délai arbitraire à ajuster quand les timers de jeu changent.
- Robustesse face aux futurs rôles actifs (chaque nouveau tour ajoute
  aujourd'hui un +Xs implicite à calibrer manuellement).

**Risques et prérequis :**
- Nécessite que tous les clients appellent /night-ready de façon fiable,
  y compris après un refresh ou une reconnexion en cours de nuit.
- Le timeout de sécurité doit couvrir les cas de reconnexion (actuellement
  30s selon config reconnection). Valeur suggérée : max(night_start_delay,
  reconnection_timeout / 2).
- Tests d'intégration à écrire : test_night_starts_after_all_players_ready,
  test_night_starts_after_timeout_if_player_disconnected.
- Priorité : après stabilisation de v1.2. Ne pas faire avant d'avoir
  tous les rôles stables.