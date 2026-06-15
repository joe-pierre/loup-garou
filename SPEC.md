# SPEC.md — Loup-Garou en Ligne (v1.1)
> Fichier de contexte pour Claude Code. Lire intégralement avant toute implémentation.

---

## 1. VUE D'ENSEMBLE DU PROJET

Jeu Loup-Garou multijoueur en temps réel. Les joueurs se connectent via Google OAuth, créent ou rejoignent une partie, et jouent des phases de nuit/jour alternées jusqu'à la victoire d'un camp.

**URL de prod cible :** `https://ton-domaine.com`
**Nom app :** `Loup-Garou Undu`

---

## 2. STACK TECHNIQUE

| Composant | Technologie |
|---|---|
| Backend | Laravel 11 (PHP 8.3+) |
| WebSocket | Laravel Reverb |
| Client WebSocket | Laravel Echo + pusher-js |
| Frontend | Blade + Alpine.js + GSAP |
| CSS | Tailwind CSS |
| BDD | MySQL 8+ |
| Auth | Google OAuth (Laravel Socialite) |
| Push Notifications | laravel-notification-channels/webpush |
| Queue (dev) | database |
| Queue (prod) | Redis |
| Scheduler | Laravel Scheduler |

---

## 3. MODÈLE DE DONNÉES

### Table `users`
```
id | google_id (unique) | email (unique) | name | created_at | updated_at
```

### Table `games`
```
id | code (6 chars, unique) | status (enum) | max_players | round (default 0)
   | phase_deadline (nullable timestamp) | winner_team (enum, nullable)
   | settings (json, nullable)
   | started_at (nullable) | finished_at (nullable) | created_at | updated_at

status: waiting | electing_mayor | night | day | finished
winner_team: villagers | werewolves | null
null = partie en cours OU annulée (status = finished + winner_team = null → annulée)
```

### Table `game_players`
```
id | game_id (FK) | user_id (FK) | pseudo | role (enum, nullable)
   | is_alive (bool, default true) | is_host (bool) | is_mayor (bool)
   | is_inactive (bool) | is_ready (bool) | joined_at

role: villager | werewolf | seer | witch | hunter | null
⚠️ white_wolf absent en v1.1 et v1.2 — anticipation v1.3+.
```

### Table `game_actions`
```
id | game_id (FK) | player_id (FK game_players) | type (enum)
   | weight (tinyint, default 1) | target_player_id (FK, nullable)
   | round | phase (enum) | created_at

type: mayor_vote | night_vote | day_vote | seer_check | mayor_succession |
      werewolf_chat | ready | witch_heal | witch_kill | witch_pass | hunter_shot
phase: election | night | day
weight = 2 si maire (day_vote uniquement)
```

### Table `chat_messages`
```
id | game_id (FK) | player_id (FK) | message (text) | channel (enum)
   | round | phase (enum) | created_at

channel: general | werewolves | dead
```

### Table `exclusions`
```
id | game_id (FK cascadeOnDelete) | user_id (FK users, cascadeOnDelete)
   | player_id (FK game_players, nullOnDelete, nullable)
   | reason (text) | excluded_at
```

### Index importants
- `games.code` → unique
- `game_players.(game_id, user_id)` → unique
- `game_actions.(game_id, round, phase)` → index

---

## 4. RÈGLES MÉTIER CRITIQUES

### Création de partie
- `max_players` : parmi `[6, 8, 10, 12]`
- Code 6 chars alphanumériques unique (retry si collision)
- Créateur = host (`is_host = true`)
- `status` initial = `waiting`

### Rejoindre une partie
- Uniquement si `status = waiting`
- Uniquement si `count < max_players`
- Vérifier table `exclusions` (joueur banni ne peut pas rejoindre)
- **Race condition** : utiliser `lockForUpdate()` en transaction pour éviter de dépasser `max_players`
- Quand `count == max_players` → démarrage automatique

### Distribution des rôles
Gérée par RoleDistributor. Lit games.settings['roles'] en priorité,
fallback sur config/game.php.

v1.1 (valeurs par défaut si settings null) :
6 joueurs  → 1 loup, 1 voyante, 4 villageois
8 joueurs  → 2 loups, 1 voyante, 5 villageois
10 joueurs → 2 loups, 1 voyante, 7 villageois
12 joueurs → 3 loups, 1 voyante, 8 villageois
Formule loups (valeur par défaut) : floor(n * 0.25), min 1.
Max configurable par le host : voir tableau de fourchettes ci-dessous.
Ces deux valeurs sont distinctes — la formule donne le défaut, le max définit la fourchette haute.

v1.2 : host configure depuis la waiting-room (status = waiting).
Villageois = toujours calculé automatiquement (fill) : non configurable.

Règles de validation serveur (GameService, status = waiting uniquement) :
- nb_loups >= 1
- nb_loups <= Max loups du tableau de fourchettes ci-dessous
- chaque rôle spécial (voyante, sorcière, chasseur...) : 0 ou 1 exemplaire max
- villageois résultants >= 1  →  max_players - loups - spéciaux >= 1
- total = max_players exactement (garanti par le fill villageois)

Fourchettes par nombre de joueurs :
| Joueurs | Min loups | Max loups |
|---------|-----------|-----------|
| 6       | 1         | 2         |
| 8       | 1         | 2         |
| 10      | 2         | 3         |
| 12      | 2         | 3         |


### Timers (v1.1 fixes, configurables par le host en v1.2 sauf TIMER_RECONNECTION et TIMER_READY_TIMEOUT)
```
TIMER_MAYOR_ELECTION   = 30s
TIMER_SEER             = 30s
TIMER_WEREWOLVES       = 30s
TIMER_MAYOR_SUCCESSION = 15s
TIMER_DAY_VOTE         = 90s
TIMER_RECONNECTION  = 30s  ← fixe, non configurable (contrainte technique, pas gameplay)
TIMER_READY_TIMEOUT = 60s  ← fixe, non configurable
```

### Phase nuit — ordre des actions
1. Voyante agit (30s) → `SeerTurnStarted` sur channel privé voyante
2. Loups agissent (30s) → `WerewolvesTurnStarted` sur channel loups

### Votes — règles générales
- Égalité → sélection **aléatoire** parmi les ex-aequo (sauf vote jour → personne éliminé)
- Vote jour en égalité → `NoElimination` (reason: `equality`)
- 0 votes jour → élimination aléatoire parmi les vivants (RandomElimination event)
- Maire : `weight = 2` sur `day_vote` uniquement
- Loups ne peuvent pas voter pour un autre loup (nuit)
- Joueur ne peut pas voter pour lui-même (jour) — autorisé pour l'élection maire

### Conditions de victoire (vérifiées après chaque mort)
```
Loups gagnent : nb_loups_vivants >= nb_autres_vivants
Village gagne : nb_loups_vivants == 0
```

### Succession maire
- Déclenché si le maire meurt (nuit OU jour)
- Timer 15s pour que le maire mort désigne son successeur
- Si inactif ou timer expiré → successeur aléatoire parmi vivants

### Chat — règles de visibilité
```
general   : écriture = joueurs vivants, phases [electing_mayor, day]
            lecture  = tous (vivants + morts)
werewolves: écriture = loups vivants, phase night uniquement
            lecture  = loups (vivants + morts ex-loups)
```

### Anonymat des votes
- `game_actions` stocke `player_id` (nécessaire pour la logique serveur)
- `GET /game/{code}/history` → utiliser `scope anonymized()` sur `GameAction`
- Events WS `DayVoteCast` et `MayorVoteCast` → uniquement totaux par cible, jamais l'auteur

### Déconnexion / Inactivité
- Déconnexion détectée → broadcaster `PlayerDisconnected`, dispatcher `CheckReconnectionTimeout` avec delay 30s
- Après 30s sans reconnexion → `is_inactive = true`, broadcaster `PlayerInactive`
- Reconnexion → `is_inactive = false`, broadcaster `PlayerReconnected`
- Si > 50% joueurs inactifs simultanément → partie annulée (`status = finished`, `winner_team = null`)
- Maire inactif pendant succession → désignation aléatoire **immédiate** (pas d'attente 15s)

### Exclusion par le host
- Uniquement en phase `waiting`
- Motif obligatoire (texte, max 500 chars)
- Joueur supprimé de `game_players`, entrée dans `exclusions`
- Event WS public (pseudo) + privé joueur exclu (motif)

---

## 5. ARCHITECTURE CODE

```
app/
├── Console/Commands/CleanOldGames.php
├── Events/Game/                    ← tous les events WebSocket
├── Http/Controllers/
│   ├── Auth/GoogleController.php
│   └── Game/
│       ├── GameController.php      ← history, state
│       ├── LobbyController.php     ← create, join, ready, exclude
│       ├── VoteController.php      ← mayor, day, night
│       ├── ChatController.php
│       └── ActionController.php   ← seer, mayorSuccession
├── Jobs/
│   ├── ProcessNightActions.php
│   ├── ProcessDayVote.php
│   ├── ProcessMayorElection.php
│   ├── ProcessMayorSuccession.php
│   └── CheckReconnectionTimeout.php
├── Models/
│   ├── User.php | Game.php | GamePlayer.php
│   ├── GameAction.php | ChatMessage.php | Exclusion.php
├── Notifications/
│   ├── GameStartedNotification.php
│   ├── PlayerKilledNightNotification.php
│   ├── PlayerEliminatedDayNotification.php
│   ├── GameFinishedNotification.php
│   └── PlayerExcludedNotification.php
├── Policies/GamePolicy.php
└── Services/
    ├── GameService.php             ← orchestration principale
    ├── RoleDistributor.php         ← distribution rôles (extensible v1.2)
    ├── PhaseManager.php            ← transitions de phases + hooks
    ├── VoteService.php             ← logique votes (résolution, ex-aequo)
    ├── WinConditionChecker.php
    └── ChatService.php
```

### Principes d'architecture
- **Toute logique métier dans les Services** — jamais dans les Controllers
- Controllers : valider la request + appeler le Service
- Jobs : gérer les timers (dispatched avec `delay`)
- Policies : accès à l'historique, channels WS privés
- `RoleDistributor` : pattern tableau de configuration pour extensibilité v1.2

---

## 6. ÉVÉNEMENTS WEBSOCKET

### Channels
```
game.{gameId}                    → public (tous les joueurs de la partie)
game.{gameId}.werewolves         → privé (loups uniquement)
game.{gameId}.player.{playerId}  → privé (joueur individuel)
```

### Autorisation channels (`routes/channels.php`)
```php
// game.{gameId} → joueur appartient à la partie
// game.{gameId}.werewolves → joueur est loup (isWerewolf())
// game.{gameId}.player.{playerId} → l'user est ce joueur
```

### Liste complète des events

| Event | Channel | Payload clé |
|---|---|---|
| `PlayerJoined` | game.{id} | pseudo, players_count, slots_remaining |
| `GameStarted` | game.{id} + privé chaque joueur | global: liste joueurs sans rôles / individuel: rôle + loups alliés si loup |
| `PlayerReady` | game.{id} | nb_ready / total |
| `MayorElectionStarted` | game.{id} | timer |
| `MayorVoteCast` | game.{id} | votes par cible (anonyme) |
| `MayorElected` | game.{id} | joueur élu, was_random |
| `NightStarted` | game.{id} | round, timer |
| `SeerTurnStarted` | privé voyante | timer |
| `SeerResult` | privé voyante | joueur inspecté, rôle |
| `WerewolvesTurnStarted` | werewolves | timer, cibles éligibles |
| `WerewolvesVoteCast` | werewolves | état vote loups |
| `WerewolfChatMessage` | werewolves | pseudo, message, timestamp |
| `DayStarted` | game.{id} | joueur(s) tué(s) la nuit avec rôle révélé, round |
| `MayorSuccessionStarted` | game.{id} | timer (15s) |
| `MayorSuccessionDone` | game.{id} | nouveau maire, was_random |
| `DayVoteCast` | game.{id} | nb votes par joueur (anonyme) |
| `PlayerEliminated` | game.{id} | joueur, rôle révélé, raison (night_kill/day_vote) |
| `NoElimination` | game.{id} | raison (equality/no_vote) |
| `ChatMessageSent` | game.{id} ou werewolves | pseudo, message, channel, timestamp |
| `PlayerDisconnected` | game.{id} | pseudo, temps restant (30s) |
| `PlayerReconnected` | game.{id} | pseudo |
| `PlayerInactive` | game.{id} | pseudo |
| `GameFinished` | game.{id} | winner_team, tous les joueurs avec rôles révélés |
| `PlayerExcluded` | game.{id} + privé joueur | global: pseudo / individuel: motif |

---

## 7. ENDPOINTS API

| Méthode | Route | Controller#method |
|---|---|---|
| POST | /game | LobbyController@create |
| POST | /game/{code}/join | LobbyController@join |
| POST | /game/{id}/ready | ActionController@ready |
| POST | /game/{id}/vote/mayor | VoteController@mayorVote |
| POST | /game/{id}/vote/day | VoteController@dayVote |
| POST | /game/{id}/vote/night | VoteController@nightVote |
| POST | /game/{id}/seer/check | ActionController@seerCheck |
| POST | /game/{id}/mayor/succession | ActionController@mayorSuccession |
| POST | /game/{id}/chat | ChatController@send |
| POST | /game/{id}/exclude/{playerId} | LobbyController@exclude |
| POST | /game/{id}/witch/act | ActionController@witchAct |
| POST | /game/{id}/hunter/shoot | ActionController@hunterShoot |
| POST | /game/{id}/settings/timers | LobbyController@updateTimers |
| POST | /game/{id}/settings/roles | LobbyController@updateRoles |
| GET | /game/{code}/state | GameController@state |
| GET | /game/{code}/history | GameController@history |

### Payload `GET /game/{code}/state`
```json
{
  "phase": "night",
  "round": 2,
  "my_role": "seer",
  "is_alive": true,
  "is_mayor": false,
  "seer_turn_active": true,
  "werewolves_turn_active": false,
  "phase_remaining_seconds": 14
}
```

---

## 8. EXTENSIBILITÉ (anticiper, ne pas implémenter)

- `RoleDistributor` : tableau de config des rôles, pas de hardcode
- `PhaseManager` : hooks `before`/`after` chaque phase pour nouveaux rôles
- Timers : configurables par le host via `games.settings['timers']` (JSON).
  Fallback `config('game.timers.x')`. TIMER_RECONNECTION et TIMER_READY_TIMEOUT : fixes, non configurables par le host.
  Accessibles via `$game->timer('phase_name')` uniquement — jamais config() directement.
- `GamePlayer::isWerewolf()` → `role IN ('werewolf', 'white_wolf')`
  ⚠️ white_wolf absent de l'enum DB en v1.1 et v1.2 — anticipation v1.3+. Ne pas ajouter à l'enum avant v1.3.
- `GamePlayer::isVillagerSide()` → `role IN ('villager', 'seer', 'witch', 'hunter')`
  ⚠️ `witch` et `hunter` absents de l'enum DB en v1.1. Anticipation v1.2.
  Ne pas ajouter à l'enum avant la v1.2.
- Ne pas hardcoder les checks `role === 'werewolf'`, toujours passer par les méthodes du modèle
- `max_players` : extensible à [14, 16, 18] en v1.3+ ou v1.4+.
   Formule défaut loups `floor(n * 0.25)` reste valide jusqu'à 18 joueurs.
   Max loups : étendre le tableau de fourchettes §4 pour les nouvelles tailles.

---

## 9. SÉCURITÉ ET VALIDATIONS SERVEUR

- Un joueur ne peut pas voter pour lui-même (jour)
- Un joueur ne peut pas voter pour un joueur mort
- La voyante ne peut pas s'inspecter elle-même
- Les loups ne peuvent pas voter pour tuer un autre loup
- Vérifier la phase courante avant d'accepter toute action
- Channels WS privés → vérification via Broadcasting Auth
- Vérifier qu'un joueur appartient à la partie avant chaque action
- Scope `anonymized()` sur `GameAction` pour l'historique public

---

## 10. IDENTITÉ VISUELLE (pour les vues Blade)

### Palette
```
#0a0f1e  → fond principal (bleu nuit profond)
#111827  → fond carte/panel
#c9a84c  → or (titres, bordures, CTAs)
#e8e0d0  → parchemin (textes courants)
#8b0000  → rouge sang (danger, mort)
#7c3aed  → violet (voyante)
#030712  → nuit profonde (phase nuit)
#16a34a  → vert (victoire village)
#f97316  → orange (timer alerte)
```

### Typographie
- Titres : **Cinzel** (Google Fonts)
- Corps : **EB Garamond** (Google Fonts)

### Animations GSAP — patterns récurrents
```js
// Entrée carte
gsap.from(el, { opacity: 0, y: 40, duration: 0.7, ease: "power2.out" })

// Timer
gsap.to(bar, { width: "0%", duration: TIMER_SECONDS, ease: "none" })
// Changement couleur : or → orange (sous 10s) → rouge (sous 5s)

// Révélation rôle (retournement carte)
gsap.to(card, { rotateY: 90, duration: 0.4, ease: "power2.in" })
// swap contenu
gsap.fromTo(card, { rotateY: -90 }, { rotateY: 0, duration: 0.4, ease: "power2.out" })

// Mort joueur
gsap.to(card, { opacity: 0.3, filter: "grayscale(100%)", duration: 0.8 })

// Transition nuit
gsap.to(body, { backgroundColor: "#030712", duration: 1.5 })

// Transition jour
gsap.to(overlay, { opacity: 0, duration: 2 })
gsap.from(sunGlow, { y: "100%", opacity: 0, duration: 2.5, ease: "power2.out" })
```

### Composants Blade réutilisables
```
<x-game-timer :seconds="30" color="gold|red|purple" />
<x-player-avatar :player="$player" size="sm|md|lg" />
<x-player-list :players="$players" :show-votes="true" :show-roles="false" />
<x-chat-panel :channel="'general'|'werewolves'" :readonly="false" />
<x-role-card :role="'villager'|'werewolf'|'seer'|'mayor'" :revealed="false" />
<x-phase-header :phase="'night'|'day'|'election'" :round="$round" />
<x-toast />
```

---

## 11. ÉCRANS (résumé des 13 écrans)

| # | Écran | Route |
|---|---|---|
| 1 | Landing Page | `/` |
| 2 | Auth Google | `/auth/google` |
| 3 | Lobby (créer/rejoindre) | `/lobby` |
| 4 | Salle d'attente | `/game/{code}/lobby` |
| 5 | Révélation du rôle | (auto après Écran 4) |
| 6 | Élection du Maire | (phase `electing_mayor`) |
| 7 | Phase Nuit — Voyante | (privé voyante, phase `night`) |
| 8 | Phase Nuit — Loups | (channel loups, phase `night`) |
| 9 | Phase Jour | (phase `day`) |
| 10 | Succession Maire | (overlay sur Écran 9) |
| 11 | Fin de partie | (phase `finished`) |
| 12 | Spectateur (joueur mort) | (is_alive = false) |
| 13 | Historique | `/game/{code}/history` |

### Transitions principales
```
[4] max_players atteint  → [5] Révélation rôle (auto)
[5] "Entrer"             → [6] Élection maire
[6] MayorElected         → [7/8] Phase nuit (selon rôle)
[8] timer expiré         → [9] Phase jour
[9] vote terminé         → vérif victoire → [7] round suivant OU [11] fin
[9] maire éliminé        → [10] Succession (overlay)
[9/7] GameFinished       → [11] Fin de partie
[11] mort détecté        → [12] Spectateur
```

---

## 12. TÂCHES (40 tâches, ordre suggéré)

```
1.  Migrations et modèles
2.  Auth Google (Socialite)
3.  Création de partie (Lobby)
4.  Rejoindre une partie
5.  Salle d'attente + broadcast PlayerJoined
6.  Exclusion joueur par host
7.  RoleDistributor
8.  Démarrage automatique + GameStarted
9.  Écran révélation rôle + endpoint ready
10. Setup WebSocket (Reverb + Echo + channels)
11. Élection Maire — votes
12. Élection Maire — timer et résolution
13. Phase Nuit — action Voyante
14. Phase Nuit — action Loups (vote)
15. Phase Nuit — chat loups
16. Phase Nuit — résolution + transition Jour
17. Phase Jour — chat général
18. Phase Jour — vote d'élimination
19. Phase Jour — résolution vote
20. Succession du Maire
21. Conditions de victoire (WinConditionChecker)
22. Gestion déconnexion / inactivité
23. Endpoint GET /game/{code}/state
24. Push Notifications
25. Historique de partie
26. Fin de partie (Écran 11)
27. Spectateur joueur mort (Écran 12)
28. Scheduler suppression parties
29. Layout principal + composants Blade
30. Alpine.js store central gameState
31. Landing Page (Écran 1)
32–40. Tests fonctionnels (Auth, Lobby, Rôles, Maire, Nuit, Jour, Victoire, Succession, Historique) + Recette finale
```

---

## 13. RACE CONDITIONS À SURVEILLER

| Situation | Solution |
|---|---|
| Deux joueurs rejoignent simultanément | Transaction + `lockForUpdate()` |
| Double démarrage de partie | Vérifier status avant `startGame()`, verrou DB |
| Double résolution d'une phase | Job vérifie que phase/round correspond toujours avant d'agir |
| Vote maire concurrent | Transaction pour insertion + lecture totaux |
| Vote jour + weight maire | Lire `is_mayor` au moment de l'insertion, pas avant |

---

## 14. COMMANDES DE DÉMARRAGE LOCAL

```bash
# Terminal 1
php artisan serve

# Terminal 2
php artisan reverb:start

# Terminal 3
php artisan queue:work

# Terminal 4
npm run dev
```

### Variables `.env` minimales requises
```
DB_DATABASE=loup_garou
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback
REVERB_APP_ID=loup-garou-local
REVERB_APP_KEY=loup-garou-key-local
REVERB_APP_SECRET=loup-garou-secret-local
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
```

---

## 15. NOTES IMPORTANTES POUR CLAUDE CODE

1. **Ne jamais implémenter les fonctionnalités non planifiées pour la version en cours.**
v1.2 : Sorcière, Chasseur — timers + composition rôles configurables par le host
v1.3+ : Loup Blanc, Cupidon, Petite Fille

2. **Toujours utiliser `GamePlayer::isWerewolf()`** plutôt que comparer `role === 'werewolf'` directement.

3. **Le scope `anonymized()`** sur `GameAction` doit être utilisé pour toute exposition
   publique des votes. Implémentation : `select()` en excluant `player_id` —
   NE PAS utiliser `whereNotIn`. Toutes les lignes sont retournées, seul `player_id`
   est masqué de la projection.

4. **Les payloads WebSocket** ne doivent jamais exposer le rôle d'un joueur vivant à d'autres joueurs (sauf loups entre eux, et voyante pour le résultat de son inspection).

5. **`PhaseManager`** centralise tous les timers — ne pas hardcoder les valeurs en secondes ailleurs que dans les constantes de `PhaseManager`. Les valeurs listées en §4 sont documentaires uniquement, pas des sources d'implémentation.

6. **Chaque Job** doit vérifier en début de `handle()` que la phase/status correspond encore à ce qu'il attend (un autre Job peut avoir déjà changé l'état).

7. **La suppression en cascade** (`cascadeOnDelete`) doit être configurée sur toutes les FK, sauf `exclusions.player_id` qui est `nullOnDelete` (voir DECISIONS.md tâche 27).

8.  **SEO meta tags** :
   - Title : `Loup-Garou Undu — Joue en ligne avec tes amis`
   - Description : `Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.`