/**
 * gameState(gameId, userId) — Store Alpine central pour toutes les vues de jeu.
 *
 * Usage dans Blade :
 *   <div x-data="gameState({{ $game->id }}, {{ auth()->id() }})"
 *        data-game-code="{{ $game->code }}"
 *        data-player-id="{{ $player->id }}">
 *
 * Les vues existantes (day.blade.php, etc.) peuvent continuer à utiliser leurs
 * propres fonctions locales ; ce store s'y substitue progressivement (tâche 29).
 */
export function gameState(gameId, userId) {
    return {
        // ── Identifiants ────────────────────────────────────────────────────
        gameId,
        userId,
        playerId:   null,  // game_players.id — lu depuis le DOM
        gameCode:   null,  // code 6 chars — lu depuis le DOM ou window.GAME_CODE

        // ── État de jeu ──────────────────────────────────────────────────────
        phase:                null,
        round:                0,
        players:              [],
        myRole:               null,
        isMayor:              false,
        isAlive:              true,
        winnerTeam:           null,
        nightVictim:          null,
        // Compteur de successions actives. Incrémenté à chaque MayorSuccessionStarted,
        // décrémenté à chaque MayorSuccessionDone. Permet de gérer les cascades
        // (successeur éliminé à son tour) sans perdre le fil.
        successionDepth:      0,

        // ── Phases nuit ──────────────────────────────────────────────────────
        nightPhase:       'village_sleeping',
        pendingSeerEvent: null,
        seerResult:       null,

        // ── Chat ─────────────────────────────────────────────────────────────
        chat:       [],
        wolvesChat: [],

        // ── Votes ────────────────────────────────────────────────────────────
        votes:       {},   // { player_id: total_weight }
        wolvesVotes: {},   // { player_id: count }

        // ── Annonces de phases (overlays de transition) ───────────────────────
        announcements:         [],     // file FIFO d'objets { type, message, durationMs }
        isAnnouncing:          false,  // true pendant l'affichage d'un overlay
        _pendingNightStarted:  null,
        _pendingDayStarted:    null,
        _pendingMayorElected:  null,

        // ── Internes ─────────────────────────────────────────────────────────
        _csrf:     '',
        _motion:   true,   // !prefers-reduced-motion
        _allies:   [],     // alliés loups (pour les loups)

        // ── Dérivés ──────────────────────────────────────────────────────────
        get isWerewolf() {
            return ['werewolf', 'white_wolf'].includes(this.myRole);
        },

        // ════════════════════════════════════════════════════════════════════
        // INIT
        // ════════════════════════════════════════════════════════════════════
        async init() {
            this._csrf   = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            this._motion = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            // Lire les identifiants en lazy — window.GAME_CODE/MY_PLAYER_ID
            // sont définis par le script de la vue APRÈS game-state.js
            await this.$nextTick();
            this.gameCode = this.$el?.dataset?.gameCode ?? window.GAME_CODE ?? null;
            this.playerId = parseInt(this.$el?.dataset?.playerId ?? window.MY_PLAYER_ID ?? 0, 10) || null;
            // Lire MY_ROLE immédiatement depuis window (défini par la vue) pour que
            // isWerewolf soit correct avant initWebSocket(), même si _loadState échoue
            this.myRole   = window.MY_ROLE ?? this.myRole;

            // Charger l'état initial depuis le serveur
            if (this.gameCode) {
                await this._loadState();
            }

            // Fallback garanti avant initWebSocket() — window.MY_ROLE est défini
            // par le script inline de la vue après le bundle Vite, mais au moment
            // de init() (déclenché par Alpine au chargement du DOM), il peut ne pas
            // encore être disponible si _loadState() a échoué silencieusement.
            if (!this.myRole) {
                this.myRole = window.MY_ROLE ?? null;
            }
            this.initWebSocket();
            this._setupBeforeUnload();
            this._reconnect();
        },

        async _loadState() {
            try {
                const res  = await fetch(`/game/${this.gameCode}/state`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const json = await res.json();
                if (!json.success) return;
                const d               = json.data;
                this.phase                = d.phase;
                this.round                = d.round;
                this.myRole               = d.my_role ?? window.MY_ROLE ?? null;
                this.isAlive              = d.is_alive;
                this.isMayor              = d.is_mayor;
                if (d.seer_turn_active)            this.nightPhase = 'seer_turn';
                else if (d.werewolves_turn_active) this.nightPhase = 'werewolves_turn';
                this._allies              = d.allies ?? [];
                this.players              = d.players ?? [];

                // Rattrapage de sous-phase de nuit (loup/voyante/sorcière/chasseur/cupidon) —
                // voir DECISIONS.md "Resynchronisation des sous-phases de nuit". Consommé par
                // night.blade.php via window event, jamais via $dispatch (règle Alpine du projet).
                if (d.night_action) {
                    window.dispatchEvent(new CustomEvent('night-phase-resync', { detail: d.night_action }));
                }
            } catch {
                // silencieux — état sera reconstruit via WebSocket
            }
        },

        // ════════════════════════════════════════════════════════════════════
        // WEBSOCKET
        // ════════════════════════════════════════════════════════════════════
        initWebSocket() {
            if (this._wsInitialized) return;
            this._wsInitialized = true;

            // Garantir que myRole est résolu avant toute décision de souscription
            if (!this.myRole) {
                this.myRole = window.MY_ROLE ?? null;
            }

            const echo = window.Echo;
            if (!echo) return;

            // ── Canal public ─────────────────────────────────────────────────
            // ⚠️ Ordre critique : .phase.announcement AVANT .night.started et .day.started
            // (SPEC_TRANSITIONS.md §5.3) pour alimenter la file avant que ces events tentent d'être différés.
            echo.channel(`game.${this.gameId}`)
                .listen('.phase.announcement',         e => this.addAnnouncement(e.type, e.messagePublic, e.durationMs))
                .listen('.night.started',              e => this.handleNightStarted(e))
                .listen('.day.started',                e => this.handleDayStarted(e))
                .listen('.mayor.election.started',     e => this._handleMayorElectionStarted(e))
                .listen('.mayor.elected',              e => this.handleMayorElected(e))
                .listen('.mayor.vote.cast',            e => this._handleMayorVoteCast(e))
                .listen('.mayor.succession.started',   e => {
                    this.successionDepth++;
                    window.dispatchEvent(new CustomEvent('mayor-succession-started', { detail: e }));
                })
                .listen('.mayor.succession.done',      e => this.handleMayorSuccessionDone(e))
                .listen('.day.vote.cast',              e => this._handleDayVoteCast(e))
                .listen('.player.eliminated',          e => this.handlePlayerEliminated(e))
                .listen('.no.elimination',             e => {
                    this._dispatchToast(
                        e.reason === 'equality' ? 'Égalité — personne éliminé.' : 'Aucun vote exprimé.', 'info'
                    );
                    window.dispatchEvent(new CustomEvent('no-elimination', { detail: e }));
                })
                .listen('.random.elimination',         e => {
                    this.handlePlayerEliminated({
                        player_id: e.player_id,
                        pseudo:    e.pseudo,
                        role:      e.role ?? null,
                        reason:    'random_elimination',
                    });
                    window.dispatchEvent(new CustomEvent('random-elimination', { detail: e }));
                })
                .listen('.chat.message.sent',          e => this._handleChatMessage(e))
                .listen('.player.disconnected',        e => this._handlePlayerDisconnected(e))
                .listen('.player.reconnected',         e => this._handlePlayerReconnected(e))
                .listen('.player.inactive',            e => this._handlePlayerInactive(e))
                .listen('.hunter.shot',                e => {
                    window.dispatchEvent(new CustomEvent('hunter-shot', { detail: e }));
                })
                .listen('.witch.acted.public',         e => {
                    const msg = e.target_pseudo
                        ? `🧙 La sorcière a empoisonné ${e.target_pseudo}.`
                        : '🧙 La sorcière a agi cette nuit.';
                    this._dispatchToast(msg, 'info');
                })
                .listen('.player.ready',               e => this._handlePlayerReady(e))
                .listen('.game.finished',              e => this.handleGameFinished(e));

            // Présence (détection leaving)
            echo.join(`game.${this.gameId}.presence`)
                .leaving(() => {
                    // Toast géré exclusivement par _handlePlayerDisconnected via .player.disconnected
                    // Le canal de présence fire .leaving() à chaque navigation — pas de toast ici.
                });

            // ── Canal joueur individuel ──────────────────────────────────────
            if (this.playerId) {
                echo.private(`game.${this.gameId}.player.${this.playerId}`)
                    .listen('.game.started',       e => this._handleRoleAssigned(e))
                    .listen('.cupidon.turn.started', e => {
                        this.nightPhase = 'cupidon_turn';
                        window.dispatchEvent(new CustomEvent('cupidon-turn-started', { detail: e }));
                    })
                    .listen('.lover.revealed',     e => {
                        window.dispatchEvent(new CustomEvent('lover-revealed', { detail: e }));
                    })
                    .listen('.seer.turn.started',  e => {
                        this.pendingSeerEvent = e;
                        // Propager sur window pour que night.blade.php puisse réagir
                        // sans double-abonnement au canal privé
                        window.dispatchEvent(new CustomEvent('seer-turn-started', { detail: e }));
                    })
                    .listen('.seer.result',        e => {
                        this.seerResult = e;
                        this.nightPhase = 'seer_result';
                        // window.dispatchEvent pour night.blade.php (pas $dispatch qui reste sur le DOM)
                        window.dispatchEvent(new CustomEvent('seer-result', { detail: e }));
                    })
                    .listen('.witch.turn.started', e => {
                        this.nightPhase = 'witch_turn';
                        window.dispatchEvent(new CustomEvent('witch-turn-started', { detail: e }));
                    })
                    .listen('.witch.acted',        e => {
                        window.dispatchEvent(new CustomEvent('witch-acted', { detail: e }));
                    })
                    .listen('.hunter.turn.started', e => {
                        this.nightPhase = 'hunter_turn';
                        window.dispatchEvent(new CustomEvent('hunter-turn-started', { detail: e }));
                    });
            }

            // ── Canal loups (si loup) ────────────────────────────────────────
            // Utiliser window.MY_ROLE comme fallback au moment précis de la décision
            // — this.myRole peut être null si _loadState() a échoué et que le fallback
            // dans init() n'a pas encore reçu window.MY_ROLE (défini par le layout).
            const effectiveRole = this.myRole ?? window.MY_ROLE ?? null;
            const isWolfEffective = ['werewolf', 'white_wolf'].includes(effectiveRole);

            if (isWolfEffective) {
                echo.private(`game.${this.gameId}.werewolves`)
                    .listen('.werewolves.turn.started', e => {
                        this.nightPhase = 'werewolves_turn';
                        window.dispatchEvent(new CustomEvent('werewolves-turn-started', { detail: e }));
                    })
                    .listen('.werewolves.vote.cast', e => {
                        this.wolvesVotes = this._buildVoteMap(e.votes ?? []);
                        window.dispatchEvent(new CustomEvent('wolves-vote-cast', { detail: e }));
                    })
                    .listen('.werewolf.chat.message', e => {
                        this.wolvesChat.push(e);
                        window.dispatchEvent(new CustomEvent('werewolf-chat-message', { detail: e }));
                    });
            }

            // ── Reconnexion WebSocket (Pusher/Reverb) ────────────────────────
            try {
                const conn = echo.connector.pusher.connection;
                conn.bind('disconnected', () => {
                    this._dispatchToast('Connexion perdue. Reconnexion…', 'warning');
                });
                conn.bind('connected', () => {
                    if (!this._wsEverConnected) {
                        this._wsEverConnected = true;
                        return;
                    }
                    if (sessionStorage.getItem('__internalNavigation')) return;
                    this._dispatchToast('Reconnecté !', 'success');
                    // Une vraie coupure/reconnexion Echo peut avoir manqué le broadcast
                    // de sous-phase de nuit (seer/wolves/witch/hunter/cupidon turn started) —
                    // ré-appeler /state rattrape l'écran actif exactement comme au chargement
                    // de page (voir DECISIONS.md "Resynchronisation des sous-phases de nuit").
                    if (this.gameCode) this._loadState();
                });
            } catch {}

            // ── Signal de fin d'overlay (depuis announcementOverlay) ─────────
            window.addEventListener('announcement-done', () => {
                this.onAnnouncementDone();
            });
        },

        // ════════════════════════════════════════════════════════════════════
        // ANNONCES DE PHASES — FILE FIFO (SPEC_TRANSITIONS.md §5.1)
        // ════════════════════════════════════════════════════════════════════
        addAnnouncement(type, message, durationMs) {
            this.announcements.push({ type, message, durationMs });
            this.consumeAnnouncements();
        },

        consumeAnnouncements() {
            if (this.isAnnouncing) return;
            if (this.announcements.length === 0) return;

            this.isAnnouncing = true;
            const next = this.announcements.shift();
            window.dispatchEvent(new CustomEvent('show-announcement', { detail: next }));
        },

        onAnnouncementDone() {
            this.isAnnouncing = false;
            if (this._pendingNightStarted)  { this._applyNightStarted(this._pendingNightStarted);  this._pendingNightStarted  = null; }
            if (this._pendingDayStarted)    { this._applyDayStarted(this._pendingDayStarted);      this._pendingDayStarted    = null; }
            if (this._pendingMayorElected)  { this._applyMayorElected(this._pendingMayorElected);  this._pendingMayorElected  = null; }
            this.consumeAnnouncements();
        },

        // ════════════════════════════════════════════════════════════════════
        // HANDLERS — PHASES
        // ════════════════════════════════════════════════════════════════════
            handleNightStarted(e) {
                if (this.isAnnouncing) {
                    this._pendingNightStarted = e;
                    return;
                }
                this._applyNightStarted(e);
            },

            _applyNightStarted(e) {
                this.phase            = 'night';
                this.round            = e.round ?? this.round;
                this.votes            = {};
                this.wolvesVotes      = {};
                this.nightPhase       = 'village_sleeping';
                this.pendingSeerEvent = null;
                this.nightVictim      = null;

                const redirect = () => {
                    if (this.gameCode) this._navigateTo(`/game/${this.gameCode}/night`);
                };

                // Si une ou plusieurs successions sont en cours, attendre qu'elles soient
                // toutes terminées avant de rediriger. Couvre la cascade N maires successifs.
                if (this.successionDepth > 0) {
                    const waitForSuccession = () => {
                        if (this.successionDepth > 0) return; // cascade encore en cours
                        window.removeEventListener('mayor-succession-done', waitForSuccession);
                        clearTimeout(safetyTimeout);
                        this._doNightRedirect(redirect);
                    };
                    // Guard si MayorSuccessionDone arrive avant que le listener soit posé
                    // (réordonnancement WebSocket) — force la redirection après 25s.
                    const safetyTimeout = setTimeout(() => {
                        window.removeEventListener('mayor-succession-done', waitForSuccession);
                        this._doNightRedirect(redirect);
                    }, 25000);
                    window.addEventListener('mayor-succession-done', waitForSuccession);
                    return;
                }

                this._doNightRedirect(redirect);
            },

            _doNightRedirect(redirect) {
                if (this._motion) {
                    gsap.to(document.body, {
                        backgroundColor: '#030712',
                        duration: 1.5,
                        ease: 'power2.inOut',
                        onComplete: redirect,
                    });
                } else {
                    redirect();
                }
            },

        handleSeerTurnReady() {
            if (this.myRole === 'seer' && this.pendingSeerEvent !== null) {
                this.nightPhase = 'seer_turn';
                window.dispatchEvent(new CustomEvent('seer-turn-ready', { detail: this.pendingSeerEvent }));
            }
        },

        handleDayStarted(e) {
            if (this.isAnnouncing) {
                this._pendingDayStarted = e;
                return;
            }
            this._applyDayStarted(e);
        },

        _applyDayStarted(e) {
            this.phase            = 'day';
            this.nightPhase       = 'village_sleeping';
            this.pendingSeerEvent = null;
            this.nightVictim      = e.killed ?? null;

            if (e.killed?.player_id) {
                this._markPlayerDead(e.killed.player_id);
            }

            const myId = this.playerId || window.MY_PLAYER_ID || null;
            if (myId && e.saved_player_id === myId) {
                // Le toast ne peut pas être affiché ici : handleDayStarted déclenche
                // immédiatement une redirection GSAP qui détruit la page /night avant
                // que le composant toast Alpine ait pu rendre le message.
                // On le pousse dans sessionStorage pour le consommer sur /day.
                sessionStorage.setItem(
                    'pending_toast_' + myId,
                    JSON.stringify({ message: '🧙 La sorcière t\'a sauvé cette nuit.', type: 'success' })
                );
                window.dispatchEvent(new CustomEvent('i-was-saved'));
            }

            if (e.witch_player_id || e.poisoned_player_id || e.saved_player_id) {
                const pendingToastKey = 'pending_toast_' + myId;

                if (myId && e.witch_player_id === myId) {
                    // Je suis la sorcière
                    if (e.saved_player_id === myId) {
                        sessionStorage.setItem(pendingToastKey, JSON.stringify({
                            message: '🧙 Tu t\'es sauvée cette nuit.',
                            type: 'success',
                        }));
                    } else if (e.poisoned_player_pseudo) {
                        sessionStorage.setItem(pendingToastKey, JSON.stringify({
                            message: `☠️ Tu as empoisonné ${e.poisoned_player_pseudo}.`,
                            type: 'error',
                        }));
                    }
                } else if (myId && e.poisoned_player_id === myId) {
                    // Je suis le joueur empoisonné
                    sessionStorage.setItem(pendingToastKey, JSON.stringify({
                        message: '☠️ La sorcière t\'a empoisonné cette nuit.',
                        type: 'error',
                    }));
                } else {
                    // Tous les autres joueurs — toast générique sur /day
                    if (e.poisoned_player_pseudo) {
                        this._dispatchToast(`🧙 La sorcière a empoisonné ${e.poisoned_player_pseudo}.`, 'info');
                    } else if (e.saved_player_id) {
                        this._dispatchToast('🧙 La sorcière a utilisé sa potion de guérison.', 'info');
                    }
                }
            }

            const redirect = () => {
                if (this.gameCode) this._navigateTo(`/game/${this.gameCode}/day`);
            };

            if (this._motion) {
                const sun = document.getElementById('sun-glow');
                gsap.to(document.body, {
                    backgroundColor: '#0a0f1e',
                    duration: 2,
                    ease: 'power2.out',
                    onComplete: sun ? null : redirect,
                });
                if (sun) {
                    gsap.from(sun, {
                        y: '100%', opacity: 0, duration: 2.5,
                        ease: 'power2.out', onComplete: redirect,
                    });
                }
            } else {
                redirect();
            }
        },

        handleMayorElected(e) {
            if (this.isAnnouncing) {
                this._pendingMayorElected = e;
                return;
            }
            this._applyMayorElected(e);
        },

        _applyMayorElected(e) {
            this._dispatchToast(`👑 ${e.pseudo} est élu Maire`, 'info');
            this._updateMayorBadges(e.player_id);
            window.dispatchEvent(new CustomEvent('mayor-elected', { detail: e }));
        },

        _updateMayorBadges(playerId) {
            this.players = this.players.map(p => ({
                ...p,
                is_mayor: p.id === playerId,
            }));

            document.querySelectorAll('[data-player-id]').forEach(el => {
                const pid   = parseInt(el.dataset.playerId, 10);
                const crown = el.querySelector('[data-badge="mayor"]');

                if (crown) crown.style.display = pid === playerId ? '' : 'none';
            });
        },

        handleMayorSuccessionDone(e) {
            this._dispatchToast(`👑 ${e.new_mayor_pseudo} est le nouveau Maire`, 'info');
            this.successionDepth = Math.max(0, this.successionDepth - 1);
            this._updateMayorBadges(e.new_mayor_id);
            window.dispatchEvent(new CustomEvent('mayor-succession-done', { detail: e }));
        },

        handlePlayerEliminated(e) {
            if (this.phase === 'finished') {
                this._markPlayerDead(e.player_id);
                window.dispatchEvent(new CustomEvent('player-eliminated', { detail: e }));
                return;
            }

            this._markPlayerDead(e.player_id);

            const roleLabels = {
                werewolf: 'Loup-Garou', seer: 'Voyante', witch: 'Sorcière',
                hunter: 'Chasseur', cupidon: 'Cupidon', villager: 'Villageois',
            };
            const roleLabel = roleLabels[e.role] ?? e.role ?? '';
            const msg = `💀 ${e.pseudo} était le ${roleLabel || e.role}`;
            this._dispatchToast(msg, e.role === 'werewolf' ? 'success' : 'info');

            // Propager à toutes les vues pour mise à jour de leurs listes locales
            window.dispatchEvent(new CustomEvent('player-eliminated', { detail: e }));

            // Lire playerId en lazy (window.MY_PLAYER_ID défini par la vue après game-state.js)
            const myId = this.playerId || window.MY_PLAYER_ID || null;

            // Animation d'élimination sur la carte joueur
            const card = document.querySelector(`[data-player-id="${e.player_id}"]`);
            if (card) {
                if (this._motion) {
                    gsap.to(card, {
                        backgroundColor: 'rgba(139,0,0,0.10)',
                        borderColor:     'rgba(139,0,0,0.25)',
                        duration: 0.8,
                        ease: 'power2.out',
                    });
                } else {
                    card.style.backgroundColor = 'rgba(139,0,0,0.10)';
                    card.style.borderColor     = 'rgba(139,0,0,0.25)';
                }
            }

            // Si c'est le joueur courant
            if (myId && e.player_id === myId) {
                // Ne pas traiter l'élimination personnelle si la sorcière voit encore son panel :
                // elle vient de passer/empoisonner et sera redirigée vers /day via DayStarted.
                if (this.nightPhase === 'witch_turn' && this.myRole === 'witch') {
                    return;
                }

                this.isAlive = false;
                this.playerId = myId;

                const screen = document.querySelector('.game-screen');
                if (screen && this._motion) {
                    gsap.to(screen, { filter: 'grayscale(30%)', duration: 1 });
                }

                // window.dispatchEvent et non $dispatch : les vues écoutent sur window
                window.dispatchEvent(new CustomEvent('i-was-eliminated', { detail: e }));
            }
        },

        handleGameFinished(e) {
            this.winnerTeam = e.winner_team;
            this.phase      = 'finished';

            const redirect = () => {
                if (!this.gameCode) return;
                if (e.winner_team !== null) {
                    sessionStorage.setItem('last_action', JSON.stringify(e.last_action ?? null));
                    this._navigateTo(`/game/${this.gameCode}/summary`);
                } else {
                    this._navigateTo(`/game/${this.gameCode}/cancelled`);
                }
            };

            if (this._motion) {
                const banner = document.getElementById('victory-banner');
                if (banner) {
                    gsap.fromTo(banner,
                        { scale: 0.7, opacity: 0 },
                        { scale: 1, opacity: 1, duration: 0.7, ease: 'back.out(1.4)', onComplete: redirect }
                    );
                    return;
                }
                gsap.to('body', { opacity: 0, duration: 0.4, ease: 'power2.in', onComplete: redirect });
            } else {
                redirect();
            }
        },

        // ════════════════════════════════════════════════════════════════════
        // HANDLERS — CHAT / VOTES (privés)
        // ════════════════════════════════════════════════════════════════════
        _handleChatMessage(e) {
            if (e.channel === 'general') {
                this.chat.push(e);
            }
            window.dispatchEvent(new CustomEvent('chat-message', { detail: e }));
        },

        _handleDayVoteCast(e) {
            this.votes = this._buildVoteMap(e.votes ?? []);

            if (e.voter_pseudo && e.target_pseudo) {
                const msg = e.voter_pseudo === e.target_pseudo
                    ? `🗳️ ${e.voter_pseudo} a voté pour lui-même`
                    : `🗳️ ${e.voter_pseudo} a voté pour ${e.target_pseudo}`;
                this._dispatchToast(msg, 'info');
            }

            window.dispatchEvent(new CustomEvent('day-vote-cast', { detail: e }));
        },

        _handleMayorVoteCast(e) {
            // e.votes = [{ target_player_id, vote_count }]
            this.votes = {};
            (e.votes ?? []).forEach(v => { this.votes[v.target_player_id] = v.vote_count; });

            if (e.voter_pseudo && e.target_pseudo) {
                const msg = e.voter_pseudo === e.target_pseudo
                    ? `👑 ${e.voter_pseudo} a voté pour lui-même`
                    : `👑 ${e.voter_pseudo} a voté pour ${e.target_pseudo}`;
                this._dispatchToast(msg, 'info');
            }

            window.dispatchEvent(new CustomEvent('mayor-vote-cast', { detail: e }));
        },

        _handleMayorElectionStarted(e) {
            this.phase = 'electing_mayor';
            this.votes = {};
            window.dispatchEvent(new CustomEvent('mayor-election-started', { detail: e }));
        },

        _handlePlayerReady(e) {
            window.dispatchEvent(new CustomEvent('player-ready', { detail: e }));
        },

        _handleRoleAssigned(e) {
            if (e.role) this.myRole = e.role;
            if (e.allies) this._allies = e.allies;
        },

        // ════════════════════════════════════════════════════════════════════
        // HANDLERS — DÉCONNEXION
        // ════════════════════════════════════════════════════════════════════
        _handlePlayerDisconnected(e) {
            if (sessionStorage.getItem('__internalNavigation')) return;
            this._dispatchToast(`${e.pseudo} se reconnecte…`, 'info');
            if (e.pseudo === this._myPseudo()) {
                document.getElementById('reconnecting-overlay')
                    ?? this._showReconnectingOverlay();
            }
        },

        _handlePlayerReconnected(e) {
            if (sessionStorage.getItem('__internalNavigation')) return;
            this._dispatchToast(`${e.pseudo} est de retour !`, 'success');
            if (e.pseudo === this._myPseudo()) {
                this._hideReconnectingOverlay();
            }
        },

        _handlePlayerInactive(e) {
            if (sessionStorage.getItem('__internalNavigation')) return;
            this._dispatchToast(`${e.pseudo} est inactif`, 'warning');
            if (e.pseudo === this._myPseudo()) {
                this._hideReconnectingOverlay();
            }
        },

        // ════════════════════════════════════════════════════════════════════
        // ACTIONS
        // ════════════════════════════════════════════════════════════════════
        async sendMessage(message, channel = 'general') {
            if (!this.isAlive) return;
            const allowedPhases = channel === 'general'
                ? ['day', 'electing_mayor']
                : ['night'];
            if (!allowedPhases.includes(this.phase)) return;

            try {
                await fetch(`/game/${this.gameId}/chat`, {
                    method:  'POST',
                    headers: this._headers(),
                    body:    JSON.stringify({ message, channel }),
                });
            } catch {}
        },

        async castVote(type, targetPlayerId) {
            const endpoints = { mayor: 'mayor', day: 'day', night: 'night' };
            const path      = endpoints[type];
            if (!path) return;

            try {
                const res  = await fetch(`/game/${this.gameId}/vote/${path}`, {
                    method:  'POST',
                    headers: this._headers(),
                    body:    JSON.stringify({ target_player_id: targetPlayerId }),
                });
                const json = await res.json();

                // Mettre à jour les barres de votes
                if (json.success && this._motion) {
                    document.querySelectorAll('[data-vote-bar]').forEach(bar => {
                        const pid   = parseInt(bar.dataset.voteBar, 10);
                        const total = this.votes[pid] ?? 0;
                        const max   = Math.max(1, ...Object.values(this.votes));
                        gsap.to(bar, { width: `${(total / max) * 100}%`, duration: 0.4, ease: 'power2.out' });
                    });
                }
            } catch {}
        },

        // ════════════════════════════════════════════════════════════════════
        // UTILITAIRES INTERNES
        // ════════════════════════════════════════════════════════════════════
        _navigateTo(url) {
            sessionStorage.setItem('__internalNavigation', '1');
            // setTimeout(0) garantit que le sessionStorage est flushé avant que
            // beforeunload soit déclenché — évite le faux /disconnect sur mobile.
            setTimeout(() => { window.location.href = url; }, 0);
        },

        _setupBeforeUnload() {
            window.addEventListener('beforeunload', () => {
                if (sessionStorage.getItem('__internalNavigation')) return;
                const fd = new FormData();
                fd.append('_token', this._csrf);
                navigator.sendBeacon(`/game/${this.gameId}/disconnect`, fd);
            });
        },

        _reconnect() {
            // Un joueur qui se reconnecte ne voit jamais les annonces manquées (SPEC_TRANSITIONS.md §6)
            this.announcements        = [];
            this.isAnnouncing         = false;
            this._pendingNightStarted = null;
            this._pendingDayStarted   = null;
            this._pendingMayorElected = null;

            if (sessionStorage.getItem('__internalNavigation')) { sessionStorage.removeItem('__internalNavigation'); return; }
            if (!this.gameCode) return;
            const attempt = (retries) => {
                fetch(`/game/${this.gameCode}/reconnect`, {
                    method:  'POST',
                    headers: this._headers(),
                }).catch(() => {
                    if (retries > 0) setTimeout(() => attempt(retries - 1), 2000);
                });
            };
            attempt(3);
        },

        _markPlayerDead(playerId) {
            this.players = this.players.map(p =>
                p.id === playerId ? { ...p, is_alive: false } : p
            );
        },

        _buildVoteMap(votes) {
            const map = {};
            votes.forEach(v => {
                const id = v.target_player_id ?? v.player_id;
                map[id]  = v.vote_weight ?? v.vote_count ?? 1;
            });
            return map;
        },

        _myPseudo() {
            return document.querySelector('[data-player-pseudo]')?.dataset?.playerPseudo
                ?? document.querySelector('meta[name="player-pseudo"]')?.content
                ?? '';
        },

        _dispatchToast(message, type = 'info') {
            const detail = { message, type };
            if (window.__toastReady) {
                window.dispatchEvent(new CustomEvent('show-toast', { detail }));
            } else {
                // Alpine pas encore prêt — mettre en buffer
                window.__toastBuffer = window.__toastBuffer ?? [];
                window.__toastBuffer.push(detail);
                // Retry après init Alpine
                requestAnimationFrame(() => {
                    if (window.__toastReady && window.__toastBuffer?.length) {
                        window.__toastBuffer.forEach(d =>
                            window.dispatchEvent(new CustomEvent('show-toast', { detail: d }))
                        );
                        window.__toastBuffer = [];
                    }
                });
            }
        },

        _headers() {
            return {
                'Content-Type':     'application/json',
                'Accept':           'application/json',
                'X-CSRF-TOKEN':     this._csrf,
                'X-Requested-With': 'XMLHttpRequest',
            };
        },

        _showReconnectingOverlay() {
            if (document.getElementById('reconnecting-overlay')) return;
            const el    = document.createElement('div');
            el.id        = 'reconnecting-overlay';
            el.className = 'fixed inset-0 z-40 flex items-center justify-center pointer-events-none';
            el.style.backgroundColor = 'rgba(3,7,18,0.8)';
            el.innerHTML = `<div class="text-center">
                <svg class="animate-spin h-10 w-10 mx-auto mb-4" style="color:#c9a84c;"
                     xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <p style="color:#c9a84c;font-family:Cinzel,serif;">Reconnexion…</p>
            </div>`;
            document.body.appendChild(el);
        },

        _hideReconnectingOverlay() {
            document.getElementById('reconnecting-overlay')?.remove();
        },
    };
}