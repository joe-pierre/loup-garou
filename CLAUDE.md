# Loup-Garou Undu — Contexte projet Claude

## Stack
- Laravel 11.* (PHP 8.3+) + MySQL 8+
- Laravel Reverb (WebSocket) + Laravel Echo (client)
- Blade + Tailwind CSS + Alpine.js + GSAP
- Auth : Google OAuth (Socialite)
- Queue : database (dev) / Redis (prod)
- Push : laravel-notification-channels/webpush

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
- GamePlayer::isVillagerSide() → role IN ('villager', 'seer', 'witch', 'hunter')
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

## Timers (v1.1 — valeurs fixes, prévoir config pour v1.2)
- Élection maire : 30s
- Action voyante : 30s
- Action loups : 30s
- Succession maire : 15s
- Débat + vote jour : 90s
- Reconnexion : 30s

## Règles métier critiques (ne jamais oublier)
- Un joueur ne vote pas pour lui-même (sauf élection maire)
- Les loups ne votent pas pour un autre loup
- La voyante ne s'inspecte pas elle-même
- Vérifier la phase côté serveur avant toute action
- Vote maire : is_mayor = true, weight = 2 dans game_actions (day_vote uniquement)
- Égalité vote jour → personne éliminé
- Égalité vote loups/maire → aléatoire parmi ex-aequo
- > 50% inactifs → partie annulée (winner_team = null, rôles non révélés)

## Distribution rôles (RoleDistributor)
- Toujours 1 Voyante
- Loups ≈ 20% (arrondi inf, min 1)
  - 6j → 1L | 8j → 2L | 10j → 2L | 12j → 3L
- Utiliser pattern Strategy ou config array pour extensibilité v1.2

## Versioning
- v1.1 (en cours) : Villageois, Loup-Garou, Voyante, Maire électif
- v1.2 (anticiper) : Sorcier, Cupidon, Loup Blanc, timers/joueurs customisables

## État d'avancement
→ Voir TODO.md (source de vérité unique)
→ Voir DECISIONS.md (bugs résolus + décisions techniques)