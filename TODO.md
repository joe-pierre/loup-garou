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

## Phase 3 — Lobby
- [x] POST /game (création)
- [x] POST /game/{code}/join
- [x] POST /game/{id}/exclude/{playerId}
- [ ] POST /game/{id}/ready
- [x] Event PlayerJoined
- [x] Event PlayerExcluded
- [ ] RoleDistributor (avec config extensible)
- [ ] Démarrage automatique quand max_players atteint
- [x] GET /game/{code}/lobby (salle d'attente)
- [x] Vue waiting-room.blade.php (Écran 4)

## Phase 4 — WebSocket Setup
- [x] Reverb config (config/broadcasting.php)
- [x] Echo config (resources/js/echo.js)
- [x] Channel definitions (routes/channels.php)
- [x] Broadcasting Auth (loups, joueur individuel)

## Phase 5 — Élection Maire
- [ ] POST /game/{id}/vote/mayor
- [ ] Job ProcessMayorElection (timer 30s)
- [ ] VoteService::processMayorVote()
- [ ] VoteService::resolveMayorElection()
- [ ] Event MayorElectionStarted
- [ ] Event MayorVoteCast
- [ ] Event MayorElected

## Phase 6 — Nuit
- [ ] POST /game/{id}/seer/check
- [ ] POST /game/{id}/vote/night
- [ ] Job ProcessNightActions (timer 30s par action)
- [ ] Event NightStarted
- [ ] Event SeerTurnStarted + SeerResult
- [ ] Event WerewolvesTurnStarted + WerewolvesVoteCast
- [ ] Event WerewolfChatMessage
- [ ] Chat loups (channel werewolves)

## Phase 7 — Jour
- [ ] POST /game/{id}/vote/day
- [ ] POST /game/{id}/mayor/succession
- [ ] Job ProcessDayVote (timer 90s)
- [ ] Event DayStarted
- [ ] Event DayVoteCast
- [ ] Event PlayerEliminated / NoElimination
- [ ] Event MayorSuccessionStarted + MayorSuccessionDone
- [ ] WinConditionChecker

## Phase 8 — Déconnexion
- [ ] Détection déconnexion (Reverb presence channel)
- [ ] Job CheckReconnectionTimeout (30s)
- [ ] is_inactive logic
- [ ] Annulation si > 50% inactifs
- [ ] GET /game/{code}/state (reconnexion)

## Phase 9 — Fin de partie
- [ ] Event GameFinished
- [ ] Push notifications (fin de partie, mort, exclusion)
- [ ] Scheduler CleanOldGames

## Phase 10 — UI
- [ ] Écrans Blade (liste dans SPEC.md)
- [ ] Alpine.js composants
- [ ] GSAP animations
- [ ] Responsive

## BUGS CONNUS
(remplir au fur et à mesure)