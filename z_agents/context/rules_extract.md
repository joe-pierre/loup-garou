# Règles du projet Loup-Garou Undu — Contexte agent

## Architecture obligatoire
- Logique métier : UNIQUEMENT dans app/Services/ — jamais dans les Controllers ni les Jobs
- Controllers : valider la request + appeler le Service, RIEN D'AUTRE
- Jobs : gestion des timers uniquement

## Timers — règle absolue
- TOUJOURS via `$game->timer('nom_interne')` — JAMAIS `config('game.timers.x')` directement
- TimerCalculator lit `$game->settings['timers']` en priorité, fallback config/game.php
- Exception autorisée : `config('game.timers.limits')` dans validateTimerSettings() uniquement

## Transactions — règles absolues
- canTransition() autorisé dans lockForUpdate()
- applyTransition() INTERDIT dans lockForUpdate() — risque de deadlock MySQL
- Broadcasts (broadcast()) INTERDIT dans DB::transaction() — risque de rollback si Reverb lent
- Dispatch de jobs INTERDIT dans DB::transaction() sauf avec delay > 0

## Alpine.js — règles absolues
- Tout init() avec window.addEventListener() DOIT commencer par :
    if (this._initialized) return; this._initialized = true;
- window.dispatchEvent() pour events cross-composants — JAMAIS $dispatch()
- gameState() est l'unique source de vérité pour role, allies, phase, players[], mayorId
- Un seul composant s'abonne à Echo par canal — jamais deux composants sur le même canal

## Réponses API
- Format : { "success": true|false, "data": {}, "message": "" }
- HTTP : 200/201 succès | 422 validation | 403 interdit | 409 conflit métier

## Canaux WebSocket
- game.{gameId} — public, tous les joueurs
- game.{gameId}.werewolves — privé, loups uniquement
- game.{gameId}.player.{playerId} — privé, joueur individuel
- SeerResult et WerewolvesTurnStarted : canaux privés UNIQUEMENT — jamais sur le canal public