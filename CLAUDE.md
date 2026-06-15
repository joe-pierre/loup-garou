# Loup-Garou Undu — Contexte projet Claude Code (PHP 8.3+)

## Prompt d'amorçage (automatique à chaque session)

Lis ces fichiers dans l'ordre avant de faire quoi que ce soit :
- `SPEC.md`
- `SPEC_TIMERS.md`
- `SPEC_TRANSITIONS.md`
- `CONVENTIONS.md`
- `TODO.md`
- `DECISIONS.md`
- `CODE_SNAPSHOT.md` (index structurel pour économiser les tokens)
- `RISK_GUARDS.md` (protections anti-bugs obligatoires — lire avant Étapes 3, 4, 5)

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

- `WORKFLOW.md` est destiné au développeur uniquement. **Ne jamais le lire ni le modifier.**
- Ne jamais travailler directement sur `dev` ou `main`. Chaque tâche démarre sur une branche dédiée (indiquée dans TASK_PROMPTS_REMAINING.md).
- Ne jamais merger une branche — c'est la responsabilité du développeur.
- `CODE_SNAPSHOT.md` est généré par l'utilisateur (script externe). **Claude Code ne doit jamais le modifier.** Il le lit uniquement pour comprendre la structure du code.
- Ne pas dupliquer les informations : un bug complexe avec analyse va dans `DECISIONS.md` ; un bug simple (typo, oubli d'import) va dans `BUGS_AND_ROADMAP.md` section "BUGS CORRIGÉS".
- Toujours lire `CODE_SNAPSHOT.md` avant d'entamer une modification pour cibler uniquement les fichiers nécessaires (économie de tokens).

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
- PhaseManager.php — transitions de phases + hooks
- VoteService.php — votes maire, jour, loups, seer, witch, hunter
- WinConditionChecker.php — vérifications post-élimination
- ChatService.php — visibilité messages
- TimerCalculator.php — accès aux timers (settings en priorité, fallback config)

## Modèles — méthodes importantes
- GamePlayer::isWerewolf() → role IN ('werewolf', 'white_wolf')
  ⚠️ white_wolf absent de l'enum DB en v1.1 et v1.2 — anticipation v1.3+.
- GamePlayer::isVillagerSide() → role IN ('villager', 'seer', 'witch', 'hunter')
  ⚠️ witch et hunter ajoutés à l'enum en v1.2 (Étape 4).
- GameAction::scopeAnonymized() → select() toutes colonnes SAUF player_id
  NE PAS utiliser whereNotIn.

## Channels WebSocket
- game.{gameId} — public, tous les joueurs
- game.{gameId}.werewolves — privé, loups uniquement
- game.{gameId}.player.{playerId} — privé, joueur individuel

## Timers
Toujours via `$game->timer('nom_interne')` — jamais `config('game.timers.x')` directement.
TimerCalculator lit `$game->settings['timers']` en priorité, fallback config/game.php.
Timers fixes (jamais surchargés) : reconnection, ready_timeout, night_start_delay, mayor_reveal.
Voir SPEC_TIMERS.md §4 pour le tableau complet v1.1 et v1.2.

## Timers — pattern action volontaire / job auto (v1.2)
Voir SPEC_TIMERS.md §2 et §3 pour l'implémentation complète.
Résumé :
- Endpoint POST /game/{id}/X/done → résolution immédiate, dispatch ProcessWerewolvesTurn delay(0)
- Job ProcessXAutoAction dispatché avec delay($game->timer('X')) depuis ProcessXTurn
- Guard : vérifier existence de X_check en base avant d'agir (si existant → return)
- ProcessSeerAutoAction : si inactif → NE PAS créer de seer_check, dispatch wolves directement

## Annonces de phases (v1.2)
Voir SPEC_TRANSITIONS.md pour l'implémentation complète.
Résumé :
- PhaseAnnouncement broadcasté sur canal public avant chaque event de phase
- seer_turn et werewolves_turn jamais publics
- File announcements[] dans gameState, consommation FIFO via consumeAnnouncements()
- Events à différer pendant overlay : NightStarted, DayStarted, MayorElected
- Events immédiats : GameFinished, PlayerDisconnected, PlayerInactive, chat, votes

## Symfony Workflow (v1.2 — Étape 2)
- `$game->canTransition('X')` → guard de validation (utilisable dans lockForUpdate())
- `$game->applyTransition('X')` → applique + save() — jamais dans lockForUpdate()
- Statuts principaux couverts : waiting, electing_mayor, night, day, finished
- Statuts intermédiaires hors Workflow : processing_night, processing_day, wolves_turn

## Architecture Alpine.js — Règles critiques
`game-state.js` est le seul store source de vérité pour :
`role`, `allies`, `phase`, `players[]`, `mayorId`, `isConnected`,
`announcements`, `isAnnouncing` (v1.2).

Règles :
- Les vues ne dupliquent pas ces propriétés dans un store local
- `$watch` pour réagir aux events WebSocket, jamais `setTimeout`
- `window.dispatchEvent` pour les events cross-composants, jamais `$dispatch()`
- `const GAME_ID = @json($game->id)` en haut de chaque partial avec script

### Guard obligatoire dans init() Alpine

Tout composant Alpine qui enregistre des listeners via `window.addEventListener()`
dans son `init()` DOIT commencer par :

```js
if (this._initialized) return;
this._initialized = true;
```

Sans ce guard, Alpine peut déclencher `init()` plusieurs fois et empiler les listeners
— chaque event window sera capturé autant de fois que `init()` a été appelé.

## Règles métier critiques (ne jamais oublier)
- Un joueur ne vote pas pour lui-même (sauf élection maire)
- Les loups ne votent pas pour un autre loup
- La voyante ne s'inspecte pas elle-même
- La sorcière ne peut pas s'auto-sauver (v1.2)
- Vérifier la phase côté serveur avant toute action
- Vote maire : weight = 2 dans game_actions (day_vote uniquement)
- Égalité vote jour → personne éliminé
- > 50% inactifs → partie annulée (winner_team = null, rôles non révélés)
- Quitter depuis waiting-room → DELETE game_players (pas quitGame())

## Distribution rôles (RoleDistributor)
Lit `$game->settings['roles']` en priorité, fallback sur `config('game.roles')`.
Ne jamais hardcoder la composition dans RoleDistributor.
Villageois = toujours fill. Non configurable par le host.
Rôles spéciaux (seer, witch, hunter) : 0 ou 1 max chacun.

## Versioning
- v1.1 ✅ : Villageois, Loup-Garou, Voyante, Maire électif
- v1.2 (en cours) : Sorcière, Chasseur — timers + composition rôles configurables
- v1.3+ (ne pas anticiper) : Loup Blanc, Cupidon, Petite Fille

## État d'avancement
→ Voir TODO.md (source de vérité unique pour les tâches)
→ Voir TASK_PROMPTS_REMAINING.md (prompts des Étapes 2→5)
→ Voir DECISIONS.md (bugs résolus + décisions techniques)
→ Voir SPEC_TIMERS.md (timers, pattern action volontaire / job auto)
→ Voir SPEC_TRANSITIONS.md (annonces de phases, file Alpine, reconnexion)