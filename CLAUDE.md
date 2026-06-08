# Loup-Garou Undu — Contexte projet Claude Code (PHP 8.3+)

## Prompt d'amorçage (automatique à chaque session)

Lis ces fichiers dans l'ordre avant de faire quoi que ce soit :
- `SPEC.md`
- `CONVENTIONS.md`
- `TODO.md`
- `DECISIONS.md`
- `CODE_SNAPSHOT.md` (index structurel pour économiser les tokens)

Dis-moi ce que tu as compris du projet en 5 points clés, puis attends mes instructions.

À la FIN de chaque tâche, avant de dire "terminé" :
1. Détermine si un ajout dans `DECISIONS.md` est justifié (bug non évident, choix technique, contournement)
2. Si oui → écris l'entrée dans `DECISIONS.md` en respectant le format défini ci-dessous
3. Si non → dis explicitement "Rien à ajouter dans DECISIONS.md"
4. Mets à jour `TODO.md` : coche `[x]` les tâches terminées
5. Ajoute tout bug simple ou idée d'amélioration dans `BUGS_AND_ROADMAP.md` (respecte le format)

## Formats des fichiers Markdown modifiables par Claude Code

### `TODO.md`
Checklist Markdown standard. Marqueurs autorisés :
- `- [ ]` à faire · `- [x]` terminé · `- [~]` en cours · `- [!]` bug connu
Ne pas inventer d'autres marqueurs.

### `DECISIONS.md`
Chaque nouvelle entrée doit suivre exactement ce modèle :

## [RÉSOLU | CHOIX] Titre court
**Contexte :** (tâche, fichiers concernés)
**Symptôme / Problème :** (ce qui s'est produit ou le dilemme)
**Cause / Alternatives :** (pourquoi, options envisagées)
**Fix / Décision :** (ce qui a été retenu)
**Leçon :** (règle générale pour la suite)
**Statut :** ✅ Résolu | 🔵 Choix assumé

### `BUGS_AND_ROADMAP.md`
Deux sections obligatoires. Toujours respecter exactement ce format :

# BUGS CORRIGÉS

### [x] YYYY-MM-DD — Titre court

- **Symptôme :** ce qui s'est produit
- **Cause :** pourquoi
- **Fix :** ce qui a été appliqué

# ROADMAP (idées / améliorations futures)
- [ ] Description de l'idée ou de l'amélioration

Règles :
- Date au format `YYYY-MM-DD`
- Un bloc par bug, séparé par une ligne vide
- ROADMAP : une ligne par idée, pas de bloc narratif

## Règles complémentaires

- `CODE_SNAPSHOT.md` est généré par l'utilisateur (script externe). **Claude Code ne doit jamais le modifier.** Il le lit uniquement pour comprendre la structure du code.
- Ne pas dupliquer les informations : un bug complexe avec analyse va dans `DECISIONS.md` ; un bug simple (typo, oubli d'import) va dans `BUGS_AND_ROADMAP.md` section "BUGS CORRIGÉS".
- Toujours lire `CODE_SNAPSHOT.md` avant d'entamer une modification pour cibler uniquement les fichiers nécessaires (économie de tokens).
- `WORKFLOW.md` est destiné au développeur uniquement. **Ne jamais le lire ni le modifier.**

## Commandes utiles
```bash
php artisan migrate:fresh --seed
php artisan queue:work
php artisan reverb:start
npm run dev
php artisan test
```

## Architecture — règles strictes
- Logique métier UNIQUEMENT dans app/Services/
- Controllers : valider request + appeler Service, rien d'autre
- Jobs : gestion des timers uniquement
- Utiliser Gates/Policies pour toutes les autorisations
- Lire DECISIONS.md en début de session pour éviter de reproduire les bugs connus

## Réponses API
Toujours : { "success": true|false, "data": {}, "message": "" }
HTTP : 200/201 succès | 422 validation | 403 interdit | 409 conflit métier
FormRequest dédié pour chaque endpoint — jamais valider dans le Controller

## Services clés
- GameService.php — orchestration générale
- RoleDistributor.php — distribution rôles (extensible v1.2)
- PhaseManager.php — transitions de phases (prévoir hooks before/after)
- VoteService.php — votes maire, jour, loups
- WinConditionChecker.php — vérifications post-élimination
- ChatService.php — visibilité messages

## Modèles — méthodes importantes à toujours implémenter
- GamePlayer::isWerewolf() → role IN ('werewolf', 'white_wolf')
  ⚠️ `isWerewolf()` inclut intentionnellement 'white_wolf' bien que cette valeur soit absente
  de l'enum DB en v1.1 et v1.2. C'est une anticipation v1.3+. Ne pas supprimer et ne pas
  ajouter la valeur à l'enum avant la v1.3.
- GamePlayer::isVillagerSide() → role IN ('villager', 'seer', 'witch', 'hunter')
  ⚠️ `witch` et `hunter` absents de l'enum DB en v1.1. Anticipation v1.2.
  Ne pas ajouter à l'enum avant la v1.2.
- GameAction::scopeAnonymized() → select() toutes colonnes SAUF player_id
    NE PAS utiliser whereNotIn — toutes les lignes sont retournées, seul player_id est exclu

## Channels WebSocket
- game.{gameId} — public, tous les joueurs
- game.{gameId}.werewolves — privé, loups uniquement
- game.{gameId}.player.{playerId} — privé, joueur individuel

## Détection déconnexion
Reverb n'expose pas d'événement serveur natif de déconnexion client.
Solution : PresenceChannel("game.{gameId}.presence") côté Reverb
+ POST /game/{id}/disconnect déclenché par window.addEventListener('beforeunload') côté client
CheckReconnectionTimeout dispatché avec delay 30s après déconnexion détectée

## Timers (v1.1)
Timers gérés via `TimerCalculator` (app/Services/TimerCalculator.php) et accessibles
via `$game->timer('phase_name')`. Ne pas utiliser `config('game.timers.x')` directement.
Tous les timers sauf TIMER_RECONNECTION et TIMER_READY_TIMEOUT sont configurables
par le host depuis la waiting-room (v1.2).

- Élection maire : 30s
- Révélation maire : 5s
- Action voyante : 30s
- Action loups : 30s
- Succession maire : 15s
- Débat + vote jour : 90s
- Reconnexion : 30s  ← fixe, non configurable (contrainte technique, pas gameplay)
- Ready timeout : 60s  ← fixe, non configurable (attente écran révélation rôle)

## Méthodes utilitaires
- `$game->timer('mayor_election')` → secondes du timer
- `$game->phaseRemainingSeconds()` → secondes restantes dans la phase courante
- `Game::timer()` → accès générique aux timers via TimerCalculator

## Architecture Alpine.js — Règles critiques

`game-state.js` est le **seul store source de vérité** pour : `role`, `allies`, `phase`,
`players[]`, `mayorId`.

**Règles :**
- Les vues ne doivent pas dupliquer ces propriétés dans un store local
- Pour réagir à un event WebSocket reçu après chargement : utiliser `$watch`, **jamais** `setTimeout`
- Toute variable Blade injectée dans un store Alpine risque d'être `null` si la vue se charge après un changement d'état — voir CONVENTIONS.md §Partials Alpine

**Propriétés publiques du store `gameState` :**
`role`, `allies`, `phase`, `players[]`, `mayorId`, `isConnected`

**Méthodes publiques :**
`syncRole()`, `handleSeerTurnReady()`, `handleWerewolvesTurnReady()`

**Flag `seerWatchTriggered` dans `night.blade.php` :** guard anti-double exécution du
`$watch` sur `pendingSeerEvent`. Ne pas supprimer — sans ce flag, `handleSeerTurnReady()`
peut être appelé deux fois si Alpine réévalue le `$watch`.

## Règles métier critiques (ne jamais oublier)
- Un joueur ne vote pas pour lui-même (sauf élection maire)
- Les loups ne votent pas pour un autre loup
- La voyante ne s'inspecte pas elle-même
- Vérifier la phase côté serveur avant toute action
- Vote maire : is_mayor = true, weight = 2 dans game_actions (day_vote uniquement)
- Égalité vote jour → personne éliminé
- Égalité vote loups/maire → aléatoire parmi ex-aequo
- > 50% inactifs → partie annulée (winner_team = null, rôles non révélés)
- Quitter depuis waiting-room → DELETE game_players (pas quitGame())
- Quitter depuis une vue de jeu → quitGame() → is_alive = false

## Distribution rôles (RoleDistributor)
Lit `$game->settings['roles']` en priorité, fallback sur `config('game.roles')`.
Ne jamais hardcoder la composition dans RoleDistributor.

Villageois = toujours fill (max_players - tous les autres). Non configurable par le host.

Validation serveur (GameService::validateRoleSettings()) :
- nb_loups >= 1 et <= Max loups défini dans le tableau de fourchettes (SPEC.md §4)
- chaque rôle spécial : 0 ou 1 max
- villageois résultants >= 1
- Modifiable uniquement si status = waiting

v1.1 par défaut : 6j→1L|4V, 8j→2L|5V, 10j→2L|7V, 12j→3L|8V (toujours 1 voyante)

## Versioning
- v1.1 (en cours) : Villageois, Loup-Garou, Voyante, Maire électif
- v1.2 (anticiper) : Sorcière, Chasseur — timers + composition rôles configurables par le host (waiting-room)
- v1.3+ (ne pas anticiper) : Loup Blanc, Cupidon, Petite Fille

## État d'avancement
→ Voir TODO.md (source de vérité unique pour les tâches)
→ Voir TASK_PROMPTS_REMAINING.md (prompts des tâches A→D restantes à exécuter)
→ Voir DECISIONS.md (bugs résolus + décisions techniques)