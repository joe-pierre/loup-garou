@extends('layouts.game')

@section('title', 'Loup-Garou Undu — Phase Nuit')

@push('styles')
<style>
    [x-cloak] { display: none !important; }
    body { background-color: #030712; font-family: 'Crimson Text', serif; }

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
    .avatar {
        width: 2rem; height: 2rem; border-radius: 9999px;
        background-color: rgba(201,168,76,0.15);
        border: 1px solid rgba(201,168,76,0.4);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Cinzel', serif; font-weight: 600;
        font-size: 0.75rem; color: #c9a84c; flex-shrink: 0;
    }
    .timer-bar { height: 4px; border-radius: 9999px; background-color: rgba(201,168,76,0.15); overflow: hidden; }
    .timer-fill { height: 100%; border-radius: 9999px; transition: background-color 0.3s; }
    .mist-overlay {
        position: fixed; bottom: 0; left: 0; right: 0; height: 25vh;
        background: linear-gradient(to top, rgba(3,7,18,0.85) 0%, transparent 100%);
        pointer-events: none; z-index: 0;
    }
    .night-timer-bar { height: 3px; border-radius: 9999px; background-color: rgba(201,168,76,0.12); overflow: hidden; }
    .seer-panel {
        border: 1px solid rgba(124,58,237,0.4);
        box-shadow: 0 0 28px rgba(124,58,237,0.12);
        background: linear-gradient(135deg, #1a0a2e 0%, #0d0518 100%); border-radius: 1rem;
    }
    .wolf-panel {
        border: 1px solid rgba(139,0,0,0.5);
        box-shadow: 0 0 28px rgba(139,0,0,0.18);
        background: linear-gradient(135deg, #1a0000 0%, #0d0005 100%); border-radius: 1rem;
    }
    .target-btn {
        background-color: rgba(255,255,255,0.03);
        border: 1px solid rgba(255,255,255,0.08);
        border-radius: 0.5rem;
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.6rem 0.75rem;
        cursor: pointer; transition: border-color 0.15s, background-color 0.15s;
        width: 100%; text-align: left;
    }
    .target-btn.seer-sel { background-color: rgba(124,58,237,0.15); border-color: rgba(124,58,237,0.5); }
    .target-btn.wolf-sel { background-color: rgba(139,0,0,0.15); border-color: rgba(139,0,0,0.5); }
    .seer-result-card { border-radius: 0.75rem; padding: 1.25rem; text-align: center; }
    .seer-result-werewolf { background: #1f0505; border: 1px solid #ff4444; color: #e8e0d0; }
    .seer-result-innocent { background: #0a1f0a; border: 1px solid #4ade80; color: #e8e0d0; }
    .wolf-chat-bubble {
        background: #2d0a0a; border: 1px solid rgba(139,0,0,0.25);
        border-radius: 0.75rem; padding: 0.45rem 0.7rem; max-width: 85%;
        color: #e8e0d0;
    }
    .wolf-chat-bubble.wolf-own {
        background: #3d1212; border-color: rgba(255,68,68,0.45); align-self: flex-end;
    }
    .wolf-chat-input::placeholder { color: rgba(232,224,208,0.5); }
    @media (prefers-reduced-motion: reduce) {
        .mist-overlay { display: none; }
        * { animation: none !important; transition-duration: 0.01ms !important; }
    }
</style>
@endpush

@section('header-phase')
    <span class="text-sm font-medieval" style="color: rgba(201,168,76,0.7);">🌙 NUIT — Round {{ $game->round }}</span>
@endsection

@section('content')
<div
    class="game-screen min-h-screen"
    x-data="nightScreen()"
    x-init="init()"
>
    {{-- ══ Bandeau mort ══ --}}
    <div
        x-show="showDeathBanner"
        x-transition.opacity
        class="fixed top-16 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 px-4 py-3 rounded-xl"
        style="background-color:#1a0000;border:1px solid rgba(139,0,0,0.6);min-width:280px;"
    >
        <span class="text-2xl">💀</span>
        <div>
            <p class="font-medieval font-semibold text-sm" style="color:#ef4444;">Tu as été éliminé.</p>
            <p class="text-xs italic" style="color:rgba(232,224,208,0.5);">Tu observes la suite en silence.</p>
        </div>
    </div>

    <div class="mist-overlay" aria-hidden="true"></div>

    {{-- ── 1. ÉCRAN GÉNÉRIQUE — "Le village dort..." ── --}}
    <div x-show="!seerTurnActive && !werewolvesTurnActive">
        <div class="relative min-h-screen overflow-hidden"
             style="background-color:#030712;">

            {{-- Décors atmosphériques --}}
            @include('partials.game.moon-stars')
            @include('partials.game.quit-button')
            @include('partials.game.quit-modal')

            {{-- Contenu central --}}
            <div class="relative z-20 flex flex-col items-center justify-center min-h-screen text-center px-4">

                <h1 class="reveal font-title text-4xl sm:text-5xl mb-4"
                    style="color:#c9a84c;">
                    Le village dort...
                </h1>
                <p class="reveal italic text-lg mb-10"
                   style="color:#e8e0d0; font-family:'Crimson Text',serif;">
                    Les forces de la nuit sont à l'œuvre.
                </p>

                {{-- Joueurs vivants --}}
                <p class="font-title text-xs tracking-widest mb-4"
                   style="color:#c9a84c;">
                    JOUEURS ENCORE EN VIE
                </p>
                <div class="flex flex-wrap justify-center gap-4 mb-8">
                    @foreach($players->where('is_alive', true) as $p)
                    <div class="flex flex-col items-center gap-1">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-xs font-bold"
                             style="background:rgba(201,168,76,0.15);
                                    border:1px solid rgba(201,168,76,0.3);
                                    color:#c9a84c;">
                            {{ strtoupper(substr($p->pseudo, 0, 2)) }}
                        </div>
                        <span class="text-xs" style="color:#e8e0d0;">
                            {{ $p->pseudo }}
                        </span>
                    </div>
                    @endforeach
                </div>

                {{-- Message silence --}}
                <div class="px-6 py-3 rounded-full text-sm italic"
                     style="background:rgba(201,168,76,0.08);
                            border:1px solid rgba(201,168,76,0.2);
                            color:rgba(232,224,208,0.6);
                            font-family:'Crimson Text',serif;">
                    🔇 Le village est silencieux cette nuit...
                </div>
            </div>
        </div>
    </div>

    {{-- ── 2. ÉCRAN VOYANTE ── --}}
    <div x-show="isSeer && seerTurnActive" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="relative min-h-screen flex items-center justify-center px-4 py-10"
         style="background: linear-gradient(135deg, #1a0a2e 0%, #0d0518 100%);"
         x-ref="seerScreen">
        @include('partials.game.quit-button')
        @include('partials.game.quit-modal')
        <div class="seer-panel p-6 w-full max-w-lg">
            <p class="font-medieval text-xl font-bold mb-1 text-center" style="color:#a78bfa;">
                🔮 C'est ton tour, Voyante...
            </p>
            <p class="text-xs italic text-center mb-5" style="color:rgba(232,224,208,0.85);">
                Inspecte un joueur pour découvrir s'il est un loup.
            </p>

            {{-- Résultat inspection --}}
            <div x-show="seerResult" x-cloak class="mb-5" x-ref="seerResultCard">
                <div class="seer-result-card"
                     :class="seerResult?.isWerewolf ? 'seer-result-werewolf' : 'seer-result-innocent'">
                    <p class="text-4xl mb-2" x-text="seerResult?.isWerewolf ? '🐺' : '🌾'"></p>
                    <p class="font-medieval text-sm font-semibold mb-1"
                       :style="'color:#e8e0d0'"
                       x-text="seerResult?.pseudo"></p>
                    <p class="text-xs"
                       :style="'color:#e8e0d0'"
                       x-text="seerResult?.isWerewolf ? '🐺 Loup-Garou — méfie-toi !' : (seerResult?.role === 'seer' ? '🔮 Voyante — une alliée !' : '🪓 Villageois — innocent.')"></p>
                </div>
            </div>

            {{-- Liste cibles (avant résultat) --}}
            <div x-show="!seerResult">
                <p class="text-xs mb-3" style="color:rgba(232,224,208,0.85);">Joueurs inspectables :</p>
                <div class="flex flex-col gap-2 mb-4" style="max-height:220px;overflow-y:auto;">
                    @foreach($players->filter(fn($p) => $p->id !== $player->id)->values() as $seerTarget)
                    <button
                        type="button"
                        class="target-btn"
                        :class="seerSelectedTarget === {{ $seerTarget->id }} ? 'seer-sel' : ''"
                        @click="seerSelectedTarget = {{ $seerTarget->id }}"
                    >
                        <div class="avatar" style="background:#1a1035;border:1px solid rgba(167,139,250,0.3);color:#e8e0d0;">
                            {{ strtoupper(substr($seerTarget->pseudo, 0, 1)) }}
                        </div>
                        <span class="text-sm" style="color:#e8e0d0;">{{ $seerTarget->pseudo }}</span>
                        <span x-show="seerSelectedTarget === {{ $seerTarget->id }}" class="ml-auto text-xs" style="color:#a78bfa;">✓</span>
                    </button>
                    @endforeach
                </div>

                <button
                    @click="seerInspect()"
                    :disabled="!seerSelectedTarget || seerSubmitting"
                    class="w-full py-3 rounded-xl font-medieval font-semibold text-sm disabled:opacity-50 transition-all"
                    style="background:#a78bfa;color:#0a0f1e;"
                >
                    <span x-show="!seerSubmitting">🔍 Inspecter</span>
                    <span x-show="seerSubmitting">Inspection…</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ── 3. ÉCRAN LOUPS ── --}}
    <div x-show="isWerewolf && werewolvesTurnActive" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="relative min-h-screen px-4 py-10"
         style="background: linear-gradient(135deg, #1a0000 0%, #0d0005 100%);"
         x-ref="wolfScreen">
        @include('partials.game.quit-button')
        @include('partials.game.quit-modal')
        <div class="wolf-panel p-6 mb-5 max-w-lg mx-auto">
            <p class="font-medieval text-xl font-bold mb-1 text-center" style="color:#ff4444;">
                🐺 C'est votre tour, mes frères...
            </p>
            <p class="text-xs italic text-center mb-5" style="color:rgba(232,224,208,0.85);">
                Désignez votre victime. La décision est collective.
            </p>

            <div class="flex flex-col gap-2 mb-4" style="max-height:220px;overflow-y:auto;">
                <template x-for="target in wolfEligibleTargets" :key="target.id">
                    <button
                        type="button"
                        class="target-btn"
                        :class="wolfSelectedTarget === target.id ? 'wolf-sel' : ''"
                        @click="wolfSelectedTarget = target.id"
                    >
                        <div class="avatar" style="background:#1f0a0a;border:1px solid rgba(255,68,68,0.3);color:#e8e0d0;">
                            <span x-text="target.pseudo.charAt(0).toUpperCase()"></span>
                        </div>
                        <span class="text-sm" style="color:#e8e0d0;" x-text="target.pseudo"></span>
                        <span x-show="wolfSelectedTarget === target.id" class="ml-auto text-xs" style="color:#ef4444;">🗡</span>
                    </button>
                </template>
            </div>

            <button
                @click="wolfVote()"
                :disabled="!wolfSelectedTarget || wolfVoteSubmitting"
                class="w-full py-3 rounded-xl font-medieval font-semibold text-sm disabled:opacity-40 transition-all mb-4"
                style="background-color:#8b0000;color:#fff;"
            >
                <span x-show="!wolfVoteSubmitting">🗡 Cibler</span>
                <span x-show="wolfVoteSubmitting">Vote en cours…</span>
            </button>

            <div x-show="wolfVoteState.length > 0">
                <p class="text-xs mb-2" style="color:rgba(232,224,208,0.85);">Votes de la meute :</p>
                <div class="flex flex-col gap-1">
                    <template x-for="wolf in wolfVoteState" :key="wolf.player_id">
                        <div class="flex items-center gap-2 text-xs" style="color:rgba(232,224,208,0.85);">
                            <span x-text="wolf.pseudo"></span>
                            <span style="color:rgba(232,224,208,0.25);">→</span>
                            <span x-text="wolf.has_voted ? '✓' : '?'"
                                  :style="wolf.has_voted ? 'color:#16a34a' : 'color:rgba(232,224,208,0.25)'"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    {{-- ── 4. CHAT LOUPS ── --}}
    <div x-show="isWerewolf && werewolvesTurnActive" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="max-w-lg mx-auto mt-4">
        <p class="font-medieval text-xs mb-2 tracking-widest" style="color:rgba(232,224,208,0.85);">CANAL LOUPS</p>
        <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(139,0,0,0.3);background:#1a0505;">
            <div class="p-3 overflow-y-auto flex flex-col gap-2" style="height:150px;" x-ref="wolfChatMessages">
                <template x-for="(msg, i) in wolfMessages" :key="i">
                    <div class="wolf-chat-bubble text-xs flex flex-col"
                         :class="msg.pseudo === MY_PSEUDO ? 'wolf-own ml-auto' : ''">
                        <span class="font-semibold mb-0.5" style="color:#8b0000;" x-text="msg.pseudo"></span>
                        <span style="color:rgba(232,224,208,0.8);" x-text="msg.message"></span>
                    </div>
                </template>
                <p x-show="wolfMessages.length === 0"
                   class="text-xs italic text-center m-auto"
                   style="color:rgba(232,224,208,0.25);">
                    Les loups se taisent pour l'instant...
                </p>
            </div>
            <div class="flex" style="border-top:1px solid rgba(139,0,0,0.2);">
                <input
                    type="text"
                    x-model="wolfChatInput"
                    :disabled="!isAlive || wolfChatSending"
                    @keydown.enter.prevent="sendWolfChat()"
                    maxlength="200"
                    placeholder="Message aux loups..."
                    class="flex-1 wolf-chat-input px-3 py-2 text-xs outline-none disabled:opacity-40"
                    style="background:#1a0505; border:1px solid rgba(255,68,68,0.3); color:#e8e0d0;"
                >
                <button
                    @click="sendWolfChat()"
                    :disabled="!wolfChatInput.trim() || !isAlive || wolfChatSending"
                    class="px-3 text-xs font-medieval disabled:opacity-30 transition-opacity"
                    style="color:#8b0000;"
                >✉</button>
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
        style="background-color: rgba(3,7,18,0.9);"
    >
        <div class="succession-modal w-full max-w-sm p-6" x-ref="successionModal">

            <div class="text-center mb-5">
                <p class="font-medieval text-xl font-bold mb-1" style="color: #c9a84c;">👑 Succession du Maire</p>
                <p class="text-sm opacity-60" x-text="'Ancien maire : ' + dyingMayorPseudo"></p>
            </div>

            {{-- Vue MAIRE MORT : désignation --}}
            <div x-show="isDyingMayor">
                <p class="text-sm mb-3 opacity-70">Désigne ton successeur :</p>

                <div class="flex flex-col gap-2 mb-5" style="max-height:240px;overflow-y:auto;">
                    @foreach($players as $p)
                    <button
                        type="button"
                        class="succession-player-btn text-left"
                        :class="successionTarget === {{ $p->id }} ? 'selected' : ''"
                        @click="successionTarget = {{ $p->id }}"
                    >
                        <div class="avatar">{{ strtoupper(substr($p->pseudo, 0, 1)) }}</div>
                        <span class="text-sm" style="color:#e8e0d0;">{{ $p->pseudo }}</span>
                        <span x-show="successionTarget === {{ $p->id }}" class="ml-auto text-xs" style="color:#c9a84c;">✓</span>
                    </button>
                    @endforeach
                </div>

                <button
                    @click="designateSuccessor()"
                    :disabled="!successionTarget || submitting"
                    class="w-full py-3 rounded-xl font-medieval font-semibold text-sm disabled:opacity-40"
                    style="background-color:#c9a84c;color:#0a0f1e;"
                >
                    <span x-show="!submitting">👑 Désigner</span>
                    <span x-show="submitting">Désignation…</span>
                </button>
            </div>

            {{-- Vue AUTRES JOUEURS : lecture seule --}}
            <div x-show="!isDyingMayor" class="text-center py-4">
                <div class="flex items-center justify-center gap-3 mb-4">
                    <svg class="animate-spin h-5 w-5" style="color:#c9a84c;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <p class="text-sm">
                        <span class="font-semibold" style="color:#c9a84c;" x-text="dyingMayorPseudo"></span>
                        choisit son successeur…
                    </p>
                </div>
                <div class="flex justify-between text-xs mb-1 opacity-50">
                    <span>Temps restant</span>
                    <span x-text="timerSeconds + 's'" :style="timerSeconds <= 5 ? 'color:#ef4444' : ''"></span>
                </div>
                <div class="timer-bar">
                    <div class="timer-fill" id="night-succession-timer"
                         :style="timerSeconds <= 5 ? 'background-color:#ef4444' : (timerSeconds <= 10 ? 'background-color:#f97316' : 'background-color:#c9a84c')">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@include('partials.game.stars-init')

<script>
gsap.fromTo('.reveal',
    { opacity: 0, y: 40 },
    { opacity: 1, y: 0, duration: .9, ease: 'power3.out', stagger: .2, delay: 0.3 }
);
</script>

<script>
    const GAME_ID          = {{ $game->id }};
    const GAME_CODE        = '{{ $game->code }}';
    const MY_PLAYER_ID     = {{ $player->id }};
    const MY_PSEUDO        = '{{ $player->pseudo }}';
    const MY_ROLE          = '{{ $player->role }}';
    const MY_IS_MAYOR      = {{ $player->is_mayor ? 'true' : 'false' }};
    const MY_IS_ALIVE      = {{ $player->is_alive ? 'true' : 'false' }};
    const SUCCESSION_TIMER = {{ config('game.timers.mayor_succession', 15) }};
    const PHASE_SECONDS    = {{ max(0, $game->phaseRemainingSeconds()) }};

    function nightScreen() {
        return {
            confirmQuit:      false,

            successionOpen:   false,
            dyingMayorPseudo: '',
            isDyingMayor:     false,
            timerSeconds:     SUCCESSION_TIMER,
            successionTarget: null,
            submitting:       false,
            _timerInterval:   null,

            isAlive:         MY_IS_ALIVE,
            showDeathBanner: false,

            isWerewolf:           MY_ROLE === 'werewolf',
            isSeer:               MY_ROLE === 'seer',
            seerTurnActive:       false,
            werewolvesTurnActive: false,
            nightTimerSeconds:    PHASE_SECONDS,

            seerSelectedTarget: null,
            seerResult:         null,
            seerSubmitting:     false,

            wolfEligibleTargets: [],
            wolfSelectedTarget:  null,
            wolfVoteState:       [],
            wolfVoteSubmitting:  false,

            wolfMessages:    [],
            wolfChatInput:   '',
            wolfChatSending: false,

            init() {
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
                    });

                window.Echo.channel(`game.${GAME_ID}`)
                    .listen('.day.started', () => {
                        setTimeout(() => {
                            window.location.href = `/game/${GAME_CODE}/day`;
                        }, 1500);
                    });

                if (this.isSeer) {
                    window.Echo.private(`game.${GAME_ID}.player.${MY_PLAYER_ID}`)
                        .listen('.seer.turn.started', () => {
                            this.seerTurnActive = true;
                            this.$nextTick(() => this._animateSeerEntry());
                        })
                        .listen('.seer.result', (data) => {
                            this.seerResult = { pseudo: data.pseudo, role: data.role, isWerewolf: data.role === 'werewolf' };
                            this.$nextTick(() => this._revealSeerResult(this.seerResult.isWerewolf));
                        });
                }

                if (this.isWerewolf) {
                    window.Echo.private(`game.${GAME_ID}.werewolves`)
                        .listen('.werewolves.turn.started', (data) => {
                            this.werewolvesTurnActive = true;
                            this.wolfEligibleTargets  = data.eligible_targets ?? [];
                            this.$nextTick(() => this._animateWolfEntry());
                        })
                        .listen('.werewolves.vote.cast', (data) => { this.wolfVoteState = data.wolves ?? []; })
                        .listen('.werewolf.chat.message', (data) => {
                            this.wolfMessages.push(data);
                            this.$nextTick(() => {
                                const el = this.$refs.wolfChatMessages;
                                if (el) el.scrollTop = el.scrollHeight;
                            });
                        });
                }

                this._startNightTimer();
                this.$nextTick(() => {
                    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        const moon = this.$refs.moon;
                        if (moon) gsap.from(moon, { y: 40, opacity: 0, duration: 2.5, ease: 'power2.out' });
                    }
                });
            },

            _startNightTimer() {
                if (PHASE_SECONDS <= 0) return;
                if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    const el = document.getElementById('night-phase-timer');
                    if (el) gsap.to(el, { width: '0%', duration: PHASE_SECONDS, ease: 'none' });
                }
                const iv = setInterval(() => {
                    this.nightTimerSeconds = Math.max(0, this.nightTimerSeconds - 1);
                    if (this.nightTimerSeconds <= 0) clearInterval(iv);
                }, 1000);
            },

            _animateSeerEntry() {
                // Animation supprimée — Alpine gère l'affichage via x-show
            },

            _revealSeerResult(isWerewolf) {
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                const card = this.$refs.seerResultCard;
                if (!card) return;
                gsap.to(card, {
                    rotateY: 90, duration: 0.35, ease: 'power2.in',
                    onComplete: () => { gsap.fromTo(card, { rotateY: -90 }, { rotateY: 0, duration: 0.35, ease: 'power2.out' }); },
                });
            },

            _animateWolfEntry() {
                // Animation supprimée — Alpine gère l'affichage via x-show
            },

            async seerInspect() {
                if (!this.seerSelectedTarget || this.seerSubmitting) return;
                this.seerSubmitting = true;
                try {
                    await fetch(`/game/${GAME_ID}/seer/check`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ target_player_id: this.seerSelectedTarget }),
                    });
                } catch { }
                finally { this.seerSubmitting = false; }
            },

            async wolfVote() {
                if (!this.wolfSelectedTarget || this.wolfVoteSubmitting) return;
                this.wolfVoteSubmitting = true;
                try {
                    await fetch(`/game/${GAME_ID}/vote/night`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ target_player_id: this.wolfSelectedTarget }),
                    });
                } catch { }
                finally { this.wolfVoteSubmitting = false; }
            },

            async sendWolfChat() {
                const msg = this.wolfChatInput.trim();
                if (!msg || !this.isAlive || this.wolfChatSending) return;
                this.wolfChatInput   = '';
                this.wolfChatSending = true;
                try {
                    await fetch(`/game/${GAME_ID}/chat`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ message: msg, channel: 'werewolves' }),
                    });
                } catch { }
                finally { this.wolfChatSending = false; }
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

                    if (data.timer > 0) {
                        const el = document.getElementById('night-succession-timer');
                        if (el) gsap.to(el, { width: '0%', duration: data.timer, ease: 'none' });

                        this._timerInterval = setInterval(() => {
                            this.timerSeconds = Math.max(0, this.timerSeconds - 1);
                            if (this.timerSeconds <= 0) clearInterval(this._timerInterval);
                        }, 1000);
                    }
                });
            },

            closeSuccessionModal() {
                clearInterval(this._timerInterval);
                this.successionOpen = false;
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
                    if (json.success) this.closeSuccessionModal();
                } catch { }
                finally { this.submitting = false; }
            },
        };
    }
</script>
@endpush
