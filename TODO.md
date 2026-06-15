# TODO — PROGRESSION

## Légende

| Marqueur | Signification |
|----------|---------------|
| `- [ ]`  | À faire       |
| `- [x]`  | Terminé       |
| `- [~]`  | En cours      |
| `- [!]`  | Bug connu     |

---

## Phase 1 — Base

- [x] Migrations (users, games, game_players, game_actions, chat_messages, exclusions)
- [x] Modèles + relations + scopes
- [x] Factories + Seeders
- [x] `config/game.php` (timers, rôles)

---

## Phase 2 — Auth

- [x] Google OAuth (GoogleController)
- [x] Routes auth
- [x] Middleware auth

---

## Phase 3 — Lobby (+ Écran 5)

- [x] `POST /game` (création)
- [x] `POST /game/{code}/join`
- [x] `POST /game/{id}/exclude/{playerId}`
- [x] `POST /game/{id}/ready` — vérifié : route + `ActionController::ready()` présents
- [x] Event `PlayerJoined`
- [x] Event `PlayerExcluded`
- [x] `RoleDistributor` (avec config extensible)
- [x] Démarrage automatique quand `max_players` atteint
- [x] `GET /game/{code}/lobby` (salle d'attente)
- [x] Vue `waiting-room.blade.php` (Écran 4)
- [x] `GET /game/{code}/role-reveal` + `POST /game/{id}/ready`
- [x] Event `PlayerReady`
- [x] Vue `game/role-reveal.blade.php` (Écran 5)

### Recette manuelle Couche 2 (lobby)

- [x] Créer une partie → redirige vers `/game/{code}/lobby`
- [x] Rejoindre avec un code valide → redirige vers `/game/{code}/lobby`
- [x] Rejoindre avec un code invalide → message d'erreur affiché
- [x] Pseudo vide → message de validation affiché
- [x] OTP : navigation automatique entre les cases
- [x] OTP : pré-remplissage via `?code=XXXXXX` dans l'URL

---

## Phase 4 — WebSocket Setup

- [x] Reverb config (`config/broadcasting.php` + `config/reverb.php`)
- [x] Echo config (`resources/js/echo.js`) — CSRF token dans `auth.headers`
- [x] Channel definitions (`routes/channels.php`)
- [x] Broadcasting Auth (loups, joueur individuel)
- [x] CORS Reverb (`allowed_origins: ['*']` pour dev)

---

## Phase 5 — Élection Maire

- [x] `POST /game/{id}/vote/mayor` — vérifié : route + `VoteController::mayor()` présents
- [x] Job `ProcessMayorElection` (timer 30s) — vérifié : guard + resolve + dispatch `ProcessSeerTurn`
- [x] `VoteService::castMayorVote()` (= `processMayorVote`)
- [x] `VoteService::resolveMayorElection()`
- [x] Event `MayorElectionStarted`
- [x] Event `MayorVoteCast`
- [x] Event `MayorElected`

---

## Phase 6 — Nuit

- [x] `POST /game/{id}/seer/check`
- [x] `POST /game/{id}/vote/night`
- [x] Job `ProcessNightActions` (timer 30s par action)
- [x] Event `NightStarted`
- [x] Event `SeerTurnStarted` + `SeerResult`
- [x] Event `WerewolvesTurnStarted` + `WerewolvesVoteCast`
- [x] Event `WerewolfChatMessage`
- [x] Chat loups (channel `werewolves`)

---

## Phase 7 — Jour

- [x] `POST /game/{id}/vote/day`
- [x] `POST /game/{id}/mayor/succession`
- [x] Job `ProcessDayVote` (timer 90s)
- [x] Event `DayStarted`
- [x] Event `DayVoteCast`
- [x] Event `PlayerEliminated` / `NoElimination`
- [x] Event `MayorSuccessionStarted` + `MayorSuccessionDone`
- [x] `WinConditionChecker`

---

## Phase 8 — Déconnexion

- [x] Détection déconnexion (Reverb presence channel)
- [x] Job `CheckReconnectionTimeout` (30s)
- [x] `is_inactive` logic
- [x] Annulation si > 50% inactifs
- [x] `GET /game/{code}/state` (reconnexion)

---

## Phase 9 — Fin de partie

- [x] Event `GameFinished`
- [x] Push notifications (fin de partie, mort, exclusion)
- [x] Historique de partie (Écran 13)
- [x] Écran Fin de partie (Écran 11) + Annulation
- [x] Logique Écran Spectateur mort (Écran 12) — vue Blade dans Phase 10
- [x] Scheduler `CleanOldGames`

---

## Phase 10 — UI

- [x] Landing Page (Écran 1)
- [x] Layout principal + composants Blade réutilisables
- [x] Alpine.js store central `gameState`
- [x] GSAP animations (intégrées dans composants + landing)
- [x] Écrans Blade restants (tâche 29 — night + day)
- [x] Intégration templates HTML → vues Blade `@extends` (waiting-room, role-reveal, mayor-election, night, day, finished)
- [x] `window.gameId`/`playerId` dans layout
- [x] Vérifier et aligner la police de corps (EB Garamond conservée — toutes les références à Crimson Text supprimées dans `app.css`, `tailwind.config.js` et les vues Blade)
- [x] Responsive
- [x] `cancelled.blade.php` — réécrite en `@extends('layouts.game')`, affiche message d'annulation + CTA retour accueil
- [x] `spectator.blade.php` — réécrite en `@extends('layouts.game')` + store `gameState` central (renommée depuis `dead-spectator.blade.php`), lecture seule, voit le chat village, chat loups si ex-loup

---

## Phase 11 — Tests

- [x] Tests Feature Auth (`GoogleAuthTest` — 4 tests)
- [x] Tests Feature Game/Lobby (`CreateGameTest`, `JoinGameTest`, `ExcludePlayerTest` — 20 tests)
- [x] Vérifier et écrire tests phases 5→9 (nuit, jour, chat, race conditions)

---

## Phase 12 — Audit final (Tâche D)

- [x] Sécurité : channels, anti-spoofing, exposition rôles, middleware auth
- [x] Accessibilité : `prefers-reduced-motion` GSAP, `aria-labels`, `focus:ring`, `role="log"`
- [x] Fonctionnel : points documentés dans rapport (recette manuelle)
- [x] Production : config queue, `RoleDistributor` extensibilité

---

## Phase 13 — Bugfixes post-audit (2026-06-10)

- [x] Bouton "Tuer" inactif — `castNightVote` accepte `wolves_turn`
- [x] Tour voyante/loups jamais affiché — `night_start_delay` 4s dans `PhaseManager`
- [x] `confirmQuit not defined` — `gameState` déplacé sur div fantôme hors `<main>`
- [x] Modale succession fantôme + events doublés — suppression double abonnement Echo, `window.dispatchEvent` partout
- [x] 404 sur `/night` — `GameController` accepte `wolves_turn`/`processing_night`
- [x] `state()` `isNight` corrigé pour `wolves_turn`/`processing_night`
- [x] `DayStarted` payload manquait `player_id`
- [x] Accumulation `CheckReconnectionTimeout` — guard `Cache::has()`
- [x] Barre timer jour pleine à 0s — `initialPct` calculé depuis `PHASE_SECONDS`
- [x] Résolution anticipée nuit/jour quand tous ont voté (`VoteService`)

---

## Phase 14 — Bugfixes critiques production (2026-06-11)

- [x] Tâche E — Migration : supprimer migration fantôme 004809, ajouter `processing_day` à l'ENUM
- [x] Tâche F — `ProcessDayVote` : double-fire corrigé via atomicité dans `VoteService`
- [x] Tâche G — `ProcessMayorSuccession` : succession nocturne sans transition de phase prématurée
- [x] Tâche H — `ProcessNightEnd` : fin de nuit systématique même sans voyante
- [x] Tâche I — Tests : non-régression E→H (double-fire, succession nuit, migration enum)

---

## Phase 15 — Bugfix succession maire en cascade (2026-06-12)

- [x] Tâche J — `ProcessMayorSuccession` : flag `shouldStartNight` — correctif bug modale bloquée si successeur tué nuit suivante
- [x] Tâche K — Tests : non-régression succession en cascade (successeur tué nuit suivante)
- [x] Fix is_mayor non retiré à l'ancien maire lors d'une succession (2026-06-14)
- [x] Fix guard $alreadyDone bloquait succession de jour après succession de nuit (2026-06-14)

---

## Phase 16 — Bugfix modale succession maire côté client (2026-06-13)

- [x] `game-state.js` : compteur `successionDepth` + `handleNightStarted` attend la fin des successions avant redirection `/night`
- [x] `day.blade.php` : garde-fou 20s sur la modale "Succession du Maire"

---

## Phase 17 — Correctifs UX pré-Étape 4 (à exécuter avant les rôles v1.2)

> Ces cinq correctifs doivent être mergés sur `dev` **avant** l'Étape 4 (Rôles v1.2).
> Chaque prompt est indépendant et produit sa propre branche + commit.
> Respecter l'ordre A → E : B et E touchent tous deux `waiting-room.blade.php`.

- [x] **Prompt A** — Timer GSAP désynchronisé en arrière-plan (`fix/timer-gsap-sync`)
  - `day.blade.php` : `_startDayTimer()` synchronisé avec `setInterval`, GSAP limité aux changements de couleur
- [x] **Prompt B** — Anonymat du pseudo host pour les autres joueurs (`fix/host-anonymity`)
  - `config/game.php` : `night_start_delay` → 8s, `mayor_reveal` → 8s
  - `waiting-room.blade.php` : pseudo host masqué pour les non-host, badge "(Hôte)" pour le host lui-même
- [x] **Prompt C** — Chat en `processing_day` + rôles révélés des joueurs morts (`fix/day-improvements`)
  - `ChatService.php` : accepter `processing_day` pour le canal `general`
  - `day.blade.php` : affichage du rôle révélé sous le pseudo des joueurs morts + tri vivants en premier
- [x] **Prompt D** — Votes loups : afficher qui vote pour qui (`fix/wolves-vote-visibility`)
  - `VoteService::getNightVoteState()` : enrichir le payload avec `target_pseudo`
  - `night.blade.php` : affichage "Loup → Cible" dans la section "Votes de la meute"
- [x] **Prompt E** — Config host (timers + rôles) déplacée en modale (`feat/settings-modal`)
  - `waiting-room.blade.php` : suppression des panneaux inline `#wr-timers` et `#wr-roles`, remplacement par bouton ⚙️ + modale à deux onglets
  - Suppression du bloc "Exclure un joueur" dupliqué (lignes ~134 et ~185)

---

## Phase 18 — UX & Polish (post-v1.2)

- [x] **Prompt 1** — Fix doublons chat (`fix/chat-double-messages`)
- [x] **Prompt 2** — Rôles manquants partout (`fix/roles-completeness`)
- [x] **Prompt 3** — Élection maire : carte avec rôle (`feat/mayor-election-role-card`)
- [x] **Prompt 4** — Modale succession : flou + délai (`fix/succession-modal-ux`)
- [x] **Prompt 5** — Chat UX : textarea + bouton (`feat/chat-ux-improvements`)
- [x] **Prompt 6** — Canal des fantômes phase jour (`feat/dead-chat-day`)
- [x] **Fix chasseur jour** — modale chasseur dans day.blade.php déclenchée
      par hunter-turn-started (fix/hunter-day-panel-and-ux)
- [x] **Fix toast éliminations** — toast visible par tous à chaque mort dans
      game-state.js::handlePlayerEliminated
- [x] **Fix timers UX** — résultat maire 6s, fermeture modale succession 5s
- [x] **Fix sorcière égalité loups** — tour maintenu si poison disponible,
      WitchTurnStarted avec victim:null, panel client adapté, tests mis à jour,
      RISK_GUARDS.md Guard #3 révisé (fix/witch-turn-no-victim)
- [x] **Fix chat loups** — fallback myRole avant initWebSocket() pour garantir
      la souscription au canal loups (fix/wolf-chat-and-role-init)
- [x] **Fix doublons chat cause racine** — vérifié : aucun window.Echo.channel()
      dans day.blade.php, day-vote-cast et chat-message dispatchés/écoutés via
      window.addEventListener (fix/chat-double-messages-root-cause-final)
- [x] **Fix toasts timing** — buffer pour les toasts dispatchés avant init Alpine
      du composant toast (fix/toast-timing)
- [x] **Fix timeline historique invisible** — opacity:0 retirée de .timeline-item,
      .player-row et #players-section ; animations GSAP déclenchées au clic sur
      les onglets Joueurs/Déroulé au lieu d'un MutationObserver (fix/history-timeline-animation)
- [x] **Fix doublons chat — cause racine définitive** — guard `_initialized` dans
      `dayScreen.init()`/`nightScreen.init()`, guard `_wsInitialized` dans
      `game-state.js::initWebSocket()` (fix/chat-double-listeners)
- [x] **Fix window.MY_ROLE layout** — window.MY_ROLE exposé dans layouts/game.blade.php
      pour garantir la souscription au canal loups dès init() de game-state.js
      (fix/window-my-role-layout)

---

## Phase 19 — Toasts narratifs client (post-v1.2)

- [x] Toast "👑 {pseudo} est élu Maire" dans `handleMayorElected()`
- [x] Toast "👑 {pseudo} est le nouveau Maire" dans `handleMayorSuccessionDone()`
- [x] Toast "☠️ La sorcière t'a empoisonné cette nuit." si `reason === 'witch_kill'`
      dans `handlePlayerEliminated()` pour le joueur concerné
- [x] Message nocturne générique ("Des forces mystérieuses agissent dans l'ombre...")
      via `$watch('nightPhase')` dans `nightScreen()` (night.blade.php), affiché
      uniquement aux joueurs non concernés par le tour actif (seer/werewolves/witch/hunter)
- [x] Event `WitchActedPublic` broadcasté sur canal public si la sorcière a agi
      (action !== 'pass'), toast "🧙 La sorcière a agi cette nuit." pour tous
- [x] `DayStarted` enrichi (`witch_acted`, `saved_player_id`) — toast générique
      "La sorcière a agi" + toast personnel "La sorcière t'a sauvé cette nuit."
      pour le joueur sauvé

---

## Phase v1.2 — Améliorations différées

- [x] **Étape 1** — Réorganisation documentaire (`SPEC_TIMERS.md`, `SPEC_TRANSITIONS.md`)
- [x] **Étape 2** — State machine Symfony Workflow — refactoriser les transitions de phases
- [x] **Étape 3** — Timers configurables par partie — stockage dans `games.settings['timers']`, fallback `config/game.php`, UI dans waiting-room
- [x] **Étape 4** — Rôles v1.2 — Sorcière, Chasseur (avec actions volontaires et timers fallback)
- [x] **Étape 5** — Tests intégration v1.2 — couvrir les nouveaux endpoints, jobs auto, file d'annonces, reconnexion
  - Note : `PhaseAnnouncementTest.php` non créé — event `PhaseAnnouncement` non implémenté (prévu SPEC_TRANSITIONS.md)
- [ ] **Rôles v1.3+** (hors périmètre v1.2) — Loup Blanc, Cupidon, Petite Fille
- [ ] **Timers par défaut** — révision des valeurs par défaut et minimales
      dans config/game.php (day_vote, seer, werewolves) — à faire en prompt séparé
      après validation en prod du fix/hunter-day-panel-and-ux

---

## BUGS CONNUS

→ Voir `BUGS_AND_ROADMAP.md` (source de vérité unique pour les bugs)

---

## État global

- v1.1 ✅ Terminé et taggué `v1.1.1`
- v1.2 ✅ Terminé — Étapes 2→5 + Phases 17→18 complètes
- v1.3+ En attente — voir ROADMAP dans BUGS_AND_ROADMAP.md
