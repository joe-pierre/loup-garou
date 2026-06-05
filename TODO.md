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
- [x] Écran Spectateur mort (Écran 12)
- [x] Scheduler CleanOldGames

## Phase 10 — UI
- [x] Landing Page (Écran 1)
- [x] Layout principal + composants Blade réutilisables
- [x] Alpine.js store central gameState
- [x] GSAP animations (intégrées dans composants + landing)
- [x] Écrans Blade restants (tâche 29 — night + day)
- [x] Intégration templates HTML → vues Blade @extends (waiting-room, role-reveal, mayor-election, night, day, finished)
- [x] Font Crimson Text (remplace EB Garamond) + window.gameId/playerId dans layout
- [ ] Responsive

## Phase 11 — Tests
- [x] Tests Feature Auth (GoogleAuthTest — 4 tests)
- [x] Tests Feature Game/Lobby (CreateGameTest, JoinGameTest, ExcludePlayerTest — 20 tests)

## BUGS CONNUS
- [x] Phase nuit bloquée — broadcast dans DB::transaction → fixed (PhaseManager::startDay + startNight)