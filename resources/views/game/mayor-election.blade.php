@extends('layouts.game')

@section('title', 'Loup-Garou Undu — Élection du Maire')

@push('styles')
<style>
    [x-cloak] { display: none !important; }
    #me-header, #me-timer, #me-candidates { opacity: 0; }
    body { background-color: #0a0f1e; font-family: 'EB Garamond', serif; }

    .candidate-card {
        background-color: #111827;
        border: 1px solid rgba(201,168,76,0.2);
        border-radius: 0.875rem;
        padding: 1rem 0.75rem;
        display: flex; flex-direction: column; align-items: center; gap: 0.6rem;
        cursor: pointer; transition: border-color 0.25s, box-shadow 0.25s;
        user-select: none;
    }
    .candidate-card:not(.has-voted):hover {
        border-color: rgba(201,168,76,0.5);
        transform: translateY(-3px);
        transition: transform 0.15s ease;
    }
    .candidate-card.is-selected {
        border-color: rgba(201,168,76,0.8) !important;
        box-shadow: 0 0 18px rgba(201,168,76,0.5);
    }
    .candidate-card.is-dimmed { opacity: 0.45; cursor: default; }
    .avatar {
        width: 2.75rem; height: 2.75rem; border-radius: 9999px;
        background-color: rgba(201,168,76,0.12); border: 1px solid rgba(201,168,76,0.35);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Cinzel', serif; font-weight: 600; font-size: 0.95rem; color: #c9a84c; flex-shrink: 0;
    }
    .vote-badge {
        font-family: 'Cinzel', serif; font-size: 0.7rem;
        padding: 0.1rem 0.55rem; border-radius: 9999px;
        background-color: rgba(201,168,76,0.1); border: 1px solid rgba(201,168,76,0.25); color: #c9a84c;
    }
    .timer-track { background-color: rgba(201,168,76,0.12); border-radius: 9999px; height: 5px; overflow: hidden; }
    .btn-primary { background-color: #c9a84c; color: #0a0f1e; transition: background-color .3s, transform .2s; }
    .btn-primary:hover { background-color: #e0c068; transform: translateY(-2px); }
    .btn-primary:disabled { opacity: .4; cursor: not-allowed; transform: none; }
    @media (prefers-reduced-motion: reduce) {
        #election-timer-fill { transition: none !important; }
    }
</style>
@endpush

@section('header-phase')
    <span class="text-xs font-medieval tracking-widest" style="color: rgba(201,168,76,0.7);">👑 ÉLECTION DU MAIRE</span>
@endsection

@php
    $myRoleConfig = match($player->role) {
        'werewolf' => ['icon' => '🐺', 'label' => 'Loup-Garou',  'color' => '#f87171'],
        'seer'     => ['icon' => '🔮', 'label' => 'Voyante',     'color' => '#a78bfa'],
        'witch'    => ['icon' => '🧙‍♀️', 'label' => 'Sorcière',   'color' => '#3493d3'],
        'hunter'   => ['icon' => '🏹', 'label' => 'Chasseur',    'color' => '#fbbf24'],
        default    => ['icon' => '🧑‍🌾', 'label' => 'Villageois', 'color' => '#e8e0d0'],
    };
@endphp

@section('content')
@include('partials.game.quit-button')
@include('partials.game.quit-modal')
<div
    x-cloak
    class="flex-1 max-w-2xl mx-auto w-full px-4 py-8 min-h-screen"
    style="background-color: #0a0f1e; color: #e8e0d0;"
    x-data="mayorElection()"
    x-init="init()"
>
    {{-- En-tête phase --}}
    <div id="me-header" class="text-center mb-6">
        <p class="text-xs mb-1" style="color: #e8e0d0; opacity: 0.4; letter-spacing: 0.12em;">PHASE 1</p>
        <h1 class="font-medieval text-2xl font-bold mb-1" style="color: #c9a84c;">Élection du Maire</h1>
        <p class="text-sm" style="color: #e8e0d0; opacity: 0.55;">
            Vote pour le joueur que tu veux voir prendre ce rôle.
            <span x-show="myVote === null"> Tu peux voter pour toi-même.</span>
            <span x-show="myVote !== null" style="color: #c9a84c;"> Vote enregistré.</span>
        </p>
    </div>

    {{-- Timer --}}
    <div id="me-timer" class="mb-7">
        <div class="flex justify-between text-xs mb-2" style="color: #e8e0d0; opacity: 0.45;">
            <span x-text="timerSeconds > 0 ? 'Résolution dans ' + timerSeconds + 's' : 'Résolution en cours…'"></span>
            <span x-text="totalVotes + ' / {{ count($players) }} vote' + (totalVotes > 1 ? 's' : '')"></span>
        </div>
        <div class="timer-track" aria-hidden="true">
            <div
                id="election-timer-fill"
                style="height: 100%; width: 100%; border-radius: 9999px; background-color: #c9a84c; transition: background-color 0.3s;"
            ></div>
        </div>
    </div>

    {{-- Grille des candidats --}}
    <div id="me-candidates" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        @foreach ($players as $candidate)
        <div
            class="candidate-card"
            :class="{
                'is-selected': myVote === {{ $candidate->id }},
                'is-dimmed':   myVote !== null && myVote !== {{ $candidate->id }},
                'has-voted':   myVote !== null,
            }"
            @click="castVote({{ $candidate->id }})"
        >
            @php
            // TODO : cette fonction est dupliquée dans plusieurs vues.
            // Source de vérité : config/game_ui.php > avatar_colors
            // À centraliser dans un helper Alpine global en v1.3+
            $avatarColors = ['#c9a84c','#a78bfa','#4ade80','#ff4444','#38bdf8','#fb923c','#f472b6','#34d399'];
            $avatarColor  = $avatarColors[$candidate->id % count($avatarColors)];
            @endphp
            <div class="avatar"
                 style="{{ $candidate->id === $player->id
                     ? 'background-color: rgba(' . implode(',', sscanf($myRoleConfig['color'], '#%02x%02x%02x')) . ', 0.15); border: 1px solid ' . $myRoleConfig['color'] . '55; font-size: 1.5rem;'
                     : 'background-color: ' . $avatarColor . '; color: #0a0f1e; border: none; font-weight: 700;' }}">
                @if ($candidate->id === $player->id)
                    <span>{{ $myRoleConfig['icon'] }}</span>
                @else
                    {{ strtoupper(mb_substr($candidate->pseudo, 0, 1)) }}
                @endif
            </div>

            <div class="text-center w-full px-1">
                <p class="text-sm font-medieval font-semibold truncate" style="color: #e8e0d0;">
                    {{ $candidate->pseudo }}
                </p>
                @if ($candidate->id === $player->id)
                    <p class="text-xs mt-0.5 font-semibold" style="color: {{ $myRoleConfig['color'] }};">
                        {{ $myRoleConfig['icon'] }} {{ $myRoleConfig['label'] }} · MOI
                    </p>
                @endif
            </div>

            <div
                class="vote-badge"
                x-text="voteCount({{ $candidate->id }}) + ' vote' + (voteCount({{ $candidate->id }}) !== 1 ? 's' : '')"
            ></div>

            <div x-show="myVote === {{ $candidate->id }}" class="text-xs font-medieval" style="color: #c9a84c;">
                ✓ Ton vote
            </div>
        </div>
        @endforeach
    </div>

    <p x-show="myVote !== null" x-cloak
       class="mt-6 text-center font-title text-sm tracking-wide"
       style="color:#4ade80;">
        ✓ Vote enregistré
    </p>

    {{-- Overlay résultat --}}
    <div
        x-show="showResult"
        x-transition.opacity
        class="fixed inset-0 flex flex-col items-center justify-center z-50 text-center px-6"
        style="background-color: rgba(10,15,30,0.93);"
    >
        <div class="font-medieval text-6xl mb-6" style="filter: drop-shadow(0 0 24px rgba(201,168,76,0.5));">👑</div>
        <p class="font-medieval text-3xl font-bold mb-2" style="color: #c9a84c;" x-text="electedMayor"></p>
        <p class="font-medieval text-base mb-4" style="color: #e8e0d0;">est élu Maire</p>
        <p x-show="wasRandom" class="text-sm mb-6" style="color: #e8e0d0; opacity: 0.45;">
            Désigné par tirage au sort (égalité ou aucun vote)
        </p>
        <p x-show="!wasRandom" class="text-sm mb-6" style="color: #e8e0d0; opacity: 0.45;">
            Élu à la majorité
        </p>
        <p class="text-xs" style="color: #e8e0d0; opacity: 0.3; letter-spacing: 0.08em;">LA NUIT TOMBE…</p>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function mayorElection() {
        return {
            confirmQuit:     false,

            gameId:          {{ $game->id }},
            gameCode:        '{{ $game->code }}',
            currentPlayerId: {{ $player->id }},
            phaseSeconds:    {{ max(0, $phaseRemainingSeconds) }},
            timerSeconds:    {{ max(0, $phaseRemainingSeconds) }},

            votes:      @json(collect($currentVotes)->keyBy('target_player_id')),
            myVote:     {{ $myVote ?? 'null' }},
            submitting: false,

            electedMayor: null,
            wasRandom:    false,
            showResult:   false,

            get totalVotes() {
                return Object.values(this.votes).reduce((sum, v) => sum + (v.vote_count ?? 0), 0);
            },

            voteCount(playerId) {
                return this.votes[playerId]?.vote_count ?? 0;
            },

            init() {
                if (this._initialized) return;
                this._initialized = true;

                gsap.fromTo('#me-header', { opacity: 0, y: -20 }, { opacity: 1, y: 0, duration: 0.6, ease: 'power2.out' });
                gsap.fromTo('#me-timer', { opacity: 0 }, { opacity: 1, duration: 0.5, delay: 0.05, ease: 'power2.out' });
                gsap.fromTo('#me-candidates', { opacity: 0, y: 20 }, { opacity: 1, y: 0, duration: 0.6, delay: 0.15, ease: 'power2.out' });

                const noMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                if (!noMotion && this.phaseSeconds > 0) {
                    gsap.to('#election-timer-fill', { width: '0%', duration: this.phaseSeconds, ease: 'none' });
                } else {
                    document.getElementById('election-timer-fill').style.width = '0%';
                }

                const tick = setInterval(() => {
                    this.timerSeconds--;
                    if (!noMotion) {
                        if (this.timerSeconds === 10) gsap.to('#election-timer-fill', { backgroundColor: '#f97316', duration: 0.3 });
                        if (this.timerSeconds === 5)  gsap.to('#election-timer-fill', { backgroundColor: '#8b0000', duration: 0.3 });
                    }
                    if (this.timerSeconds <= 0) clearInterval(tick);
                }, 1000);

                window.addEventListener('mayor-vote-cast', (ev) => {
                    const updated = {};
                    (ev.detail.votes ?? []).forEach(v => { updated[v.target_player_id] = v; });
                    this.votes = updated;
                });

                window.addEventListener('mayor-elected', (ev) => {
                    this.electedMayor = ev.detail.pseudo;
                    this.wasRandom    = ev.detail.was_random;
                    this.showResult   = true;
                    setTimeout(() => { sessionStorage.setItem('__internalNavigation', '1'); window.location.href = `/game/${this.gameCode}/night`; }, 6000);
                });
            },

            async castVote(targetId) {
                if (this.myVote !== null || this.submitting) return;
                this.submitting = true;
                try {
                    const res = await fetch(`/game/${this.gameId}/vote/mayor`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ target_player_id: targetId }),
                    });
                    const json = await res.json();
                    if (json.success) { this.myVote = targetId; }
                } catch { }
                finally { this.submitting = false; }
            },

            submitQuit() {
                fetch(`/game/${this.gameId}/quit`, {
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
