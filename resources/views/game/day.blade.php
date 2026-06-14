@extends('layouts.game')

@section('title', 'Loup-Garou Undu — Phase Jour')

@push('styles')
<style>
    [x-cloak] { display: none !important; }
    body { background: linear-gradient(160deg, #0d1426 0%, #1a1f35 100%); font-family: 'EB Garamond', serif; }
    .avatar {
        width: 2.5rem; height: 2.5rem; border-radius: 9999px;
        background-color: rgba(201,168,76,0.15); border: 1px solid rgba(201,168,76,0.4);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Cinzel', serif; font-weight: 600;
        font-size: 0.875rem; color: #c9a84c; flex-shrink: 0;
    }
    .succession-modal {
        background-color: #111827;
        border: 1px solid rgba(201,168,76,0.5);
        border-radius: 1rem;
    }
    .timer-bar { height: 4px; border-radius: 9999px; background-color: rgba(201,168,76,0.15); overflow: hidden; }
    .timer-fill { height: 100%; border-radius: 9999px; transition: background-color 0.3s; }
    .day-timer-bar { height: 3px; border-radius: 9999px; background-color: rgba(201,168,76,0.12); overflow: hidden; }
    .vote-player-card {
        background-color: #111827;
        border: 1px solid rgba(201,168,76,0.15);
        border-radius: 0.75rem;
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.65rem 0.9rem;
        transition: border-color 0.15s, background-color 0.15s;
    }
    .vote-player-card.v-selected {
        border-color: #c9a84c !important;
        box-shadow: 0 0 18px rgba(201,168,76,0.5);
        background-color: rgba(201,168,76,0.07);
    }
    .vote-player-card.v-dead { opacity: .3; filter: grayscale(100%); cursor: not-allowed; }
    .vote-player-card.v-dead:hover { transform: none; }
    .vote-player-card.v-clickable { cursor: pointer; }
    .vote-player-card.v-clickable:hover { transform: translateY(-3px); transition: transform 0.15s ease; }
    .vote-bar-wrap {
        height: 5px; border-radius: 9999px;
        background-color: rgba(255,255,255,0.06); overflow: hidden; margin-top: 5px;
    }
    .vote-bar-fill { height: 100%; border-radius: 9999px; background-color: #c9a84c; }
    .chat-bubble {
        background-color: #111827; border: 1px solid rgba(255,255,255,0.06);
        border-radius: 0.75rem; padding: 0.45rem 0.7rem; max-width: 85%;
    }
    .chat-bubble.chat-own {
        background-color: rgba(201,168,76,0.08); border-color: rgba(201,168,76,0.2);
        align-self: flex-end;
    }
    .char-counter { font-size: 0.7rem; color: rgba(232,224,208,0.4); }
    .char-counter.cc-warn { color: #f97316; }
    .char-counter.cc-danger { color: #ef4444; }
    .reveal { opacity: 0; }
    @media (prefers-reduced-motion: reduce) {
        .vote-bar-fill { transition: none !important; }
        * { animation: none !important; transition-duration: 0.01ms !important; }
    }
</style>
@endpush

@section('header-phase')
    <span class="text-sm font-medieval" style="color: #c9a84c;">☀️ JOUR — Round {{ $game->round }}</span>
@endsection

@section('content')
@include('partials.game.quit-button')
@include('partials.game.quit-modal')
<div
    class="game-screen max-w-2xl mx-auto px-4 py-10 min-h-screen"
    style="color: #e8e0d0;"
    x-data="dayScreen()"
    x-init="init()"
>
    {{-- ══ Bandeau mort ══ --}}
    <div
        x-show="showDeathBanner"
        x-transition.opacity
        class="mb-5 flex items-center gap-3 px-4 py-3 rounded-xl"
        style="background-color:#1a0000;border:1px solid rgba(139,0,0,0.6);"
    >
        <span class="text-2xl">💀</span>
        <div>
            <p class="font-medieval font-semibold text-sm" style="color:#ef4444;">Tu as été éliminé.</p>
            <p class="text-xs italic" style="color:rgba(232,224,208,0.5);">Tu observes la suite en silence.</p>
        </div>
    </div>

    @if($nightVictim)
    <div class="mb-6 px-4 py-3 rounded-xl text-center" style="background-color:#1a1010;border:1px solid rgba(139,0,0,.5)">
        <p style="color:#e8e0d0;">⚰️ Cette nuit, <span class="font-medieval" style="color:#ff8888">{{ $nightVictim->pseudo }}</span> a été dévoré.</p>
        <p class="text-sm mt-1" style="color:#e8e0d0;">C'était un <span style="color:#4ade80">{{ match($nightVictim->role) { 'werewolf' => 'Loup-Garou', 'seer' => 'Voyante', default => 'Villageois' } }}</span>.</p>
    </div>
    @endif

    <h1 class="reveal font-title text-3xl sm:text-4xl text-center mb-6" style="color:#c9a84c;">
        ☀️ Phase Jour — Round {{ $game->round }}
    </h1>

    {{-- ── VOTE DU JOUR ── --}}
    <div class="mb-8">
        <p class="font-medieval text-xl font-semibold mb-1" style="color:#c9a84c;">⚖ Vote du village</p>
        <p class="text-sm italic mb-4" style="color:rgba(232,224,208,0.45);">
            Désignez le joueur à éliminer. La voix du Maire compte double.
        </p>

        {{-- Timer --}}
        <div class="mb-5">
            <div class="flex justify-between text-xs mb-1" style="color:rgba(232,224,208,0.4);">
                <span class="font-medieval tracking-wide">Temps restant</span>
                <span x-text="dayTimerSeconds + 's'"
                      :style="dayTimerSeconds <= 5 ? 'color:#ef4444' : dayTimerSeconds <= 10 ? 'color:#f97316' : ''"></span>
            </div>
            <div class="day-timer-bar">
                <div id="day-vote-timer" class="timer-fill" style="background-color:#c9a84c;width:0%;"></div>
            </div>
        </div>

        {{-- Message égalité / aucun vote --}}
        <div x-show="noEliminationMessage" x-cloak
             class="mb-4 px-4 py-3 rounded-xl text-sm text-center font-medieval"
             style="background-color:rgba(249,115,22,0.08);border:1px solid rgba(249,115,22,0.3);color:#f97316;"
             x-text="noEliminationMessage">
        </div>

        {{-- Confirmation vote --}}
        <div x-show="myVoteTarget" x-cloak class="mb-4 text-xs italic text-center" style="color:rgba(232,224,208,0.45);">
            Tu as voté pour <span class="font-semibold" style="color:#c9a84c;" x-text="myVoteTargetPseudo"></span>.
        </div>

        {{-- Liste joueurs --}}
        <div class="flex flex-col gap-2 mb-4">
            <template x-for="p in players" :key="p.id">
                <div
                    class="vote-player-card"
                    :class="{
                        'v-selected':  dayVoteTarget === p.id,
                        'v-dead':      !p.is_alive,
                        'v-clickable': p.is_alive && isAlive && !myVoteTarget && p.id !== MY_PLAYER_ID,
                    }"
                    @click="selectVoteTarget(p)"
                >
                    <div class="avatar"
                         style="width:2.2rem;height:2.2rem;font-size:0.8rem;"
                         :style="`background-color:${playerAvatarColor(p.id)}; color:#0a0f1e; border:none; font-weight:700;`">
                        <span x-text="p.pseudo.charAt(0).toUpperCase()"></span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-1.5">
                            <span class="text-sm font-medium truncate"
                                  :style="!p.is_alive ? 'text-decoration:line-through;color:rgba(232,224,208,0.4)' : ''"
                                  x-text="p.pseudo"></span>
                            <span x-show="p.is_mayor" class="text-xs flex-shrink-0">👑</span>
                            <span x-show="p.id === MY_PLAYER_ID" class="text-xs flex-shrink-0" style="color:rgba(232,224,208,0.3);">(toi)</span>
                            <span x-show="!p.is_alive" class="text-xs flex-shrink-0">💀</span>
                        </div>
                        <span
                            x-show="!p.is_alive && p.revealed_role_label"
                            class="text-xs italic"
                            style="color:rgba(232,224,208,0.4);"
                            x-text="p.revealed_role_label"
                        ></span>
                        <div class="vote-bar-wrap" x-show="totalVoteWeight > 0">
                            <div class="vote-bar-fill" :id="'vbar-' + p.id" :style="'width:' + getVotePercent(p.id) + '%'"></div>
                        </div>
                    </div>
                    <span class="text-xs flex-shrink-0 tabular-nums" style="color:rgba(201,168,76,0.7);"
                          x-show="getVoteCount(p.id) > 0"
                          x-text="getVoteCount(p.id) + (getVoteCount(p.id) > 1 ? ' votes' : ' vote')"></span>
                </div>
            </template>
        </div>

        <button
            @click="castDayVote()"
            x-show="isAlive && !myVoteTarget"
            x-cloak
            :disabled="!dayVoteTarget || dayVoteSubmitting"
            class="w-full py-3 rounded-xl font-medieval font-semibold text-sm disabled:opacity-40 transition-all focus:outline-none focus:ring-2 focus:ring-[#c9a84c]"
            style="background-color:#c9a84c;color:#0a0f1e;"
        >
            <span x-show="!dayVoteSubmitting">Voter</span>
            <span x-show="dayVoteSubmitting">Vote en cours…</span>
        </button>
    </div>

    {{-- ── CHAT GÉNÉRAL ── --}}
    <div>
        <p class="font-medieval text-sm tracking-widest mb-2" style="color:#c9a84c;">💬 PLACE DU VILLAGE</p>
        <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(201,168,76,0.12);background:#0d1117;">
            <div class="p-3 overflow-y-auto flex flex-col gap-2 h-48 sm:h-80" x-ref="chatMessages"
                 role="log" aria-label="Messages du village" aria-live="polite">
                <template x-for="(msg, i) in chatMessages" :key="i">
                    <div class="chat-bubble text-xs flex flex-col"
                         :class="msg.pseudo === MY_PSEUDO ? 'chat-own ml-auto' : ''">
                        <span class="font-semibold mb-0.5" style="color:#c9a84c;" x-text="msg.pseudo"></span>
                        <span style="color:rgba(232,224,208,0.8);" x-text="msg.message"></span>
                    </div>
                </template>
                <p x-show="chatMessages.length === 0" class="text-xs italic text-center m-auto" style="color:rgba(232,224,208,0.25);">
                    Le débat n'a pas encore commencé...
                </p>
            </div>
            <div class="flex flex-col gap-2 p-2" style="border-top:1px solid rgba(201,168,76,0.08);">
                <textarea
                    x-model="chatInput"
                    :disabled="!isAlive || chatSending"
                    @keydown.enter.prevent="if (!$event.shiftKey) sendChat()"
                    maxlength="200"
                    rows="2"
                    :placeholder="isAlive ? 'Votre message... (Entrée pour envoyer)' : 'Tu es mort, silence...'"
                    class="w-full resize-none bg-transparent px-3 py-2 text-sm outline-none disabled:opacity-40 rounded-lg"
                    style="color:#e8e0d0; border:1px solid rgba(201,168,76,0.15);
                           background-color:rgba(255,255,255,0.03);"
                ></textarea>
                <div class="flex items-center justify-between">
                    <span class="text-xs char-counter"
                          :class="chatInput.length >= 195 ? 'cc-danger' : chatInput.length >= 180 ? 'cc-warn' : ''"
                          x-show="chatInput.length >= 150"
                          x-text="(200 - chatInput.length) + ' restants'"></span>
                    <button
                        @click="sendChat()"
                        :disabled="!chatInput.trim() || !isAlive || chatSending"
                        class="px-4 py-1.5 rounded-lg text-xs font-medieval font-semibold
                               disabled:opacity-30 transition-all hover:opacity-80"
                        style="background-color:#c9a84c; color:#0a0f1e; min-width:80px;"
                    >
                        <span x-show="!chatSending">Envoyer ✉</span>
                        <span x-show="chatSending">…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── CHAT FANTÔMES (morts uniquement) ── --}}
    <div x-show="!isAlive" x-cloak class="mt-6">
        <p class="font-medieval text-sm tracking-widest mb-2" style="color:rgba(139,0,0,0.7);">
            💀 CANAL DES FANTÔMES
        </p>
        <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(139,0,0,0.3);background:#0d0505;">
            <div class="p-3 overflow-y-auto flex flex-col gap-2 h-32 sm:h-48"
                 x-ref="deadChatMessages"
                 role="log" aria-label="Messages des fantômes" aria-live="polite">
                <template x-for="(msg, i) in deadChatMessages" :key="i">
                    <div class="text-xs flex flex-col"
                         style="background:#1a0808;border:1px solid rgba(139,0,0,0.2);border-radius:0.5rem;padding:0.35rem 0.6rem;"
                         :class="msg.pseudo === MY_PSEUDO ? 'ml-auto' : ''">
                        <span class="font-semibold mb-0.5" style="color:rgba(139,0,0,0.8);" x-text="msg.pseudo"></span>
                        <span style="color:rgba(232,224,208,0.6);" x-text="msg.message"></span>
                    </div>
                </template>
                <p x-show="deadChatMessages.length === 0"
                   class="text-xs italic text-center m-auto"
                   style="color:rgba(232,224,208,0.2);">
                    Les fantômes gardent le silence...
                </p>
            </div>
            <div class="flex flex-col gap-2 p-2" style="border-top:1px solid rgba(139,0,0,0.2);">
                <textarea
                    x-model="deadChatInput"
                    :disabled="deadChatSending"
                    @keydown.enter.prevent="if (!$event.shiftKey) sendDeadChat()"
                    maxlength="200"
                    rows="2"
                    placeholder="Parle avec les autres fantômes..."
                    class="w-full resize-none px-3 py-2 text-xs outline-none rounded-lg"
                    style="background:rgba(139,0,0,0.08); border:1px solid rgba(139,0,0,0.25); color:#e8e0d0;"
                ></textarea>
                <div class="flex justify-end">
                    <button
                        @click="sendDeadChat()"
                        :disabled="!deadChatInput.trim() || deadChatSending"
                        class="px-4 py-1.5 rounded-lg text-xs font-medieval font-semibold disabled:opacity-30"
                        style="background-color:rgba(139,0,0,0.5);color:#fca5a5;border:1px solid rgba(139,0,0,0.4);">
                        <span x-show="!deadChatSending">Envoyer</span>
                        <span x-show="deadChatSending">…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════ MODALE SUCCESSION — lecture seule (automatique) ═══════ --}}
    <div
        x-show="successionOpen"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 flex items-center justify-center z-50 px-4"
        style="background-color: rgba(10,15,30,0.82);"
    >
        <div class="succession-modal w-full max-w-sm p-6" x-ref="successionModal">
            <div class="text-center">
                <p class="font-medieval text-xl font-bold mb-2" style="color: #c9a84c;">👑 Succession du Maire</p>
                <p class="text-sm mb-5 opacity-60" x-text="'Le maire ' + dyingMayorPseudo + ' est mort.'"></p>
                <div class="flex items-center justify-center gap-3">
                    <svg class="animate-spin h-5 w-5" style="color:#c9a84c;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-sm" style="color:#e8e0d0;">Désignation du successeur en cours…</p>
                </div>
                <p x-show="newMayorPseudo"
                   class="text-sm mt-4 font-medieval font-semibold"
                   style="color:#c9a84c;"
                   x-text="'👑 ' + newMayorPseudo + ' est le nouveau Maire'">
                </p>
            </div>
        </div>
    </div>
</div>
@endsection

@php
    $playersJson = $players->map(fn ($p) => [
        'id'                  => $p->id,
        'pseudo'              => $p->pseudo,
        'is_mayor'            => $p->is_mayor,
        'is_alive'            => $p->is_alive,
        'revealed_role'       => $p->is_alive ? null : $p->role,
        'revealed_role_label' => $p->is_alive ? null : match($p->role) {
            'werewolf' => 'Loup-Garou',
            'seer'     => 'Voyante',
            'witch'    => 'Sorcière',
            'hunter'   => 'Chasseur',
            default    => 'Villageois',
        },
    ])->values();
@endphp

@push('scripts')
<script>
    const GAME_ID       = {{ $game->id }};
    const GAME_CODE     = '{{ $game->code }}';
    const MY_PLAYER_ID  = {{ $player->id }};
    const MY_PSEUDO     = '{{ $player->pseudo }}';
    const MY_IS_ALIVE   = {{ $player->is_alive ? 'true' : 'false' }};
    const PHASE_SECONDS = {{ max(0, $game->phaseRemainingSeconds()) }};
    const PLAYERS_DATA  = @json($playersJson);

    // Exposer sur window pour game-state.js
    window.GAME_ID      = GAME_ID;
    window.GAME_CODE    = GAME_CODE;
    window.MY_PLAYER_ID = MY_PLAYER_ID;

    function playerAvatarColor(id) {
        const colors = ['#c9a84c','#a78bfa','#4ade80','#ff4444','#38bdf8','#fb923c','#f472b6','#34d399'];
        return colors[id % colors.length];
    }

    function dayScreen() {
        return {
            confirmQuit:      false,
            successionOpen:   false,
            dyingMayorPseudo: '',
            newMayorPseudo:   '',
            _successionTimer: null,

            isAlive:         MY_IS_ALIVE,
            showDeathBanner: sessionStorage.getItem('dead_' + MY_PLAYER_ID) === '1',

            players:              PLAYERS_DATA,
            voteWeights:          {},
            totalVoteWeight:      0,
            dayTimerSeconds:      PHASE_SECONDS,
            dayVoteTarget:        null,
            myVoteTarget:         null,
            myVoteTargetPseudo:   '',
            dayVoteSubmitting:    false,
            noEliminationMessage: '',

            chatMessages: [],
            chatInput:    '',
            chatSending:  false,

            deadChatMessages: [],
            deadChatInput:    '',
            deadChatSending:  false,

            init() {
                this.players = [...PLAYERS_DATA].sort((a, b) => b.is_alive - a.is_alive);

                if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    gsap.fromTo('.reveal',
                        { opacity: 0, y: 40 },
                        { opacity: 1, y: 0, duration: .7, ease: 'power3.out', stagger: .12, delay: 0.2 }
                    );
                }

                // Les events gérés par game-state.js (player.eliminated, night.started, game.finished)
                // sont propagés via window.dispatchEvent — on écoute ici via window.
                // On s'abonne à Echo uniquement pour les events locaux à cette vue
                // (day.vote.cast, chat) pour éviter les doubles handlers.
                window.Echo.channel(`game.${GAME_ID}`)
                    .listen('.day.vote.cast', (data) => { this._updateVoteBars(data.votes ?? []); });

                // Messages chat dispatchés par game-state.js (évite le double abonnement Echo)
                window.addEventListener('chat-message', (e) => {
                    if (e.detail?.channel === 'general') {
                        this.chatMessages.push(e.detail);
                        this.$nextTick(() => {
                            const el = this.$refs.chatMessages;
                            if (el) el.scrollTop = el.scrollHeight;
                        });
                    } else if (e.detail?.channel === 'dead') {
                        this.deadChatMessages.push(e.detail);
                        this.$nextTick(() => {
                            const el = this.$refs.deadChatMessages;
                            if (el) el.scrollTop = el.scrollHeight;
                        });
                    }
                });

                // Écoute des events window dispatchés par game-state.js
                window.addEventListener('i-was-eliminated', () => {
                    this.showDeathBanner = true;
                    sessionStorage.setItem('dead_' + MY_PLAYER_ID, '1');
                });
                window.addEventListener('mayor-succession-started', (e) => {
                    this.openSuccessionModal(e.detail);
                });
                window.addEventListener('mayor-succession-done', (e) => {
                    this.newMayorPseudo = e.detail?.new_mayor_pseudo ?? '';
                    this.closeSuccessionModal();
                });
                // .no.elimination → game-state.js affiche le toast, on écoute aussi localement
                // pour afficher le message inline
                window.addEventListener('no-elimination', (e) => {
                    this.noEliminationMessage = e.detail?.reason === 'equality'
                        ? 'Égalité — personne n\'est éliminé ce jour.'
                        : 'Aucun vote exprimé — personne n\'est éliminé.';
                });
                // .player.eliminated → mettre à jour la liste locale
                window.addEventListener('player-eliminated', (e) => {
                    const p = this.players.find(p => p.id === e.detail?.player_id);
                    if (p) {
                        p.is_alive = false;
                        const roleLabels = {
                            werewolf: 'Loup-Garou',
                            seer:     'Voyante',
                            witch:    'Sorcière',
                            hunter:   'Chasseur',
                            villager: 'Villageois',
                        };
                        p.revealed_role       = e.detail?.role ?? null;
                        p.revealed_role_label = roleLabels[e.detail?.role] ?? '';
                    }
                });

                this._startDayTimer();
            },

            _startDayTimer() {
                const el = document.getElementById('day-vote-timer');
                const totalSeconds = {{ $game->timer('day_vote') }};

                if (PHASE_SECONDS <= 0) {
                    if (el) el.style.width = '0%';
                    this.dayTimerSeconds = 0;
                    return;
                }

                this.dayTimerSeconds = PHASE_SECONDS;
                const initialPct = Math.min(100, Math.round((PHASE_SECONDS / totalSeconds) * 100));
                if (el) el.style.width = initialPct + '%';

                const iv = setInterval(() => {
                    this.dayTimerSeconds = Math.max(0, this.dayTimerSeconds - 1);
                    const pct = Math.round((this.dayTimerSeconds / totalSeconds) * 100);
                    if (el) el.style.width = pct + '%';

                    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        if (this.dayTimerSeconds <= 5 && el) {
                            gsap.to(el, { backgroundColor: '#ef4444', duration: 0.3, overwrite: true });
                        } else if (this.dayTimerSeconds <= 10 && el) {
                            gsap.to(el, { backgroundColor: '#f97316', duration: 0.3, overwrite: true });
                        }
                    }

                    if (this.dayTimerSeconds <= 0) {
                        clearInterval(iv);
                        if (el) el.style.width = '0%';
                    }
                }, 1000);
            },

            _updateVoteBars(votes) {
                this.voteWeights     = {};
                this.totalVoteWeight = 0;
                votes.forEach(v => {
                    const w = v.vote_weight ?? v.vote_count ?? 0;
                    this.voteWeights[v.player_id] = w;
                    this.totalVoteWeight += w;
                });
                if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    votes.forEach(v => {
                        const el = document.getElementById(`vbar-${v.player_id}`);
                        if (el) gsap.to(el, { width: this.getVotePercent(v.player_id) + '%', duration: 0.5, ease: 'power2.out' });
                    });
                }
            },

            getVoteCount(playerId)   { return this.voteWeights[playerId] ?? 0; },
            getVotePercent(playerId) {
                if (this.totalVoteWeight === 0) return 0;
                return Math.round((this.getVoteCount(playerId) / this.totalVoteWeight) * 100);
            },

            selectVoteTarget(p) {
                if (!p.is_alive || !this.isAlive || this.myVoteTarget || p.id === MY_PLAYER_ID) return;
                this.dayVoteTarget = p.id;
            },

            async castDayVote() {
                if (!this.dayVoteTarget || this.dayVoteSubmitting) return;
                this.dayVoteSubmitting = true;
                try {
                    const res = await fetch(`/game/${GAME_ID}/vote/day`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ target_player_id: this.dayVoteTarget }),
                    });
                    const json = await res.json();
                    if (json.success) {
                        this.myVoteTarget       = this.dayVoteTarget;
                        const target            = this.players.find(p => p.id === this.dayVoteTarget);
                        this.myVoteTargetPseudo = target?.pseudo ?? '';
                    }
                } catch { }
                finally { this.dayVoteSubmitting = false; }
            },

            async sendChat() {
                const msg = this.chatInput.trim();
                if (!msg || !this.isAlive || this.chatSending) return;
                this.chatInput   = '';
                this.chatSending = true;
                try {
                    await fetch(`/game/${GAME_ID}/chat`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ message: msg, channel: 'general' }),
                    });
                } catch { }
                finally { this.chatSending = false; }
            },

            async sendDeadChat() {
                const msg = this.deadChatInput.trim();
                if (!msg || this.deadChatSending) return;
                this.deadChatInput   = '';
                this.deadChatSending = true;
                try {
                    await fetch(`/game/${GAME_ID}/chat`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ message: msg, channel: 'dead' }),
                    });
                } catch { }
                finally { this.deadChatSending = false; }
            },

            openSuccessionModal(data) {
                this.dyingMayorPseudo = data.dying_mayor_pseudo;
                this.successionOpen   = true;
                this.$nextTick(() => {
                    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        gsap.from(this.$refs.successionModal, { opacity: 0, y: 30, duration: 0.4, ease: 'power2.out' });
                    }
                });
                // Garde-fou : fermeture automatique si mayor-succession-done non reçu dans 20s
                if (this._successionTimer) clearTimeout(this._successionTimer);
                this._successionTimer = setTimeout(() => {
                    this.closeSuccessionModal();
                }, 20000);
            },

            closeSuccessionModal() {
                // Délai avant fermeture pour laisser le temps de voir le résultat
                setTimeout(() => {
                    this.successionOpen = false;
                }, 2500);
                if (this._successionTimer) {
                    clearTimeout(this._successionTimer);
                    this._successionTimer = null;
                }
            },

            submitQuit() {
                fetch(`/game/${GAME_ID}/quit`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                }).then(() => { window.location.href = '/'; });
            },
        };
    }
</script>
@endpush