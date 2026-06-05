@extends('layouts.game')

@section('title', 'Loup-Garou Undu — Phase Jour')

@push('styles')
<style>
    [x-cloak] { display: none !important; }
    body { background: linear-gradient(160deg, #0d1426 0%, #1a1f35 100%); font-family: 'Crimson Text', serif; }

    .player-card {
        background-color: #111827;
        border: 1px solid rgba(201,168,76,0.25);
        border-radius: 0.75rem;
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.75rem 1rem;
    }
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
    .succession-player-btn {
        background-color: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 0.5rem;
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.6rem 0.75rem;
        cursor: pointer; transition: all 0.15s;
    }
    .succession-player-btn.selected {
        background-color: rgba(201,168,76,0.15);
        border-color: rgba(201,168,76,0.5);
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
        border-color: rgba(201,168,76,0.6);
        background-color: rgba(201,168,76,0.07);
    }
    .vote-player-card.v-dead { opacity: 0.38; }
    .vote-player-card.v-clickable { cursor: pointer; }
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
    @media (prefers-reduced-motion: reduce) {
        .vote-bar-fill { transition: none !important; }
        * { animation: none !important; transition-duration: 0.01ms !important; }
    }
    .btn-primary {
        background-color: #c9a84c;
        color: #0a0f1e;
        transition: background-color .3s, transform .2s;
    }
    .btn-primary:hover    { background-color: #e0c068; transform: translateY(-2px); }
    .btn-primary:disabled { opacity: .4; cursor: not-allowed; transform: none; }
    .vote-player-card.v-clickable:hover {
        transform: translateY(-3px);
        transition: transform 0.15s ease;
    }
    .vote-player-card.v-selected {
        border-color: #c9a84c !important;
        box-shadow: 0 0 18px rgba(201,168,76,0.5);
    }
    .vote-player-card.v-dead       { opacity: .3; filter: grayscale(100%); }
    .vote-player-card.v-dead:hover { transform: none; }
    .reveal { opacity: 0; }
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
    {{-- ══ Nouvelle de la nuit ══ --}}
    <div class="mb-6 px-4 py-3 rounded-xl text-center" style="background-color:#1a1010;border:1px solid rgba(139,0,0,.5)">
        <p style="color:#e8e0d0;">⚰️ Cette nuit, <span class="font-medieval" style="color:#ff8888">{{ $nightVictim->pseudo }}</span> a été dévoré.</p>
        <p class="text-sm mt-1" style="color:#e8e0d0;">C'était un <span style="color:#4ade80">{{ match($nightVictim->role) { 'werewolf' => 'Loup-Garou', 'seer' => 'Voyante', default => 'Villageois' } }}</span>.</p>
    </div>
    @endif

    {{-- Grand titre ──}}
    <h1 class="reveal font-title text-3xl sm:text-4xl text-center mb-6"
        style="color:#c9a84c;">
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
                <div id="day-vote-timer" class="timer-fill" style="background-color:#c9a84c;width:100%;"></div>
            </div>
        </div>

        {{-- Message égalité / aucun vote --}}
        <div x-show="noEliminationMessage" x-cloak
             class="mb-4 px-4 py-3 rounded-xl text-sm text-center font-medieval"
             style="background-color:rgba(249,115,22,0.08);border:1px solid rgba(249,115,22,0.3);color:#f97316;"
             x-text="noEliminationMessage">
        </div>

        {{-- Confirmation vote personnel --}}
        <div x-show="myVoteTarget" x-cloak class="mb-4 text-xs italic text-center" style="color:rgba(232,224,208,0.45);">
            Tu as voté pour
            <span class="font-semibold" style="color:#c9a84c;" x-text="myVoteTargetPseudo"></span>.
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
                        <div class="vote-bar-wrap" x-show="totalVoteWeight > 0">
                            <div class="vote-bar-fill"
                                 :id="'vbar-' + p.id"
                                 :style="'width:' + getVotePercent(p.id) + '%'"></div>
                        </div>
                    </div>
                    <span class="text-xs flex-shrink-0 tabular-nums" style="color:rgba(201,168,76,0.7);"
                          x-show="getVoteCount(p.id) > 0"
                          x-text="getVoteCount(p.id) + (getVoteCount(p.id) > 1 ? ' votes' : ' vote')"></span>
                </div>
            </template>
        </div>

        {{-- Bouton voter --}}
        <button
            @click="castDayVote()"
            x-show="isAlive && !myVoteTarget"
            x-cloak
            :disabled="!dayVoteTarget || dayVoteSubmitting"
            class="w-full py-3 rounded-xl font-medieval font-semibold text-sm disabled:opacity-40 transition-all"
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
            <div class="p-3 overflow-y-auto flex flex-col gap-2" style="height:320px;" x-ref="chatMessages">
                <template x-for="(msg, i) in chatMessages" :key="i">
                    <div class="chat-bubble text-xs flex flex-col"
                         :class="msg.pseudo === MY_PSEUDO ? 'chat-own ml-auto' : ''">
                        <span class="font-semibold mb-0.5" style="color:#c9a84c;" x-text="msg.pseudo"></span>
                        <span style="color:rgba(232,224,208,0.8);" x-text="msg.message"></span>
                    </div>
                </template>
                <p x-show="chatMessages.length === 0"
                   class="text-xs italic text-center m-auto"
                   style="color:rgba(232,224,208,0.25);">
                    Le débat n'a pas encore commencé...
                </p>
            </div>
            <div style="border-top:1px solid rgba(201,168,76,0.08);">
                <div class="flex items-center">
                    <input
                        type="text"
                        x-model="chatInput"
                        :disabled="!isAlive || chatSending"
                        @keydown.enter.prevent="sendChat()"
                        maxlength="200"
                        :placeholder="isAlive ? 'Votre message...' : 'Tu es mort, silence...'"
                        class="flex-1 bg-transparent px-3 py-2 text-xs outline-none disabled:opacity-40"
                        style="color:#e8e0d0;"
                    >
                    <span class="px-2 char-counter"
                          :class="chatInput.length >= 195 ? 'cc-danger' : chatInput.length >= 180 ? 'cc-warn' : ''"
                          x-show="chatInput.length >= 150"
                          x-text="200 - chatInput.length"></span>
                    <button
                        @click="sendChat()"
                        :disabled="!chatInput.trim() || !isAlive || chatSending"
                        class="px-3 text-xs font-medieval disabled:opacity-30 transition-opacity"
                        style="color:#c9a84c;"
                    >✉</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════
         MODALE SUCCESSION DU MAIRE
    ════════════════════════════════════════════════════ --}}
    <div
        x-show="successionOpen"
        x-transition.opacity
        class="fixed inset-0 flex items-center justify-center z-50 px-4"
        style="background-color: rgba(10,15,30,0.85);"
    >
        <div class="succession-modal w-full max-w-sm p-6" x-ref="successionModal">

            <div class="text-center mb-5">
                <p class="font-medieval text-xl font-bold mb-1" style="color: #c9a84c;">👑 Succession du Maire</p>
                <p class="text-sm opacity-60" x-text="'Ancien maire : ' + dyingMayorPseudo"></p>
            </div>

            <div class="mb-5" x-show="!isDyingMayor">
                <div class="flex justify-between text-xs mb-1 opacity-50">
                    <span>Temps restant</span>
                    <span x-text="timerSeconds + 's'" :style="timerSeconds <= 5 ? 'color:#ef4444' : 'color:#c9a84c'"></span>
                </div>
                <div class="timer-bar">
                    <div class="timer-fill" id="succession-timer-fill"
                         :style="timerSeconds <= 5 ? 'background-color:#ef4444' : (timerSeconds <= 10 ? 'background-color:#f97316' : 'background-color:#c9a84c')">
                    </div>
                </div>
            </div>

            <div x-show="isDyingMayor">
                <p class="text-sm mb-3 opacity-70">Désigne ton successeur avant expiration du timer :</p>

                <div class="flex flex-col gap-2 mb-5" style="max-height:240px;overflow-y:auto;">
                    @foreach($players as $p)
                    <button
                        type="button"
                        class="succession-player-btn text-left"
                        :class="successionTarget === {{ $p->id }} ? 'selected' : ''"
                        @click="successionTarget = {{ $p->id }}"
                    >
                        @php
                        $successionColors = ['#c9a84c','#a78bfa','#4ade80','#ff4444','#38bdf8','#fb923c','#f472b6','#34d399'];
                        $successionColor  = $successionColors[$loop->index % count($successionColors)];
                        @endphp
                        <div class="avatar"
                             style="width:2rem;height:2rem;font-size:0.75rem;background-color:{{ $successionColor }};color:#0a0f1e;border:none;font-weight:700;">
                            {{ strtoupper(substr($p->pseudo, 0, 1)) }}
                        </div>
                        <span class="text-sm" style="color:#e8e0d0;">{{ $p->pseudo }}</span>
                        <span x-show="successionTarget === {{ $p->id }}" class="ml-auto text-xs" style="color:#c9a84c;">✓</span>
                    </button>
                    @endforeach
                </div>

                <button
                    @click="designateSuccessor()"
                    :disabled="!successionTarget || submitting"
                    class="w-full py-3 rounded-xl font-medieval font-semibold text-sm disabled:opacity-40 transition-all"
                    style="background-color:#c9a84c;color:#0a0f1e;"
                >
                    <span x-show="!submitting">👑 Désigner</span>
                    <span x-show="submitting">Désignation…</span>
                </button>
            </div>

            <div x-show="!isDyingMayor" class="text-center py-4">
                <div class="flex items-center justify-center gap-3 mb-4">
                    <svg class="animate-spin h-5 w-5" style="color:#c9a84c;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-sm" style="color:#e8e0d0;">
                        <span class="font-semibold" style="color:#c9a84c;" x-text="dyingMayorPseudo"></span>
                        choisit son successeur…
                    </p>
                </div>
                <div class="flex justify-between text-xs mb-1" style="color:#e8e0d0;opacity:0.5;">
                    <span>Temps restant</span>
                    <span x-text="timerSeconds + 's'" :style="timerSeconds <= 5 ? 'color:#ef4444' : ''"></span>
                </div>
                <div class="timer-bar">
                    <div class="timer-fill" id="succession-timer-fill-ro"
                         :style="timerSeconds <= 5 ? 'background-color:#ef4444' : (timerSeconds <= 10 ? 'background-color:#f97316' : 'background-color:#c9a84c')">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@php
    $playersJson = $players->map(fn ($p) => [
        'id'       => $p->id,
        'pseudo'   => $p->pseudo,
        'is_mayor' => $p->is_mayor,
        'is_alive' => $p->is_alive,
    ])->values();
@endphp

@push('scripts')
<script>
    const GAME_ID          = {{ $game->id }};
    const GAME_CODE        = '{{ $game->code }}';
    const MY_PLAYER_ID     = {{ $player->id }};
    const MY_PSEUDO        = '{{ $player->pseudo }}';
    const MY_IS_MAYOR      = {{ $player->is_mayor ? 'true' : 'false' }};
    const MY_IS_ALIVE      = {{ $player->is_alive ? 'true' : 'false' }};
    const SUCCESSION_TIMER = {{ config('game.timers.mayor_succession', 15) }};
    const PHASE_SECONDS    = {{ max(0, $game->phaseRemainingSeconds()) }};
    const PLAYERS_DATA     = @json($playersJson);

    function playerAvatarColor(id) {
        const colors = ['#c9a84c','#a78bfa','#4ade80','#ff4444','#38bdf8','#fb923c','#f472b6','#34d399'];
        return colors[id % colors.length];
    }

    function dayScreen() {
        return {
            confirmQuit:      false,
            successionOpen:   false,
            dyingMayorPseudo: '',
            isDyingMayor:     false,
            timerSeconds:     SUCCESSION_TIMER,
            successionTarget: null,
            submitting:       false,
            _timerInterval:   null,
            _timerTween:      null,

            isAlive:         MY_IS_ALIVE,
            showDeathBanner: false,

            players:            PLAYERS_DATA,
            voteWeights:        {},
            totalVoteWeight:    0,
            dayTimerSeconds:    PHASE_SECONDS,
            dayVoteTarget:      null,
            myVoteTarget:       null,
            myVoteTargetPseudo: '',
            dayVoteSubmitting:  false,
            noEliminationMessage: '',

            chatMessages: [],
            chatInput:    '',
            chatSending:  false,

            init() {
                gsap.fromTo('.reveal',
                    { opacity: 0, y: 40 },
                    { opacity: 1, y: 0, duration: .7, ease: 'power3.out', stagger: .12, delay: 0.2 }
                );
                window.Echo.channel(`game.${GAME_ID}`)
                    .listen('.mayor.succession.started', (data) => { this.openSuccessionModal(data); })
                    .listen('.mayor.succession.done', () => { this.closeSuccessionModal(); })
                    .listen('.game.finished', (data) => {
                        setTimeout(() => {
                            window.location.href = data.winner_team !== null
                                ? `/game/${GAME_CODE}/finished`
                                : `/game/${GAME_CODE}/cancelled`;
                        }, 2000);
                    })
                    .listen('.player.eliminated', (data) => {
                        if (data.player_id === MY_PLAYER_ID) {
                            this.isAlive         = false;
                            this.showDeathBanner = true;
                            gsap.to('.game-screen', { filter: 'grayscale(30%)', duration: 1 });
                        }
                        const p = this.players.find(p => p.id === data.player_id);
                        if (p) p.is_alive = false;
                    })
                    .listen('.day.vote.cast', (data) => { this._updateVoteBars(data.votes ?? []); })
                    .listen('.no.elimination', (data) => {
                        this.noEliminationMessage = data.reason === 'equality'
                            ? 'Égalité — personne n\'est éliminé ce jour.'
                            : 'Aucun vote exprimé — personne n\'est éliminé.';
                    })
                    .listen('.random.elimination', (data) => {
                        const p = this.players.find(p => p.id === data.player_id);
                        if (p) p.is_alive = false;
                        if (data.player_id === MY_PLAYER_ID) {
                            this.isAlive         = false;
                            this.showDeathBanner = true;
                        }
                        this.noEliminationMessage = `☠️ Le destin a frappé ! ${data.pseudo} a été foudroyé par les dieux du village !`;
                    })
                    .listen('.chat.message.sent', (data) => {
                        if (data.channel === 'general') {
                            this.chatMessages.push(data);
                            this.$nextTick(() => {
                                const el = this.$refs.chatMessages;
                                if (el) el.scrollTop = el.scrollHeight;
                            });
                        }
                    });

                this._startDayTimer();
            },

            _startDayTimer() {
                if (PHASE_SECONDS <= 0) return;
                if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    const el = document.getElementById('day-vote-timer');
                    if (el) gsap.to(el, { width: '0%', duration: PHASE_SECONDS, ease: 'none' });
                }
                const iv = setInterval(() => {
                    this.dayTimerSeconds = Math.max(0, this.dayTimerSeconds - 1);
                    if (this.dayTimerSeconds <= 0) clearInterval(iv);
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
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
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
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ message: msg, channel: 'general' }),
                    });
                } catch { }
                finally { this.chatSending = false; }
            },

            openSuccessionModal(data) {
                this.dyingMayorPseudo = data.dying_mayor_pseudo;
                this.timerSeconds     = data.timer;
                this.isDyingMayor     = MY_IS_MAYOR && !MY_IS_ALIVE;
                this.successionTarget = null;
                this.submitting       = false;
                this.successionOpen   = true;
                this.$nextTick(() => {
                    gsap.from(this.$refs.successionModal, { opacity: 0, y: 30, duration: 0.4, ease: 'power2.out' });
                });
                if (data.timer > 0) { this._startCountdown(data.timer); }
            },

            closeSuccessionModal() {
                clearInterval(this._timerInterval);
                if (this._timerTween) this._timerTween.kill();
                this.successionOpen = false;
            },

            _startCountdown(seconds) {
                clearInterval(this._timerInterval);
                ['succession-timer-fill', 'succession-timer-fill-ro'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) { gsap.killTweensOf(el); gsap.to(el, { width: '0%', duration: seconds, ease: 'none' }); }
                });
                this._timerInterval = setInterval(() => {
                    this.timerSeconds = Math.max(0, this.timerSeconds - 1);
                    if (this.timerSeconds <= 0) { clearInterval(this._timerInterval); }
                }, 1000);
            },

            async designateSuccessor() {
                if (!this.successionTarget || this.submitting) return;
                this.submitting = true;
                try {
                    const res = await fetch(`/game/${GAME_ID}/mayor/succession`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ target_player_id: this.successionTarget }),
                    });
                    const json = await res.json();
                    if (json.success) { this.closeSuccessionModal(); }
                } catch { }
                finally { this.submitting = false; }
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
