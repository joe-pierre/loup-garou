# TODO — PROGRESSION

## Légende
- [ ] À faire
- [~] En cours
- [x] Terminé
- [!] Bug connu

## Phase 1 — Base
- [ ] Migrations (users, games, game_players, game_actions, chat_messages, exclusions)
- [ ] Modèles + relations + scopes
- [ ] Factories + Seeders
- [ ] config/game.php (timers, rôles)

## Phase 2 — Auth
- [ ] Google OAuth (GoogleController)
- [ ] Routes auth
- [ ] Middleware auth

## Phase 3 — Lobby
- [ ] POST /game (création)
- [ ] POST /game/{code}/join
- [ ] POST /game/{id}/exclude/{playerId}
- [ ] POST /game/{id}/ready
- [ ] Event PlayerJoined
- [ ] Event PlayerExcluded
- [ ] RoleDistributor (avec config extensible)
- [ ] Démarrage automatique quand max_players atteint

## Phase 4 — WebSocket Setup
- [ ] Reverb config
- [ ] Echo config (frontend)
- [ ] Channel definitions (routes/channels.php)
- [ ] Broadcasting Auth (loups, joueur individuel)

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