# Laravel Core Logic Analysis

Generated at: 16h58

## PHP Analysis (Core Logic)

// app/Console/Commands/CleanOldGames.php
CleanOldGames.php
    attributes:
      - signature
      - description
    functions:
      - handle() → return self::SUCCESS

// app/Models/Game.php
Game.php
    attributes:
      - HasFactory
      - fillable
    functions:
      - casts() → return ['phase_deadline' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'timers' => 'array', 'settings' => 'array']
      - timer(string $key) → return TimerCalculator::get($this, $key)
      - players() → return $this->hasMany(GamePlayer::class)
      - alivePlayers() → return $this->hasMany(GamePlayer::class)->where('is_alive', true)
      - actions() → return $this->hasMany(GameAction::class)
      - messages() → return $this->hasMany(ChatMessage::class)
      - exclusions() → return $this->hasMany(Exclusion::class)
      - phaseRemainingSeconds() → return max(0, (int) now()->diffInSeconds($this->phase_deadline, false))
      - getStatus() → return $this->status
      - setStatus(string $status, array $context) → void
      - canTransition(string $transitionName) → return $registry->get($this)->can($this, $transitionName)
      - applyTransition(string $transitionName) → void

// app/Models/GameAction.php
GameAction.php
    attributes:
      - HasFactory
      - timestamps
      - fillable
    functions:
      - player() → return $this->belongsTo(GamePlayer::class, 'player_id')
      - target() → return $this->belongsTo(GamePlayer::class, 'target_player_id')
      - scopeAnonymized(Builder $query) → return $query->select(['id', 'game_id', 'type', 'weight', 'target_player_id', 'round', 'phase', 'created_at'])

// app/Models/ChatMessage.php
ChatMessage.php
    attributes:
      - HasFactory
      - timestamps
      - fillable
    functions:
      - game() → return $this->belongsTo(Game::class)
      - player() → return $this->belongsTo(GamePlayer::class, 'player_id')

// app/Models/User.php
User.php
    attributes:
      - HasFactory
      - Notifiable
      - HasPushSubscriptions
      - fillable
      - hidden
    functions:
      - casts() → return []
      - gamePlayers() → return $this->hasMany(GamePlayer::class)
      - getRememberTokenName() → return null

// app/Models/Exclusion.php
Exclusion.php
    attributes:
      - HasFactory
      - timestamps
      - fillable
    functions:
      - casts() → return ['excluded_at' => 'datetime']
      - game() → return $this->belongsTo(Game::class)
      - user() → return $this->belongsTo(User::class)
      - player() → return $this->belongsTo(GamePlayer::class, 'player_id')

// app/Models/GamePlayer.php
GamePlayer.php
    attributes:
      - HasFactory
      - timestamps
      - fillable
    functions:
      - casts() → return ['is_alive' => 'boolean', 'is_host' => 'boolean', 'is_mayor' => 'boolean', 'is_inactive' => 'boolean', 'is_ready' => 'boolean', 'joined_at' => 'datetime', 'settings' => 'array']
      - game() → return $this->belongsTo(Game::class)
      - user() → return $this->belongsTo(User::class)
      - actions() → return $this->hasMany(GameAction::class, 'player_id')
      - isWerewolf() → return in_array($this->role, ['werewolf', 'white_wolf'])
      - isVillagerSide() → return in_array($this->role, ['villager', 'seer', 'witch', 'hunter'])
      - isWitch() → return $this->role === 'witch'
      - isHunter() → return $this->role === 'hunter'

// app/Jobs/ProcessMayorSuccession.php
ProcessMayorSuccession.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId, int $round, bool $shouldStartNight) {}
      - handle(PhaseManager $phaseManager) → void

// app/Jobs/ProcessWerewolvesTurn.php
ProcessWerewolvesTurn.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId) {}
      - handle() → void

// app/Jobs/ProcessNightActions.php
ProcessNightActions.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId, int $round) {}
      - handle(VoteService $voteService, WinConditionChecker $winChecker) → void

// app/Jobs/ProcessNightEnd.php
ProcessNightEnd.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId, int $round) {}
      - handle(PhaseManager $phaseManager) → void

// app/Jobs/ProcessWitchTurn.php
ProcessWitchTurn.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId, int $round) {}
      - handle(VoteService $voteService) → void

// app/Jobs/ProcessDayVote.php
ProcessDayVote.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId, int $round) {}
      - handle(VoteService $voteService) → void

// app/Jobs/ProcessHunterAutoAction.php
ProcessHunterAutoAction.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId, int $round, int $hunterId, bool $fromNight) {}
      - handle(PhaseManager $phaseManager, WinConditionChecker $winChecker) → void

// app/Jobs/ProcessSeerTurn.php
ProcessSeerTurn.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId) {}
      - handle() → void

// app/Jobs/ProcessWitchAutoAction.php
ProcessWitchAutoAction.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId, int $round) {}
      - handle() → void

// app/Jobs/CheckReconnectionTimeout.php
CheckReconnectionTimeout.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $playerId, string $disconnectToken) {}
      - handle(GameService $gameService) → void

// app/Jobs/ProcessMayorElection.php
ProcessMayorElection.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId) {}
      - handle(VoteService $voteService) → void

// app/Jobs/WaitForReadyPlayers.php
WaitForReadyPlayers.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId) {}
      - handle() → void

// app/Jobs/ProcessSeerAutoAction.php
ProcessSeerAutoAction.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId, int $seerId, int $round) {}
      - handle() → void

// app/Jobs/ProcessHunterTurn.php
ProcessHunterTurn.php
    attributes:
      - Dispatchable
      - InteractsWithQueue
      - Queueable
      - SerializesModels
    functions:
      - __construct(int $gameId, int $round, int $hunterId) {}
      - handle(PhaseManager $phaseManager, WinConditionChecker $winChecker) → void

// app/Events/Game/PlayerInactive.php
PlayerInactive.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(int $gameId, string $pseudo) {}
      - fromPlayer(GamePlayer $player) → return new self($player->game_id, $player->pseudo)
      - broadcastOn() → return [new Channel("game.{$this->gameId}")]
      - broadcastAs() → return 'player.inactive'
      - broadcastWith() → return ['pseudo' => $this->pseudo]

// app/Events/Game/WitchActedPublic.php
WitchActedPublic.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'witch.acted.public'
      - broadcastWith() → return []

// app/Events/Game/WitchActed.php
WitchActed.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $witch, string $action, ?GamePlayer $target) {}
      - broadcastOn() → return [new PrivateChannel("game.{$this->game->id}.player.{$this->witch->id}")]
      - broadcastAs() → return 'witch.acted'
      - broadcastWith() → return ['action' => $this->action, 'target_player_id' => $this->target?->id, 'pseudo' => $this->target?->pseudo]

// app/Events/Game/NightStarted.php
NightStarted.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'night.started'
      - broadcastWith() → return ['round' => $this->game->round, 'timer' => $this->game->timer('seer')]

// app/Events/Game/PlayerJoined.php
PlayerJoined.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $player) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'player.joined'
      - broadcastWith() → return ['pseudo' => $this->player->pseudo, 'players' => $players, 'slots_remaining' => $this->game->max_players - count($players)]

// app/Events/Game/PlayerReady.php
PlayerReady.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, int $nbReady, int $total) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'player.ready'
      - broadcastWith() → return ['nb_ready' => $this->nbReady, 'total' => $this->total]

// app/Events/Game/DayVoteCast.php
DayVoteCast.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, array $summary) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'day.vote.cast'
      - broadcastWith() → return ['votes' => collect($this->summary)->map(fn($totalWeight, $targetPlayerId) => ['target_player_id' => $targetPlayerId, 'total_weight' => $totalWeight])->values()->toArray()]

// app/Events/Game/MayorVoteCast.php
MayorVoteCast.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, array $votes) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'mayor.vote.cast'
      - broadcastWith() → return ['votes' => $this->votes]

// app/Events/Game/MayorSuccessionDone.php
MayorSuccessionDone.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $newMayor, bool $wasRandom) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'mayor.succession.done'
      - broadcastWith() → return ['new_mayor_id' => $this->newMayor->id, 'new_mayor_pseudo' => $this->newMayor->pseudo, 'was_random' => $this->wasRandom]

// app/Events/Game/NoElimination.php
NoElimination.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, string $reason) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'no.elimination'
      - broadcastWith() → return ['reason' => $this->reason]

// app/Events/Game/HunterTurnStarted.php
HunterTurnStarted.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $hunter) {}
      - broadcastOn() → return [new PrivateChannel("game.{$this->game->id}.player.{$this->hunter->id}")]
      - broadcastAs() → return 'hunter.turn.started'
      - broadcastWith() → return ['timer' => $this->game->timer('hunter')]

// app/Events/Game/WitchTurnStarted.php
WitchTurnStarted.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $witch, ?GamePlayer $victim, bool $healAvailable, bool $killAvailable) {}
      - broadcastOn() → return [new PrivateChannel("game.{$this->game->id}.player.{$this->witch->id}")]
      - broadcastAs() → return 'witch.turn.started'
      - broadcastWith() → return ['victim' => $this->victim ? ['id' => $this->victim->id, 'pseudo' => $this->victim->pseudo] : null, 'heal_available' => $this->healAvailable, 'kill_available' => $this->killAvailable, 'timer' => $this->game->timer('witch')]

// app/Events/Game/GameStarted.php
GameStarted.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
      - gameId
      - targetPlayerId
      - targetRole
      - allies
    functions:
      - __construct(Game $game, ?GamePlayer $player, ?array $allies) {}
      - broadcastOn() → return [new PrivateChannel("game.{$this->gameId}.player.{$this->targetPlayerId}")]
      - broadcastAs() → return 'game.started'
      - broadcastWith() → return ['players' => $publicPlayers, 'role' => $this->targetRole, 'allies' => $this->allies ?? []]

// app/Events/Game/PlayerEliminated.php
PlayerEliminated.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $player, string $reason) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'player.eliminated'
      - broadcastWith() → return ['player_id' => $this->player->id, 'pseudo' => $this->player->pseudo, 'role' => $this->player->role, 'reason' => $this->reason]

// app/Events/Game/HunterShot.php
HunterShot.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $hunter, GamePlayer $target) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'hunter.shot'
      - broadcastWith() → return ['hunter_pseudo' => $this->hunter->pseudo, 'target_player_id' => $this->target->id, 'target_pseudo' => $this->target->pseudo]

// app/Events/Game/SeerResult.php
SeerResult.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $seer, GamePlayer $target) {}
      - broadcastOn() → return [new PrivateChannel("game.{$this->game->id}.player.{$this->seer->id}")]
      - broadcastAs() → return 'seer.result'
      - broadcastWith() → return ['target_player_id' => $this->target->id, 'pseudo' => $this->target->pseudo, 'role' => $this->target->role]

// app/Events/Game/MayorElectionStarted.php
MayorElectionStarted.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'mayor.election.started'
      - broadcastWith() → return ['timer' => $this->game->timer('mayor_election')]

// app/Events/Game/DayStarted.php
DayStarted.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, ?GamePlayer $victim, bool $witchActed, ?int $savedPlayerId) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'day.started'
      - broadcastWith() → return ['round' => $this->game->round, 'killed' => $this->victim ? ['player_id' => $this->victim->id, 'pseudo' => $this->victim->pseudo, 'role' => $this->victim->role] : null, 'witch_acted' => $this->witchActed, 'saved_player_id' => $this->savedPlayerId]

// app/Events/Game/WerewolvesVoteCast.php
WerewolvesVoteCast.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, array $wolfVoteState) {}
      - broadcastOn() → return [new PrivateChannel("game.{$this->game->id}.werewolves")]
      - broadcastAs() → return 'werewolves.vote.cast'
      - broadcastWith() → return ['wolves' => $this->wolfVoteState]

// app/Events/Game/WerewolfChatMessage.php
WerewolfChatMessage.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $player, string $message, string $timestamp) {}
      - broadcastOn() → return [new PrivateChannel("game.{$this->game->id}.werewolves")]
      - broadcastAs() → return 'werewolf.chat.message'
      - broadcastWith() → return ['pseudo' => $this->player->pseudo, 'message' => $this->message, 'timestamp' => $this->timestamp]

// app/Events/Game/GameFinished.php
GameFinished.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
      - gameId
      - winnerTeam
      - players
    functions:
      - __construct(Game $game, Collection $players, ?string $winnerTeam) {}
      - broadcastOn() → return [new Channel("game.{$this->gameId}")]
      - broadcastAs() → return 'game.finished'
      - broadcastWith() → return ['winner_team' => $this->winnerTeam, 'players' => $this->players]

// app/Events/Game/ChatMessageSent.php
ChatMessageSent.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $player, string $message, string $timestamp, string $channel) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'chat.message.sent'
      - broadcastWith() → return ['pseudo' => $this->player->pseudo, 'message' => $this->message, 'channel' => $this->channel, 'timestamp' => $this->timestamp]

// app/Events/Game/WerewolvesTurnStarted.php
WerewolvesTurnStarted.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, array $eligibleTargets) {}
      - broadcastOn() → return [new PrivateChannel("game.{$this->game->id}.werewolves")]
      - broadcastAs() → return 'werewolves.turn.started'
      - broadcastWith() → return ['timer' => $this->game->timer('werewolves'), 'eligible_targets' => $this->eligibleTargets]

// app/Events/Game/PlayerDisconnected.php
PlayerDisconnected.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(int $gameId, string $pseudo, int $reconnectionTimeout) {}
      - fromPlayer(GamePlayer $player, int $timeout) → return new self($player->game_id, $player->pseudo, $timeout)
      - broadcastOn() → return [new Channel("game.{$this->gameId}")]
      - broadcastAs() → return 'player.disconnected'
      - broadcastWith() → return ['pseudo' => $this->pseudo, 'reconnection_timeout' => $this->reconnectionTimeout]

// app/Events/Game/MayorSuccessionStarted.php
MayorSuccessionStarted.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, string $dyingMayorPseudo) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'mayor.succession.started'
      - broadcastWith() → return ['timer' => $this->game->timer('mayor_succession'), 'dying_mayor_pseudo' => $this->dyingMayorPseudo]

// app/Events/Game/SeerTurnStarted.php
SeerTurnStarted.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $seer) {}
      - broadcastOn() → return [new PrivateChannel("game.{$this->game->id}.player.{$this->seer->id}")]
      - broadcastAs() → return 'seer.turn.started'
      - broadcastWith() → return ['timer' => $this->game->timer('seer')]

// app/Events/Game/PlayerReconnected.php
PlayerReconnected.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(int $gameId, string $pseudo) {}
      - fromPlayer(GamePlayer $player) → return new self($player->game_id, $player->pseudo)
      - broadcastOn() → return [new Channel("game.{$this->gameId}")]
      - broadcastAs() → return 'player.reconnected'
      - broadcastWith() → return ['pseudo' => $this->pseudo]

// app/Events/Game/PlayerExcluded.php
PlayerExcluded.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
      - gameId
      - excludedPlayerId
      - pseudo
    functions:
      - __construct(Game $game, GamePlayer $player, ?string $reason) {}
      - broadcastOn() → return [new PrivateChannel("game.{$this->gameId}.player.{$this->excludedPlayerId}")]
      - broadcastAs() → return 'player.excluded'
      - broadcastWith() → return $payload

// app/Events/Game/RandomElimination.php
RandomElimination.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $player) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'random.elimination'
      - broadcastWith() → return ['player_id' => $this->player->id, 'pseudo' => $this->player->pseudo, 'reason' => 'no_votes']

// app/Events/Game/MayorElected.php
MayorElected.php
    attributes:
      - Dispatchable
      - InteractsWithSockets
      - SerializesModels
    functions:
      - __construct(Game $game, GamePlayer $player, bool $wasRandom) {}
      - broadcastOn() → return [new Channel("game.{$this->game->id}")]
      - broadcastAs() → return 'mayor.elected'
      - broadcastWith() → return ['player_id' => $this->player->id, 'pseudo' => $this->player->pseudo, 'was_random' => $this->wasRandom]

// app/Providers/AppServiceProvider.php
AppServiceProvider.php
    functions:
      - register() → void
      - boot() → void

// app/Providers/WorkflowServiceProvider.php
WorkflowServiceProvider.php
    functions:
      - register() → void

// app/Policies/GamePolicy.php
GamePolicy.php
    functions:
      - viewHistory(User $user, Game $game) → return $game->players()->where('user_id', $user->id)->exists()
      - updateSettings(User $user, Game $game, GamePlayer $player) → return $player->is_host

// app/Http/Requests/HunterShootRequest.php
HunterShootRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['target_player_id' => ['required', 'integer', Rule::exists('game_players', 'id')->where('game_id', $gameId)->where('is_alive', true), Rule::notIn($selfId)]]
      - messages() → return ['target_player_id.required' => 'La cible est obligatoire.', 'target_player_id.exists' => 'Ce joueur n\'existe pas ou est déjà éliminé.', 'target_player_id.not_in' => 'Le chasseur ne peut pas se tirer lui-même.']

// app/Http/Requests/DayVoteRequest.php
DayVoteRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['target_player_id' => 'required|integer']

// app/Http/Requests/SendMessageRequest.php
SendMessageRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['message' => ['required', 'string', 'max:200'], 'channel' => ['required', 'string', Rule::in(['general', 'werewolves', 'dead'])]]
      - messages() → return ['message.required' => 'Le message est obligatoire.', 'message.max' => 'Le message ne peut pas dépasser 200 caractères.', 'channel.required' => 'Le canal est obligatoire.', 'channel.in' => 'Canal invalide. Valeurs acceptées : general, werewolves, dead.']

// app/Http/Requests/ExcludePlayerRequest.php
ExcludePlayerRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['reason' => ['required', 'string', 'max:500']]
      - messages() → return ['reason.required' => 'Le motif est obligatoire.', 'reason.max' => 'Le motif ne peut pas dépasser 500 caractères.']

// app/Http/Requests/MayorVoteRequest.php
MayorVoteRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['target_player_id' => ['required', 'integer', Rule::exists('game_players', 'id')->where('game_id', $this->route('id'))->where('is_alive', true)]]
      - messages() → return ['target_player_id.required' => 'La cible est obligatoire.', 'target_player_id.exists' => 'Ce joueur n\'existe pas ou est éliminé.']

// app/Http/Requests/UpdateTimersRequest.php
UpdateTimersRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['timers' => ['required', 'array', 'min:1'], 'timers.*' => ['required', 'integer']]
      - messages() → return ['timers.required' => 'Les timers sont obligatoires.', 'timers.array' => 'Format de timers invalide.', 'timers.min' => 'Au moins un timer doit être fourni.', 'timers.*.required' => 'La valeur du timer est obligatoire.', 'timers.*.integer' => 'Chaque timer doit être un nombre entier de secondes.']

// app/Http/Requests/WitchActRequest.php
WitchActRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['action' => ['required', 'string', 'in:heal,kill,pass'], 'target_player_id' => ['nullable', 'required_if:action,heal,kill', 'integer', Rule::exists('game_players', 'id')->where('game_id', $gameId)]]
      - messages() → return ['action.required' => 'L\'action est obligatoire.', 'action.in' => 'Action invalide.', 'target_player_id.required_if' => 'La cible est obligatoire pour cette action.', 'target_player_id.exists' => 'Ce joueur n\'existe pas.']

// app/Http/Requests/JoinGameRequest.php
JoinGameRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['pseudo' => ['required', 'string', 'min:2', 'max:20']]
      - messages() → return ['pseudo.required' => 'Le pseudo est obligatoire.', 'pseudo.min' => 'Le pseudo doit faire au moins 2 caractères.', 'pseudo.max' => 'Le pseudo ne peut pas dépasser 20 caractères.']

// app/Http/Requests/UpdateRolesRequest.php
UpdateRolesRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['roles' => ['required', 'array', 'min:1'], 'roles.*' => ['required', 'integer', 'in:0,1']]
      - messages() → return ['roles.required' => 'La composition des rôles est obligatoire.', 'roles.array' => 'Format de composition invalide.', 'roles.min' => 'Au moins un rôle doit être fourni.', 'roles.*.required' => 'La valeur du rôle est obligatoire.', 'roles.*.integer' => 'Chaque rôle doit valoir 0 ou 1.', 'roles.*.in' => 'Chaque rôle doit valoir 0 ou 1.']

// app/Http/Requests/CreateGameRequest.php
CreateGameRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['pseudo' => ['required', 'string', 'min:2', 'max:20'], 'max_players' => ['required', 'integer', 'in:6,8,10,12']]
      - messages() → return ['pseudo.required' => 'Le pseudo est obligatoire.', 'pseudo.min' => 'Le pseudo doit faire au moins 2 caractères.', 'pseudo.max' => 'Le pseudo ne peut pas dépasser 20 caractères.', 'max_players.required' => 'Le nombre de joueurs est obligatoire.', 'max_players.in' => 'Le nombre de joueurs doit être 6, 8, 10 ou 12.']

// app/Http/Requests/NightVoteRequest.php
NightVoteRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['target_player_id' => ['required', 'integer', Rule::exists('game_players', 'id')->where('game_id', $gameId)->where('is_alive', true), Rule::notIn($wolfIds)]]
      - messages() → return ['target_player_id.required' => 'La cible est obligatoire.', 'target_player_id.exists' => 'Ce joueur n\'existe pas ou est éliminé.', 'target_player_id.not_in' => 'Les loups ne peuvent pas voter contre un autre loup.']

// app/Http/Requests/SeerCheckRequest.php
SeerCheckRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['target_player_id' => ['required', 'integer', Rule::exists('game_players', 'id')->where('game_id', $gameId)->where('is_alive', true), Rule::notIn($selfId)]]
      - messages() → return ['target_player_id.required' => 'La cible est obligatoire.', 'target_player_id.exists' => 'Ce joueur n\'existe pas ou est éliminé.', 'target_player_id.not_in' => 'La voyante ne peut pas s\'inspecter elle-même.']

// app/Http/Requests/MayorSuccessionRequest.php
MayorSuccessionRequest.php
    functions:
      - authorize() → return true
      - rules() → return ['target_player_id' => ['required', 'integer', Rule::exists('game_players', 'id')->where('game_id', $this->route('id'))->where('is_alive', true)]]
      - messages() → return ['target_player_id.required' => 'Le successeur est obligatoire.', 'target_player_id.exists' => 'Ce joueur n\'existe pas ou est éliminé.']

// app/Http/Controllers/Auth/GoogleController.php
GoogleController.php
    functions:
      - redirect() → return Socialite::driver('google')->redirect()
      - callback() → return redirect()->route('lobby')
      - destroy() → return redirect('/')

// app/Http/Controllers/Controller.php
Controller.php
    attributes:
      - AuthorizesRequests

// app/Http/Controllers/Game/LobbyController.php
LobbyController.php
    functions:
      - __construct(GameService $gameService) {}
      - create(CreateGameRequest $request) → return response()->json(['success' => true, 'data' => ['game_id' => $game->id, 'code' => $game->code]], 201)
      - join(JoinGameRequest $request, string $code) → return response()->json(['success' => true, 'data' => ['player_id' => $player->id, 'game_code' => strtoupper($code)]])
      - exclude(ExcludePlayerRequest $request, int $id, int $playerId) → return response()->json(['success' => true, 'data' => []])
      - updateTimers(UpdateTimersRequest $request, int $id) → return response()->json(['success' => true, 'data' => ['timers' => $game->settings['timers'] ?? []]])
      - updateRoles(UpdateRolesRequest $request, int $id) → return response()->json(['success' => true, 'data' => ['roles' => $game->settings['roles'] ?? []]])
      - lobbyState(int $id) → return response()->json(['success' => true, 'data' => ['status' => $game->status, 'players_count' => count($players), 'max_players' => $game->max_players, 'slots_remaining' => $game->max_players - count($players), 'players' => $players]])
      - waitingRoom(string $code) → return view('game.waiting-room', compact('game', 'player', 'players'))

// app/Http/Controllers/Game/GameController.php
GameController.php
    functions:
      - __construct(GameService $gameService, VoteService $voteService) {}
      - mayorElection(Request $request, string $code) → return view('game.mayor-election', compact('game', 'player', 'players', 'myVote', 'currentVotes', 'phaseRemainingSeconds'))
      - finished(Request $request, string $code) → return view('game.finished', compact('game', 'player', 'players'))
      - cancelled(Request $request, string $code) → return view('game.cancelled', compact('game', 'player', 'allPlayers'))
      - spectator(Request $request, string $code) → return view('game.spectator', compact('game', 'player', 'allPlayers'))
      - day(Request $request, string $code) → return view('game.day', compact('game', 'player', 'players', 'nightVictim'))
      - night(Request $request, string $code) → return view('game.night', compact('game', 'player', 'players'))
      - redirectToCurrentPhase(Game $game, string $code) → return match (true) { in_array($game->status, ['day', 'processing_day']) => redirect()->route('game.day', ['code' => $code]), in_array($game->status, ['night', 'wolves_turn', 'processing_night', 'processing_wolves']) => redirect()->route('game.night', ['code' => $code]), $game->status === 'electing_mayor' => redirect()->route('game.mayor-election', ['code' => $code]), $game->status === 'finished' && $game->winner_team !== null => redirect()->route('game.finished', ['code' => $code]), $game->status === 'finished' => redirect()->route('game.cancelled', ['code' => $code]), default => redirect()->route('game.role-reveal', ['code' => $code]), }
      - history(Request $request, string $code) → return view('game.history', compact('game', 'players', 'timeline', 'duration', 'myPlayer'))
      - buildTimeline(Game $game, Collection $players, Collection $actions) → return $timeline
      - playerSnapshot(Collection $players, int $id) → return ['id' => $id, 'pseudo' => $p?->pseudo ?? '?', 'role' => $p?->role ?? null]
      - state(Request $request, string $code) → return response()->json(['success' => true, 'data' => ['phase' => $game->status, 'round' => $game->round, 'my_role' => $player->role, 'is_alive' => (bool) $player->is_alive, 'is_mayor' => (bool) $player->is_mayor, 'phase_remaining_seconds' => $game->phaseRemainingSeconds(), 'seer_turn_active' => $seerTurnActive, 'werewolves_turn_active' => $werewolvesTurnActive, 'allies' => $allies]])
      - quit(Request $request, int $id) → return response()->json(['success' => true])
      - disconnect(Request $request, int $id) → return response()->json(['success' => true])
      - reconnect(Request $request, string $code) → return response()->json(['success' => true])

// app/Http/Controllers/Game/VoteController.php
VoteController.php
    functions:
      - __construct(VoteService $voteService) {}
      - mayor(MayorVoteRequest $request, int $id) → return response()->json(['success' => true, 'data' => ['votes' => $votes]])
      - day(DayVoteRequest $request, int $id) → return response()->json(['success' => true, 'data' => ['votes' => $summary]])
      - night(NightVoteRequest $request, int $id) → return response()->json(['success' => true, 'data' => ['wolves' => $voteState]])
      - _checkAllMayorVotesCast(Game $game) → void
      - _checkAllDayVotesCast(Game $game) → void
      - _checkAllNightVotesCast(Game $game) → void

// app/Http/Controllers/Game/ActionController.php
ActionController.php
    functions:
      - __construct(GameService $gameService) {}
      - ready(Request $request, int $id) → return response()->json(['success' => true, 'data' => []])
      - seerCheck(SeerCheckRequest $request, int $id) → return response()->json(['success' => true, 'data' => ['target_player_id' => $target->id, 'pseudo' => $target->pseudo, 'role' => $target->role]])
      - witchAct(WitchActRequest $request, int $id) → return response()->json(['success' => true, 'data' => $result['action'] === 'pass' ? [] : ['target_player_id' => $result['target']?->id]])
      - hunterShoot(HunterShootRequest $request, int $id) → return response()->json(['success' => true, 'data' => ['target_player_id' => $target->id]])
      - mayorSuccession(MayorSuccessionRequest $request, int $id) → return response()->json(['success' => true, 'data' => []])
      - roleReveal(Request $request, string $code) → return view('game.role-reveal', compact('game', 'player', 'allies', 'nbReady', 'total'))

// app/Http/Controllers/Game/ChatController.php
ChatController.php
    functions:
      - __construct(ChatService $chatService) {}
      - send(SendMessageRequest $request, int $id) → return response()->json(['success' => true, 'data' => []])

// app/Http/Controllers/PushSubscriptionController.php
PushSubscriptionController.php
    functions:
      - store(Request $request) → return response()->json(['success' => true])
      - destroy(Request $request) → return response()->json(['success' => true])

// app/Notifications/PlayerKilledNightNotification.php
PlayerKilledNightNotification.php
    attributes:
      - Queueable
    functions:
      - via($notifiable) → return [WebPushChannel::class]
      - toWebPush($notifiable, $notification) → return (new WebPushMessage())->title('☠️ Tu as été tué cette nuit')->body('Les loups gardent leurs secrets.')->icon('/images/icon-192.png')->badge('/images/badge-72.png')

// app/Notifications/GameFinishedNotification.php
GameFinishedNotification.php
    attributes:
      - Queueable
    functions:
      - __construct(?string $winnerTeam) {}
      - via($notifiable) → return [WebPushChannel::class]
      - toWebPush($notifiable, $notification) → return (new WebPushMessage())->title($title)->body('La partie est terminée.')->icon('/images/icon-192.png')->badge('/images/badge-72.png')

// app/Notifications/RoleAssignedNotification.php
RoleAssignedNotification.php
    attributes:
      - Queueable
      - ROLE_LABELS
    functions:
      - __construct(string $role) {}
      - via($notifiable) → return [WebPushChannel::class]
      - toWebPush($notifiable, $notification) → return (new WebPushMessage())->title('La partie commence !')->body("Votre rôle : {$label}")->icon('/images/icon-192.png')->badge('/images/badge-72.png')

// app/Notifications/PlayerExcludedNotification.php
PlayerExcludedNotification.php
    attributes:
      - Queueable
    functions:
      - __construct(string $reason) {}
      - via($notifiable) → return [WebPushChannel::class]
      - toWebPush($notifiable, $notification) → return (new WebPushMessage())->title('Tu as été exclu')->body($this->reason)->icon('/images/icon-192.png')->badge('/images/badge-72.png')

// app/Notifications/PlayerEliminatedDayNotification.php
PlayerEliminatedDayNotification.php
    attributes:
      - Queueable
      - ROLE_LABELS
    functions:
      - __construct(string $role) {}
      - via($notifiable) → return [WebPushChannel::class]
      - toWebPush($notifiable, $notification) → return (new WebPushMessage())->title('⚖️ Le village t\'a éliminé')->body("Tu étais {$label}.")->icon('/images/icon-192.png')->badge('/images/badge-72.png')

// app/Services/RoleDistributor.php
RoleDistributor.php
    functions:
      - distribute(Collection $players, Game $game) → return $assignments
      - getRoleConfig(Game $game) → return $config
      - computeCounts(int $total, Game $game) → return $resolved
      - resolveAmount(string $role, int|string $amount, int $total) → return 0
      - werewolfCount(int $playerCount) → return max(1, (int) floor($playerCount * 0.2))

// app/Services/VoteService.php
VoteService.php
    functions:
      - __construct(PhaseManager $phaseManager, WinConditionChecker $winConditionChecker) {}
      - castMayorVote(GamePlayer $voter, int $targetId) → return DB::transaction(function () use ($voter, $targetId, $game) { // lockForUpdate sur les votes existants du joueur : anti-double-vote concurrent $alreadyVoted = GameAction::where('game_id', $game->id)->where('player_id', $voter->id)->where('type', 'mayor_vote')->where('round', $game->round)->lockForUpdate()->exists(); if ($alreadyVoted) { abort(409, 'Vous avez déjà voté pour l\'élection du maire.'); } GameAction::create(['game_id' => $game->id, 'player_id' => $voter->id, 'type' => 'mayor_vote', 'weight' => 1, 'target_player_id' => $targetId, 'round' => $game->round, 'phase' => 'election']); return $this->getMayorVoteTotals($game); })
      - resolveMayorElection(Game $game) → return DB::transaction(function () use ($game) { $locked = Game::where('id', $game->id)->where('status', 'electing_mayor')->lockForUpdate()->first(); if (!$locked) { return null; } if (!$locked->canTransition('start_night')) { Log::warning("Transition 'start_night' refusée depuis status={$locked->status}"); return null; } $votes = GameAction::where('game_id', $locked->id)->where('type', 'mayor_vote')->where('round', $locked->round)->selectRaw('target_player_id, COUNT(*) as vote_count')->groupBy('target_player_id')->orderByDesc('vote_count')->get(); $wasRandom = false; if ($votes->isEmpty()) { $winner = $locked->alivePlayers()->inRandomOrder()->first(); $wasRandom = true; } else { $maxVotes = $votes->first()->vote_count; $topCandidates = $votes->where('vote_count', $maxVotes); if ($topCandidates->count() > 1) { $wasRandom = true; $winnerId = $topCandidates->random()->target_player_id; } else { $winnerId = $topCandidates->first()->target_player_id; } $winner = GamePlayer::find($winnerId); } $winner->update(['is_mayor' => true]); $locked->update(['status' => 'night', 'round' => 1, 'phase_deadline' => now()->addSeconds($locked->timer('seer'))]); return ['player' => $winner, 'game' => $locked, 'was_random' => $wasRandom]; })
      - resolveNightVote(Game $game) → return GamePlayer::find($winnerId)
      - castNightVote(GamePlayer $wolf, int $targetId) → return $state
      - resolveDayVote(Game $game) → void
      - castDayVote(GamePlayer $voter, int $targetId) → return $this->getDayVoteSummary($voter->game)
      - getDayVoteSummary(Game $game) → return GameAction::where('game_id', $game->id)->where('type', 'day_vote')->where('round', $game->round)->get()->groupBy('target_player_id')->map(fn($group) => $group->sum('weight'))->toArray()
      - getNightVoteState(Game $game) → return $aliveWolves->map(fn(GamePlayer $w) => ['player_id' => $w->id, 'pseudo' => $w->pseudo, 'has_voted' => $votes->has($w->id), 'target_player_id' => $votes->get($w->id)?->target_player_id, 'target_pseudo' => $votes->has($w->id) ? $targets[$votes[$w->id]->target_player_id] ?? null : null])->values()->toArray()
      - getMayorVoteTotals(Game $game) → return GameAction::where('game_actions.game_id', $game->id)->where('game_actions.type', 'mayor_vote')->where('game_actions.round', $game->round)->join('game_players', 'game_actions.target_player_id', '=', 'game_players.id')->selectRaw('game_actions.target_player_id, game_players.pseudo, COUNT(*) as vote_count')->groupBy('game_actions.target_player_id', 'game_players.pseudo')->get()->map(fn($row) => ['target_player_id' => $row->target_player_id, 'pseudo' => $row->pseudo, 'vote_count' => (int) $row->vote_count])->values()->toArray()

// app/Services/PhaseManager.php
PhaseManager.php
    functions:
      - startDay(Game $game, ?GamePlayer $victim, bool $witchActed, ?int $savedPlayerId) → void
      - startNight(Game $game) → void
      - endNight(Game $game) → void

// app/Services/TimerCalculator.php
TimerCalculator.php
    attributes:
      - TIMERS
      - FIXED
      - NON_CONFIGURABLE
    functions:
      - forPlayerCount(int $n) → return array_merge(self::TIMERS[$n], self::FIXED)
      - get(Game $game, string $key) → return config("game.timers.{$key}", 30)

// app/Services/GameService.php
GameService.php
    functions:
      - __construct(RoleDistributor $roleDistributor, PhaseManager $phaseManager) {}
      - createGame(User $user, string $pseudo, int $maxPlayers) → return DB::transaction(function () use ($user, $pseudo, $maxPlayers, $code) { $game = Game::create(['code' => $code, 'status' => 'waiting', 'max_players' => $maxPlayers]); GamePlayer::create(['game_id' => $game->id, 'user_id' => $user->id, 'pseudo' => $pseudo, 'is_host' => true, 'joined_at' => now()]); return $game; })
      - joinGame(User $user, string $code, string $pseudo) → return DB::transaction(function () use ($user, $code, $pseudo) { $game = Game::where('code', $code)->lockForUpdate()->first(); if (!$game) { abort(404, 'Partie introuvable.'); } if ($game->status !== 'waiting') { abort(409, 'Cette partie a déjà commencé.'); } // Vérifier si l'utilisateur est déjà dans la partie $existing = GamePlayer::where('game_id', $game->id)->where('user_id', $user->id)->first(); if ($existing) { return $existing; } if ($game->players()->count() >= $game->max_players) { abort(409, 'Cette partie est déjà complète.'); } $excluded = Exclusion::where('game_id', $game->id)->where('user_id', $user->id)->exists(); if ($excluded) { abort(403, 'Tu as été exclu de cette partie.'); } $player = GamePlayer::create(['game_id' => $game->id, 'user_id' => $user->id, 'pseudo' => $pseudo, 'joined_at' => now()]); broadcast(new PlayerJoined($game, $player)); $currentCount = $game->players()->count(); if ($currentCount === $game->max_players) { $this->startGame($game); } return $player; })
      - startGame(Game $game) → void
      - markReady(GamePlayer $player) → void
      - excludePlayer(GamePlayer $host, GamePlayer $target, string $reason) → void
      - seerCheck(GamePlayer $seer, int $targetId) → return DB::transaction(function () use ($seer, $targetId, $game) { $alreadyActed = GameAction::where('game_id', $game->id)->where('player_id', $seer->id)->where('type', 'seer_check')->where('round', $game->round)->lockForUpdate()->exists(); if ($alreadyActed) { abort(409, 'Vous avez déjà utilisé votre pouvoir ce round.'); } GameAction::create(['game_id' => $game->id, 'player_id' => $seer->id, 'type' => 'seer_check', 'target_player_id' => $targetId, 'round' => $game->round, 'phase' => 'night']); return GamePlayer::findOrFail($targetId); })
      - mayorSuccessionByPlayer(GamePlayer $mayor, int $targetId) → return $target
      - handleDisconnection(GamePlayer $player) → void
      - handleReconnection(GamePlayer $player) → void
      - quitGame(Game $game, GamePlayer $player) → void
      - validateTimerSettings(array $timers) → void
      - updateTimerSettings(Game $game, array $timers) → return $game
      - validateRoleSettings(array $roles) → void
      - updateRoleSettings(Game $game, array $roles) → return $game
      - witchAct(GamePlayer $witch, string $action, ?int $targetId) → return $result
      - hunterShoot(GamePlayer $hunter, int $targetId) → return DB::transaction(function () use ($hunter, $targetId, $game) { $alreadyShot = GameAction::where('game_id', $game->id)->where('player_id', $hunter->id)->where('type', 'hunter_shot')->where('round', $game->round)->lockForUpdate()->exists(); if ($alreadyShot) { abort(409, 'Vous avez déjà tiré ce round.'); } $target = GamePlayer::where('id', $targetId)->where('game_id', $game->id)->where('is_alive', true)->lockForUpdate()->first(); if (!$target) { abort(404, 'Cible invalide.'); } $target->update(['is_alive' => false]); GameAction::create(['game_id' => $game->id, 'player_id' => $hunter->id, 'type' => 'hunter_shot', 'target_player_id' => $target->id, 'round' => $game->round, 'phase' => in_array($game->status, ['night', 'processing_night']) ? 'night' : 'day']); return $target; })
      - cancelGame(Game $game) → void
      - generateUniqueCode() → return $code

// app/Services/WinConditionChecker.php
WinConditionChecker.php
    functions:
      - check(Game $game) → return true

// app/Services/ChatService.php
ChatService.php
    functions:
      - sendMessage(GamePlayer $player, string $message, string $channel) → return ChatMessage::create(['game_id' => $game->id, 'player_id' => $player->id, 'message' => $message, 'channel' => $channel, 'round' => $game->round, 'phase' => $this->mapStatusToPhase($game->status)])
      - mapStatusToPhase(string $status) → return match ($status) { 'electing_mayor' => 'election', 'night' => 'night', default => 'day', }

// tests/Feature/Auth/GoogleAuthTest.php
GoogleAuthTest.php
    attributes:
      - RefreshDatabase
    functions:
      - mockSocialiteUser(string $googleId, string $email, string $name) → void
      - test_crée_un_nouvel_utilisateur_si_google_id_inconnu() → void
      - test_met_à_jour_utilisateur_existant_si_google_id_connu() → void
      - test_authentifie_le_joueur_après_callback() → void
      - test_exception_socialite_redirige_vers_login_avec_erreur() → void

// tests/Feature/Game/ReconnectionTest.php
ReconnectionTest.php
    attributes:
      - RefreshDatabase
    functions:
      - test_state_endpoint_retourne_phase_courante() → void
      - test_state_traduit_wolves_turn_en_night() → void
      - test_state_traduit_processing_day_en_day() → void
      - test_state_retourne_403_si_joueur_absent() → void
      - test_state_retourne_404_si_partie_inexistante() → void
      - test_reconnect_remet_is_inactive_a_false() → void
      - test_reconnect_invalide_token_cache() → void

// tests/Feature/Game/HunterTest.php
HunterTest.php
    attributes:
      - RefreshDatabase
    functions:
      - makeNightGame(int $round) → return Game::factory()->create(['status' => 'night', 'max_players' => 6, 'round' => $round])
      - makeDayGame(int $round) → return Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => $round])
      - test_chasseur_tire_apres_mort_nuit() → void
      - test_chasseur_tire_apres_mort_jour() → void
      - test_chasseur_ne_peut_pas_tirer_sur_lui_meme() → void
      - test_chasseur_ne_peut_pas_tirer_sur_joueur_mort() → void
      - test_chasseur_ne_peut_pas_tirer_deux_fois() → void
      - test_hunter_auto_action_skipped_if_already_shot() → void
      - test_hunter_auto_action_no_elimination_if_inactive() → void
      - test_chasseur_tire_apres_resolution_complete_de_nuit() → void
      - test_chasseur_ne_tire_pas_avant_day_started() → void

// tests/Feature/Game/ChatTest.php
ChatTest.php
    attributes:
      - RefreshDatabase
    functions:
      - test_message_loup_broadcasté_sur_channel_werewolves_uniquement() → void
      - test_villageois_ne_peut_pas_écrire_sur_channel_werewolves_retourne_403() → void
      - test_message_après_mort_retourne_403() → void

// tests/Feature/Game/ExcludePlayerTest.php
ExcludePlayerTest.php
    attributes:
      - RefreshDatabase
    functions:
      - makeWaitingGame() → return [$game, $host, $target]
      - test_host_peut_exclure_un_joueur() → void
      - test_non_host_ne_peut_pas_exclure_retourne_403() → void
      - test_exclusion_hors_phase_waiting_retourne_409() → void
      - test_motif_vide_retourne_422() → void
      - test_joueur_exclu_ne_peut_plus_rejoindre() → void
      - test_player_excluded_broadcasté_après_exclusion() → void

// tests/Feature/Game/TimerSettingsTest.php
TimerSettingsTest.php
    attributes:
      - RefreshDatabase
    functions:
      - makeWaitingGame() → return [$game, $host]
      - test_host_peut_modifier_les_timers() → void
      - test_joueur_non_host_ne_peut_pas_modifier_les_timers() → void
      - test_timer_hors_plage_est_rejeté() → void
      - test_timer_non_configurable_est_rejeté() → void
      - test_game_timer_lit_settings_en_priorite() → void
      - test_game_timer_fallback_sur_config() → void
      - test_timers_fixes_ignorent_settings() → void
      - test_modification_impossible_hors_waiting() → void

// tests/Feature/Game/RoleSettingsTest.php
RoleSettingsTest.php
    attributes:
      - RefreshDatabase
    functions:
      - makeWaitingGame(int $maxPlayers) → return Game::factory()->create(['status' => 'waiting', 'max_players' => $maxPlayers, 'round' => 0])
      - test_host_peut_activer_sorciere() → void
      - test_host_peut_activer_chasseur() → void
      - test_joueur_non_host_ne_peut_pas_modifier_roles() → void
      - test_modification_roles_impossible_hors_waiting() → void
      - test_role_invalide_est_rejete() → void
      - test_role_distributor_inclut_sorciere_si_configuree() → void
      - test_role_distributor_inclut_chasseur_si_configure() → void
      - test_role_distributor_remplit_villageois_automatiquement() → void
      - test_deux_sorcieres_impossibles() → void
      - test_villageois_residuels_toujours_positifs() → void

// tests/Feature/Game/CreateGameTest.php
CreateGameTest.php
    attributes:
      - RefreshDatabase
    functions:
      - test_création_réussie_retourne_201_avec_code_et_statut_waiting() → void
      - test_max_players_invalide_retourne_422(int $value) → void
      - invalidMaxPlayersProvider() → return [[5], [7], [13]]
      - test_pseudo_vide_retourne_422() → void
      - test_pseudo_trop_court_retourne_422() → void
      - test_code_collision_retente_jusqu_à_code_unique() → void
      - test_non_authentifié_redirigé_vers_login() → void

// tests/Feature/Game/JoinGameTest.php
JoinGameTest.php
    attributes:
      - RefreshDatabase
    functions:
      - test_rejoindre_une_partie_waiting_réussie() → void
      - test_rejoindre_une_partie_non_waiting_retourne_409() → void
      - test_rejoindre_une_partie_pleine_retourne_409() → void
      - test_joueur_exclu_ne_peut_pas_rejoindre_retourne_403() → void
      - test_code_inexistant_retourne_404() → void
      - test_race_condition_deux_joueurs_remplissent_le_dernier_slot() → void

// tests/Feature/Game/AutoActionTest.php
AutoActionTest.php
    attributes:
      - RefreshDatabase
    functions:
      - makeNightGame(int $round) → return Game::factory()->create(['status' => 'night', 'max_players' => 6, 'round' => $round])
      - test_seer_auto_action_skipped_if_already_acted() → void
      - test_seer_auto_action_passes_to_wolves_if_inactive() → void
      - test_seer_auto_action_skipped_if_wrong_round() → void
      - test_seer_auto_action_skipped_if_wrong_status() → void
      - test_seer_check_endpoint_dispatches_wolves_immediately() → void
      - test_seer_check_rejected_if_already_acted() → void
      - test_seer_check_rejected_if_self_target() → void

// tests/Feature/Game/NightPhaseTest.php
NightPhaseTest.php
    attributes:
      - RefreshDatabase
    functions:
      - makeNightGame() → return Game::factory()->create(['status' => 'night', 'max_players' => 6, 'round' => 1])
      - test_voyante_ne_peut_pas_sinspecter_elle_meme_retourne_422() → void
      - test_loup_ne_peut_pas_voter_pour_un_autre_loup_retourne_422() → void
      - test_action_voyante_hors_phase_night_retourne_409() → void
      - test_vote_nuit_hors_phase_night_retourne_409() → void
      - test_mayor_succession_triggered_at_night() → void
      - test_mayor_succession_not_triggered_if_victory_occurs_simultaneously() → void
      - test_night_ends_with_werewolves_turn_even_when_seer_dead() → void
      - test_night_end_dispatched_after_mayor_succession_with_buffer() → void

// tests/Feature/Game/RaceConditionTest.php
RaceConditionTest.php
    attributes:
      - RefreshDatabase
    functions:
      - test_join_race_max_6_joueurs_jamais_dépassé() → void
      - test_start_game_race_double_appel_idempotent() → void
      - test_mayor_vote_race_double_vote_même_joueur_retourne_409() → void
      - test_day_vote_mayor_weight_2_même_si_maire_assigné_en_cours() → void
      - test_resolve_day_vote_double_fire_processing_day_guard_ignore_second_appel() → void

// tests/Feature/Game/WorkflowTransitionTest.php
WorkflowTransitionTest.php
    attributes:
      - RefreshDatabase
    functions:
      - test_waiting_vers_electing_mayor_autorise() → void
      - test_electing_mayor_vers_night_autorise() → void
      - test_night_vers_day_autorise() → void
      - test_day_vers_night_autorise() → void
      - test_night_vers_finished_autorise() → void
      - test_day_vers_finished_autorise() → void
      - test_waiting_vers_night_interdit() → void
      - test_finished_vers_quoi_que_ce_soit_interdit() → void
      - test_day_vers_start_night_interdit() → void
      - test_electing_mayor_vers_continue_night_interdit() → void
      - test_apply_transition_persiste_le_nouveau_status() → void

// tests/Feature/Game/MayorSuccessionTest.php
MayorSuccessionTest.php
    attributes:
      - RefreshDatabase
    functions:
      - test_succession_nuit_designe_un_successeur_sans_changer_de_phase() → void
      - test_succession_jour_designe_un_successeur_puis_demarre_la_nuit() → void
      - test_succession_nuit_ne_redemarre_pas_la_nuit_si_le_statut_est_deja_passe_a_day() → void
      - test_cascade_succession_nuit_puis_nouveau_maire_tue_la_nuit_suivante() → void

// tests/Feature/Game/WitchTest.php
WitchTest.php
    attributes:
      - RefreshDatabase
    functions:
      - makeNightGame() → return Game::factory()->create(['status' => 'night', 'max_players' => 6, 'round' => 1])
      - test_sorciere_peut_sauver_la_victime_des_loups() → void
      - test_sorciere_ne_peut_pas_sauver_si_elle_est_la_victime() → void
      - test_sorciere_peut_empoisonner_un_joueur() → void
      - test_sorciere_ne_peut_pas_utiliser_deux_fois_la_meme_potion() → void
      - test_witch_turn_avec_poison_disponible_si_egalite_loups() → void
      - test_witch_turn_skipped_si_egalite_loups_et_potions_epuisees() → void
      - test_witch_turn_avec_soin_epuise_mais_poison_disponible() → void
      - test_witch_turn_dispatche_par_night_actions_uniquement() → void
      - test_witch_turn_non_double_dispatche_meme_round() → void
      - test_sorciere_auto_action_sans_victime_ne_bloque_pas() → void

// tests/Feature/Game/ProcessDayVoteTest.php
ProcessDayVoteTest.php
    attributes:
      - RefreshDatabase
    functions:
      - test_double_fire_does_not_execute_twice() → void
      - test_day_vote_does_not_accept_processing_day_as_initial_state() → void

// tests/Feature/Game/DayPhaseTest.php
DayPhaseTest.php
    attributes:
      - RefreshDatabase
    functions:
      - makeDayGame() → return Game::factory()->create(['status' => 'day', 'max_players' => 6, 'round' => 1])
      - test_un_joueur_ne_peut_pas_voter_pour_lui_meme_retourne_422() → void
      - test_égalité_vote_jour_élimine_personne_et_broadcast_no_elimination() → void
      - test_vote_maire_weight_2_correctement_compté() → void
      - test_vote_hors_phase_day_retourne_409() → void

// tests/Feature/Game/MigrationTest.php
MigrationTest.php
    attributes:
      - RefreshDatabase
      - MIGRATION_FILE
    functions:
      - statusEnumDefinition() → return DB::selectOne("SHOW COLUMNS FROM games WHERE Field = 'status'")->Type
      - test_processing_day_added_to_enum() → void
      - test_rollback_of_processing_day_removes_it() → void

// tests/Feature/ExampleTest.php
ExampleTest.php
    functions:
      - test_the_application_returns_a_successful_response() → void

// tests/Feature/LobbyTest.php
LobbyTest.php
    attributes:
      - RefreshDatabase
    functions:
      - test_cas1_creer_une_partie_fournit_le_code_qui_mene_au_lobby() → void
      - test_cas2_rejoindre_avec_code_valide_fournit_le_code_qui_mene_au_lobby() → void
      - test_cas3_rejoindre_avec_code_invalide_renvoie_un_message_derreur_exploitable() → void
      - test_cas4_pseudo_vide_renvoie_une_erreur_de_validation_exploitable() → void

// tests/Unit/Models/GameActionTest.php
GameActionTest.php
    attributes:
      - RefreshDatabase
    functions:
      - test_scope_anonymized_exclut_player_id_des_colonnes() → void
      - test_scope_anonymized_retourne_toutes_les_lignes_sans_filtre() → void
      - test_scope_anonymized_retourne_target_player_id_type_weight_round_phase() → void

// tests/Unit/Events/EventPayloadTest.php
EventPayloadTest.php
    attributes:
      - RefreshDatabase
    functions:
      - test_mayor_vote_cast_payload_ne_contient_pas_player_id() → void
      - test_day_vote_cast_payload_ne_contient_pas_player_id() → void
      - test_seer_result_broadcasté_sur_channel_privé_uniquement() → void
      - test_werewolf_chat_message_broadcasté_sur_channel_werewolves_uniquement() → void

// tests/Unit/ExampleTest.php
ExampleTest.php
    functions:
      - test_that_true_is_true() → void

// tests/TestCase.php
TestCase.php

// database/migrations/2026_06_05_074521_add_user_id_to_exclusions_table.php
2026_06_05_074521_add_user_id_to_exclusions_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_03_000003_create_game_actions_table.php
2026_06_03_000003_create_game_actions_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_03_000005_create_exclusions_table.php
2026_06_03_000005_create_exclusions_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_03_000002_create_game_players_table.php
2026_06_03_000002_create_game_players_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/0001_01_01_000002_create_jobs_table.php
0001_01_01_000002_create_jobs_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_04_002156_create_push_subscriptions_table.php
2026_06_04_002156_create_push_subscriptions_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_12_120000_add_settings_to_games_table.php
2026_06_12_120000_add_settings_to_games_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_10_000003_add_wolves_turn_to_games_status.php
2026_06_10_000003_add_wolves_turn_to_games_status.php
    functions:
      - up() → void
      - down() → void

// database/migrations/0001_01_01_000000_create_users_table.php
0001_01_01_000000_create_users_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_12_203056_add_witch_hunter_to_game_players_role_enum.php
2026_06_12_203056_add_witch_hunter_to_game_players_role_enum.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_10_000002_add_processing_wolves_to_games_status.php
2026_06_10_000002_add_processing_wolves_to_games_status.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_16_165013_add_player_type_round_index_to_game_actions_table.php
2026_06_16_165013_add_player_type_round_index_to_game_actions_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_10_000001_add_processing_night_to_games_status.php
2026_06_10_000001_add_processing_night_to_games_status.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_05_235433_add_timers_to_games_table.php
2026_06_05_235433_add_timers_to_games_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_11_191937_add_processing_day_to_games_status_enum.php
2026_06_11_191937_add_processing_day_to_games_status_enum.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_03_000004_create_chat_messages_table.php
2026_06_03_000004_create_chat_messages_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_03_000001_create_games_table.php
2026_06_03_000001_create_games_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/0001_01_01_000001_create_cache_table.php
0001_01_01_000001_create_cache_table.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_12_203056_add_witch_hunter_actions_to_game_actions_type_enum.php
2026_06_12_203056_add_witch_hunter_actions_to_game_actions_type_enum.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_14_000000_add_dead_to_chat_messages_channel_enum.php
2026_06_14_000000_add_dead_to_chat_messages_channel_enum.php
    functions:
      - up() → void
      - down() → void

// database/migrations/2026_06_12_203056_add_settings_to_game_players_table.php
2026_06_12_203056_add_settings_to_game_players_table.php
    functions:
      - up() → void
      - down() → void

// database/factories/ChatMessageFactory.php
ChatMessageFactory.php
    functions:
      - definition() → return ['game_id' => Game::factory(), 'player_id' => GamePlayer::factory(), 'message' => fake()->sentence(), 'channel' => fake()->randomElement(['general', 'werewolves']), 'round' => 1, 'phase' => fake()->randomElement(['election', 'night', 'day'])]

// database/factories/UserFactory.php
UserFactory.php
    functions:
      - definition() → return ['google_id' => fake()->unique()->numerify('####################'), 'email' => fake()->unique()->safeEmail(), 'name' => fake()->name()]

// database/factories/GameFactory.php
GameFactory.php
    functions:
      - definition() → return ['code' => strtoupper(Str::random(6)), 'status' => 'waiting', 'max_players' => fake()->randomElement([6, 8, 10, 12]), 'round' => 0, 'phase_deadline' => null, 'winner_team' => null, 'started_at' => null, 'finished_at' => null]
      - inProgress() → return $this->state(['status' => 'night', 'started_at' => now(), 'round' => 1])
      - finished() → return $this->state(['status' => 'finished', 'started_at' => now()->subHour(), 'finished_at' => now(), 'winner_team' => fake()->randomElement(['villagers', 'werewolves'])])

// database/factories/GameActionFactory.php
GameActionFactory.php
    functions:
      - definition() → return ['game_id' => Game::factory(), 'player_id' => GamePlayer::factory(), 'type' => fake()->randomElement(['mayor_vote', 'night_vote', 'day_vote', 'seer_check', 'mayor_succession', 'werewolf_chat', 'ready']), 'weight' => 1, 'target_player_id' => null, 'round' => 1, 'phase' => fake()->randomElement(['election', 'night', 'day'])]

// database/factories/GamePlayerFactory.php
GamePlayerFactory.php
    functions:
      - definition() → return ['game_id' => Game::factory(), 'user_id' => User::factory(), 'pseudo' => fake()->userName(), 'role' => null, 'is_alive' => true, 'is_host' => false, 'is_mayor' => false, 'is_inactive' => false, 'is_ready' => false, 'joined_at' => now()]
      - werewolf() → return $this->state(['role' => 'werewolf'])
      - seer() → return $this->state(['role' => 'seer'])
      - witch() → return $this->state(['role' => 'witch'])
      - hunter() → return $this->state(['role' => 'hunter'])
      - villager() → return $this->state(['role' => 'villager'])
      - host() → return $this->state(['is_host' => true])
      - dead() → return $this->state(['is_alive' => false])

// database/factories/ExclusionFactory.php
ExclusionFactory.php
    functions:
      - definition() → return ['game_id' => Game::factory(), 'user_id' => User::factory(), 'player_id' => null, 'reason' => fake()->sentence(), 'excluded_at' => now()]

// database/seeders/DatabaseSeeder.php
DatabaseSeeder.php
    functions:
      - run() → void
