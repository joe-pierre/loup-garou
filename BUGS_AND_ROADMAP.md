# BUGS CORRIGÉS

### [x] 2026-06-11 — Double-fire ProcessDayVote bloque la phase jour

- **Symptôme :** la partie se bloque en phase jour — la résolution du vote est déclenchée deux fois
- **Cause :** ProcessDayVote dispatché plusieurs fois sans guard atomique ; VoteService::resolveDayVote() lockForUpdate() sur status='day' mais ne changeait jamais ce statut, donc un second appel concurrent retrouvait status='day' et retraitait les votes
- **Fix :** `resolveDayVote()` passe le statut à `processing_day` (lockForUpdate) dès l'entrée en transaction — un second appel ne trouve plus `status='day'` et est ignoré. `PhaseManager::startNight()` et `ProcessMayorSuccession` mis à jour pour accepter `processing_day` en plus de `day` (sinon la transition nuit / la succession du maire ne se déclenchait plus après ce changement)

---

### [ ] 2026-06-11 — Succession du maire non déclenchée si mort la nuit

- **Symptôme :** quand le maire meurt la nuit, aucun nouveau maire n'est élu
- **Cause :** ProcessMayorSuccession appelait startDay() au lieu de ne rien faire en contexte nuit (sens inversé documenté dans DECISIONS.md)
- **Fix :** correction de l'inversion startDay/startNight dans ProcessMayorSuccession ; en contexte nuit, le job se termine après l'élection sans déclencher de transition de phase

---

### [ ] 2026-06-11 — Nuit sans loups si la voyante est morte

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

# ROADMAP (idées / améliorations futures)

- [ ] Délai voyante : réduire de ~8s à ~5s — broadcaster `SeerTurnStarted` avec `delay(5s)` côté serveur, supprimer le `setTimeout` client (v1.2)
- [ ] Timers configurables par partie depuis la waiting-room (v1.2)
- [ ] Rôles v1.2 : Sorcière, Chasseur
- [ ] Rôles v1.3+ : Loup Blanc, Cupidon, Petite Fille
- [ ] `ProcessMayorSuccession` : flag `shouldStartNight` pour distinguer mort nuit vs mort jour