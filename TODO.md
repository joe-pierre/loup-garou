# TODO — PROGRESSION

## Légende
- [ ] À faire
- [~] En cours
- [x] Terminé
- [!] Bug connu

## Phase 1 — Base
- [x] Migrations (users, games, game_players, game_actions, chat_messages, exclusions)
- [x] Modèles + relations + scopes
- [x] Factories + Seeders
- [x] config/game.php (timers, rôles)

## Phase 2 — Auth
- [x] Google OAuth (GoogleController)
- [x] Routes auth
- [x] Middleware auth

## Phase 3 — Lobby (+ Écran 5)
- [x] POST /game (création)
- [x] POST /game/{code}/join
- [x] POST /game/{id}/exclude/{playerId}
- [x] POST /game/{id}/ready  ← vérifié : route + ActionController::ready() présents
- [x] Event PlayerJoined
- [x] Event PlayerExcluded
- [x] RoleDistributor (avec config extensible)
- [x] Démarrage automatique quand max_players atteint
- [x] GET /game/{code}/lobby (salle d'attente)
- [x] Vue waiting-room.blade.php (Écran 4)
- [x] GET /game/{code}/role-reveal + POST /game/{id}/ready
- [x] Event PlayerReady
- [x] Vue game/role-reveal.blade.php (Écran 5)

### Recette manuelle Couche 2 (lobby)
- [x] Créer une partie → redirige vers /game/{code}/lobby
- [x] Rejoindre avec un code valide → redirige vers /game/{code}/lobby
- [x] Rejoindre avec un code invalide → message d'erreur affiché
- [x] Pseudo vide → message de validation affiché
- [x] OTP : navigation automatique entre les cases
- [x] OTP : pré-remplissage via ?code=XXXXXX dans l'URL

## Phase 4 — WebSocket Setup
- [x] Reverb config (config/broadcasting.php + config/reverb.php)
- [x] Echo config (resources/js/echo.js) — CSRF token dans auth.headers
- [x] Channel definitions (routes/channels.php)
- [x] Broadcasting Auth (loups, joueur individuel)
- [x] CORS Reverb (allowed_origins: ['*'] pour dev)

## Phase 5 — Élection Maire
- [x] POST /game/{id}/vote/mayor  ← vérifié : route + VoteController::mayor() présents
- [x] Job ProcessMayorElection (timer 30s)  ← vérifié : guard + resolve + dispatch ProcessSeerTurn
- [x] VoteService::castMayorVote()  (= processMayorVote)
- [x] VoteService::resolveMayorElection()
- [x] Event MayorElectionStarted
- [x] Event MayorVoteCast
- [x] Event MayorElected

## Phase 6 — Nuit
- [x] POST /game/{id}/seer/check
- [x] POST /game/{id}/vote/night
- [x] Job ProcessNightActions (timer 30s par action)
- [x] Event NightStarted
- [x] Event SeerTurnStarted + SeerResult
- [x] Event WerewolvesTurnStarted + WerewolvesVoteCast
- [x] Event WerewolfChatMessage
- [x] Chat loups (channel werewolves)

## Phase 7 — Jour
- [x] POST /game/{id}/vote/day
- [x] POST /game/{id}/mayor/succession
- [x] Job ProcessDayVote (timer 90s)
- [x] Event DayStarted
- [x] Event DayVoteCast
- [x] Event PlayerEliminated / NoElimination
- [x] Event MayorSuccessionStarted + MayorSuccessionDone
- [x] WinConditionChecker

## Phase 8 — Déconnexion
- [x] Détection déconnexion (Reverb presence channel)
- [x] Job CheckReconnectionTimeout (30s)
- [x] is_inactive logic
- [x] Annulation si > 50% inactifs
- [x] GET /game/{code}/state (reconnexion)

## Phase 9 — Fin de partie
- [x] Event GameFinished
- [x] Push notifications (fin de partie, mort, exclusion)
- [x] Historique de partie (Écran 13)
- [x] Écran Fin de partie (Écran 11) + Annulation
- [x] Logique Écran Spectateur mort (Écran 12) — vue Blade dans Phase 10
- [x] Scheduler CleanOldGames

## Phase 10 — UI
- [x] Landing Page (Écran 1)
- [x] Layout principal + composants Blade réutilisables
- [x] Alpine.js store central gameState
- [x] GSAP animations (intégrées dans composants + landing)
- [x] Écrans Blade restants (tâche 29 — night + day)
- [x] Intégration templates HTML → vues Blade @extends (waiting-room, role-reveal, mayor-election, night, day, finished)
- [x] window.gameId/playerId dans layout
- [x] Vérifier et aligner la police de corps (EB Garamond conservée — toutes les références à Crimson Text supprimées dans app.css, tailwind.config.js et les vues Blade ; SPEC.md §10 était déjà correct)
- [x] Responsive
- [x] cancelled.blade.php — réécrite en @extends('layouts.game') (alignée sur finished.blade.php), affiche message d'annulation + CTA retour accueil
- [x] spectator.blade.php — réécrite en @extends('layouts.game') + store gameState central (renommée depuis dead-spectator.blade.php), lecture seule, voit le chat village, chat loups si ex-loup

## Phase 11 — Tests
- [x] Tests Feature Auth (GoogleAuthTest — 4 tests)
- [x] Tests Feature Game/Lobby (CreateGameTest, JoinGameTest, ExcludePlayerTest — 20 tests)
- [x] Vérifier et écrire tests phases 5→9 (nuit, jour, chat, race conditions)

## Phase 12 — Audit final (Tâche D)
- [x] Sécurité : channels, anti-spoofing, exposition rôles, middleware auth
- [x] Accessibilité : prefers-reduced-motion GSAP, aria-labels, focus:ring, role="log"
- [x] Fonctionnel : points documentés dans rapport (recette manuelle)
- [x] Production : config queue, RoleDistributor extensibilité

## Phase 13 — Bugfixes post-audit (2026-06-10)
- [x] Bouton "Tuer" inactif — castNightVote accepte wolves_turn
- [x] Tour voyante/loups jamais affiché — night_start_delay 4s dans PhaseManager
- [x] confirmQuit not defined — gameState déplacé sur div fantôme hors <main>
- [x] Modale succession fantôme + events doublés — suppression double abonnement Echo, window.dispatchEvent partout
- [x] 404 sur /night — GameController accepte wolves_turn/processing_night
- [x] state() isNight corrigé pour wolves_turn/processing_night
- [x] DayStarted payload manquait player_id
- [x] Accumulation CheckReconnectionTimeout — guard Cache::has()
- [x] Barre timer jour pleine à 0s — initialPct calculé depuis PHASE_SECONDS
- [x] Résolution anticipée nuit/jour quand tous ont voté (VoteService)

## Phase 14 — Bugfixes critiques production (2026-06-11)
- [x] Tâche E — Migration : supprimer migration fantôme 004809, ajouter processing_day à l'ENUM
- [x] Tâche F — ProcessDayVote : double-fire corrigé via atomicité dans VoteService
- [x] Tâche G — ProcessMayorSuccession : succession nocturne sans transition de phase prématurée
- [x] Tâche H — ProcessNightEnd : fin de nuit systématique même sans voyante
- [x] Tâche I — Tests : non-régression E→H (double-fire, succession nuit, migration enum)

## Phase v1.2 — Améliorations différées
- [ ] Délai voyante : réduire de ~8s à ~5s (broadcaster SeerTurnStarted avec delay(5s) côté serveur — $watch déjà en place, setTimeout client déjà supprimé en v1.1)
- [ ] Timers configurables par partie
- [ ] Rôles v1.2 : Sorcière, Chasseur
- [ ] Rôles v1.3+ : Loup Blanc, Cupidon, Petite Fille
- [ ] ProcessMayorSuccession : flag `shouldStartNight` pour distinguer mort nuit vs mort jour

## BUGS CONNUS
→ Voir BUGS_AND_ROADMAP.md (source de vérité unique pour les bugs)