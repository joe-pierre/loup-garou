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

## Phase 47 — Icône Cupidon manquante dans le résultat d'inspection Voyante (2026-07-23)

- [x] `night.blade.php` (`roleEmoji(role)`/`roleLabel(role)` dans `nightScreen()`, écran
      `nightPhase === 'seer_result'`) — pattern objet+fallback (`?? '❓'` / `?? 'Rôle inconnu.'`),
      distinct du pattern liste blanche déjà corrigé dans `role-reveal.blade.php` (Phase 46), donc
      non trouvé par le grep `witch`+`hunter` combiné de cette phase-là. `cupidon → 💘` (icône) et
      `'Cupidon — Innocent.'` (label) ajoutés, couleur `#f472b6` déjà standardisée réutilisée pour
      la bordure de carte via la logique binaire `isWerewolf` existante (non modifiée).
- [x] Audit Étape 4 (grep `default:`/`match (`/`switch (` + `role`) — 2 emplacements supplémentaires
      trouvés avec le même bug (match PHP défaillant sur `default => 'Villageois'` pour Cupidon) :
      `day.blade.php` ligne ~101 (message "C'était un ..." victime de nuit, ne couvre même pas
      witch/hunter) et `GameController::state()` (`revealed_role_label`, couvre witch/hunter mais
      pas cupidon). Non corrigés dans cette tâche (hors périmètre explicite : un seul composant
      demandé) — voir BUGS_AND_ROADMAP.md.
- [x] `php artisan test` : 257/257 verts (aucun cassé, aucun test ajouté — bugfix d'affichage pur).

## Phase 48 — Bugfix des 2 emplacements laissés hors périmètre par la Phase 47 (2026-07-23)

- [x] Confirmé préexistant (bug antérieur à Cupidon, pas une régression) : `day.blade.php:101`
      ne couvrait que `werewolf`/`seer` avec `default => 'Villageois'` depuis le commit initial
      des vues de jeu (`7239050`), avant même l'existence de Sorcière/Chasseur (v1.2). Les deux
      autres emplacements du même fichier (`$playersJson` ~ligne 470, `roleLabels` du listener
      `player-eliminated` ~ligne 662) couvraient déjà les 6 rôles — confirme l'oubli isolé de
      cette seule ligne, pas un pattern plus large.
- [x] `day.blade.php:101` (message "C'était un ..." victime de nuit) — `witch`, `hunter`,
      `cupidon` ajoutés au `match()`.
- [x] `GameController::state()` (`revealed_role_label`) — `'cupidon' => 'Cupidon'` ajouté
      (label court, cohérent avec les entrées sœurs du même tableau — pas la phrase longue
      de `night.blade.php`, qui sert un contexte d'affichage différent).
- [x] `ReconnectionTest::test_state_endpoint_retourne_la_liste_des_joueurs` étendu pour
      couvrir witch/hunter/cupidon (jusqu'ici seul werewolf était testé) — pas de nouveau
      test créé, test existant déjà pertinent enrichi.
- [x] `php artisan test` : 257/257 verts.

## Phase 49 — Bugfix couple Cupidon absent de l'historique de fin de partie (2026-07-23)

- [x] `GameController::history()` — `cupidon_link` chargé via une requête dédiée sans
      `anonymized()` (pattern identique à `mayor_vote`, les 2 identités doivent rester
      lisibles) et mergé aux autres actions.
- [x] `HistoryService::buildTimeline()` — les 2 `GameAction cupidon_link` du round 1 (une
      par amoureux, `player_id` = Cupidon, `target_player_id` = amoureux) regroupées en une
      paire `cupidon_couple` ajoutée à l'entrée `night` du round 1 uniquement (Cupidon n'agit
      qu'une fois). `null` si Cupidon n'a pas agi (timeout ou rôle non distribué) — aucun
      placeholder affiché.
- [x] `history.blade.php` — affichage de la paire dans la carte "Nuit 1", icône/couleur
      Cupidon déjà standardisées (💘, `#f472b6`), sur le modèle visuel des autres lignes
      d'action de la carte (`witch_heal`, `succession`).
- [x] `GameHistoryServiceTest` — 2 tests ajoutés : paire `cupidon_couple` présente au round 1,
      `cupidon_couple` reste `null` sans Cupidon (aucune régression sur le reste de l'entrée
      night).
- [x] `php artisan test` : 259/259 verts (257 avant + 2 nouveaux, aucun cassé).

## Phase 50 — Bugfix ordre du lien Cupidon dans la carte "Nuit 1" (2026-07-23)

- [x] Confirmé : l'ordre des sous-lignes d'une carte nuit est déterminé par l'ordre
      séquentiel des blocs `@if` dans `history.blade.php` (`@elseif($entry['type'] === 'night')`,
      ~ligne 331) — pas par l'ordre des clés du tableau retourné par
      `HistoryService::buildTimeline()`, qui n'a aucun effet sur le rendu.
- [x] `history.blade.php` — bloc `cupidon_couple` déplacé en tête de la séquence `night`
      (avant `killed`/`wolf_no_agreement`), reflétant le fait que Cupidon joue en tout
      premier au round 1. Reste de l'ordre inchangé (déjà correct) : `killed` →
      `witch_heal`/`witch_kill` → `hunter_shot` → `succession`. Marge `mb-1` (au lieu de
      `mt-1`) sur le bloc Cupidon pour un espacement autonome, sans dépendance au bloc suivant.
- [x] `GameHistoryServiceTest` — 1 test ajouté (`test_night1_affiche_le_lien_cupidon_avant_les_autres_evenements`),
      vérifie l'ordre réel du rendu HTML via `assertSeeTextInOrder()` sur la route `game.history`
      avec Cupidon + victime + tir chasseur + succession tous présents simultanément au round 1.
- [x] `php artisan test` : 260/260 verts (259 avant + 1 nouveau, aucun cassé).

## Phase 51 — Bugfix resynchronisation des sous-phases de nuit (2026-07-23)

- [x] `games.night_sub_phase` (migration) — vérité persistée de la sous-phase de nuit
      active, posée par chaque `ProcessXTurn` (Cupidon/Voyante/Loups/Sorcière/Chasseur)
      au moment du broadcast `XTurnStarted` ; `phase_deadline` posé aussi pour
      Sorcière/Chasseur/Cupidon (absent auparavant). Purgée à chaque nouvelle nuit.
- [x] `NightResyncService` (nouveau) — lecture seule, expose sous-phase active +
      `already_acted` + payload minimal par rôle, branché dans `GET /state`
      (clé additive `night_action`, `seer_turn_active`/`werewolves_turn_active`
      existants inchangés).
- [x] `game-state.js` — `_loadState()` dispatche `night-phase-resync` ; câblé aussi
      à la reconnexion Echo réelle (`conn.bind('connected', ...)`), pas seulement
      au chargement de page.
- [x] `night.blade.php` — chemin unique `applyNightResync()` partagé entre les 5
      listeners d'events live et le rattrapage resync (garde anti-double-reset),
      barre de temps recalculée depuis le temps restant (jamais relancée pleine durée).
- [x] `tests/Feature/Game/NightResyncTest.php` — 23 tests (5 rôles × refresh sans
      action / avec action déjà soumise / reconnexion, cas négatifs, persistance
      des Jobs). 283/283 tests verts (260 avant + 23 nouveaux, aucun cassé).
- [x] Scénario manuel rejoué depuis, à plusieurs reprises, en local et en prod (audit
      documentaire du 2026-07-24) : le bug original (loup/refresh → écran générique au
      lieu de l'écran loups) n'a pas réapparu, y compris lors des sessions de test du
      fix victoire des Amoureux (Phases 53-55) qui ont fait tourner des nuits complètes
      avec plusieurs rôles actifs (Cupidon, Voyante, Loups, Sorcière). Nuance : il ne
      s'agit pas d'un rejeu scripté ciblant spécifiquement le scénario de reproduction
      d'origine, mais d'une réutilisation organique de la fonctionnalité pendant des
      sessions de test ultérieures — la réserve initiale (absence de test E2E dédié,
      OAuth Google requis) reste donc valable en tant que limite méthodologique, mais
      le risque pratique qu'elle signalait est levé par l'usage répété sans régression.
- [x] Gap connu, hors périmètre : tir du Chasseur pendant la phase JOUR non couvert
      par la resynchro (voir ROADMAP dans BUGS_AND_ROADMAP.md) — **toujours réel**
      (vérifié 2026-07-24) : `NightResyncService::currentSubPhase()` retourne `null`
      dès l'entrée si `! $game->isNightPhase()`, donc ne couvre jamais un tir de
      Chasseur déclenché en phase JOUR (maire-chasseur éliminé par le vote du jour).
      Aucun chantier ultérieur (Phase 52, fix victoire des Amoureux) n'a touché à ce
      périmètre. Marqueur `[x]` car la vérification elle-même est terminée — le gap
      reste ouvert en tant que tel, voir ROADMAP.

## Phase 52 — Bugfix victime des loups jamais éliminée au timeout Sorcière (2026-07-23)

- [x] Confirmé : `ProcessWitchAutoAction` créait le `GameAction witch_pass` (traçabilité
      historique) mais ne reprenait jamais la logique de finalisation de la branche `pass`
      de `WitchAction::act()` — la victime des loups (sorcière elle-même, maire en sursis,
      ou victime ordinaire) restait `is_alive = true` indéfiniment si la Sorcière n'agissait
      pas avant l'expiration de son timer.
- [x] `WitchAction` — logique de finalisation de la branche `pass` extraite en 3 méthodes
      privées réutilisables (`resolveDeferredVictim()`, `broadcastDeferredVictim()`,
      `finalizeOrdinaryVictim()`) + une méthode publique `finalizeTimedOutVictim()` pour
      le chemin timeout. Branche `pass` de `act()` inchangée en comportement observable ;
      branche `kill` (duplication préexistante de la même logique) non touchée.
- [x] `ProcessWitchAutoAction::handle()` — injection de `WitchAction`, appelle
      `finalizeTimedOutVictim()` uniquement si CE job a créé le `witch_pass` (guard
      anti-doublon existant), jamais si une action manuelle a déjà résolu le round.
- [x] `WitchTest.php` — 5 nouveaux tests (victime ordinaire, sorcière victime, maire en
      sursis, maire-chasseur en sursis sans succession, guard anti-doublon). 2 tests
      existants mis à jour (`app(WitchAction::class)` passé explicitement à `->handle()`,
      comme pour les autres Jobs de la suite).
- [x] `php artisan test` : 288/288 verts (283 avant + 5 nouveaux, aucun cassé).
- [x] Vérification manuelle via `php artisan tinker` (scénario reproduisant le bug de prod) :
      `is_alive` passe bien de `true` à `false` après timeout.
- [x] Branche `fix/witch-timeout-victim-not-eliminated` — committée (`8e3d821`) et
      mergée dans `dev` (`b38716d`, audit documentaire du 2026-07-24). Statut mis à
      jour : cette entrée indiquait à tort "non mergée, non committée" alors que les
      deux commits sont bien présents dans l'historique `dev`.

## Phase 53 — Bugfix victoire des Amoureux non déclenchée + durcissement WinConditionChecker (2026-07-24)

- [x] Confirmé (test qui échoue avant fix) : `WitchAction` n'appelait jamais
      `WinConditionChecker::check()` après une élimination différée (sorcière elle-même,
      maire en sursis, ou victime ordinaire) — seul point d'élimination de toute la
      codebase sans ce check, contrairement à `ProcessNightActions`, `PhaseManager::endNight()`,
      `ProcessHunterTurn`/`AutoAction`, `VoteService`.
- [x] `WitchAction` — `WinConditionChecker` injecté, `check()` appelé en fin de `act()`
      et de `finalizeTimedOutVictim()` (couvre les 2 chemins manuel + timeout).
- [x] `ProcessHunterTurn::handle()` — `check()` déplacé en tout début de méthode (priorité
      victoire amoureux > tir du Chasseur en attente, SPEC_CUPIDON.md §6), plus seulement
      dans la branche de repli "chasseur déjà résolu/invalide".
- [x] `WinConditionChecker::check()` réécrit — atomique (`lockForUpdate()`) + idempotent
      (no-op si `status === 'finished'`), même pattern que `cancelGame()`/`resolveMayorElection()`.
      Durcissement de l'anomalie #2 (incohérence "Annulée" vs "Les Loups ont gagné") — cause
      exacte de la partie SFICZ8 non confirmée avec certitude (race non reproductible en test
      synchrone), mais gap structurel réel corrigé.
- [x] Tests : 3 tests ajoutés (2 `CupidonTest`, 1 `WinConditionCheckerTest`), 1 test existant
      (`NightResyncTest`) enrichi d'un effectif vivant réaliste. 291/291 tests verts (288 avant
      + 3 nouveaux, aucun cassé). Suite complète exécutée avec Reverb démarré localement.
- [x] Branche `fix/lovers-victory-witch-deferred-resolution` — committée (`8bb28cd`) et
      mergée dans `dev` (`05c0695`, constaté en cours d'audit documentaire du 2026-07-24).

## Phase 54 — Cause racine confirmée : victoire des Amoureux annulée à tort par une course cancelGame() (2026-07-24)

- [x] Reproduction directe et déterministe du chemin vote de jour seul (partie RIQPAZ) —
      `test_victoire_amoureux_declenchee_par_vote_de_jour_qui_fait_tomber_effectif_a_deux`
      passe déjà avec le code existant, excluant un bug de logique dans `VoteService`.
- [x] Cause racine trouvée dans `GameService::cancelGame()` : garde `whereNotIn('status',
      ['finished', 'waiting'])` bien plus permissif que le pré-check de `CheckReconnectionTimeout`
      (`in_array($game->status, ['night', 'day', 'electing_mayor'])`) — TOCTOU classique,
      `cancelGame()` pouvait annuler une partie en statut `'processing_day'`/`'processing_night'`
      (résolution de vote/nuit déjà en cours, `WinConditionChecker::check()` pas encore appelé).
- [x] `GameService::cancelGame()` — garde resserré à `whereIn('status', ['night', 'day',
      'electing_mayor'])`, symétrique au pré-check de `CheckReconnectionTimeout`, revalidé
      atomiquement sous le même `lockForUpdate()` que l'écriture.
- [x] Tests : 2 tests ajoutés dans `CancelGameTest.php` reproduisant directement la fenêtre
      de course (rouge sur le code d'origine, vert après fix) + 1 test `CupidonTest`
      (non-régression vote de jour isolé). 294/294 tests verts (291 avant + 3 nouveaux,
      aucun cassé).
- [x] `DECISIONS.md` — entrée "Victoire des Amoureux annulée à tort par une course avec
      cancelGame()..." remplace la mention "non confirmé" de la Phase 53.
- [x] Branche `fix/lovers-victory-witch-deferred-resolution` — mergée dans `dev` (`05c0695`),
      même constat que ci-dessus.

## Phase 55 — Affichage victoire des Amoureux : finished.blade.php et history.blade.php généralisés (2026-07-24)

- [x] Confirmé (audit demandé en complément des Phases 53-54) : `finished.blade.php` pilotait
      tout son thème via un booléen `$isVillage` — toute valeur de `winner_team` autre que
      `'villagers'` (donc `'werewolves'` ET `'lovers'`) retombait sur le thème "Loups". Une
      victoire amoureux correctement persistée aurait donc quand même affiché "Les Loups ont
      gagné !".
- [x] `finished.blade.php` — `$isVillage` remplacé par `$theme` (3 branches via `match()`,
      `villagers`/`lovers`/`default` werewolves), thème amoureux rose `#f472b6` (déjà
      standardisé pour Cupidon), emoji 💞, "Les Amoureux ont gagné !", confettis or+rose.
- [x] `history.blade.php` + `HistoryService::buildTimeline()` — même défaut trouvé
      indépendamment : `winner_team = 'lovers'` retombait dans le `default` affichant
      "🏁 Annulée"/"🏁 Partie annulée" — exactement le symptôme "Annulée" des Phases 53-54,
      mais de cause purement affichage. Branches `lovers` ajoutées (badge, classe CSS
      `.winner-lovers`, nœud timeline `.node-finish-lovers`, libellé `HistoryService`).
- [x] `admin/games/show.blade.php` + `admin/users/show.blade.php` — même binaire trouvé et
      corrigé pour cohérence (écrans admin, priorité moindre).
- [x] `summary.blade.php` (écran intermédiaire avant `/finished`) vérifié neutre, aucune
      modification nécessaire.
- [x] Tests : 2 tests ajoutés dans `GameHistoryServiceTest.php` (rouge avant fix, vert après).
      `finished.blade.php` vérifié par rendu direct pour les 3 valeurs de `winner_team`.
      `npm run build` sans erreur. 296/296 tests verts (294 avant + 2 nouveaux, aucun cassé).
- [x] Branche `fix/lovers-victory-witch-deferred-resolution` — mergée dans `dev` (`05c0695`),
      même constat que ci-dessus.

## Phase 56 — Icône cœur privée sur le pseudo des amoureux (2026-07-25)

- [x] `GameController::state()` — `my_lover_player_id` ajouté (strictement privé au joueur
      courant, `$player->lover_player_id` déjà chargé, jamais dans la liste `players`
      générique). Vérifié : aucune fuite préexistante de `lover_player_id` nulle part
      (aucun `@json($players)`/sérialisation brute de `GamePlayer`, tous les payloads —
      `GameFinished` inclus — whitelistent déjà explicitement leurs champs).
- [x] `day.blade.php` — const JS `MY_LOVER_ID` ajoutée ; cœur 💘 (`#f472b6`) sur sa propre
      ligne + celle du partenaire dans la liste de vote du jour et la liste de cibles
      du Chasseur.
- [x] `night.blade.php` — même const `MY_LOVER_ID` (listes réactives loups) + comparaison
      directe `$player->lover_player_id` en Blade (listes rendues serveur) : village
      endormi, cibles Voyante, cibles Sorcière, cibles Chasseur, votes de la meute.
      Écran de sélection de Cupidon lui-même exclu (aucun couple n'existe encore pendant
      son propre tour) ; `mayor-election.blade.php` exclu (se déroule avant la nuit 1,
      donc avant que Cupidon n'agisse).
- [x] Voir `DECISIONS.md` — choix assumé d'exposer l'info à la fois via `/state` (lettre
      du prompt) et directement en rendu serveur Blade (`day()`/`night()` ne consomment
      pas `players` depuis `/state` pour leurs listes locales).
- [x] `php artisan test` : 298/298 tests pertinents verts (6 échecs `BroadcastException`
      préexistants, environnement local sans Reverb démarré, fichiers non touchés par
      cette tâche). `npm run build` sans erreur.

## Phase 57 — Notification de mort par chagrin des amoureux (2026-07-25)

- [x] `PlayerEliminationService::eliminate()` — branche cascade amoureux : après l'appel
      récursif, `$lover->load('user')` + `broadcast(new PlayerEliminated($game, $lover,
      'heartbreak'))` (même pattern que les 4 call sites existants, avant le bloc
      `hunter_pending` déjà en place). Le `$player` du paramètre initial reste sous la
      responsabilité de l'appelant, aucune duplication du broadcast normal.
- [x] `game-state.js::handlePlayerEliminated()` — branche `reason === 'heartbreak'` :
      toast public dédié "💔 {pseudo} meurt de chagrin après la mort de son amoureux —
      c'était le {rôle}.", distinct du toast générique "💀 {pseudo} était le {rôle}".
      Aucun toast personnel séparé ajouté (voir DECISIONS.md — contrairement au poison
      Sorcière, pas de problème de timing lié à une redirection de page ici).
- [x] `PlayerEliminationServiceTest` — `Event::fake()` ajouté aux tests de cascade
      (jusqu'ici absents car `eliminate()` ne broadcastait jamais rien), nouveau test
      `test_cascade_broadcast_player_eliminated_reason_heartbreak_uniquement_pour_lamoureux`
      (broadcast `heartbreak` pour l'amoureux cascadé, jamais pour le `$player` initial),
      assertion `Event::assertNotDispatched` ajoutée au test amoureux-déjà-mort.
- [x] `php artisan test` : 299/299 tests pertinents verts (298 avant + 1 nouveau, 6 échecs
      `BroadcastException` préexistants, environnement local sans Reverb démarré, fichiers
      non touchés par cette tâche). `npm run build` sans erreur.

## Phase 58 — Bugfix nombre de voix non affiché en temps réel (vote de jour) (2026-07-25)

- [x] `day.blade.php` (`_updateVoteBars()`) — lecture corrigée `v.player_id`/`v.vote_weight`/`v.vote_count`
      → `v.target_player_id`/`v.total_weight`, seuls noms de champs réels du payload `DayVoteCast`.
      `_handleMayorVoteCast` (`game-state.js`) non touchée, déjà correcte pour `MayorVoteCast`
      (`target_player_id`/`vote_count` — nom de champ de poids différent, les deux events ne sont
      pas interchangeables).
- [x] Grep `player_id`/`vote_weight`/`vote_count` sur `day.blade.php` — aucun autre endroit du fichier
      ne fait la même lecture erronée.
- [x] Trouvé hors périmètre (voir BUGS_AND_ROADMAP.md ROADMAP) : `game-state.js::_buildVoteMap()`
      a le même défaut de nommage, non corrigé — sans impact visible, aucune vue Blade ne consomme
      son résultat actuellement.
- [x] `npm run build` sans erreur. Aucun test PHP affecté (fix Blade/Alpine pur, aucun changement
      backend).

## Phase 56 — Bugfix défauts de timers désynchronisés modale host (2026-07-25)

- [x] `TimerCalculator::TIMERS` mis à jour — nouvelle table de défauts par effectif
      (`day_vote` uniformisé à 115s, `werewolves` à 45s dès 8 joueurs).
- [x] `LobbyController::waitingRoom()` — calcule `TimerCalculator::forPlayerCount($game->max_players)`
      (même pattern que `GameService::startGame()`) et l'injecte à la vue (`$timerDefaults`).
- [x] `waiting-room.blade.php` (`timerSettings()`) — les 5 fallbacks lisent `$timerDefaults[$clé]`
      au lieu de constantes hardcodées (30/30/15/90) ; priorité `settings['timers'] > défaut`
      inchangée ; `limits` (min/max) non touché.
- [x] `tests/Feature/Game/WaitingRoomTimerDefaultsTest.php` créé (6 tests : 4 effectifs sans
      settings, priorité settings sauvegardés, `mayor_election`/`mayor_succession` fixes).
      311 tests (305 + 6 nouveaux, aucun cassé — 6 échecs `BroadcastException` pré-existants
      liés à Reverb non démarré localement, confirmés identiques sur `dev` avant ce fix).

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
- Phase 51 ✅ Terminée — Resynchro sous-phases de nuit (refresh/reconnexion) — mergée dans
  `dev` (`d4c2d18`). Rejeu manuel effectué depuis à plusieurs reprises sans régression
  (audit documentaire du 2026-07-24) ; gap Chasseur/phase JOUR toujours ouvert, voir ROADMAP.
- Phase 52 ✅ Terminée — Fix victime des loups jamais éliminée au timeout Sorcière — mergée
  dans `dev` (`b38716d`, audit documentaire du 2026-07-24 : statut "non mergée" corrigé)
- Phase 53 ✅ Terminée — Fix victoire des Amoureux non déclenchée (gap WitchAction/ProcessHunterTurn)
  + durcissement atomicité/idempotence WinConditionChecker — mergée dans `dev` (`05c0695`,
  branche `fix/lovers-victory-witch-deferred-resolution`, constaté en cours d'audit
  documentaire du 2026-07-24)
- Phase 54 ✅ Terminée — Cause racine confirmée de l'anomalie "Annulée vs Loups ont gagné" (course
  `cancelGame()`/résolution en cours) — mergée dans `dev`, même commit `05c0695`
- Phase 55 ✅ Terminée — Affichage victoire des Amoureux généralisé (finished.blade.php,
  history.blade.php, écrans admin) — mergée dans `dev`, même commit `05c0695`
- v1.3+ En attente — voir ROADMAP dans BUGS_AND_ROADMAP.md (Cupidon désormais livré en v1.3 ;
  restent Loup Blanc et Petite Fille)
