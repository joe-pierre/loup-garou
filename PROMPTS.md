# PROMPTS.md — Loup-Garou en Ligne
# Un prompt par tâche, prêt à coller dans Claude Code.
# Lire SPEC.md et CONVENTIONS.md une fois au démarrage du projet, puis utiliser le prompt de la tâche courante.

---

## Prompt d'amorçage (à coller en début de CHAQUE session)

```
Lis ces fichiers dans l'ordre avant de faire quoi que ce soit :
SPEC.md
CONVENTIONS.md
TODO.md
DECISIONS.md

Dis-moi ce que tu as compris du projet en 5 points clés,
puis attends mes instructions.

À la FIN de chaque tâche, avant de dire "terminé" :
1. Détermine si un ajout dans DECISIONS.md est justifié (bug non évident, choix technique, contournement)
2. Si oui → écris l'entrée dans DECISIONS.md avant de rendre la main
3. Si non → dis explicitement "Rien à ajouter dans DECISIONS.md"
```
---

## TÂCHE 1 — Migrations et modèles de base

```
Contexte : lis SPEC.md §3 (Modèle de données) et CONVENTIONS.md.

Crée exactement les fichiers suivants, dans cet ordre :

MIGRATIONS (dans database/migrations/) :
1. create_users_table → colonnes : id, google_id (string unique), email (string unique), name (string), timestamps
2. create_games_table → colonnes : id, code (string 6, unique), status (enum : waiting|electing_mayor|night|day|finished, default waiting), max_players (unsignedInt), round (unsignedInt, default 0), phase_deadline (timestamp nullable), winner_team (enum : villagers|werewolves, nullable), started_at (timestamp nullable), finished_at (timestamp nullable), timestamps
3. create_game_players_table → colonnes : id, game_id (FK→games cascadeOnDelete), user_id (FK→users cascadeOnDelete), pseudo (string), role (enum : villager|werewolf|seer, nullable), is_alive (bool, default true), is_host (bool, default false), is_mayor (bool, default false), is_inactive (bool, default false), is_ready (bool, default false), joined_at (timestamp useCurrent). Index unique sur (game_id, user_id).
4. create_game_actions_table → colonnes : id, game_id (FK cascadeOnDelete), player_id (FK→game_players cascadeOnDelete), type (enum : mayor_vote|night_vote|day_vote|seer_check|mayor_succession|werewolf_chat|ready), weight (tinyInt, default 1), target_player_id (FK→game_players nullOnDelete, nullable), round (unsignedInt), phase (enum : election|night|day), created_at (timestamp useCurrent). Index sur (game_id, round, phase).
5. create_chat_messages_table → colonnes : id, game_id (FK cascadeOnDelete), player_id (FK→game_players cascadeOnDelete), message (text), channel (enum : general|werewolves), round (unsignedInt), phase (enum : election|night|day), created_at (timestamp useCurrent)
6. create_exclusions_table → colonnes : id, game_id (FK cascadeOnDelete), player_id (FK→game_players cascadeOnDelete), reason (text), excluded_at (timestamp useCurrent)

MODÈLES (dans app/Models/) :
- User.php : fillable [google_id, email, name], relation gamePlayers()
- Game.php : fillable, casts (phase_deadline/started_at/finished_at en datetime), relations players(), alivePlayers(), actions(), messages(), exclusions(). Méthode phaseRemainingSeconds(): int
- GamePlayer.php : fillable, casts (booléens), $timestamps = false, relations game(), user(), actions(). Méthodes : isWerewolf() → true si role IN ['werewolf','white_wolf'], isVillagerSide() → true si role IN ['villager','seer','witch','hunter']
- GameAction.php : fillable, $timestamps = false, relations player(), target(). Scope scopeAnonymized() : retourne une query avec select() listant toutes les colonnes SAUF player_id, sans whereNotIn (toutes les lignes sont retournées, seul player_id est masqué)
- ChatMessage.php : fillable, $timestamps = false, relations player(), game()
- Exclusion.php : fillable, $timestamps = false, casts excluded_at

FACTORIES (dans database/factories/) :
- Une factory par modèle avec des valeurs cohérentes (ex: GameFactory génère un code 6 chars uppercase)

CONFIG :
- config/game.php → tableau avec les timers (TIMER_MAYOR_ELECTION=30, TIMER_SEER=30, TIMER_WEREWOLVES=30, TIMER_MAYOR_SUCCESSION=15, TIMER_DAY_VOTE=90, TIMER_RECONNECTION=30, TIMER_READY_TIMEOUT=60) et la config des rôles extensible : ['seer'=>1, 'werewolf'=>'auto', 'villager'=>'fill']

CONTRAINTES :
- Toutes les FK ont cascadeOnDelete
- Ne crée aucun Service, aucun Event, aucun Controller, aucun Job
- Le scope anonymized() masque player_id via select() — ne pas utiliser whereNotIn
- isWerewolf() et isVillagerSide() préparent l'extensibilité v1.2 (ne pas hardcoder 'werewolf' ailleurs)

Quand c'est fait, liste les fichiers créés et signale toute ambiguïté.
```

---

## TÂCHE 2 — Authentification Google (OAuth)

```
Contexte : lis SPEC.md §4 (Auth Google) et CONVENTIONS.md. La tâche 1 est terminée.

Crée exactement les fichiers suivants :

- app/Http/Controllers/Auth/GoogleController.php
  → méthode redirect() : Socialite::driver('google')->redirect()
  → méthode callback() : récupère google_id/email/name, User::updateOrCreate(['google_id'=>...], [...]), Auth::login($user, true), redirect vers route('lobby')
  → méthode destroy() : Auth::logout(), redirect('/')
  → si exception Socialite dans callback() → redirect vers login avec message d'erreur en session flash

- config/services.php : ajouter le bloc 'google' avec client_id/client_secret/redirect depuis .env

- resources/views/auth/login.blade.php : Écran 2 du design
  → fond #0a0f1e, illustration background flouée, carte centrale fond #0a0f1e/90%, bordure #c9a84c, bouton Google (fond blanc, logo SVG officiel, texte #111827)
  → animation GSAP au chargement : gsap.from(card, {opacity:0, y:30, duration:0.8, ease:"power2.out"})
  → afficher le message d'erreur flash s'il existe

- routes/web.php : ajouter
  GET  /auth/google          → GoogleController@redirect
  GET  /auth/google/callback → GoogleController@callback
  POST /logout               → GoogleController@destroy (middleware auth)

CONTRAINTES :
- Réponses API au format CONVENTIONS.md : {success, data, message}
- Middleware 'auth' sur /logout uniquement pour l'instant (les autres routes protégées seront ajoutées tâche par tâche)
- Ne pas créer de FormRequest pour l'auth (pas de body à valider)
- Ne pas modifier les migrations ni les modèles

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 3 — Création de partie (Lobby)

```
Contexte : lis SPEC.md §4 (Création de partie) et CONVENTIONS.md. Tâches 1 et 2 terminées.

Crée exactement les fichiers suivants :

- app/Http/Requests/CreateGameRequest.php
  → pseudo : required, string, min:2, max:20
  → max_players : required, integer, in:6,8,10,12

- app/Services/GameService.php (créer la classe, méthode createGame seulement pour l'instant)
  → createGame(User $user, string $pseudo, int $maxPlayers): Game
  → génère un code 6 chars alphanumériques uppercase unique (retry si collision via while + Game::where('code',...)->exists())
  → DB::transaction : crée Game (status=waiting), crée GamePlayer (is_host=true, pseudo, joined_at=now())
  → retourne le Game

- app/Http/Controllers/Game/LobbyController.php
  → méthode create(CreateGameRequest $request): JsonResponse
  → appelle GameService::createGame()
  → retourne {success:true, data:{game_id, code}} HTTP 201

- routes/web.php : ajouter
  POST /game → LobbyController@create (middleware auth)

- resources/views/lobby/index.blade.php : Écran 3 du design
  → layout deux colonnes desktop / onglets mobile (Alpine.js x-data avec activeTab)
  → panneau CRÉER : sélecteur max_players (boutons 6/8/10/12, sélectionné=fond #c9a84c), champ pseudo, bouton "Créer la partie"
  → panneau REJOINDRE : input 6 cases OTP (Alpine.js, navigation automatique entre cases, auto-uppercase, paste intelligent), champ pseudo, bouton "Rejoindre"
  → si query param ?code=XXXXXX : onglet Rejoindre actif par défaut, code pré-rempli
  → gestion états d'erreur (messages depuis session flash)
  → animations GSAP : slide in des cartes au chargement

CONTRAINTES :
- Format réponse : {success, data, message} — CONVENTIONS.md
- GameService::createGame() ne doit pas encore broadcaster d'event (ce sera tâche 5)
- Ne pas valider dans le Controller — utiliser CreateGameRequest

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 4 — Rejoindre une partie

```
Contexte : lis SPEC.md §4 (Rejoindre une partie) et CONVENTIONS.md. Tâche 3 terminée.

Crée/modifie exactement les fichiers suivants :

- app/Http/Requests/JoinGameRequest.php
  → code : required, string, size:6, regex:/^[A-Z0-9]+$/
  → pseudo : required, string, min:2, max:20

- app/Services/GameService.php : ajouter méthode joinGame(User $user, string $code, string $pseudo): GamePlayer
  → trouver la Game par code (ModelNotFoundException → 404)
  → vérifier status = 'waiting' (sinon : abort(409, 'Cette partie a déjà commencé'))
  → vérifier count < max_players (sinon : abort(409, 'Cette partie est déjà complète'))
  → vérifier que l'user n'est pas dans exclusions pour cette game (sinon : abort(403, 'Tu as été exclu de cette partie'))
  → créer GamePlayer, retourner le player
  → PAS de lockForUpdate ici (ce sera ajouté tâche 5)

- app/Http/Controllers/Game/LobbyController.php : ajouter méthode join(JoinGameRequest $request): JsonResponse
  → appelle GameService::joinGame()
  → retourne {success:true, data:{player_id, game_code}} HTTP 200

- routes/web.php : ajouter
  POST /game/{code}/join → LobbyController@join (middleware auth)

CONTRAINTES :
- Format réponse CONVENTIONS.md
- Codes HTTP : 409 pour conflit métier (partie pleine / démarrée), 403 pour exclu, 404 pour code inexistant
- Ne pas encore broadcaster PlayerJoined (ce sera tâche 5)
- Ne pas déclencher le démarrage automatique (ce sera tâche 8)

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 5 — Salle d'attente (Écran 4) + broadcast PlayerJoined + WebSocket setup

```
Contexte : lis SPEC.md §5 (Architecture), §6 (Events WebSocket), CONVENTIONS.md §Broadcasting.
Tâche 4 terminée. Cette tâche combine le setup WebSocket ET la salle d'attente car les broadcasts en dépendent.

⚠️ RACE CONDITION : protéger l'insertion game_players avec DB::transaction + lockForUpdate sur Game pour ne jamais dépasser max_players.

Crée/modifie exactement les fichiers suivants :

WEBSOCKET SETUP :
- config/broadcasting.php : s'assurer que le driver 'reverb' est configuré
- resources/js/bootstrap.js (ou resources/js/echo.js) : initialiser Laravel Echo avec broadcaster 'reverb', utiliser les variables VITE_REVERB_* depuis import.meta.env
- routes/channels.php : définir les 3 channels
  → Channel('game.{gameId}') : retourner true si GamePlayer::where(game_id, user_id)->exists()
  → PrivateChannel('game.{gameId}.player.{playerId}') : vérifier que l'user est bien ce player
  → PrivateChannel('game.{gameId}.werewolves') : vérifier que le player isWerewolf()

EVENT :
- app/Events/Game/PlayerJoined.php : implements ShouldBroadcast
  → broadcastOn() : new Channel("game.{$this->game->id}")
  → broadcastAs() : 'player.joined'
  → broadcastWith() : {pseudo, players: [...{id,pseudo,is_host}], slots_remaining}

SERVICE :
- app/Services/GameService.php : modifier joinGame() pour :
  → encapsuler dans DB::transaction avec $game->lockForUpdate() avant d'insérer
  → après insertion, broadcaster PlayerJoined
  → retourner le player

VUE :
- resources/views/lobby/waiting-room.blade.php : Écran 4
  → afficher code de partie (Cinzel, #c9a84c, tracking-widest) + bouton "Copier le lien" (navigator.clipboard via Alpine.js, feedback "Lien copié !" 1.5s)
  → liste des joueurs connectés (avatar initiales, pseudo, badge 👑 Host, badge "Toi"), slots vides en pointillés avec pulsation GSAP
  → barre de progression (GSAP width animation à chaque PlayerJoined)
  → message d'attente dynamique ("Plus qu'un joueur !", "La partie commence !" quand complet)
  → bouton "Exclure un joueur" visible uniquement pour le host (modale Alpine.js, voir Tâche 6)
  → Alpine.js : écouter Echo.channel('game.X').listen('.player.joined', ...) pour mettre à jour la liste en temps réel
  → redirection automatique vers /game/{code}/role-reveal après 1.5s quand slots_remaining = 0

- routes/web.php : ajouter
  GET /game/{code}/lobby → LobbyController@waitingRoom (middleware auth)

CONTRAINTES :
- broadcastAs() obligatoire sur tous les events (CONVENTIONS.md)
- Ne pas encore gérer le démarrage automatique dans joinGame() (ce sera tâche 8)
- Channel public game.{gameId} : tous les joueurs de la partie peuvent s'y abonner

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 6 — Exclusion d'un joueur par le host

```
Contexte : lis SPEC.md §4 (Exclusion par le host) et CONVENTIONS.md. Tâche 5 terminée.

Crée/modifie exactement les fichiers suivants :

- app/Http/Requests/ExcludePlayerRequest.php
  → reason : required, string, max:500

- app/Events/Game/PlayerExcluded.php : implements ShouldBroadcast
  → broadcastOn() : Channel public ("game.{gameId}") + PrivateChannel individuel ("game.{gameId}.player.{excludedPlayerId}")
  → broadcastAs() : 'player.excluded'
  → broadcastWith() pour channel public : {pseudo}
  → broadcastWith() pour channel privé : {pseudo, reason}
  Note : utiliser broadcastToEveryone() ou deux events séparés si nécessaire pour différencier les payloads

- app/Services/GameService.php : ajouter excludePlayer(GamePlayer $host, GamePlayer $target, string $reason): void
  → vérifier game.status = 'waiting' (sinon abort 409)
  → vérifier host.is_host = true (sinon abort 403)
  → vérifier que target n'est pas le host lui-même (sinon abort 422)
  → DB::transaction : supprimer target de game_players, insérer dans exclusions
  → broadcaster PlayerExcluded

- app/Http/Controllers/Game/LobbyController.php : ajouter exclude(ExcludePlayerRequest $request, int $id, int $playerId): JsonResponse
  → $this->authorize('exclude', $game) via GamePolicy
  → appelle GameService::excludePlayer()
  → retourne {success:true} HTTP 200

- app/Policies/GamePolicy.php : créer avec méthode exclude(User $user, Game $game): bool
  → retourner true si l'user est host de la partie

- routes/web.php : ajouter
  POST /game/{id}/exclude/{playerId} → LobbyController@exclude (middleware auth)

CÔTÉ CLIENT (dans waiting-room.blade.php) :
- Écouter '.player.excluded' sur le channel public → retirer le joueur de la liste
- Écouter '.player.excluded' sur le channel privé → redirect vers /lobby?excluded=1 avec message d'erreur
- La modale de confirmation (déjà présente dans la vue tâche 5) envoie la requête POST et gère la réponse

CONTRAINTES :
- Format réponse CONVENTIONS.md
- Un joueur exclu ne peut pas rejoindre la même partie (déjà géré dans GameService::joinGame() tâche 4)
- Exclusion uniquement en phase waiting

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 7 — Distribution des rôles (RoleDistributor)

```
Contexte : lis SPEC.md §4 (Distribution des rôles) et SPEC.md §8 (Extensibilité v1.2). Tâche 1 terminée.

Crée exactement les fichiers suivants :

- app/Services/RoleDistributor.php
  → propriété protégée $roleConfig lue depuis config('game.roles')
  → méthode distribute(Collection $players): void
    - appelle buildRoleList($count)
    - shuffle la liste de rôles
    - foreach player → $player->update(['role' => array_shift($roles)])
  → méthode protégée buildRoleList(int $count): array
    - lire le nb de voyantes depuis config (1)
    - calculer les loups : max(1, floor($count * 0.2)), avec cas spécial 8 joueurs → 2
    - remplir le reste en villageois
    - retourner le tableau de rôles
  → méthode getRoleConfig(): array → retourner config('game.roles')

- Mettre à jour config/game.php (créé tâche 1) pour ajouter :
  'roles' => ['seer' => 1, 'werewolf' => 'auto', 'villager' => 'fill']
  Le tableau de config doit permettre d'ajouter 'witch'=>1, 'cupid'=>1 en v1.2 sans modifier RoleDistributor

Table de référence à respecter :
  6 joueurs  → 1 loup, 1 voyante, 4 villageois
  8 joueurs  → 2 loups, 1 voyante, 5 villageois
  10 joueurs → 2 loups, 1 voyante, 7 villageois
  12 joueurs → 3 loups, 1 voyante, 8 villageois

CONTRAINTES :
- Ne jamais hardcoder 'werewolf' dans d'autres classes — toujours passer par isWerewolf()
- La méthode distribute() modifie les GamePlayer directement en base (pas de retour de tableau)
- Ne crée aucun Controller ni Event

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 8 — Démarrage automatique + GameStarted

```
Contexte : lis SPEC.md §4 (Démarrage automatique) et §6 (Event GameStarted). Tâches 5 et 7 terminées.

⚠️ RACE CONDITION : protéger startGame() contre le double déclenchement. Utiliser un verrou DB sur la Game avant de changer son status. Si status != 'waiting' au moment du verrou → abort silencieux.

Crée/modifie exactement les fichiers suivants :

- app/Events/Game/GameStarted.php : implements ShouldBroadcast
  → broadcastOn() : Channel public + un PrivateChannel par joueur ("game.{gameId}.player.{playerId}")
  → broadcastAs() : 'game.started'
  → broadcastWith() :
    - payload public : {players: [...{id, pseudo}]} — aucun rôle
    - payload individuel sur channel privé : {role, allies: [...pseudos]} si loup, sinon {role}
  Note : pour différencier le payload par channel, implémenter la logique dans un Listener dédié (GameStartedListener) qui dispatche des notifications individuelles, plutôt que dans l'event lui-même

- app/Listeners/Game/GameStartedListener.php
  → écoute GameStarted
  → pour chaque joueur : broadcaster sur son channel privé un event RoleAssigned avec son rôle (et la liste des loups alliés s'il est loup)

- app/Events/Game/RoleAssigned.php : implements ShouldBroadcastNow
  → broadcastOn() : PrivateChannel("game.{gameId}.player.{playerId}")
  → broadcastAs() : 'role.assigned'
  → broadcastWith() : {role, allies: [...pseudos] si loup sinon []}

- app/Jobs/WaitForReadyPlayers.php : implements ShouldQueue
  → constructeur : int $gameId
  → handle() :
    - vérifier game.status = 'electing_mayor' (sinon skip — la partie aurait pu se terminer)
    - si tous is_ready = true → ne rien faire (déjà géré dans ActionController::ready())
    - sinon → broadcaster MayorElectionStarted (les joueurs non-ready seront ignorés)

- app/Services/GameService.php : ajouter/modifier
  → modifier joinGame() : après insertion du joueur, si game->players()->count() >= game->max_players → appeler startGame()
  → créer startGame(Game $game): void
    - DB::transaction avec $game->lockForUpdate()
    - vérifier game.status = 'waiting' (sinon return silencieux)
    - update game.status = 'electing_mayor', game.started_at = now()
    - appeler RoleDistributor::distribute(game->players)
    - fire event GameStarted (qui déclenchera GameStartedListener)
    - dispatch WaitForReadyPlayers avec delay config('game.timers.TIMER_READY_TIMEOUT')

- app/Providers/EventServiceProvider.php : enregistrer GameStarted → GameStartedListener

CONTRAINTES :
- Ne jamais exposer le rôle d'un joueur dans le payload public de GameStarted
- Les loups reçoivent uniquement la liste des pseudos de leurs alliés loups (pas leurs rôles d'autres joueurs)
- Utiliser config('game.timers.TIMER_READY_TIMEOUT') pour le delay, pas de valeur hardcodée

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 9 — Écran révélation du rôle (Écran 5) + endpoint ready

```
Contexte : lis SPEC.md §11 (Écran 5), SPEC.md §6 (PlayerReady, MayorElectionStarted). Tâche 8 terminée.

Crée/modifie exactement les fichiers suivants :

- app/Events/Game/PlayerReady.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'player.ready'
  → broadcastWith() : {ready_count, total}

- app/Events/Game/MayorElectionStarted.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'mayor.election.started'
  → broadcastWith() : {timer: config('game.timers.TIMER_MAYOR_ELECTION')}

- app/Http/Controllers/Game/ActionController.php : méthode ready(Request $request, int $id): JsonResponse
  → récupérer le player de l'user pour cette game
  → si already is_ready → retourner {success:true} (idempotent)
  → update is_ready = true
  → broadcaster PlayerReady
  → si tous les joueurs is_ready → broadcaster MayorElectionStarted
  → retourner {success:true, data:{ready_count, total}}

- routes/web.php : ajouter
  POST /game/{id}/ready → ActionController@ready (middleware auth)
  GET  /game/{code}/role-reveal → ActionController@roleReveal (middleware auth)

- app/Http/Controllers/Game/ActionController.php : méthode roleReveal(Request $request, string $code)
  → récupérer la game et le player
  → retourner la vue avec les données nécessaires (rôle déjà en session ou rechargé depuis game_players)

- resources/views/game/role-reveal.blade.php : Écran 5
  → fond plein #0a0f1e, fond étoilé (CSS animation simple)
  → carte centrée (280px×420px desktop, 220px×330px mobile), dos médiéval avec motif SVG + "?" Cinzel
  → animation de flottement continue : gsap.to(card, {y:-8, repeat:-1, yoyo:true, duration:2, ease:"sine.inOut"})
  → bouton "Retourner la carte" → animation retournement GSAP (rotateY 0→90→swap→-90→0)
  → après révélation : illustration rôle + nom (Cinzel #c9a84c) + description (EB Garamond italique) + lueur colorée selon rôle (rouge sang pour loup, violet pour voyante, doré pour villageois/maire)
  → si rôle = werewolf : section "Tes alliés cette nuit" avec pseudos en #8b0000
  → retournement automatique après 5s si pas cliqué (Alpine.js setTimeout)
  → bouton "Entrer dans la partie" (visible après révélation) → POST /game/{id}/ready puis redirect vers /game/{code}/election
  → Alpine.js : écouter '.mayor.election.started' → activer le bouton si pas encore cliqué

CONTRAINTES :
- Le rôle du joueur vient du GamePlayer en base (pas de localStorage)
- Ne jamais afficher le rôle des autres joueurs sur cet écran

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 10 — Phase Élection du Maire — votes (Écran 6)

```
Contexte : lis SPEC.md §4 (Élection du Maire), §6 (MayorVoteCast), CONVENTIONS.md. Tâche 9 terminée.

⚠️ RACE CONDITION : deux votes simultanés peuvent perturber le décompte. Encapsuler l'insertion + lecture des totaux dans une DB::transaction.

Crée/modifie exactement les fichiers suivants :

- app/Http/Requests/MayorVoteRequest.php
  → target_player_id : required, integer, exists:game_players,id

- app/Services/VoteService.php (créer la classe)
  → castMayorVote(GamePlayer $voter, int $targetId): array
    - vérifier game.status = 'electing_mayor' (sinon abort 409)
    - DB::transaction :
      - vérifier que voter n'a pas déjà voté ce round (game_actions type=mayor_vote)
      - vérifier que la cible est un joueur vivant de la même partie
      - vérifier que la cible != null (voter pour soi-même est autorisé)
      - insérer game_actions (type=mayor_vote, weight=1, round, phase=election)
      - retourner getMayorVoteSummary()
  → getMayorVoteSummary(Game $game): array
    - retourner groupBy(target_player_id)->map->count() pour le round courant, type=mayor_vote

- app/Events/Game/MayorVoteCast.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'mayor.vote.cast'
  → broadcastWith() : {votes: {player_id: count, ...}} — jamais de player_id votant

- app/Http/Controllers/Game/VoteController.php (créer)
  → mayorVote(MayorVoteRequest $request, int $id): JsonResponse
    - récupérer game et player
    - appeler VoteService::castMayorVote()
    - broadcaster MayorVoteCast
    - retourner {success:true, data:{votes}}

- routes/web.php : ajouter
  POST /game/{id}/vote/mayor → VoteController@mayorVote (middleware auth)
  GET  /game/{code}/election → VoteController@electionScreen (middleware auth)

- resources/views/game/election.blade.php : Écran 6
  → header phase (icône 👑, "Élection du Maire", sous-titre explicatif)
  → composant <x-game-timer> : 30s, couleur or→orange→rouge
  → liste des joueurs votables (avatar, pseudo, mini-barre de votes proportionnelle, bouton "Voter")
  → bouton désactivé après vote du joueur courant
  → confirmation de vote en bas : "✅ Tu as voté pour : [pseudo]"
  → Alpine.js : écouter '.mayor.vote.cast' → mettre à jour les barres avec GSAP
  → à la réception de '.mayor.elected' → afficher overlay résultat (voir tâche 11)

CONTRAINTES :
- MayorVoteCast ne doit jamais exposer qui a voté pour qui — uniquement les totaux par cible
- Le vote pour soi-même est autorisé (cas documenté dans SPEC.md)
- Format réponse CONVENTIONS.md

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 11 — Phase Élection du Maire — timer et résolution

```
Contexte : lis SPEC.md §4 (Élection du Maire), §6 (MayorElected, NightStarted). Tâche 10 terminée.

⚠️ RACE CONDITION : ProcessMayorElection doit vérifier en début de handle() que game.status = 'electing_mayor'. Si le status a changé → return silencieux.

Crée/modifie exactement les fichiers suivants :

- app/Jobs/ProcessMayorElection.php : implements ShouldQueue
  → constructeur : int $gameId
  → handle(VoteService $voteService, PhaseManager $phaseManager) :
    - récupérer game, vérifier status = 'electing_mayor' (sinon return)
    - appeler VoteService::resolveMayorElection($game)

- app/Services/VoteService.php : ajouter resolveMayorElection(Game $game): void
  → agréger les votes mayor_vote par target_player_id pour le round courant
  → si aucun vote → choisir aléatoirement parmi les joueurs vivants, was_random=true
  → si égalité → choisir aléatoirement parmi les ex-aequo, was_random=true
  → sinon was_random=false
  → marquer le gagnant is_mayor=true
  → broadcaster MayorElected
  → appeler PhaseManager::startNight($game)

- app/Services/PhaseManager.php (créer la classe)
  → constantes : TIMER_MAYOR_ELECTION, TIMER_SEER, TIMER_WEREWOLVES, TIMER_MAYOR_SUCCESSION, TIMER_DAY_VOTE
    (lues depuis config('game.timers'))
  → startMayorElection(Game $game): void
    - update game.status='electing_mayor', game.phase_deadline=now()+30s
    - broadcaster MayorElectionStarted
    - dispatch ProcessMayorElection::dispatch($game->id)->delay(now()->addSeconds(self::TIMER_MAYOR_ELECTION))
  → startNight(Game $game): void
    - $game->increment('round'), refresh
    - update game.status='night', game.phase_deadline=now()+30s
    - broadcaster NightStarted
    - dispatch ProcessSeerTurn::dispatch($game->id)->delay(now()->addSeconds(self::TIMER_SEER))
  → (les autres méthodes seront ajoutées dans les tâches suivantes)

- app/Events/Game/MayorElected.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'mayor.elected'
  → broadcastWith() : {player_id, pseudo, was_random}

- app/Events/Game/NightStarted.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'night.started'
  → broadcastWith() : {round, timer: TIMER_SEER}

CÔTÉ CLIENT (dans election.blade.php) :
- À la réception de '.mayor.elected' : afficher overlay résultat (animation scale 0.8→1 + lueur dorée), puis redirection vers /game/{code}/night après 3s

CONTRAINTES :
- Toutes les valeurs de timer viennent de PhaseManager::TIMER_* ou config('game.timers')
- Ne jamais hardcoder 30 directement

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 12 — Phase Nuit — action Voyante (Écran 7)

```
Contexte : lis SPEC.md §4 (Phase Nuit, Action Voyante), §6 (SeerTurnStarted, SeerResult). Tâche 11 terminée.

⚠️ SeerResult doit être broadcasté UNIQUEMENT sur le channel privé de la voyante. Jamais sur le channel public.

Crée/modifie exactement les fichiers suivants :

- app/Jobs/ProcessSeerTurn.php : implements ShouldQueue
  → constructeur : int $gameId
  → handle(PhaseManager $phaseManager) :
    - récupérer game, vérifier status = 'night' et round correspond
    - trouver la voyante vivante (role='seer', is_alive=true)
    - si voyante inexistante ou is_inactive → dispatch ProcessWerewolvesTurn immédiatement, return
    - broadcaster SeerTurnStarted sur channel privé voyante
    - dispatch ProcessWerewolvesTurn::dispatch($gameId)->delay(now()->addSeconds(PhaseManager::TIMER_SEER))

- app/Events/Game/SeerTurnStarted.php : implements ShouldBroadcast
  → broadcastOn() : PrivateChannel("game.{gameId}.player.{seerPlayerId}")
  → broadcastAs() : 'seer.turn.started'
  → broadcastWith() : {timer: TIMER_SEER}

- app/Http/Requests/SeerCheckRequest.php
  → target_player_id : required, integer

- app/Http/Controllers/Game/ActionController.php : ajouter seerCheck(SeerCheckRequest $request, int $id): JsonResponse
  → récupérer game et player
  → vérifier player.role = 'seer' et player.is_alive (sinon 403)
  → vérifier game.status = 'night' (sinon 409)
  → vérifier que player n'a pas déjà fait seer_check ce round (sinon 409)
  → vérifier target != player lui-même (sinon 422 "La voyante ne peut pas s'inspecter")
  → vérifier target est vivant et appartient à la même partie
  → insérer game_actions (type=seer_check, round, phase=night)
  → broadcaster SeerResult
  → retourner {success:true}

- app/Events/Game/SeerResult.php : implements ShouldBroadcast
  → broadcastOn() : PrivateChannel("game.{gameId}.player.{seerPlayerId}")
  → broadcastAs() : 'seer.result'
  → broadcastWith() : {target_pseudo, role} — rôle = 'werewolf' | 'villager' | 'seer'

- routes/web.php : ajouter
  POST /game/{id}/seer/check → ActionController@seerCheck (middleware auth)
  GET  /game/{code}/night → ActionController@nightScreen (middleware auth)

- resources/views/game/night.blade.php : Écran 7 (voyante) + écran nuit générique
  ÉCRAN GÉNÉRIQUE (tous sauf voyante pendant son tour) :
  → fond #030712, lune qui monte (GSAP), brume CSS, message "Le village dort..."
  → barre de progression phase nuit (lecture seule)
  → chat général désactivé (label "Le village est silencieux cette nuit...")
  ÉCRAN VOYANTE (Alpine.js x-show conditionné à seerTurnActive) :
  → titre "C'est ton tour, Voyante..." en Cinzel #7c3aed
  → lueur violette en arrière-plan
  → liste des joueurs inspectables (vivants, soi-même exclu), bouton "🔍 Inspecter"
  → après résultat : carte retournable (même GSAP que Écran 5) avec couleur rouge si loup, vert si innocent
  → Alpine.js : écouter '.seer.turn.started' sur channel privé → afficher interface inspection
  → écouter '.seer.result' → afficher résultat + bouton "J'ai compris"

CONTRAINTES :
- SeerResult ne doit jamais passer par le channel public
- Vérifier côté serveur que le joueur est bien la voyante (anti-spoofing)

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 13 — Phase Nuit — action Loups + chat loups (Écrans 8)

```
Contexte : lis SPEC.md §4 (Action Loups), §6 (WerewolvesTurnStarted, WerewolvesVoteCast, WerewolfChatMessage). Tâche 12 terminée.

⚠️ Les loups ne peuvent pas voter pour un autre loup. Vérifier côté serveur avec isWerewolf().
⚠️ Le vote des loups est modifiable jusqu'à expiration du timer (updateOrCreate).

Crée/modifie exactement les fichiers suivants :

- app/Jobs/ProcessWerewolvesTurn.php : implements ShouldQueue
  → constructeur : int $gameId
  → handle(PhaseManager $phaseManager) :
    - récupérer game, vérifier status = 'night' et round correspond
    - récupérer les cibles éligibles (alivePlayers non-loups)
    - broadcaster WerewolvesTurnStarted
    - dispatch ProcessNightResult::dispatch($gameId)->delay(now()->addSeconds(PhaseManager::TIMER_WEREWOLVES))

- app/Events/Game/WerewolvesTurnStarted.php : implements ShouldBroadcast
  → broadcastOn() : PrivateChannel("game.{gameId}.werewolves")
  → broadcastAs() : 'werewolves.turn.started'
  → broadcastWith() : {timer, eligible_targets: [...{id, pseudo}]}

- app/Http/Requests/NightVoteRequest.php
  → target_player_id : required, integer

- app/Services/VoteService.php : ajouter castNightVote(GamePlayer $wolf, int $targetId): array
  → vérifier wolf.isWerewolf() et wolf.is_alive (sinon 403)
  → vérifier game.status = 'night' (sinon 409)
  → vérifier que target est vivant, non-loup (!isWerewolf()), même partie
  → updateOrCreate sur game_actions (game_id, player_id, type=night_vote, round) → target_player_id, phase=night
  → retourner l'état du vote entre loups (qui a voté : {pseudo: boolean})

- app/Events/Game/WerewolvesVoteCast.php : implements ShouldBroadcast
  → broadcastOn() : PrivateChannel("game.{gameId}.werewolves")
  → broadcastAs() : 'werewolves.vote.cast'
  → broadcastWith() : {votes: {pseudo: has_voted}} — jamais la cible choisie

- app/Http/Controllers/Game/VoteController.php : ajouter nightVote(NightVoteRequest $request, int $id): JsonResponse
  → appelle VoteService::castNightVote()
  → broadcaster WerewolvesVoteCast
  → retourne {success:true, data:{votes}}

CHAT LOUPS :
- app/Http/Requests/SendMessageRequest.php
  → message : required, string, max:200
  → channel : required, in:general,werewolves

- app/Services/ChatService.php (créer)
  → canWrite(GamePlayer $player, Game $game, string $channel): bool
    - si !player.is_alive → false
    - channel=general → in_array(game.status, ['electing_mayor','day'])
    - channel=werewolves → player.isWerewolf() && game.status='night'
  → canRead(GamePlayer $player, string $channel): bool
    - general → true
    - werewolves → player.isWerewolf()

- app/Events/Game/WerewolfChatMessage.php : implements ShouldBroadcast
  → broadcastOn() : PrivateChannel("game.{gameId}.werewolves")
  → broadcastAs() : 'werewolf.chat.message'
  → broadcastWith() : {pseudo, message, timestamp}

- app/Events/Game/ChatMessageSent.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'chat.message.sent'
  → broadcastWith() : {pseudo, message, channel, timestamp}

- app/Http/Controllers/Game/ChatController.php (créer)
  → send(SendMessageRequest $request, int $id): JsonResponse
    - récupérer game et player
    - ChatService::canWrite() → sinon 403
    - insérer ChatMessage
    - si channel=werewolves → broadcaster WerewolfChatMessage
    - si channel=general → broadcaster ChatMessageSent
    - retourner {success:true}

- routes/web.php : ajouter
  POST /game/{id}/vote/night → VoteController@nightVote (middleware auth)
  POST /game/{id}/chat → ChatController@send (middleware auth)

VUE (dans night.blade.php) :
  → onglets [CHAT] [VOTER] sur mobile pour l'interface loups
  → chat loups : bulles #1a0a0a, bordure #8b0000, input fond #0a0f1e, bouton envoi #8b0000
  → vote loups : liste cibles + bouton "🗡 Cibler", état vote des coéquipiers ("ShadowMoon → ✓")
  → Alpine.js : écouter '.werewolves.turn.started' sur channel werewolves → afficher interface loups
  → écouter '.werewolves.vote.cast' → mettre à jour l'état vote
  → écouter '.werewolf.chat.message' → ajouter message au chat

CONTRAINTES :
- WerewolvesVoteCast ne révèle pas la cible — uniquement "qui a voté" (boolean par pseudo)
- Chat loups invisible aux non-loups (channel privé)
- ChatMessages persistés en base même pour les loups

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 14 — Phase Nuit — résolution et transition vers le Jour

```
Contexte : lis SPEC.md §4 (Phase Nuit résolution), §6 (DayStarted). Tâche 13 terminée.

⚠️ RACE CONDITION : ProcessNightResult doit vérifier en début de handle() que game.status = 'night' ET game.round correspond au round pour lequel le job a été dispatché.

Crée/modifie exactement les fichiers suivants :

- app/Jobs/ProcessNightResult.php : implements ShouldQueue
  → constructeur : int $gameId, int $round
  → handle(VoteService $voteService, PhaseManager $phaseManager, WinConditionChecker $winChecker, GameService $gameService) :
    - récupérer game, vérifier status='night' ET game.round=$this->round (sinon return)
    - agréger les votes night_vote pour ce round via VoteService::resolveNightVote($game)
    - si victime : marquer is_alive=false, broadcaster PlayerEliminated (raison=night_kill)
    - appeler WinConditionChecker::check($game) — si victoire → return (GameFinished déjà broadcasté)
    - si maire tué nuit → dispatcher ProcessMayorSuccession avant DayStarted
    - update game.status='day', game.phase_deadline=now()+90s
    - broadcaster DayStarted

- app/Services/VoteService.php : ajouter resolveNightVote(Game $game): ?GamePlayer
  → agréger night_vote par target_player_id pour le round courant
  → si aucun vote → retourner null
  → max votes → si égalité → random parmi ex-aequo
  → retourner le GamePlayer victime

- app/Services/WinConditionChecker.php (créer)
  → check(Game $game): bool
    - compter aliveWerewolves (isWerewolf()) et aliveOthers (!isWerewolf())
    - loups gagnent si aliveWerewolves >= aliveOthers
    - village gagne si aliveWerewolves = 0
    - si victoire : update game (status=finished, winner_team, finished_at=now()), broadcaster GameFinished, retourner true
    - sinon retourner false

- app/Events/Game/PlayerEliminated.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'player.eliminated'
  → broadcastWith() : {player_id, pseudo, role, reason} — role révélé à la mort

- app/Events/Game/GameFinished.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'game.finished'
  → broadcastWith() : {winner_team, players: [...{id, pseudo, role, is_alive}]}

- app/Events/Game/DayStarted.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'day.started'
  → broadcastWith() : {round, killed: {pseudo, role} | null}

- app/Services/PhaseManager.php : ajouter startDay(Game $game, ?GamePlayer $victim): void
  → update game.status='day', game.phase_deadline=now()+TIMER_DAY_VOTE
  → broadcaster DayStarted
  → dispatch ProcessDayVote::dispatch($game->id, $game->round)->delay(now()->addSeconds(self::TIMER_DAY_VOTE))

CONTRAINTES :
- WinConditionChecker::check() doit être appelé avant la transition vers le jour
- Si winner_team est défini → ne pas appeler startDay()
- Révéler le rôle du joueur tué uniquement dans DayStarted/PlayerEliminated, jamais avant

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 15 — Phase Jour — chat + vote d'élimination (Écran 9)

```
Contexte : lis SPEC.md §4 (Phase Jour), §6 (DayVoteCast, NoElimination). Tâches 13 et 14 terminées.

⚠️ RACE CONDITION : le vote du maire (weight=2) doit être calculé au moment de l'insertion (lire is_mayor depuis la DB dans la transaction).

Crée/modifie exactement les fichiers suivants :

- app/Http/Requests/DayVoteRequest.php
  → target_player_id : required, integer

- app/Services/VoteService.php : ajouter castDayVote(GamePlayer $voter, int $targetId): array
  → vérifier game.status = 'day' (sinon 409)
  → vérifier voter.is_alive (sinon 403)
  → DB::transaction :
    - vérifier voter n'a pas déjà voté ce round (type=day_vote)
    - vérifier target est vivant et dans la même partie
    - vérifier target.id != voter.id (sinon 422 "Vous ne pouvez pas voter contre vous-même")
    - relire voter->is_mayor depuis la DB (dans la transaction, anti-race)
    - weight = voter->is_mayor ? 2 : 1
    - insérer game_actions (type=day_vote, weight, round, phase=day)
    - retourner getDayVoteSummary($game)
  → getDayVoteSummary(Game $game): array
    - retourner groupBy(target_player_id)->map(sum weight) pour round courant, type=day_vote

- app/Events/Game/DayVoteCast.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'day.vote.cast'
  → broadcastWith() : {votes: {player_id: total_weight}} — jamais l'auteur

- app/Http/Controllers/Game/VoteController.php : ajouter dayVote(DayVoteRequest $request, int $id): JsonResponse
  → appelle VoteService::castDayVote()
  → broadcaster DayVoteCast
  → retourne {success:true, data:{votes}}

- routes/web.php : ajouter
  POST /game/{id}/vote/day → VoteController@dayVote (middleware auth)
  GET  /game/{code}/day → VoteController@dayScreen (middleware auth)

- resources/views/game/day.blade.php : Écran 9
  → transition jour au chargement (GSAP : overlay nuit disparaît, lueur dorée monte)
  → bannière annonce mort (fond #1a0000, bordure #8b0000) ou "personne n'est mort cette nuit" (fond vert)
  → layout deux colonnes desktop (chat gauche, vote droite) / onglets [CHAT][VOTER] mobile
  CHAT :
  → messages en temps réel via '.chat.message.sent', scroll auto Alpine.js $nextTick
  → compteur caractères : affiché à partir de 150, orange à 180, rouge à 195
  → input désactivé si is_alive=false avec label "Tu es mort, silence..."
  VOTE :
  → liste joueurs vivants avec badges (👑 maire, barres de votes proportionnelles)
  → joueurs morts : grisés, barrés, badge 💀, non votables
  → bouton "Voter" → POST vote/day
  → confirmation vote en bas, timer <x-game-timer>
  → à '.day.vote.cast' → GSAP mise à jour barres
  → à '.player.eliminated' → GSAP grayscale sur la carte du joueur + check game.finished
  → à '.no.elimination' → message "Égalité — personne n'est éliminé"
  → à '.game.finished' → redirect vers /game/{code}/finished après 2s
  → à '.mayor.succession.started' → afficher modale succession (voir Tâche 16)

CONTRAINTES :
- DayVoteCast ne révèle jamais l'auteur du vote
- Le weight=2 du maire est géré côté serveur, invisible côté client (sauf que la barre avance plus vite)

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 16 — Phase Jour — résolution vote + élimination

```
Contexte : lis SPEC.md §4 (Phase Jour résolution), §6 (PlayerEliminated, NoElimination). Tâche 15 terminée.

⚠️ RACE CONDITION : ProcessDayVote doit vérifier game.status='day' ET game.round correspond en début de handle().

Crée/modifie exactement les fichiers suivants :

- app/Jobs/ProcessDayVote.php : implements ShouldQueue
  → constructeur : int $gameId, int $round
  → handle(VoteService $voteService, PhaseManager $phaseManager, WinConditionChecker $winChecker) :
    - récupérer game, vérifier status='day' ET game.round=$this->round (sinon return)
    - appeler VoteService::resolveDayVote($game)

- app/Services/VoteService.php : ajouter resolveDayVote(Game $game): void
  → agréger day_vote pondérés (sum weight par target) pour le round courant
  → si aucun vote → broadcaster NoElimination (reason='no_vote'), puis startNight
  → calculer max, vérifier si égalité
  → si égalité → broadcaster NoElimination (reason='equality'), puis startNight
  → sinon : marquer éliminé is_alive=false, broadcaster PlayerEliminated (raison=day_vote)
    - si WinConditionChecker::check() → return (GameFinished broadcasté)
    - si éliminé était maire → dispatch ProcessMayorSuccession (avant startNight)
    - sinon → PhaseManager::startNight($game)

- app/Events/Game/NoElimination.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'no.elimination'
  → broadcastWith() : {reason} — 'equality' | 'no_vote'

- app/Services/PhaseManager.php : ajouter startNight() si pas encore ajouté (normalement fait tâche 11)
  Vérifier que startNight() incrémente bien game.round avant de dispatcher ProcessSeerTurn

CONTRAINTES :
- Si WinConditionChecker retourne true → ne pas appeler startNight
- L'ordre est : élimination → check victoire → succession maire (si besoin) → startNight

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 17 — Succession du Maire (Écran 10)

```
Contexte : lis SPEC.md §4 (Succession maire), §6 (MayorSuccessionStarted, MayorSuccessionDone). Tâche 16 terminée.

Crée/modifie exactement les fichiers suivants :

- app/Jobs/ProcessMayorSuccession.php : implements ShouldQueue
  → constructeur : int $gameId, int $round
  → handle() :
    - récupérer game
    - vérifier qu'une action mayor_succession pour ce round n'existe pas déjà → si oui return
    - désignation aléatoire : prendre un joueur vivant au hasard (hors le maire mort)
    - marquer ancien maire is_mayor=false (si pas déjà fait)
    - marquer successeur is_mayor=true
    - broadcaster MayorSuccessionDone (was_random=true)

- app/Events/Game/MayorSuccessionStarted.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'mayor.succession.started'
  → broadcastWith() : {timer: TIMER_MAYOR_SUCCESSION, dying_mayor_pseudo}

- app/Events/Game/MayorSuccessionDone.php : implements ShouldBroadcast
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'mayor.succession.done'
  → broadcastWith() : {new_mayor_id, new_mayor_pseudo, was_random}

- app/Http/Requests/MayorSuccessionRequest.php
  → target_player_id : required, integer

- app/Http/Controllers/Game/ActionController.php : ajouter mayorSuccession(MayorSuccessionRequest $request, int $id): JsonResponse
  → récupérer game et player
  → vérifier player.is_mayor=true ET player.is_alive=false (maire mort qui désigne)
  → vérifier que pas déjà de succession faite ce round
  → DB::transaction :
    - player->update(['is_mayor'=>false])
    - target->update(['is_mayor'=>true])
    - insérer game_actions (type=mayor_succession, round)
  → broadcaster MayorSuccessionDone (was_random=false)
  → retourner {success:true}

- Modifier ProcessMayorSuccession/ProcessNightResult/ProcessDayVote pour :
  → si maire inactif (is_inactive=true) → dispatch ProcessMayorSuccession immédiatement (delay=0)
  → sinon → dispatch avec delay TIMER_MAYOR_SUCCESSION

- routes/web.php : ajouter
  POST /game/{id}/mayor/succession → ActionController@mayorSuccession (middleware auth)

VUE (modale overlay dans day.blade.php et night.blade.php) :
  POUR LE MAIRE MORT :
  → modale centrée, fond overlay #0a0f1e/85%, carte #111827 bordure #c9a84c
  → titre "👑 Succession du Maire", timer urgent (rouge sous 5s)
  → liste joueurs vivants + bouton "👑 Désigner"
  POUR LES AUTRES :
  → modale en lecture seule : "[Pseudo] choisit son successeur...", spinner, timer visible
  → Alpine.js : écouter '.mayor.succession.started' → afficher modale
  → écouter '.mayor.succession.done' → fermer modale, mettre à jour badge 👑

CONTRAINTES :
- Un maire inactif → désignation aléatoire immédiate sans attendre les 15s
- Vérifier côté serveur que c'est bien le maire mort qui fait la requête

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 18 — Gestion déconnexion et inactivité

```
Contexte : lis SPEC.md §4 (Déconnexion/Inactivité), §6 (PlayerDisconnected, PlayerReconnected, PlayerInactive). Tâche 10 terminée (WebSocket setup requis).

⚠️ MÉCANISME DÉCONNEXION : Laravel Reverb ne fournit pas d'événement serveur natif de déconnexion client. Implémenter via presence channel + heartbeat côté client.

Crée/modifie exactement les fichiers suivants :

- routes/channels.php : ajouter un PresenceChannel("game.{gameId}.presence")
  → retourner ['id'=>$player->id, 'pseudo'=>$player->pseudo] si le joueur appartient à la partie

- resources/js/game-state.js (ou dans bootstrap.js) : côté client Alpine.js
  → rejoindre le presence channel : Echo.join('game.X.presence')
    .here(members => {...})
    .joining(member => {...})
    .leaving(member => { /* déclencher handleDisconnection */ })
  → handleDisconnection(member) : si c'est un autre joueur → afficher toast "X s'est déconnecté"

- app/Http/Controllers/Game/GameController.php : ajouter disconnect(Request $request, int $id): JsonResponse
  → endpoint appelé par le beforeunload client (best-effort)
  → récupérer player, appeler GameService::handleDisconnection($player)
  → retourner {success:true}

- app/Services/GameService.php : ajouter handleDisconnection(GamePlayer $player): void
  → broadcaster PlayerDisconnected
  → dispatch CheckReconnectionTimeout::dispatch($player->id)->delay(now()->addSeconds(config('game.timers.TIMER_RECONNECTION')))

- app/Jobs/CheckReconnectionTimeout.php : implements ShouldQueue
  → constructeur : int $playerId
  → handle() :
    - récupérer player
    - si player reconecté (vérifier via presence channel ou flag) → return
    - update player.is_inactive=true
    - broadcaster PlayerInactive
    - vérifier si >50% des joueurs vivants sont inactifs → si oui annuler la partie

- app/Services/GameService.php : ajouter cancelGame(Game $game): void
  → update game.status=finished, game.winner_team=null, game.finished_at=now()
  → broadcaster GameFinished (winner_team=null, players avec rôles non révélés)

- app/Http/Controllers/Game/GameController.php : ajouter reconnect(Request $request, string $code): JsonResponse
  → récupérer game et player
  → update player.is_inactive=false
  → broadcaster PlayerReconnected
  → retourner {success:true}

- app/Events/Game/PlayerDisconnected.php, PlayerReconnected.php, PlayerInactive.php
  → broadcastOn() : Channel("game.{gameId}")
  → broadcastAs() : 'player.disconnected' | 'player.reconnected' | 'player.inactive'
  → broadcastWith() : {pseudo} + {reconnection_timeout: 30} pour Disconnected

- routes/web.php : ajouter
  POST /game/{id}/disconnect → GameController@disconnect (middleware auth)
  POST /game/{code}/reconnect → GameController@reconnect (middleware auth)

CÔTÉ CLIENT (dans game-state.js) :
  → à '.player.disconnected' : toast info "X se reconnecte...", spinner dans header si c'est le joueur courant
  → à '.player.inactive' : toast warning "X est inactif"
  → à '.player.reconnected' : toast success "X est de retour"
  → gestion UI déconnexion propre : overlay pointer-events-none pendant la reconnexion

CONTRAINTES :
- Le mécanisme de détection repose sur le presence channel Reverb + POST /disconnect en beforeunload
- is_inactive=false uniquement à la reconnexion explicite via /reconnect
- Partie annulée uniquement si >50% des joueurs VIVANTS sont inactifs simultanément

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 19 — Endpoint GET /game/{code}/state (reconnexion)

```
Contexte : lis SPEC.md §7 (payload /state) et §4 (Déconnexion cas limites). Tâche 18 terminée.

Crée/modifie exactement les fichiers suivants :

- app/Http/Controllers/Game/GameController.php : ajouter state(Request $request, string $code): JsonResponse
  → récupérer game par code (404 si inexistant)
  → récupérer player de l'user pour cette game (403 si non participant)
  → calculer phase_remaining_seconds = max(0, game.phase_deadline->diffInSeconds(now(), false))
  → déterminer seer_turn_active : game.status='night' ET une voyante vivante existe ET ProcessSeerTurn pas encore expiré
    (approximation : phase_deadline > now() ET pas de seer_check pour ce round)
  → déterminer werewolves_turn_active : game.status='night' ET seer_turn_active=false ET phase_deadline > now()
  → retourner {success:true, data:{
      phase, round, my_role, is_alive, is_mayor,
      phase_remaining_seconds, seer_turn_active, werewolves_turn_active,
      allies: [...pseudos] si isWerewolf() sinon []
    }}

- routes/web.php : ajouter
  GET /game/{code}/state → GameController@state (middleware auth)

CONTRAINTES :
- Ne jamais retourner le rôle des autres joueurs dans cet endpoint
- Les loups reçoivent uniquement la liste des pseudos de leurs alliés loups (pas leurs rôles)
- Cet endpoint est le point d'entrée pour restaurer l'UI après reconnexion
- Format réponse CONVENTIONS.md

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 20 — Push Notifications

```
Contexte : lis SPEC.md §4 (Push Notifications). Tâches 8, 14, 16, 17 terminées.

Crée/modifie exactement les fichiers suivants :

- Installer le package : composer require laravel-notification-channels/webpush
- Publier les migrations webpush et migrer
- Générer les clés VAPID : php artisan webpush:vapid → copier dans .env

- app/Notifications/RoleAssignedNotification.php
  → via() : [WebPushChannel::class]
  → toWebPush() : title="La partie commence !", body="Votre rôle : [emoji + nom rôle]", icon, badge

- app/Notifications/PlayerKilledNightNotification.php
  → toWebPush() : title="☠️ Tu as été tué cette nuit", body="[pseudo du tueur]... non, les loups gardent leurs secrets."

- app/Notifications/PlayerEliminatedDayNotification.php
  → toWebPush() : title="⚖️ Le village t'a éliminé", body="Tu étais [rôle]."

- app/Notifications/GameFinishedNotification.php
  → toWebPush() : title selon winner_team ("🏆 Le village a gagné !" ou "🐺 Les loups ont gagné !"), body="La partie est terminée."

- app/Notifications/PlayerExcludedNotification.php
  → toWebPush() : title="Tu as été exclu", body=reason

- Dispatcher les notifications aux bons endroits :
  → RoleAssigned : dans GameStartedListener (tâche 8), après broadcast individuel
  → PlayerKilledNight : dans ProcessNightResult::handle(), après is_alive=false
  → PlayerEliminatedDay : dans VoteService::resolveDayVote(), après is_alive=false
  → GameFinished : dans WinConditionChecker::check(), après broadcast GameFinished
  → PlayerExcluded : dans GameService::excludePlayer()

- public/sw.js (service worker) : gérer les push events et afficher les notifications

CONTRAINTES :
- Toutes les notifications sont dispatchées via $user->notify() ou Notification::send()
- Ne pas bloquer la logique principale si la notification échoue (try/catch)

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 21 — Historique de partie (Écran 13)

```
Contexte : lis SPEC.md §4 (Anonymat des votes, Policy historique), §11 (Écran 13). Tâche 14 terminée.

Crée/modifie exactement les fichiers suivants :

- app/Policies/GamePolicy.php : ajouter viewHistory(User $user, Game $game): bool
  → retourner game->players()->where('user_id', $user->id)->exists()

- app/Http/Controllers/Game/GameController.php : ajouter history(Request $request, string $code): Response
  → récupérer game par code (404 si inexistant)
  → vérifier game.status = 'finished' (403 sinon)
  → $this->authorize('viewHistory', $game)
  → construire le payload :
    - joueurs + rôles (tous révélés)
    - timeline par round : actions depuis game_actions avec scope anonymized() pour les votes
    - pas d'historique des messages chat (privé)
  → retourner la vue game/history ou JSON selon Accept header

- resources/views/game/history.blade.php : Écran 13
  → header : code partie, date, durée (finished_at - started_at), badge camp gagnant
  → section "Joueurs & Rôles" : tableau avec avatar, pseudo, rôle coloré, badge 👑/💀, ✅/❌
  → section "Déroulé" : timeline verticale par round
    - ÉLECTION : maire élu
    - NUIT X : joueur tué (rôle révélé)
    - JOUR X : joueur éliminé ou égalité
    - FIN : raison victoire
  → onglets [JOUEURS][DÉROULÉ] sur mobile
  → animations GSAP : fade in sections au chargement, timeline items en cascade
  → boutons Rejouer (→ /lobby?pseudo=X) et Accueil (→ /)

- routes/web.php : ajouter
  GET /game/{code}/history → GameController@history (middleware auth)

CONTRAINTES :
- scope anonymized() sur toutes les lectures de game_actions dans cet endpoint
- Accessible uniquement aux participants (GamePolicy)
- Accessible uniquement si game.status = 'finished'

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 22 — Fin de partie (Écran 11) + Spectateur mort (Écran 12)

```
Contexte : lis SPEC.md §11 (Écrans 11 et 12). Tâches 14 et 16 terminées.

Crée/modifie exactement les fichiers suivants :

- resources/views/game/finished.blade.php : Écran 11
  → bannière victoire selon winner_team :
    - village : fond #0f1a0a, bordure #16a34a, texte #4ade80 "🏆 VICTOIRE DU VILLAGE"
    - loups : fond #1a0000, bordure #8b0000, texte #ff4444 "🐺 LES LOUPS ONT GAGNÉ"
  → animations GSAP : scale 0.7→1 + confettis dorés (village) ou particules rouges (loups)
  → révélation des rôles en cascade (stagger 0.1s) : avatar + pseudo + badge rôle coloré + ✅/❌
  → boutons Rejouer (→ /lobby?pseudo=X) et Accueil (→ /)

- resources/views/game/cancelled.blade.php : partie annulée (winner_team=null)
  → fond #111827, bordure #f97316, titre "PARTIE ANNULÉE" en Cinzel #f97316
  → liste joueurs avec statut connecté/déconnecté — rôles NON révélés
  → même boutons

- Alpine.js dans day.blade.php et night.blade.php :
  → à '.game.finished' :
    - si winner_team != null → redirect vers /game/{code}/finished après 2s
    - si winner_team = null → redirect vers /game/{code}/cancelled après 2s

- resources/views/game/dead-spectator.blade.php : Écran 12 (joueur mort)
  → bandeau mort : fond #1a0000, "💀 Tu es mort", sous-titre italique
  → filtre CSS filter:grayscale(30%) sur l'interface globale (GSAP transition)
  → chat en lecture seule (input remplacé par "Tu es mort. Observe en silence.")
  → si ancien loup : onglet supplémentaire "Chat Loups 🐺" (lecture seule, channel werewolves)
  → liste joueurs avec badges vivant/mort et rôles connus (loups morts voient les rôles des loups vivants)

- Alpine.js dans day.blade.php / night.blade.php :
  → à '.player.eliminated' si player_id == currentPlayerId :
    - isAlive = false
    - gsap.to('.game-screen', {filter:'grayscale(30%)', duration:1})
    - afficher bandeau personnel de mort

- routes/web.php : ajouter
  GET /game/{code}/finished → GameController@finished (middleware auth)
  GET /game/{code}/cancelled → GameController@cancelled (middleware auth)

CONTRAINTES :
- Les rôles ne sont révélés QUE si winner_team != null
- Le joueur mort continue à recevoir tous les events WebSocket publics

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 23 — Scheduler suppression des parties

```
Contexte : lis SPEC.md §4 (Suppression automatique). Tâche 1 terminée (cascadeOnDelete requis).

Crée/modifie exactement les fichiers suivants :

- app/Console/Commands/CleanOldGames.php
  → signature : 'games:clean'
  → description : 'Supprime les parties terminées depuis plus de 7 jours'
  → handle() :
    - $count = Game::where('finished_at', '<', now()->subDays(7))->delete()
    - $this->info("$count partie(s) supprimée(s).")
    - Log::info("CleanOldGames: $count parties supprimées")

- routes/console.php (Laravel 11) : ajouter
  Schedule::command('games:clean')->daily();

VÉRIFIER que toutes les migrations ont bien cascadeOnDelete sur les FK :
- game_players → game_id
- game_actions → game_id
- chat_messages → game_id
- exclusions → game_id
→ si une FK manque le cascade, créer une migration corrective

CONTRAINTES :
- Ne supprimer que les parties avec finished_at renseigné et > 7 jours
- Les parties en statut 'waiting' ou 'night'/'day' sans finished_at ne sont pas supprimées

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 24 — Frontend — Layout principal + composants Blade réutilisables

```
Contexte : lis SPEC.md §10 (Identité visuelle, Composants Blade). Tâche 2 terminée (auth requise).

Crée exactement les fichiers suivants :

- tailwind.config.js : étendre avec
  → colors : night.deep=#0a0f1e, night.card=#111827, night.darkest=#030712, gold.DEFAULT=#c9a84c, gold.light=#e2c16e, parchment=#e8e0d0, blood.DEFAULT=#8b0000, blood.light=#ff4444, seer=#7c3aed
  → fontFamily : medieval:['Cinzel','serif'], body:['EB Garamond','serif']
  → boxShadow : gold, gold-lg, blood, blood-lg, seer
  → animation + keyframes : float (translateY 0→-8px), pulse-gold (boxShadow pulsation)

- resources/css/app.css : @import Google Fonts (Cinzel + EB Garamond), @tailwind directives
  + media query prefers-reduced-motion : désactiver toutes les animations

- resources/views/layouts/game.blade.php
  → header fixe h-14/h-16, fond #111827, bordure basse #c9a84c/20 : logo 🐺, phase+round, timer compact, pseudo+avatar joueur courant
  → main : pt-14, min-h-screen, fond #0a0f1e
  → footer mobile fixe bas (md:hidden) : onglets de navigation selon la phase
  → @stack('scripts') pour les scripts GSAP spécifiques à chaque page

- resources/views/components/game-timer.blade.php
  → props : seconds, color (gold|red|purple)
  → barre GSAP (width 100%→0% en $seconds secondes), changement couleur or→orange→rouge
  → compteur numérique qui décrémente, Alpine.js $dispatch('timer-expired') à 0
  → prefers-reduced-motion : afficher uniquement le compteur numérique sans animation

- resources/views/components/player-avatar.blade.php
  → props : player, size (sm|md|lg)
  → fond coloré généré depuis hash du pseudo (HSL), initiales
  → badges : 👑 si is_mayor, 💀 si !is_alive, "Toi" si joueur courant

- resources/views/components/player-list.blade.php
  → props : players, show-votes (bool), show-roles (bool)
  → itère les joueurs avec <x-player-avatar>
  → si show-votes : mini-barre de votes
  → si show-roles : badge rôle coloré

- resources/views/components/chat-panel.blade.php
  → props : channel (general|werewolves), readonly (bool)
  → zone messages avec scroll auto ($nextTick Alpine.js)
  → input + bouton envoi si !readonly
  → si readonly : label explicatif
  → compteur caractères : visible à partir de 150, orange à 180, rouge à 195

- resources/views/components/role-card.blade.php
  → props : role, revealed (bool)
  → dos : motif SVG médiéval, "?" Cinzel, animation flottement
  → face : illustration placeholder + nom + description
  → GSAP retournement au clic si !revealed

- resources/views/components/phase-header.blade.php
  → props : phase, round
  → icône (🌙☀️👑), titre phase, numéro round, badge rôle joueur courant

- resources/views/components/toast.blade.php
  → fixed bottom-4 right-4, z-50
  → types : error (#8b0000), success (#16a34a), info (#c9a84c)
  → Alpine.js x-data, auto-dismiss 3s avec GSAP slide out
  → aria-live="polite" pour accessibilité

CONTRAINTES :
- Tous les éléments interactifs : focus:ring-2 focus:ring-[#c9a84c]
- Attributs ARIA sur les régions (role="main", role="log" sur chat, aria-label sur boutons)
- prefers-reduced-motion global dans app.css

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 25 — Alpine.js store central gameState

```
Contexte : lis SPEC.md §10 (Gestion WebSocket côté client). Tâche 24 terminée.

Crée exactement les fichiers suivants :

- resources/js/game-state.js : fonction gameState(gameId, userId)
  → propriétés : phase, round, players, myRole, isMayor, isAlive, chat, wolvesChat, votes, wolvesVotes, nightVictim, winnerTeam, seerResult, seerTurnActive, werewolvesTurnActive
  → initWebSocket() :
    - Echo.channel('game.X') : écouter tous les events publics listés dans SPEC.md §6
    - Echo.private('game.X.player.Y') : écouter role.assigned, seer.turn.started, seer.result
    - si myRole isWerewolf : Echo.private('game.X.werewolves') : écouter werewolves.turn.started, werewolves.vote.cast, werewolf.chat.message
  → handlers avec animations GSAP :
    - handleDayStarted(e) : phase='day', nightVictim=e.killed, transition GSAP nuit→jour
    - handlePlayerEliminated(e) : GSAP grayscale sur carte joueur, si currentPlayer → isAlive=false + bandeau mort
    - handleGameFinished(e) : winnerTeam=e.winner_team, GSAP bannière victoire, redirect après 2s
    - handleMayorElected(e) : mettre à jour le badge 👑 dans la liste players
    - handleNightStarted(e) : phase='night', transition GSAP jour→nuit
  → sendMessage(channel) :
    - vérifier isAlive && (phase='day' ou phase='electing_mayor' pour general)
    - POST /game/{id}/chat
  → castVote(type, targetPlayerId) :
    - POST /game/{id}/vote/{type}
    - à la réponse : GSAP mise à jour barre de votes
  → gestion déconnexion WebSocket :
    - Echo.connector.pusher.connection.bind('disconnected', ...) → toast "Reconnexion..."
    - Echo.connector.pusher.connection.bind('connected', ...) → toast "Reconnecté !"

- resources/js/timer-state.js : fonction timerState(seconds)
  → propriétés : remaining, percentage, colorClass
  → start() : GSAP timeline sur la barre + setInterval pour le compteur
  → stop() : gsap.killTweensOf(), clearInterval
  → onExpired(callback) : appeler callback à 0

- resources/js/app.js : importer et enregistrer gameState + timerState comme composants Alpine

CONTRAINTES :
- Aucun localStorage (les données de jeu viennent du serveur)
- Les animations GSAP sont dans les handlers, pas dans les composants Blade
- Chaque handler GSAP doit vérifier prefers-reduced-motion avant d'animer

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 26 — Landing Page (Écran 1)

```
Contexte : lis SPEC.md §10 (SEO), §11 (Écran 1), ui_ux_contexte §ÉCRAN 1. Tâche 24 terminée.

Crée exactement les fichiers suivants :

- resources/views/landing.blade.php : 4 sections
  HERO :
  → illustration pleine page (public/images/ui/hero-village.jpg ou placeholder fond #0a0f1e)
  → overlay gradient, texte centré : "🐺 LOUP-GAROU" (Cinzel #c9a84c), "Le village a peur la nuit" (EB Garamond)
  → CTA "Jouer maintenant" : si auth → redirect /lobby, sinon → redirect /auth/google
  → parallaxe léger au scroll (GSAP ScrollTrigger)

  SECTION EXPLICATION :
  → 4 blocs icône + texte : 🌙 nuit, ☀️ jour, 🏆 village gagne, 🐺 loups gagnent
  → fade in au scroll (GSAP ScrollTrigger)

  SECTION RÔLES :
  → 4 cartes (Loup-Garou, Voyante, Villageois, Maire) avec illustrations placeholder
    - Loup : bg-red-900 + 🐺 | Voyante : bg-violet-900 + 🔮 | Villageois : bg-green-900 + 🪓 | Maire : bg-yellow-900 + 👑
  → descriptions depuis SPEC.md §10
  → fade in + slide up au scroll (GSAP ScrollTrigger, stagger)

  SECTION CTA FINALE :
  → "6 joueurs minimum, ~15 minutes par partie"
  → bouton CTA + "Connexion avec Google requise"

- resources/js/landing.js : GSAP ScrollTrigger pour les animations de section

- routes/web.php : modifier GET / pour retourner landing.blade.php

META TAGS dans le layout :
  → title : "Loup-Garou Undu — Joue en ligne avec tes amis"
  → description : "Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle."

CONTRAINTES :
- prefers-reduced-motion : désactiver le parallaxe et les animations scroll
- CTA adaptatif selon l'état d'authentification (vérifier auth()->check() côté Blade)

Quand c'est fait, liste les fichiers créés/modifiés.
```

---

## TÂCHE 27 — Tests — Authentification et Lobby

```
Contexte : lis CONVENTIONS.md §Tests. Tâches 2, 3, 4, 6 terminées.

Crée exactement les fichiers suivants :

- tests/Feature/Auth/GoogleAuthTest.php
  → test : callback crée un nouvel User si google_id inconnu
  → test : callback met à jour l'User existant (email/name) si google_id connu
  → test : callback crée la session (utilisateur authentifié après callback)
  → test : exception Socialite → redirect vers login avec message d'erreur

- tests/Feature/Game/CreateGameTest.php
  → test : création réussie → code 6 chars unique, is_host=true, status=waiting
  → test : max_players invalide (5, 7, 13) → 422
  → test : pseudo vide → 422
  → test : pseudo trop court (<2 chars) → 422
  → test : code collision → retry jusqu'à trouver un code unique
  → test : non authentifié → 401/redirect

- tests/Feature/Game/JoinGameTest.php
  → test : rejoindre une partie waiting réussie
  → test : rejoindre une partie non-waiting → 409
  → test : rejoindre une partie pleine → 409
  → test : joueur exclu → 403
  → test : code inexistant → 404
  → test : race condition — deux joueurs rejoignent simultanément → un seul dépasse, l'autre reçoit 409
    (utiliser deux requêtes concurrentes via Process ou parallel testing)

- tests/Feature/Game/ExcludePlayerTest.php
  → test : host peut exclure un joueur (status=waiting, motif fourni)
  → test : non-host ne peut pas exclure → 403
  → test : exclusion en phase non-waiting → 409
  → test : motif vide → 422
  → test : joueur exclu ne peut plus rejoindre la même partie → 403
  → test : PlayerExcluded broadcasté (utiliser Event::fake())

CONVENTIONS :
- Utiliser les factories (créées tâche 1) pour les fixtures
- RefreshDatabase sur chaque test
- Event::fake() pour vérifier les broadcasts sans Reverb

Quand c'est fait, liste les fichiers créés.
```

---

## TÂCHE 28 — Tests — Distribution des rôles et démarrage

```
Contexte : lis CONVENTIONS.md §Tests. Tâches 7 et 8 terminées.

Crée exactement les fichiers suivants :

- tests/Unit/Services/RoleDistributorTest.php
  → test 6 joueurs → exactement 1 loup, 1 voyante, 4 villageois
  → test 8 joueurs → exactement 2 loups, 1 voyante, 5 villageois
  → test 10 joueurs → exactement 2 loups, 1 voyante, 7 villageois
  → test 12 joueurs → exactement 3 loups, 1 voyante, 8 villageois
  → test : chaque joueur reçoit exactement un rôle (pas de doublon, pas de null)
  → test : distribution aléatoire (même joueur ne reçoit pas toujours le même rôle — run 10x)

- tests/Feature/Game/StartGameTest.php
  → test : démarrage automatique quand max_players atteint
  → test : status passe à 'electing_mayor' après démarrage
  → test : GameStarted broadcasté (Event::fake())
  → test : race condition — deux insertions simultanées déclenchent startGame() une seule fois
  → test : RoleAssigned broadcasté pour chaque joueur sur son channel privé
  → test : les loups reçoivent la liste de leurs alliés, les villageois et voyante non

CONVENTIONS : factories, RefreshDatabase, Event::fake()

Quand c'est fait, liste les fichiers créés.
```

---

## TÂCHE 29 — Tests — Élection du Maire

```
Contexte : lis CONVENTIONS.md §Tests. Tâches 10 et 11 terminées.

Crée exactement le fichier suivant :

- tests/Feature/Game/MayorElectionTest.php
  → test : un joueur peut voter (un seul vote par joueur — deuxième tentative → 409)
  → test : voter pour soi-même est autorisé
  → test : MayorVoteCast broadcasté avec les totaux anonymisés (pas de player_id votant)
  → test : ProcessMayorElection — joueur avec plus de votes est élu
  → test : ProcessMayorElection — égalité → was_random=true, un joueur parmi les ex-aequo est élu
  → test : ProcessMayorElection — aucun vote → maire aléatoire (was_random=true)
  → test : MayorElected broadcasté avec le bon joueur
  → test : après MayorElected → game.status='night', game.round=1

CONVENTIONS : factories, RefreshDatabase, Event::fake(), Queue::fake() pour les Jobs

Quand c'est fait, liste le fichier créé.
```

---

## TÂCHE 30 — Tests — Phase Nuit

```
Contexte : lis CONVENTIONS.md §Tests. Tâches 12 et 13 terminées.

Crée exactement le fichier suivant :

- tests/Feature/Game/NightPhaseTest.php
  → test voyante : résultat correct (rôle de la cible révélé) uniquement sur channel privé
  → test voyante : ne peut pas s'inspecter elle-même → 422
  → test voyante : ne peut pas agir deux fois par nuit → 409
  → test voyante : skip si inactive → ProcessWerewolvesTurn dispatché sans SeerResult
  → test loups : cible avec plus de votes est tuée
  → test loups : égalité → cible aléatoire parmi ex-aequo
  → test loups : un loup ne peut pas voter pour un autre loup → 422
  → test loups : aucun vote → pas de victime (DayStarted avec killed=null)
  → test loups : vote modifiable jusqu'à expiration timer (updateOrCreate)
  → test chat loups : uniquement loups vivants en phase nuit → 403 sinon
  → test chat loups : WerewolfChatMessage sur channel werewolves, pas sur général
  → test ProcessNightResult : victime marquée is_alive=false, DayStarted broadcasté

CONVENTIONS : factories, RefreshDatabase, Event::fake(), Queue::fake()

Quand c'est fait, liste le fichier créé.
```

---

## TÂCHE 31 — Tests — Phase Jour

```
Contexte : lis CONVENTIONS.md §Tests. Tâches 15 et 16 terminées.

Crée exactement le fichier suivant :

- tests/Feature/Game/DayPhaseTest.php
  → test : vote d'élimination réussi
  → test : voter pour soi-même → 422
  → test : voter pour un mort → 422
  → test : voter deux fois → 409
  → test : DayVoteCast anonymisé (pas de player_id votant)
  → test : poids maire (weight=2) — maire + un villageois contre X → X éliminé même si 2vs1
  → test : égalité → NoElimination (reason='equality')
  → test : aucun vote → NoElimination (reason='no_vote')
  → test : joueur éliminé → is_alive=false, PlayerEliminated avec rôle révélé
  → test : chat général — joueur vivant peut écrire en phase day
  → test : chat général — joueur mort → 403
  → test : chat général — phase night → 403
  → test : message > 200 chars → 422
  → test : succession maire si maire éliminé (MayorSuccessionStarted broadcasté)

CONVENTIONS : factories, RefreshDatabase, Event::fake()

Quand c'est fait, liste le fichier créé.
```

---

## TÂCHE 32 — Tests — Conditions de victoire

```
Contexte : lis CONVENTIONS.md §Tests. Tâche 14 terminée (WinConditionChecker).

Crée exactement les fichiers suivants :

- tests/Unit/Services/WinConditionCheckerTest.php
  → test : village gagne si 0 loups vivants
  → test : loups gagnent si nb_loups >= nb_autres (ex: 1 loup, 1 villageois)
  → test : pas de victoire si nb_loups < nb_autres (ex: 1 loup, 2 villageois)
  → test : check() retourne 'villagers' | 'werewolves' | null

- tests/Feature/Game/VictoryTest.php
  → test : victoire village après mort de nuit d'un loup
  → test : victoire loups après élimination de jour d'un villageois (équilibre atteint)
  → test : GameFinished broadcasté avec tous les rôles révélés
  → test : game.status='finished', game.winner_team, game.finished_at renseignés
  → test : partie annulée (>50% inactifs) → winner_team=null, rôles NON révélés dans GameFinished

CONVENTIONS : factories, RefreshDatabase, Event::fake()

Quand c'est fait, liste les fichiers créés.
```

---

## TÂCHE 33 — Tests — Succession Maire et inactivité

```
Contexte : lis CONVENTIONS.md §Tests. Tâches 17 et 18 terminées.

Crée exactement les fichiers suivants :

- tests/Feature/Game/MayorSuccessionTest.php
  → test : maire mort désigne successeur → is_mayor transféré, MayorSuccessionDone (was_random=false)
  → test : timer expiré sans désignation → successeur aléatoire (was_random=true)
  → test : maire inactif → désignation aléatoire immédiate (delay=0)
  → test : non-maire ne peut pas déclencher la succession → 403
  → test : désignation d'un joueur mort → 422

- tests/Feature/Game/InactivityTest.php
  → test : CheckReconnectionTimeout → is_inactive=true après 30s
  → test : reconnexion → is_inactive=false, PlayerReconnected broadcasté
  → test : >50% joueurs vivants inactifs → partie annulée (winner_team=null)
  → test : GET /game/{code}/state → restaure les données correctes (phase, rôle, timer restant)
  → test : loup reconnecté pendant son tour (timer pas expiré) → seer_turn_active / werewolves_turn_active correct dans /state

CONVENTIONS : factories, RefreshDatabase, Event::fake(), Queue::fake()

Quand c'est fait, liste les fichiers créés.
```

---

## TÂCHE 34 — Tests — Historique et politique d'accès

```
Contexte : lis CONVENTIONS.md §Tests. Tâche 21 terminée.

Crée exactement le fichier suivant :

- tests/Feature/Game/HistoryTest.php
  → test : participant authentifié peut accéder à l'historique d'une partie terminée
  → test : non-participant → 403
  → test : partie non terminée → 403 (ou 404)
  → test : scope anonymized() — player_id absent des game_actions retournées
  → test : tous les rôles sont révélés dans l'historique
  → test : utilisateur non authentifié → redirect vers login

CONVENTIONS : factories, RefreshDatabase

Quand c'est fait, liste le fichier créé.
```

---

## TÂCHE 35 — Tests — Phase Nuit (Voyante morte / Loup seul)

```
Contexte : lis SPEC.md §4 cas limites. Tâches 12 et 13 terminées.

Ces cas n'ont pas été couverts dans la tâche 30. Ajouter dans NightPhaseTest.php :

  → test : voyante morte → ProcessSeerTurn skip, ProcessWerewolvesTurn dispatché directement
  → test : un seul loup vivant → vote valide (pas de bug avec un seul électeur)
  → test : mayor tué la nuit → MayorSuccessionStarted broadcasté avant DayStarted
  → test : tous les loups inactifs → aucune victime (no_vote)
  → test : WinConditionChecker appelé après chaque mort de nuit

CONVENTIONS : factories, RefreshDatabase, Event::fake(), Queue::fake()

Quand c'est fait, confirme les ajouts dans NightPhaseTest.php.
```

---

## TÂCHE 36 — Tests — Phase Jour (cas limites maire)

```
Contexte : lis SPEC.md §4 cas limites. Tâche 31 terminée.

Ces cas n'ont pas été couverts dans la tâche 31. Ajouter dans DayPhaseTest.php :

  → test : maire éliminé le jour → MayorSuccessionStarted, puis startNight() seulement après MayorSuccessionDone
  → test : WinConditionChecker appelé après chaque élimination de jour
  → test : NoElimination → startNight() appelé quand même (partie continue)
  → test : nouveau maire après succession peut voter avec weight=2 dès le même round

CONVENTIONS : factories, RefreshDatabase, Event::fake()

Quand c'est fait, confirme les ajouts dans DayPhaseTest.php.
```

---

## TÂCHE 37 — Tests — Chat (toutes les règles de visibilité)

```
Contexte : lis SPEC.md §4 (Chat règles de visibilité). Tâche 31 terminée.

Ces cas complètent la couverture du ChatService. Créer :

- tests/Unit/Services/ChatServiceTest.php
  → test : canWrite general — joueur vivant en phase day → true
  → test : canWrite general — joueur vivant en phase night → false
  → test : canWrite general — joueur mort → false
  → test : canWrite general — phase electing_mayor → true
  → test : canWrite werewolves — loup vivant en phase night → true
  → test : canWrite werewolves — loup vivant en phase day → false
  → test : canWrite werewolves — villageois → false
  → test : canRead werewolves — loup mort → true
  → test : canRead werewolves — villageois → false

CONVENTIONS : factories, RefreshDatabase (pas besoin pour Unit si on mock le Game)

Quand c'est fait, liste le fichier créé.
```

---

## TÂCHE 38 — Tests — scope anonymized() et sécurité

```
Contexte : lis SPEC.md §4 (Anonymat des votes), CONVENTIONS.md §Sécurité.

Crée exactement le fichier suivant :

- tests/Unit/Models/GameActionTest.php
  → test : scopeAnonymized() ne retourne pas player_id dans les colonnes
  → test : scopeAnonymized() retourne toutes les lignes (pas de filtre sur le type)
  → test : scopeAnonymized() retourne bien target_player_id, type, weight, round, phase
  → test : MayorVoteCast payload ne contient pas player_id (test sur le broadcastWith())
  → test : DayVoteCast payload ne contient pas player_id
  → test : SeerResult broadcasté uniquement sur channel privé (pas sur Channel public)

CONVENTIONS : factories, RefreshDatabase

Quand c'est fait, liste le fichier créé.
```

---

## TÂCHE 39 — Tests — Race conditions critiques

```
Contexte : lis SPEC.md §13 (Race conditions). Tâches 4, 8, 10, 15 terminées.

Crée exactement le fichier suivant :

- tests/Feature/Game/RaceConditionTest.php
  → test joinGame race : créer une partie max_players=6, simuler 6 insertions quasi-simultanées → exactement 6 joueurs en base, pas 7
  → test startGame race : vérifier que startGame() ne peut être appelé deux fois (status change atomique)
  → test mayorVote race : deux votes simultanés du même joueur → un seul inséré en base
  → test dayVote race mayor : is_mayor lu dans la transaction → weight=2 correct même si changement de maire concurrent

Note : ces tests peuvent utiliser des goroutines PHP (parallel) ou simplement simuler le scénario séquentiellement en vérifiant les guards dans les services.

CONVENTIONS : factories, RefreshDatabase

Quand c'est fait, liste le fichier créé.
```

---

## TÂCHE 40 — Recette finale et optimisations

```
Contexte : toutes les tâches précédentes terminées. Lis SPEC.md en entier une dernière fois.

Vérifie et corrige dans l'ordre :

SÉCURITÉ :
1. routes/channels.php — chaque channel privé a bien sa vérification d'appartenance
2. Chaque Controller vérifie game_id + player_id cohérents (anti-spoofing)
3. Aucune réponse publique n'expose un rôle de joueur vivant
4. Middleware 'auth' présent sur toutes les routes protégées

ACCESSIBILITÉ :
5. prefers-reduced-motion respecté dans app.css ET dans les handlers GSAP de game-state.js
6. Contraste WCAG AA vérifié sur : #c9a84c sur #0a0f1e, #e8e0d0 sur #111827, #ff4444 sur #1a0000
7. focus:ring-2 focus:ring-[#c9a84c] présent sur tous les éléments interactifs
8. aria-label sur tous les boutons d'action, role="log" sur les zones de chat

FONCTIONNEL :
9. Flux complet (landing → auth → lobby → partie 6 joueurs → fin) sans erreur console
10. Flux reconnexion : couper la connexion en cours de nuit et de jour, vérifier restauration via /state
11. Partage lien d'invite : copier le lien, l'ouvrir dans un autre navigateur, vérifier pré-remplissage code
12. Paste input 6 cases : coller un code seul (ABC123) + coller une URL complète (?code=ABC123)
13. Suppression en cascade : créer une partie, la terminer, attendre scheduler ou appeler games:clean manuellement

PRODUCTION :
14. config/queue.php → driver=redis en production
15. Supervisor : workers + Reverb configurés (process.md §VPS-7)
16. php artisan config:cache && route:cache && view:cache en production
17. RoleDistributor extensible : ajouter un rôle fictif 'witch'=>1 dans config/game.php et vérifier que distribute() l'intègre sans modifier la logique core

Génère un rapport final : liste les points OK et ceux nécessitant une correction.
```

---

## NOTES D'UTILISATION

### Ordre recommandé
Suivre la numérotation 1→40. Les dépendances sont respectées dans cet ordre.

### Correction mid-session
Si Claude Code dévie ou fait une erreur :
```
Stop. Tu viens de [décrire l'erreur].
La règle dans CONVENTIONS.md dit [citer la règle].
Corrige uniquement [fichier concerné] sans toucher aux autres fichiers.
```

### Ajout de contexte si nécessaire
Pour les tâches complexes (13, 14, 18), si Claude Code manque de contexte :
```
Relis SPEC.md §4 section "[nom de la section]" avant de continuer.
```

### Vérification après chaque tâche
```
Liste les fichiers que tu as créés ou modifiés.
Y a-t-il des points que tu n'as pas pu implémenter exactement comme spécifié ?
Quelles hypothèses as-tu faites qui ne sont pas dans la spec ?
```
