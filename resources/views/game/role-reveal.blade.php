@extends('layouts.game')

@section('title', 'Loup-Garou Undu — Ton rôle')

@push('styles')
<style>
    [x-cloak] { display: none !important; }
    #rr-title  { opacity: 0; }
    #rr-timer  { opacity: 0; }
    #card-wrap { perspective: 1200px; opacity: 0; }
    body { background: radial-gradient(ellipse at 50% 20%, #1a0a2e 0%, #030712 75%); font-family: 'Crimson Text', serif; }

    #role-card {
        width: 240px; height: 340px;
        border-radius: 1.25rem;
        position: relative; cursor: pointer; transform-origin: center;
    }
    .card-face {
        position: absolute; inset: 0; border-radius: 1.25rem;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        gap: 1rem; padding: 1.5rem; backface-visibility: hidden;
    }
    .card-back {
        background: linear-gradient(135deg, #111827 0%, #1a2340 100%);
        border: 2px solid rgba(201,168,76,0.4);
        box-shadow: 0 0 40px rgba(201,168,76,0.08);
    }
    .card-back .question-mark {
        font-family: 'Cinzel', serif; font-size: 5rem; font-weight: 700;
        color: rgba(201,168,76,0.3); line-height: 1;
    }
    .card-front { display: none; border: 2px solid; }
    .card-front.villager {
        background: linear-gradient(135deg, #1a2340 0%, #111827 100%);
        border-color: rgba(232,224,208,0.4);
        box-shadow: 0 0 40px rgba(232,224,208,0.06);
    }
    .card-front.werewolf {
        background: linear-gradient(135deg, #1a0a0a 0%, #2a0d0d 100%);
        border-color: rgba(139,0,0,0.6);
        box-shadow: 0 0 40px rgba(139,0,0,0.2);
    }
    .card-front.seer {
        background: linear-gradient(135deg, #130a20 0%, #1e0f35 100%);
        border-color: rgba(124,58,237,0.5);
        box-shadow: 0 0 40px rgba(124,58,237,0.15);
    }
    .role-icon { font-size: 4rem; line-height: 1; }
    .role-name { font-family: 'Cinzel', serif; font-size: 1.35rem; font-weight: 700; text-align: center; }
    .role-desc { font-family: 'Crimson Text', serif; font-size: 0.85rem; text-align: center; opacity: 0.65; line-height: 1.4; }
    .ally-chip {
        display: inline-flex; align-items: center; gap: 0.4rem;
        padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.8rem;
        background-color: rgba(139,0,0,0.2); border: 1px solid rgba(139,0,0,0.4); color: #fca5a5;
    }
    .btn-primary {
        background-color: #c9a84c;
        color: #0a0f1e;
        transition: background-color .3s, transform .2s;
    }
    .btn-primary:hover { background-color: #e0c068; transform: translateY(-2px); }
    .reveal { opacity: 0; }
</style>
@endpush

@section('header-phase')
    <span class="text-xs font-medieval tracking-widest" style="color: rgba(201,168,76,0.7);">RÉVÉLATION DU RÔLE</span>
@endsection

@section('content')
@include('partials.game.quit-button')
@include('partials.game.quit-modal')
<div
    class="flex flex-col items-center justify-center px-4 py-10 min-h-screen"
    style="color: #e8e0d0;"
    x-data="roleReveal()"
    x-init="init()"
>
    {{-- Titre --}}
    <div id="rr-title" class="text-center mb-8">
        <p class="text-sm mb-1" style="color: #e8e0d0; opacity: 0.45; letter-spacing: 0.1em;">TON RÔLE DANS CETTE PARTIE</p>
        <h1 class="font-medieval text-2xl font-bold" style="color: #c9a84c;">
            <span x-show="!revealed">Retournement dans <span x-text="countdown"></span>s…</span>
            <span x-show="revealed" x-text="roleName"></span>
        </h1>
    </div>

    {{-- Timer 60s + compteur joueurs prêts --}}
    <div id="rr-timer" class="w-full max-w-xs mb-6">
        <div class="flex justify-between text-xs mb-2" style="color: #e8e0d0; opacity: 0.5;">
            <span x-text="'Démarrage dans ' + gameTimer + 's'"></span>
            <span x-text="nbReady + '/' + total + ' prêts'"></span>
        </div>
        <div style="background-color: rgba(201,168,76,0.12); border-radius: 9999px; height: 5px; overflow: hidden;">
            <div
                id="game-timer-fill"
                :style="'background-color:' + (gameTimer <= 5 ? '#8b0000' : gameTimer <= 10 ? '#f97316' : '#c9a84c')"
                style="height: 100%; width: 100%; border-radius: 9999px; background-color: #c9a84c;"
            ></div>
        </div>
        <p x-show="nbReady === total && total > 0" class="text-center text-xs mt-2 font-medieval" style="color: #c9a84c;">
            L'élection du Maire commence !
        </p>
    </div>

    {{-- Grand titre atmosphérique --}}
    <h1 class="reveal font-title text-4xl sm:text-5xl text-center mb-3"
        style="color:#c9a84c;">
        Votre rôle est scellé...
    </h1>
    <p class="reveal text-center italic mb-10"
       style="color:rgba(232,224,208,0.7); font-family:'Crimson Text',serif;">
        Cliquez sur la carte pour la révéler.
    </p>

    {{-- Carte --}}
    <div id="card-wrap" class="mb-8 flex flex-col items-center">
        <div id="role-card" @click="flipCard()">
            <div class="card-face card-back" id="card-back">
                <div class="question-mark">?</div>
                <p class="text-xs text-center" style="color: rgba(201,168,76,0.4); font-family: 'Cinzel', serif; letter-spacing: 0.1em;">
                    CLIQUE POUR RÉVÉLER
                </p>
            </div>
            <div class="card-face card-front" :class="role" id="card-front">
                <div class="role-icon" x-show="role === 'werewolf'">🐺</div>
                <div class="role-name" x-show="role === 'werewolf'" style="color: #fca5a5;">Loup-Garou</div>
                <div class="role-desc" x-show="role === 'werewolf'" style="color: #fca5a5;">
                    Chaque nuit, les loups choisissent une victime. Restez discrets le jour.
                </div>
                <div class="role-icon" x-show="role === 'seer'">👁️</div>
                <div class="role-name" x-show="role === 'seer'" style="color: #c4b5fd;">Voyante</div>
                <div class="role-desc" x-show="role === 'seer'" style="color: #c4b5fd;">
                    Chaque nuit, tu découvres la vraie nature d'un joueur de ton choix.
                </div>
                <div class="role-icon" x-show="role !== 'werewolf' && role !== 'seer'">🏘️</div>
                <div class="role-name" x-show="role !== 'werewolf' && role !== 'seer'" style="color: #e8e0d0;">Villageois</div>
                <div class="role-desc" x-show="role !== 'werewolf' && role !== 'seer'" style="color: #e8e0d0;">
                    Identifie et élimine les loups-garous avant qu'ils ne vous déciment.
                </div>
            </div>
        </div>

        <div x-show="revealed && role === 'werewolf' && allies.length > 0" x-transition class="mt-5 text-center">
            <p class="text-xs mb-2" style="color: #e8e0d0; opacity: 0.4; letter-spacing: 0.05em;">TES ALLIÉS LOUPS</p>
            <div class="flex flex-wrap gap-2 justify-center">
                <template x-for="ally in allies" :key="ally.id">
                    <span class="ally-chip">🐺 <span x-text="ally.pseudo"></span></span>
                </template>
            </div>
        </div>
    </div>

    {{-- Bouton "Entrer" (visible après reveal) --}}
    <div x-show="revealed" x-transition class="flex flex-col items-center w-full max-w-xs">
        <button
            @click="markReady()"
            :disabled="readyDone || submitting"
            class="w-full py-3 rounded-xl font-medieval font-semibold text-base transition-all disabled:opacity-50"
            style="background-color: #c9a84c; color: #0a0f1e;"
            :style="readyDone ? 'background-color: rgba(22,163,74,0.3); color: #86efac; border: 1px solid rgba(22,163,74,0.4);' : ''"
        >
            <span x-show="!readyDone && !submitting">Entrer dans la partie</span>
            <span x-show="submitting">Confirmation…</span>
            <span x-show="readyDone">Tu es prêt !</span>
        </button>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const GAME_ID    = {{ $game->id }};
    const ROLE_NAMES = { villager: 'Villageois', werewolf: 'Loup-Garou', seer: 'Voyante' };

    function roleReveal() {
        return {
            confirmQuit: false,
            revealed:   false,
            countdown:  5,
            gameTimer:  60,
            role:       '{{ $player->role }}',
            roleName:   ROLE_NAMES['{{ $player->role }}'] ?? '{{ $player->role }}',
            nbReady:    {{ $nbReady }},
            total:      {{ $total }},
            readyDone:  {{ $player->is_ready ? 'true' : 'false' }},
            submitting: false,
            allies:     @json($allies),

            init() {
                gsap.fromTo('#rr-title', { opacity: 0, y: -20 }, { opacity: 1, y: 0, duration: 0.6, ease: 'power2.out' });
                gsap.fromTo('#rr-timer', { opacity: 0 }, { opacity: 1, duration: 0.5, delay: 0.05, ease: 'power2.out' });
                gsap.fromTo('#card-wrap', { opacity: 0, y: 40 }, { opacity: 1, y: 0, duration: 0.7, delay: 0.1, ease: 'power2.out' });
                gsap.fromTo('.reveal',
                    { opacity: 0, y: 40 },
                    { opacity: 1, y: 0, duration: .7, ease: 'power3.out', stagger: .15, delay: 0.2 }
                );

                gsap.to('#game-timer-fill', { width: '0%', duration: 60, ease: 'none' });

                this._gameTick = setInterval(() => {
                    this.gameTimer--;
                    if (this.gameTimer <= 0) { clearInterval(this._gameTick); this.redirect(); }
                }, 1000);

                const tick = setInterval(() => {
                    this.countdown--;
                    if (this.countdown <= 0) { clearInterval(tick); this.flipCard(); }
                }, 1000);

                window.Echo.channel(`game.${GAME_ID}`)
                    .listen('.player.ready', (data) => { this.nbReady = data.nb_ready; })
                    .listen('.mayor.election.started', () => {
                        setTimeout(() => { this.redirect(); }, 1000);
                    });

                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') { this.syncRole(); }
                });
                setTimeout(() => { this.syncRole(); }, 500);
            },

            redirect() {
                clearInterval(this._gameTick);
                gsap.killTweensOf('#game-timer-fill');
                window.location.href = '/game/{{ $game->code }}/mayor-election';
            },

            async syncRole() {
                try {
                    const res = await fetch('/game/{{ $game->code }}/state', {
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    });
                    if (!res.ok) return;
                    const json = await res.json();
                    if (!json.success) return;
                    const data = json.data;
                    if (data.my_role && !this.role) {
                        this.role     = data.my_role;
                        this.roleName = ROLE_NAMES[this.role] ?? this.role;
                        if (Array.isArray(data.allies) && data.allies.length) { this.allies = data.allies; }
                    }
                    const targets = {
                        'night':    '/game/{{ $game->code }}/night',
                        'day':      '/game/{{ $game->code }}/day',
                        'finished': '/game/{{ $game->code }}/finished',
                    };
                    const target = targets[data.phase];
                    if (target && window.location.pathname !== target) { window.location.href = target; }
                } catch { }
            },

            flipCard() {
                if (this.revealed) return;
                gsap.to('#role-card', {
                    rotateY: 90, duration: 0.4, ease: 'power2.in',
                    onComplete: () => {
                        document.getElementById('card-back').style.display  = 'none';
                        document.getElementById('card-front').style.display = 'flex';
                        this.revealed = true;
                        gsap.fromTo('#role-card', { rotateY: -90 }, { rotateY: 0, duration: 0.4, ease: 'power2.out' });
                    },
                });
            },

            async markReady() {
                if (this.readyDone || this.submitting) return;
                this.submitting = true;
                try {
                    const res = await fetch(`/game/${GAME_ID}/ready`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    });
                    const json = await res.json();
                    if (json.success) this.readyDone = true;
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
