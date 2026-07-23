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
- [x] **PhaseAnnouncement** — event + overlays de transition de phase + `PhaseAnnouncementTest.php` (SPEC_TRANSITIONS.md §3, §5, §10)
- [ ] **Rôles v1.3+** (hors périmètre v1.2) — Loup Blanc, Cupidon, Petite Fille
- [ ] **Timers par défaut** — révision des valeurs par défaut et minimales
      dans config/game.php (day_vote, seer, werewolves) — à faire en prompt séparé
      après validation en prod du fix/hunter-day-panel-and-ux

---

## Phase 29 — Bugfix toast @show-toast.window Alpine v3 (2026-06-23)

- [x] **Fix** — `toast.blade.php` : suppression `@show-toast.window`, remplacement par `window.addEventListener('show-toast', ...)` dans `init()` (`fix/bug-toast-show-toast-window-listener`)

---

## BUGS CONNUS

→ Voir `BUGS_AND_ROADMAP.md` (source de vérité unique pour les bugs)

---

## Phase 20 — Audit & Documentation post-v1.2

- [x] **ROADMAP Étape 3** — PHPDoc sur tous les Events WebSocket (app/Events/Game/)
  - Canaux, déclencheurs, données sensibles, payload `broadcastWith()`
  - Events prioritaires sécurité documentés : SeerResult, WerewolvesTurnStarted,
    WitchTurnStarted, HunterTurnStarted, GameStarted (double canal), DayVoteCast,
    MayorVoteCast (anonymisation)
  - Tous les autres events documentés (30 fichiers total)
- [x] **ROADMAP Étape 4** — Extraction de constantes (app/Enums/)
  - GameStatus, PlayerRole, ActionType, ChatChannel, WinnerTeam créés
  - Intégration dans le code applicatif différée (Étape 5+)
- [x] **ROADMAP Étape 5** — Extraction de helpers et méthodes utilitaires dans les Models
  - Game : isNightPhase(), isDayPhase(), isFinished(), isCancelled(), aliveCount(), aliveWerewolvesCount(), aliveVillagersCount()
  - GamePlayer : isActiveAndAlive(), witchHealUsed(), witchKillUsed(), witchHasPotion()
  - WinConditionChecker : remplacé par aliveWerewolvesCount() / aliveVillagersCount()
  - CheckReconnectionTimeout : remplacé par aliveCount()
- [x] **ROADMAP Étape 6** — Nettoyage et normalisation des Controllers
  - 6.1 : `HistoryService` déjà extrait de `GameController` (branche `refactor/history-service`)
  - 6.2 : `ActionController` conforme — `$fromNight` acceptable (paramètre de dispatch uniquement)
  - 6.3 : Commentaire ajouté dans `UpdateTimersRequest` (délégation min/max vers `GameService`)
  - 6.4 : `GameController::history()` — type de retour corrigé `mixed` → `View|JsonResponse`
- [x] **ROADMAP Étape 7** — Extraction des constantes inline dans les vues Blade et JS
  - 7.1 : `config/game_ui.php` créé (`avatar_colors`, `role_labels`, `role_labels_emoji`)
  - 7.2 : `PlayerEliminatedDayNotification` + `RoleAssignedNotification` — `const ROLE_LABELS` supprimés, remplacés par `config('game_ui.role_labels_emoji')`
  - 7.3 : Blade Component `<x-role-label>` créé (`resources/views/components/role-label.blade.php`)
  - 7.4 : Commentaire TODO ajouté sur `playerAvatarColor` dans `day.blade.php`, `waiting-room.blade.php`, `mayor-election.blade.php`
- [x] **ROADMAP Étape 8** — Tests manquants pour les nouvelles méthodes (Models + HistoryService)
  - 8.1 : `tests/Unit/Models/GameModelTest.php` — isNightPhase, isDayPhase, isCancelled, aliveWerewolvesCount, aliveVillagersCount, timer fallback (7 tests)
  - 8.2 : `tests/Unit/Models/GamePlayerModelTest.php` — witchHealUsed, witchKillUsed, witchHasPotion, isActiveAndAlive (4 tests)
  - 8.3 : `tests/Feature/Game/GameHistoryServiceTest.php` — buildTimeline partie sans rounds (1 test)

---

---

## Phase 21 — Documentation routes et README technique (Étape 9)

- [x] **Étape 9.1** — `routes/web.php` : commentaires de sections + annotations Guard/Policy inline
- [x] **Étape 9.2** — `routes/channels.php` : commentaires d'autorisation par canal
- [x] **Étape 9.3** — `README.md` : v1.2 confirmé ✅, Architecture + Enums + Guards + Tests mis à jour

---

## Phase 22 — Audit final de conformité (Étape 10)

- [x] **Étape 10.1** — Audit des règles CLAUDE.md (logique métier, Gates/Policies, timers, Alpine guard, broadcastAs)
- [x] **Étape 10.2** — Grep config('game.timers.*') hors TimerCalculator — une seule occurrence autorisée (`limits`)
- [x] **Étape 10.3** — Tests : 154/154 verts ✅
- [x] **Étape 10.4** — DECISIONS.md : entrée récapitulative Roadmap Code Propre v1.2
- [x] **Étape 10.5** — Rapport de bilan produit (voir réponse finale)
- [x] **Fix** — VoteController : trois méthodes privées `_checkAll*` (logique métier) déplacées dans VoteService

---

## Phase 23 — Bugfix race condition vote loups simultanés (2026-06-18)

- [x] **Fix** — `VoteService::castNightVote()` : `->delay(now()->addSecond())` sur le dispatch anticipé de `ProcessNightActions` pour absorber les votes quasi-simultanés (`fix/wolf-vote-simultaneous`)

---

---

## Phase 24 — Bugfix double abonnement Echo vues élection (2026-06-20)

- [x] **Fix** — Suppression double abonnement `window.Echo.channel()` dans `mayor-election.blade.php` et `role-reveal.blade.php`. Trois CustomEvents ajoutés dans `game-state.js` (`mayor-vote-cast`, `mayor-elected`, `mayor-election-started`). Listener `.player.ready` + handler `_handlePlayerReady` ajoutés au canal public. Guard `_initialized` dans les deux `init()`. Redirection `.night.started` supprimée de la vue élection. (`fix/double-echo-subscription-election-views`)

---

---

## Phase 25 — Réajustements fonctionnels sorcière + notifications (2026-06-22)

- [x] **Mod 1** — Sorcière peut se sauver elle-même (`feat/witch-self-heal-and-notifications`)
  - `ProcessNightActions` : victime en sursis si sorcière avec soin disponible
  - `ProcessWitchTurn` : `healAvailable` = vrai même si victim = witch
  - `WitchAction` : guard auto-soin supprimé ; mort reportée dans `pass`/`kill`
  - `night.blade.php` : message d'avertissement "☠️ Les loups t'ont ciblée…"
  - `game-state.js` : guard dans `handlePlayerEliminated` pour witch_turn
  - Test `test_sorciere_peut_sauver_si_elle_est_la_victime` mis à jour
- [x] **Mod 2** — Toast empoisonnement public avec nom de la cible
  - `WitchActedPublic` : payload `target_pseudo` (null pour heal, pseudo pour kill)
  - `ActionController::witchAct()` : passe `$targetPseudo` à `WitchActedPublic`
  - `game-state.js` : listener `witch.acted.public` enrichi
- [x] **Mod 3** — Toasts élimination avec nom Google
  - `PlayerEliminated::broadcastWith()` : champ `google_name` ajouté
  - `HunterShot::broadcastWith()` : champ `target_google_name` ajouté
  - `ActionController::witchAct()` : `load('user')` avant broadcast kill
  - `ActionController::hunterShoot()` : `load('user')` avant broadcasts
  - `ProcessNightActions` : `load('user')` avant broadcast (nuit normale)
  - `game-state.js` : toasts "Jean aka Pseudo était le Rôle" et "Le Chasseur a tué…"

---

## Phase 26 — Bugfix flux nocturne : sursis maire + historique aléatoire (2026-06-22)

- [x] **Fix 1** — Sursis maire identique au sursis sorcière
  - `ProcessNightActions` : `$witch` résolu avant le bloc victime ; flag `$victimIsMayorWithWitchAvailable` ; mort et `MayorSuccessionStarted` différés si sorcière avec soin disponible
  - `WitchAction` : guard maire dans `kill` et `pass` (mort + succession depuis witchAct) ; cas spécial witch = maire dans `$witchDiedFromWolves`
- [x] **Fix 2** — Historique pour l'élimination aléatoire
  - `RandomElimination::broadcastWith()` : champs `role` et `google_name` ajoutés
  - `game-state.js` : listener `.random.elimination` → `handlePlayerEliminated` + toast 🎲

---

## Phase 27 — Corrections post-Phase 26 (2026-06-23)

- [x] **P1** — Succession maire non déclenchée si empoisonné par la sorcière (`fix/mayor-succession-when-killed-by-witch-poison`)
  - `WitchAction::kill()` : vérification `$target->is_mayor` → `$mayorVictim` pour déclencher broadcast `MayorSuccessionStarted` + dispatch `ProcessMayorSuccession` hors transaction
- [x] **P2** — Persistance `random_elimination` en DB + historique (`fix/add-random-elimination-history`)
  - `VoteService::resolveDayVote()` : création `GameAction random_elimination` dans la branche 0-votes
  - `GameController::history()` : `random_elimination` ajouté au `whereIn`
  - `HistoryService::buildTimeline()` : lecture de l'action pour peupler `eliminated` (résultat `no_votes`)
- [x] **P3** — Couronne affichée sur le nouveau maire en phase jour (`fix: afficher une couronne sur le nouveau maire`)
- [x] **P4** — ENUM MySQL manquant pour `random_elimination` dans `game_actions.type` (`fix/random-elimination-enum`)
  - Migration `2026_06_23_000000_add_random_elimination_to_game_actions_type_enum.php` ajoutée et appliquée
  - `day.blade.php` : listeners `mayor-elected` et `mayor-succession-done` remappent `is_mayor` dans `this.players`

---

---

## Phase 28 — Bugfixes identifiés (2026-06-23)

- [x] **Bug 1** — Progress bar manquante dans role-reveal et mayor-election
  - Barre violet `#reveal-timer-bar` pour `mayor_reveal` dans role-reveal, barre or `#election-timer-fill` corrigée dans mayor-election. Conflit Alpine `:style` / GSAP résolu (pattern night.blade.php).
- [x] **Bug 2** — Voyante voit "Innocent" pour Chasseur et Sorcière
  - `night.blade.php` : afficher le rôle précis via `roleLabel(seerResult?.role)` au lieu de "Innocent"
- [x] **Bug 3** — Messages sorcière non différenciés au matin
  - `DayStarted` : ajouter `witch_player_id` et `poisoned_player_pseudo` dans `broadcastWith()`
  - `PhaseManager::endNight()` : passer les deux nouvelles valeurs
  - `DayStarted` : ajouter aussi `poisoned_player_id` pour cibler le toast personnel du joueur empoisonné
  - `game-state.js` : logique différenciée dans `_applyDayStarted()` (sorcière / sauvé / empoisonné / autres)
- [x] **Bug 4** — Chasseur Maire : succession avant le tir
  - `VoteService::resolveDayVote()` : priorité `hunter_pending` sur `is_mayor`
  - `ProcessHunterTurn` + `ProcessHunterAutoAction` : param `bool $isMayor`, déclenche succession après tir
  - `ActionController::hunterShoot()` : lire `is_mayor` avant la mort, déclencher succession si vrai
  - Vérifier même bug dans `ProcessNightEnd` (chasseur tué la nuit)
- [x] **Bug 5** — Votes maire non affichés en temps réel
  - `MayorVoteCast` : ajouter `voter_pseudo` + `target_pseudo` dans `broadcastWith()`
  - `VoteController::mayor()` : récupérer `$targetPseudo` depuis les totaux retournés, passer les deux pseudos à `MayorVoteCast`
  - `game-state.js` : toast "👑 X a voté pour Y" dans `_handleMayorVoteCast()`
- [x] **Bug 6** — Historique élection : détail votes manquant
  - `GameController::history()` : `mayor_vote` sans `anonymized()`
  - `HistoryService::buildTimeline()` : ajouter `vote_details` dans l'entrée `election`
  - `history.blade.php` : afficher `vote_details`

---

## Phase 30 — Maintenance tests (2026-07-14)

- [x] Warning dépréciation `@dataProvider` doc-comment dans `PhaseGuardTest` — migration vers `#[DataProvider(...)]`

---

## Phase 31 — Extraction GameSettingsService (2026-07-22)

- [x] `app/Services/GameSettingsService.php` créé — `validateTimerSettings()`, `updateTimerSettings()`,
      `validateRoleSettings()`, `updateRoleSettings()` extraites de `GameService` (pattern délégation
      identique à `RoleActions/*`). Aucun appelant externe modifié. 202 tests verts.

---

## Phase 32 — Découpage VoteService::resolveDayVote() (2026-07-22)

- [x] `resolveDayVote()` découpée en trois méthodes privées : `resolveDayVoteWinner()`
      (transaction), `notifyDayVoteResult()` (broadcasts + notification push),
      `dispatchDayVoteConsequences()` (victoire, chasseur, succession maire, nuit suivante).
      Ordre d'exécution et comportement identiques (asymétrie randomVictim/eliminated
      préservée). Aucun guard touché. 202 tests verts.

---

## Phase 33 — Déduplication playerAvatarColor() dans mayor-election.blade.php (2026-07-22)

- [x] `mayor-election.blade.php` : bloc `@php` local (`$avatarColors`/`$avatarColor`) et commentaire
      TODO supprimés. Avatar des candidats calculé via `:style` Alpine appelant le
      `window.playerAvatarColor` global (`resources/js/player-avatar.js`), même source que
      `day.blade.php` et `waiting-room.blade.php`. Avatar du joueur courant (couleur de rôle)
      inchangé. Rendu identique vérifié (équivalence PHP/JS palette) + 202 tests verts.

---

## Phase 34 — Classe abstraite RoleAction, préparation Cupidon (2026-07-22)

- [x] `app/Services/RoleActions/RoleAction.php` créée — méthode protégée `guardNotAlreadyActed()`
      factorise le guard anti-double-action dupliqué dans `SeerAction`, `WitchAction`, `HunterAction`.
      Pas d'interface (signatures `check()`/`act()`/`shoot()` incompatibles). Aucune signature publique
      changée. 202 tests verts.

---

## Phase 35 — PlayerEliminationService, prérequis Cupidon (2026-07-22)

- [x] `app/Services/PlayerEliminationService.php` créé — `eliminate(GamePlayer $player): void`
      centralise `is_alive = false`, seul call site que Cupidon (v1.3) enrichira plus tard
      avec la cascade de mort des amoureux (SPEC_CUPIDON.md §5). 11 call sites migrés
      (`ProcessNightActions`, `VoteService` ×2, `GameService::quitGame`, `HunterAction`,
      `WitchAction` ×6). Aucune signature publique changée. 205 tests verts.

## Phase 36 — Bugfix startGame bloqué par échec broadcast PlayerJoined (2026-07-22)

- [x] `GameService::joinGame()` : broadcast `PlayerJoined` encapsulé dans un try/catch
      (`Log::warning()`) — un échec réseau/Reverb ne bloque plus la transition vers
      `startGame()`. `resync()` (`waiting-room.blade.php`) : suppression de la
      redirection sur `slots_remaining === 0`, ne redirige plus que sur
      `status !== 'waiting'`. Test `test_partie_demarre_meme_si_broadcast_playerjoined_echoue`
      ajouté. 206 tests verts.

## Phase 37 — Cupidon Étape 1 : migration schéma + extension enums (2026-07-22)

- [x] `PlayerRole` (+ `cupidon`), `ActionType` (+ `cupidon_link`), `WinnerTeam` (+ `lovers`)
      étendus dans `app/Enums/` — même pattern d'ajout que les cases existantes.
- [x] Migration `add_cupidon_to_game_players_role_enum` — ENUM `game_players.role`
      étendu à `cupidon` (même procédé que witch/hunter en v1.2 : `ALTER TABLE ... MODIFY COLUMN ... ENUM(...)`).
- [x] Migration `add_cupidon_link_to_game_actions_type_enum` — ENUM `game_actions.type`
      étendu à `cupidon_link`.
- [x] Migration `add_lovers_to_games_winner_team_enum` — ENUM `games.winner_team`
      étendu à `lovers` (colonne définie via `$table->enum()` dans la migration de création ;
      extension par `ALTER TABLE ... MODIFY COLUMN`, cohérent avec les deux enums ci-dessus).
- [x] Migration `add_lover_player_id_to_game_players_table` — colonne `lover_player_id`
      (FK nullable vers `game_players`, `nullOnDelete`) conforme à `SPEC_CUPIDON.md` §2.
- [x] `README.md` — tableau Enums mis à jour (`cupidon`, `cupidon_link`, `lovers`).
- [x] Aucun test existant ne fait d'assertion exhaustive sur `cases()` de ces 3 enums —
      vérifié par grep, aucune régression possible de ce type.
- [x] Aucune logique métier touchée (`RoleDistributor`, `PhaseManager`, `RoleActions/*`
      non modifiés) — périmètre strictement schéma + enums + doc. 207 tests verts
      (inchangé avant/après, migrations testées avec rollback + réapplication).

## Phase 38 — Cupidon Étape 2 : cascade de mort dans PlayerEliminationService (2026-07-22)

- [x] `PlayerEliminationService::eliminate()` enrichi — cascade récursive sur
      `lover_player_id` si l'amoureux existe et est vivant (garde défensive
      contre auto-référence). No-op garanti sur les parties sans Cupidon
      (`lover_player_id` toujours null).
- [x] `GamePlayer::$fillable` — `lover_player_id` ajouté (bug silencieux
      découvert en écrivant le test, voir DECISIONS.md).
- [x] `tests/Unit/Services/PlayerEliminationServiceTest.php` créé — 3 tests
      (élimination simple, cascade amoureux, pas de re-élimination si déjà mort).
      210 tests verts (207 avant + 3 nouveaux, aucun cassé).

## Phase 39 — Cupidon Étape 3 : CupidonAction + Jobs, non branchés (2026-07-22)

- [x] `app/Services/RoleActions/CupidonAction.php` créée — méthode `link()`,
      guards dans l'ordre exact de SPEC_CUPIDON.md §4 (rôle, phase, anti-double-action,
      cibles distinctes, cibles vivantes). Pose `lover_player_id` symétrique,
      crée 2 `GameAction cupidon_link` (historique), broadcast `LoverRevealed`
      uniquement aux amoureux distincts de Cupidon (un seul broadcast si Cupidon
      s'est choisi lui-même).
- [x] `PhaseGuard::canCupidonLink()` ajoutée — `round === 1 && status === 'night'`.
- [x] `app/Events/Game/CupidonTurnStarted.php` et `LoverRevealed.php` créés
      (canal privé, modèle SeerTurnStarted/HunterTurnStarted).
- [x] `app/Jobs/ProcessCupidonTurn.php` et `ProcessCupidonAutoAction.php` créés —
      modèle ProcessSeerTurn/ProcessSeerAutoAction. Timeout sans action volontaire
      → aucun couple formé (décision assumée, pas de tirage aléatoire contrairement
      au Chasseur). **Non branchés dans PhaseManager::startNight()** — inatteignables
      depuis le flux de jeu actuel, testés uniquement en dispatch manuel.
- [x] `config/game.php` — `timers.cupidon => 30` + `limits.cupidon` (15-60s,
      host_configurable) ajoutés sur le modèle des autres timers ; Jobs utilisent
      `$game->timer('cupidon')` (pas `config()` direct — voir DECISIONS.md).
- [x] `GamePlayerFactory::cupidon()` ajouté.
- [x] Tests : `CupidonActionTest.php` (9 tests, guards + auto-sélection),
      `CupidonJobsTest.php` (10 tests, dispatch manuel des 2 Jobs), 3 tests
      `PhaseGuardTest::canCupidonLink`. 232 tests verts (210 avant + 22 nouveaux,
      aucun cassé).

## Phase 40 — Cupidon Étape 4 : intégration dans la séquence nocturne (2026-07-22)

- [x] `RoleDistributor` — `cupidon` ajouté à la config des rôles (0 ou 1 max,
      pattern identique à witch/hunter). `config/game.php roles.cupidon => 0`
      (désactivé par défaut — voir DECISIONS.md pour le choix, indépendant
      de la prémisse initiale du prompt sur witch/hunter).
- [x] `PhaseManager::startNight()` — dispatch conditionnel : `ProcessCupidonTurn`
      si round === 1 et Cupidon distribué, sinon `ProcessSeerTurn` directement
      (comportement v1.1/v1.2 strictement inchangé pour round > 1 et parties
      sans Cupidon).
- [x] `ProcessCupidonTurn` et `ProcessCupidonAutoAction` — chaînage complet vers
      `ProcessSeerTurn` ajouté (absent/mort/inactif, et timeout sans action
      volontaire) : sans ce chaînage, toute partie avec Cupidon serait restée
      bloquée indéfiniment au round 1.
- [x] `CupidonAction::link()` — dispatch `ProcessSeerTurn` après action volontaire
      (`delay(0)`, même principe que `/seer/done`).
- [x] `tests/Feature/Game/CupidonNightIntegrationTest.php` créé (3 tests bout en
      bout). Tests existants (`CupidonJobsTest`, `CupidonActionTest`,
      `RoleSettingsTest`) mis à jour/étendus. 237 tests verts (232 avant + 5
      nouveaux, aucun cassé).

## Phase 41 — Cupidon Étape 5 : WinConditionChecker camp amoureux (2026-07-22)

- [x] `WinConditionChecker::check()` — vérification victoire amoureux ajoutée AVANT
      le calcul loups/village existant (non modifié) : si `nb_vivants === 2` et les
      2 vivants sont mutuellement `lover_player_id`, `winner_team = 'lovers'`,
      retour anticipé. Fonctionne quel que soit le camp des 2 amoureux (loup+loup,
      loup+villageois, villageois+villageois).
- [x] `GameFinishedNotification::toWebPush()` — cas `'lovers'` ajouté au `match`.
- [x] `tests/Unit/Services/WinConditionCheckerTest.php` créé (6 tests : victoire
      amoureux villageois/villageois, loup/villageois, loup/loup ; non-régression
      village gagne, loups gagnent ; garde `nb_vivants !== 2`). 243 tests verts
      (237 avant + 6 nouveaux, aucun cassé).

## Phase 42 — Cupidon Étape 6 : Frontend (route + modale + notification amoureux) (2026-07-23)

- [x] `routes/web.php` — `POST /game/{id}/cupidon/link` ajoutée dans le groupe `auth` + `throttle:60,1`,
      sur le modèle exact de `hunter/shoot`.
- [x] `CupidonLinkRequest` créée — `target1_player_id`/`target2_player_id` vivants + dans la partie,
      `different` entre eux (pas de `notIn` sur l'id de Cupidon — auto-sélection autorisée, SPEC_CUPIDON.md §1).
- [x] `GameService::cupidonLink()` (délégation) + `ActionController::cupidonLink()` ajoutés, pattern
      identique à `hunterShoot()`/`seerCheck()`.
- [x] `night.blade.php` — modale Cupidon (sélection de 2 cibles par clic, y compris soi-même) sur le
      modèle visuel du panel Chasseur ; modale privée "Tu es amoureux de X" (`loverRevealed`), gated sans
      condition de rôle, auto-fermeture 8s + bouton dismiss.
- [x] `game-state.js` — 2 nouveaux `.listen()` sur le canal privé joueur déjà souscrit
      (`.cupidon.turn.started`, `.lover.revealed`), aucun nouveau canal Echo.
- [x] Docblock stale corrigé dans `CupidonTurnStarted.php` (voir BUGS_AND_ROADMAP.md).
- [x] 243 tests verts (inchangé — tâche frontend, pas de nouveau test automatisé demandé),
      `npm run build` sans erreur.

## Phase 44 — Bugfix Cupidon absent des rôles configurables host (2026-07-23)

- [x] `GameSettingsService::validateRoleSettings()` — `cupidon` ajouté à la whitelist
      (deux boucles : validation valeur 0|1, validation clé configurable), même motif
      que `witch`/`hunter`. Docblock de `GameService::validateRoleSettings()` (délégation)
      mis à jour en cohérence.
- [x] `waiting-room.blade.php` (`roleSettings()`) — `roles.cupidon` et
      `labels.cupidon = 'Cupidon'` ajoutés, même structure que `witch`/`hunter`.
- [x] Audit grep `witch.*hunter`/`hunter.*witch` sur `app/` et `resources/` — aucune
      3e liste de rôles configurables oubliée (`RoleDistributor`, `GamePolicy` déjà
      corrects). Gap d'affichage distinct trouvé (`config/game_ui.php` role labels,
      `role-reveal.blade.php` ROLE_NAMES) — ajouté à la ROADMAP, hors périmètre de
      cette tâche (configurabilité, pas affichage).
- [x] Test `test_host_peut_activer_cupidon()` ajouté dans `RoleSettingsTest`.
- [x] `config/game.php roles.cupidon` : `0` → `1` (activé par défaut, parité
      witch/hunter demandée explicitement — annule le défaut désactivé de la
      Phase 40, voir DECISIONS.md). `waiting-room.blade.php` fallback `?? 0` → `?? 1`.
      Tests mis à jour : `test_role_distributor_ninclut_pas_cupidon_par_defaut` →
      `test_role_distributor_inclut_cupidon_par_defaut` (assertion inversée),
      `test_host_peut_desactiver_cupidon` ajouté, `test_role_distributor_remplit_villageois_automatiquement`
      corrigé (villageois 3→2, cupidon compté).
      254 tests verts (252 avant + 2 nouveaux, aucun cassé).

## Phase 43 — Cupidon Étape 7 : tests d'intégration bout en bout + validation finale (2026-07-23)

- [x] `tests/Feature/Game/CupidonTest.php` créé (9 tests, de vraies parties factory jusqu'au bout
      via les Services/Jobs réels — voir DECISIONS.md) :
      - couple formé au round 1 avant la Voyante, couple confidentiel préservé jusqu'en phase jour
      - Cupidon se choisit lui-même (1 seul `LoverRevealed`, vers l'autre amoureux uniquement)
      - timeout sans action volontaire → aucun couple, la nuit continue, round 2 sans Cupidon
        (`ProcessCupidonTurn` poussé une seule fois sur toute la partie)
      - cascade de mort testée aux 4 points d'entrée réels : loups (`ProcessNightActions`),
        sorcière (`WitchAction::act('kill')`), vote jour (`VoteService::resolveDayVote()`),
        chasseur (`HunterAction::shoot()`)
      - victoire amoureux loup+villageois derniers survivants, priorité sur le calcul loups/village
      - partie sans Cupidon : flux v1.2 (Voyante, Sorcière, Loups, Jour) strictement inchangé
- [x] `php artisan test` : 252/252 verts (243 avant + 9 nouveaux, aucun cassé) — validation finale
      avant le tag v1.3.0.

## Phase 45 — Bugfix Cupidon jamais déclenché au round 1 (chemin élection du maire) (2026-07-23)

- [x] `PhaseManager::dispatchNightOpeningTurn(Game $game, string $timerName)` créée — factorise
      la décision Cupidon vs Voyante (round === 1 && Cupidon distribué), timer paramétrable
      (voir DECISIONS.md).
- [x] `PhaseManager::startNight()` — bloc `if`/`else` remplacé par un appel à
      `dispatchNightOpeningTurn($locked, 'night_start_delay')`, comportement inchangé.
- [x] `ProcessMayorElection::handle()` — dispatch inconditionnel de `ProcessSeerTurn` remplacé
      par `$phaseManager->dispatchNightOpeningTurn($result['game'], 'mayor_reveal')`
      (`PhaseManager` injecté par paramètre de méthode) ; try/catch broadcasts
      `MayorElected`/`NightStarted` strictement inchangés.
- [x] Grep `ProcessSeerTurn::dispatch` sur tout `app/` — 3 autres call sites trouvés
      (`ProcessCupidonTurn`, `ProcessCupidonAutoAction`, `CupidonAction::link()`), tous des
      enchaînements post-tour-Cupidon, laissés inchangés à dessein (voir DECISIONS.md).
- [x] `tests/Feature/Game/ProcessMayorElectionTest.php` — 3 tests ajoutés (Cupidon déclenché
      dès l'élection du maire round 1, comportement inchangé sans Cupidon, `dispatchNightOpeningTurn`
      ne redéclenche jamais Cupidon au round 2+) ; test existant mis à jour pour le nouveau
      paramètre `PhaseManager` de `handle()`.
- [x] `php artisan test` : 257/257 verts (254 avant + 3 nouveaux, aucun cassé).

## Phase 46 — Icône/couleur Cupidon dans les écrans d'affichage de rôle (2026-07-23)

- [x] Grep exhaustif `witch`+`hunter` sur `resources/` et `app/` — 9 tables rôle→icône/couleur/label
      identifiées où Cupidon manquait : `mayor-election.blade.php`, `role-card.blade.php` (composant
      inutilisé), `player-list.blade.php`, `finished.blade.php` (2 tables), `summary.blade.php`,
      `history.blade.php`, `day.blade.php` (`revealed_role_label`, PHP+JS), `role-reveal.blade.php`,
      `game-state.js` (toast d'élimination).
- [x] Couleur rose déjà établie retrouvée : `#f472b6` (4 usages dans `night.blade.php` pour l'UI
      Cupidon spécifique). Standardisée partout, aucune nouvelle couleur introduite. Icône `💘`
      (cohérente avec `💞` déjà utilisé côté "Cupidon a frappé").
- [x] Bug trouvé dans `role-reveal.blade.php` : le bloc Villageois utilisait
      `role !== 'werewolf' && !== 'seer' && !== 'witch' && !== 'hunter'` — un joueur Cupidon tombait
      donc silencieusement dans le bloc Villageois par défaut (icône 🧑‍🌾, label "Villageois", pas
      son vrai rôle). Corrigé : condition étendue avec `&& role !== 'cupidon'` + bloc Cupidon dédié
      ajouté (voir DECISIONS.md).
- [x] Aucune icône/couleur/label de rôle existant modifiée. `php artisan test` relancé après chaque
      fichier : 257/257 verts en continu.

## État global

- v1.1 ✅ Terminé et taggué `v1.1.1`
- v1.2 ✅ Terminé — Étapes 2→5 + Phases 17→18 complètes
- Étape 9 ✅ Terminée — Documentation routes + README technique
- Phase 25 ✅ Terminée — Auto-soin sorcière + notifications enrichies
- Étape 10 ✅ Terminée — Audit final de conformité CLAUDE.md
- Roadmap Code Propre ✅ Complète (Étapes 1→10)
- Phase 26 ✅ Terminée — Sursis maire + historique aléatoire
- v1.3 Cupidon ✅ Terminé — Étapes 1→7 complètes (schéma, cascade, action+jobs,
  intégration nuit, win condition, frontend, tests d'intégration bout en bout).
  252/252 tests verts. Prêt pour le tag v1.3.0 (créé par le développeur après
  merge sur `dev`).
- Phase 27 ✅ Terminée — P1 succession sorcière, P2 persistance random_elimination, P3 couronne maire jour
- v1.3+ En attente — voir ROADMAP dans BUGS_AND_ROADMAP.md
