@extends('layouts.game')

@section('title', 'Loup-Garou Undu — Salle d\'attente')

@push('styles')
<style>
    [x-cloak] { display: none !important; }
    #wr-header, #wr-progress, #wr-players { opacity: 0; }
    body { background-color: #0a0f1e; font-family: 'EB Garamond', serif; }

    .star { position: absolute; background: #fff; border-radius: 50%; opacity: .7; animation: twinkle 4s infinite ease-in-out; }
    @keyframes twinkle { 0%,100%{opacity:.2;}50%{opacity:.9;} }

    .slot-empty {
        background-color: #0d1426;
        border: 2px dashed rgba(201,168,76,0.3);
        border-radius: 0.75rem;
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.75rem 1rem;
        opacity: 0.6;
    }
    .slot-pulse {
        width: 2.5rem; height: 2.5rem;
        border-radius: 9999px;
        background-color: rgba(201,168,76,0.08);
        flex-shrink: 0;
    }
    .player-card {
        background-color: #111827;
        border: 1px solid rgba(201,168,76,0.25);
        border-radius: 0.75rem;
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.75rem 1rem;
    }
    .avatar {
        width: 2.5rem; height: 2.5rem;
        border-radius: 9999px;
        background-color: rgba(201,168,76,0.15);
        border: 1px solid rgba(201,168,76,0.4);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Cinzel', serif; font-weight: 600;
        font-size: 0.875rem; color: #c9a84c; flex-shrink: 0;
    }
    .progress-bar-track {
        background-color: rgba(201,168,76,0.12);
        border-radius: 9999px; height: 6px; overflow: hidden;
    }
    .progress-bar-fill {
        height: 100%; background-color: #c9a84c; border-radius: 9999px;
    }
    .btn-quit {
        border: 1px solid rgba(201,168,76,0.5); color: #c9a84c;
        background: transparent; transition: background-color .3s, transform .2s;
        border-radius: 0.375rem; padding: 0.25rem 0.75rem;
        font-family: 'Cinzel', serif; font-size: 0.875rem; cursor: pointer;
    }
    .btn-quit:hover { background-color: rgba(201,168,76,.1); transform: translateY(-2px); }
    .btn-primary {
        background-color: #c9a84c; color: #0a0f1e;
        transition: background-color .3s, transform .2s; border-radius: 0.375rem;
        padding: 0.5rem 1.25rem; font-family: 'Cinzel', serif; font-weight: 600;
    }
    .btn-primary:hover { background-color: #e0c068; transform: translateY(-2px); }
</style>
@endpush

@section('header-phase')
    <span class="text-xs font-medieval tracking-widest" style="color: rgba(201,168,76,0.7);">SALLE D'ATTENTE</span>
@endsection

@section('content')
<div
    x-cloak
    class="relative min-h-screen"
    style="background-color: #0a0f1e; color: #e8e0d0;"
    x-data="waitingRoom()"
    x-init="init()"
>
    {{-- Étoiles --}}
    <div id="stars" class="fixed inset-0 pointer-events-none z-0"></div>

    {{-- Bouton quitter --}}
    <button @click="confirmQuit=true" class="btn-quit fixed top-16 right-4 z-50">Quitter</button>

    {{-- Modale quitter --}}
    <div x-show="confirmQuit" x-cloak class="fixed inset-0 z-50 flex items-center justify-center px-4" style="background:rgba(3,7,18,.85)">
        <div class="rounded-lg p-8 max-w-sm w-full text-center" style="background-color:#111827;border:1px solid rgba(201,168,76,.4)">
            <h3 class="font-medieval text-2xl mb-6" style="color:#c9a84c">Quitter la salle d'attente ?</h3>
            <div class="flex gap-4 justify-center">
                <button @click="confirmQuit=false" class="btn-quit px-5 py-2">Annuler</button>
                <a href="{{ route('home') ?? '/' }}" class="btn-primary px-5 py-2">Confirmer</a>
            </div>
        </div>
    </div>

    <main class="relative z-10 w-full max-w-2xl mx-auto px-4 min-h-screen flex flex-col justify-center py-10">

        {{-- Code de partie --}}
        <div id="wr-header" class="text-center mb-8">
            <h1 class="font-title text-4xl sm:text-5xl text-center mb-6"
                style="color:#c9a84c;">
                Salle d'attente
            </h1>
            <p class="text-sm mb-2" style="color: #e8e0d0; opacity: 0.5; letter-spacing: 0.1em;">CODE DE LA PARTIE</p>
            <div class="flex items-center justify-center gap-3 sm:gap-4 flex-wrap">
                <span class="font-medieval text-3xl sm:text-4xl font-bold tracking-widest" style="color: #c9a84c; letter-spacing: 0.25em;">{{ $game->code }}</span>
                <button
                    @click="copyLink()"
                    class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medieval font-semibold transition-all"
                    style="background-color: rgba(201,168,76,0.1); border: 1px solid rgba(201,168,76,0.35); color: #c9a84c;"
                    :style="copied ? 'background-color: rgba(22,163,74,0.15); border-color: rgba(22,163,74,0.5); color: #86efac;' : ''"
                >
                    <span x-show="!copied">⧉ Copier</span>
                    <span x-show="copied">✓ Copié !</span>
                </button>
            </div>
            <p class="mt-3 text-sm" style="color: #e8e0d0; opacity: 0.45;">
                Partage ce code à tes amis pour qu'ils rejoignent la partie.
            </p>
        </div>

        {{-- Barre de progression --}}
        <div id="wr-progress" class="mb-8">
            <div class="flex justify-between text-xs mb-2" style="color: #e8e0d0; opacity: 0.5;">
                <span x-text="players.length + ' joueur' + (players.length > 1 ? 's' : '')"></span>
                <span x-text="maxPlayers + ' max'"></span>
            </div>
            <div class="progress-bar-track">
                <div class="progress-bar-fill" id="progress-fill" style="width: 0%;"></div>
            </div>
            <p class="text-center mt-3 text-sm font-medieval" style="color: #c9a84c;" x-text="statusMessage"></p>
        </div>

        {{-- Bouton exclure (host uniquement) --}}
        <div class="text-center mb-4" x-show="isHost && players.length > 1">
            <button
                class="text-xs px-4 py-2 rounded-lg transition-opacity hover:opacity-75"
                style="background-color: rgba(139,0,0,0.2); border: 1px solid rgba(139,0,0,0.4); color: #fca5a5;"
                @click="openExcludeModal()"
            >
                Exclure un joueur
            </button>
        </div>

        @if($player->is_host)
        {{-- Réglages des timers (host uniquement) --}}
        <div id="wr-timers" class="mb-6 rounded-lg p-5" x-data="timerSettings()" style="background-color: #111827; border: 1px solid rgba(201,168,76,0.25);">
            <h3 class="font-medieval text-base mb-4 tracking-wide" style="color: #c9a84c;">Réglages des timers</h3>

            <template x-for="key in Object.keys(timers)" :key="key">
                <div class="mb-4">
                    <div class="flex justify-between text-sm mb-1" style="color: #e8e0d0;">
                        <span x-text="labels[key]"></span>
                        <span style="color: #c9a84c;" x-text="timers[key] + ' s'"></span>
                    </div>
                    <input
                        type="range"
                        :min="limits[key].min"
                        :max="limits[key].max"
                        step="1"
                        x-model.number="timers[key]"
                        class="w-full"
                    >
                    <div class="flex justify-between text-xs mt-1" style="color: #e8e0d0; opacity: 0.4;">
                        <span x-text="limits[key].min + ' s'"></span>
                        <span x-text="limits[key].max + ' s'"></span>
                    </div>
                </div>
            </template>

            <div class="flex items-center gap-3 mt-2">
                <button
                    @click="save()"
                    :disabled="saving"
                    class="btn-primary px-4 py-2 text-sm disabled:opacity-50"
                >
                    <span x-show="!saving">Enregistrer les timers</span>
                    <span x-show="saving">Enregistrement…</span>
                </button>
                <span x-show="saved" class="text-sm" style="color: #4ade80;">Enregistré ✓</span>
                <span x-show="error" x-text="error" class="text-sm" style="color: #fca5a5;"></span>
            </div>
        </div>

        {{-- Réglages de la composition des rôles (host uniquement) --}}
        <div id="wr-roles" class="mb-6 rounded-lg p-5" x-data="roleSettings()" style="background-color: #111827; border: 1px solid rgba(201,168,76,0.25);">
            <h3 class="font-medieval text-base mb-4 tracking-wide" style="color: #c9a84c;">Composition des rôles</h3>

            <template x-for="key in Object.keys(roles)" :key="key">
                <label class="flex items-center justify-between mb-3 cursor-pointer">
                    <span class="text-sm" style="color: #e8e0d0;" x-text="labels[key]"></span>
                    <input
                        type="checkbox"
                        :checked="roles[key] === 1"
                        @change="roles[key] = $event.target.checked ? 1 : 0"
                        class="w-5 h-5"
                    >
                </label>
            </template>

            <div class="flex items-center gap-3 mt-2">
                <button
                    @click="save()"
                    :disabled="saving"
                    class="btn-primary px-4 py-2 text-sm disabled:opacity-50"
                >
                    <span x-show="!saving">Enregistrer la composition</span>
                    <span x-show="saving">Enregistrement…</span>
                </button>
                <span x-show="saved" class="text-sm" style="color: #4ade80;">Enregistré ✓</span>
                <span x-show="error" x-text="error" class="text-sm" style="color: #fca5a5;"></span>
            </div>
        </div>
        @endif

        {{-- Liste des joueurs --}}
        <div id="wr-players" class="mb-6 flex flex-col gap-3">
            <template x-for="p in players" :key="p.id">
                <div class="player-card">
                    <div class="avatar"
                         :style="`background-color: ${playerAvatarColor(p.id)}; color: #0a0f1e; border: none; font-weight: 700;`"
                         x-text="p.pseudo.charAt(0).toUpperCase()"></div>
                    <span class="flex-1 text-base" style="color: #e8e0d0;">
                        <template x-if="p.is_host && p.id !== currentPlayerId">
                            <span>Hôte</span>
                        </template>
                        <template x-if="!(p.is_host && p.id !== currentPlayerId)">
                            <span>
                                <span x-text="p.pseudo"></span>
                                <span x-show="p.is_host && p.id === currentPlayerId" class="text-xs italic" style="color:#c9a84c;">(Hôte)</span>
                            </span>
                        </template>
                    </span>
                    <span
                        x-show="p.is_host"
                        class="text-xs font-medieval px-2 py-0.5 rounded"
                        style="background-color: rgba(201,168,76,0.15); color: #c9a84c; border: 1px solid rgba(201,168,76,0.3);"
                    >Host</span>
                    <span
                        x-show="p.is_ready"
                        class="text-xs font-semibold"
                        style="color: #4ade80;"
                    >Prêt ✓</span>
                    <span
                        x-show="!p.is_ready"
                        class="text-xs italic"
                        style="color: rgba(201,168,76,0.6);"
                    >En attente…</span>
                    <span
                        x-show="p.id === currentPlayerId"
                        class="text-xs px-2 py-0.5 rounded"
                        style="background-color: rgba(124,58,237,0.15); color: #c4b5fd; border: 1px solid rgba(124,58,237,0.3);"
                    >Toi</span>
                </div>
            </template>

            {{-- Slots vides --}}
            <template x-for="i in emptySlots" :key="'empty-' + i">
                <div class="slot-empty" :id="'slot-empty-' + i">
                    <div class="slot-pulse"></div>
                    <span class="text-sm" style="color: #e8e0d0; opacity: 0.3;">En attente d'un joueur…</span>
                </div>
            </template>
        </div>

        {{-- Bouton exclure (host uniquement) --}}
        <div class="text-center mt-4" x-show="isHost && players.length > 1">
            <button
                class="text-xs px-4 py-2 rounded-lg transition-opacity hover:opacity-75"
                style="background-color: rgba(139,0,0,0.2); border: 1px solid rgba(139,0,0,0.4); color: #fca5a5;"
                @click="openExcludeModal()"
            >
                Exclure un joueur
            </button>
        </div>

        {{-- Modale exclusion --}}
        <div
            x-show="showExcludeModal"
            x-transition.opacity
            class="fixed inset-0 flex items-center justify-center z-50"
            style="background-color: rgba(0,0,0,0.75);"
            @click.self="closeExcludeModal()"
        >
            <div class="rounded-2xl p-6 max-w-sm w-full mx-4" style="background-color: #111827; border: 1px solid rgba(139,0,0,0.5);">
                <h3 class="font-medieval text-lg font-semibold mb-5" style="color: #fca5a5;">Exclure un joueur</h3>

                <div class="mb-4">
                    <p class="text-xs mb-3" style="color: #e8e0d0; opacity: 0.5; letter-spacing: 0.05em;">CHOISIR LE JOUEUR</p>
                    <div class="flex flex-col gap-2">
                        <template x-for="p in excludablePlayers" :key="p.id">
                            <button
                                type="button"
                                @click="excludeTarget = p"
                                class="flex items-center gap-3 px-3 py-2 rounded-lg text-left transition-all"
                                :style="excludeTarget && excludeTarget.id === p.id
                                    ? 'background-color: rgba(139,0,0,0.25); border: 1px solid rgba(139,0,0,0.6);'
                                    : 'background-color: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);'"
                            >
                                <div class="avatar" style="width:2rem;height:2rem;font-size:0.75rem;"
                                     x-text="p.pseudo.charAt(0).toUpperCase()"></div>
                                <span class="text-sm" style="color: #e8e0d0;" x-text="p.pseudo"></span>
                                <span x-show="excludeTarget && excludeTarget.id === p.id"
                                      class="ml-auto text-xs" style="color: #fca5a5;">✓</span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="mb-5">
                    <label class="block text-xs mb-2" style="color: #e8e0d0; opacity: 0.5; letter-spacing: 0.05em;">MOTIF</label>
                    <textarea
                        x-model="excludeReason"
                        rows="3"
                        maxlength="500"
                        placeholder="Explique pourquoi tu exclus ce joueur…"
                        class="w-full px-3 py-2 rounded-lg text-sm resize-none outline-none"
                        style="background-color: #0a0f1e; border: 1px solid rgba(139,0,0,0.35); color: #e8e0d0;"
                    ></textarea>
                    <div class="flex justify-between mt-1">
                        <p x-show="excludeError" x-text="excludeError" class="text-xs" style="color: #fca5a5;"></p>
                        <span class="text-xs ml-auto" style="color: #e8e0d0; opacity: 0.3;"
                              x-text="excludeReason.length + '/500'"></span>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button
                        @click="closeExcludeModal()"
                        class="flex-1 py-2 rounded-lg text-sm font-medieval transition-opacity hover:opacity-75"
                        style="background-color: transparent; border: 1px solid rgba(201,168,76,0.25); color: #e8e0d0;"
                    >Annuler</button>
                    <button
                        @click="confirmExclude()"
                        :disabled="excluding || !excludeTarget || !excludeReason.trim()"
                        class="flex-1 py-2 rounded-lg text-sm font-medieval font-semibold transition-opacity disabled:opacity-40"
                        style="background-color: #8b0000; color: #fca5a5; border: 1px solid rgba(139,0,0,0.6);"
                    >
                        <span x-show="!excluding">Confirmer</span>
                        <span x-show="excluding">Exclusion…</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Overlay exclusion (joueur exclu) --}}
        <div
            x-show="isExcluded"
            x-transition.opacity
            class="fixed inset-0 flex flex-col items-center justify-center z-50 text-center px-6"
            style="background-color: rgba(10,15,30,0.95);"
        >
            <p class="font-medieval text-2xl font-bold mb-4" style="color: #8b0000;">Tu as été exclu</p>
            <p class="text-sm mb-2" style="color: #e8e0d0; opacity: 0.7;">Motif :</p>
            <p class="text-base mb-8" style="color: #e8e0d0;" x-text="exclusionReason"></p>
            <p class="text-xs" style="color: #e8e0d0; opacity: 0.4;">Redirection en cours…</p>
        </div>
    </main>
</div>
@endsection

@push('scripts')
<script>
    const GAME_ID = @json($game->id);

    (function(){
        const c = document.getElementById("stars");
        if (!c) return;
        for (let i = 0; i < 60; i++) {
            const s = document.createElement("div");
            s.className = "star";
            const z = Math.random() * 2 + 1;
            s.style.width = z + "px"; s.style.height = z + "px";
            s.style.left = Math.random() * 100 + "%";
            s.style.top = Math.random() * 100 + "%";
            s.style.animationDelay = Math.random() * 4 + "s";
            c.appendChild(s);
        }
    })();

    function playerAvatarColor(id) {
        const colors = ['#c9a84c','#a78bfa','#4ade80','#ff4444','#38bdf8','#fb923c','#f472b6','#34d399'];
        return colors[id % colors.length];
    }

    function timerSettings() {
        return {
            timers: {
                mayor_election:   @json($game->settings['timers']['mayor_election'] ?? 30),
                seer:             @json($game->settings['timers']['seer'] ?? 30),
                werewolves:       @json($game->settings['timers']['werewolves'] ?? 30),
                mayor_succession: @json($game->settings['timers']['mayor_succession'] ?? 15),
                day_vote:         @json($game->settings['timers']['day_vote'] ?? 90),
            },
            limits: {
                mayor_election:   { min: 20, max: 60 },
                seer:             { min: 15, max: 60 },
                werewolves:       { min: 15, max: 60 },
                mayor_succession: { min: 10, max: 30 },
                day_vote:         { min: 60, max: 180 },
            },
            labels: {
                mayor_election:   'Élection du Maire',
                seer:             'Tour de la Voyante',
                werewolves:       'Tour des Loups-Garous',
                mayor_succession: 'Succession du Maire',
                day_vote:         'Débat et vote du jour',
            },

            saving: false,
            saved:  false,
            error:  '',

            async save() {
                this.saving = true;
                this.saved  = false;
                this.error  = '';
                try {
                    const res = await fetch(`/game/${GAME_ID}/settings/timers`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ timers: this.timers }),
                    });
                    const json = await res.json();
                    if (json.success) {
                        this.saved = true;
                        setTimeout(() => { this.saved = false; }, 1500);
                    } else {
                        this.error = json.message || 'Une erreur est survenue.';
                    }
                } catch { this.error = 'Impossible de contacter le serveur.'; }
                finally { this.saving = false; }
            },
        };
    }

    function roleSettings() {
        return {
            roles: {
                witch:  @json($game->settings['roles']['witch'] ?? 0),
                hunter: @json($game->settings['roles']['hunter'] ?? 0),
            },
            labels: {
                witch:  'Sorcière',
                hunter: 'Chasseur',
            },

            saving: false,
            saved:  false,
            error:  '',

            async save() {
                this.saving = true;
                this.saved  = false;
                this.error  = '';
                try {
                    const res = await fetch(`/game/${GAME_ID}/settings/roles`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ roles: this.roles }),
                    });
                    const json = await res.json();
                    if (json.success) {
                        this.saved = true;
                        setTimeout(() => { this.saved = false; }, 1500);
                    } else {
                        this.error = json.message || 'Une erreur est survenue.';
                    }
                } catch { this.error = 'Impossible de contacter le serveur.'; }
                finally { this.saving = false; }
            },
        };
    }

    function waitingRoom() {
        return {
            players:         @json($players->values()),
            maxPlayers:      {{ $game->max_players }},
            gameId:          {{ $game->id }},
            currentPlayerId: {{ $player->id }},
            gameCode:        '{{ $game->code }}',
            isHost:          {{ $player->is_host ? 'true' : 'false' }},

            copied:           false,
            confirmQuit:      false,
            showExcludeModal: false,
            excludeTarget:    null,
            excludeReason:    '',
            excludeError:     '',
            excluding:        false,
            isExcluded:       false,
            exclusionReason:  '',

            get emptySlots() {
                return Math.max(0, this.maxPlayers - this.players.length);
            },

            get statusMessage() {
                const remaining = this.maxPlayers - this.players.length;
                if (remaining === 0) return 'La partie commence !';
                if (remaining === 1) return 'Plus qu\'un joueur !';
                return `Plus que ${remaining} joueur${remaining > 1 ? 's' : ''} pour commencer`;
            },

            get excludablePlayers() {
                return this.players.filter(p => p.id !== this.currentPlayerId && !p.is_host);
            },

            init() {
                gsap.fromTo('#wr-header', { opacity: 0, y: 20 }, { opacity: 1, y: 0, duration: 0.6, ease: 'power2.out' });
                gsap.fromTo('#wr-progress', { opacity: 0, y: 15 }, { opacity: 1, y: 0, duration: 0.6, delay: 0.1, ease: 'power2.out' });
                gsap.fromTo('#wr-players', { opacity: 0, y: 20 }, { opacity: 1, y: 0, duration: 0.6, delay: 0.2, ease: 'power2.out' });

                this.animateProgress(this.players.length);
                this.pulseEmptySlots();

                window.Echo.channel(`game.${this.gameId}`)
                    .listen('.player.joined', (data) => {
                        this.players = data.players;
                        this.animateProgress(this.players.length);
                        this.pulseEmptySlots();
                        if (data.slots_remaining === 0) {
                            setTimeout(() => { window.location.href = `/game/${this.gameCode}/role-reveal`; }, 1500);
                        }
                    })
                    .listen('.player.excluded', (data) => {
                        this.players = this.players.filter(p => p.id !== data.excluded_player_id);
                        this.animateProgress(this.players.length);
                        this.pulseEmptySlots();
                    })
                    .listen('.game.started', () => {
                        window.location.href = `/game/${this.gameCode}/role-reveal`;
                    });

                window.Echo.private(`game.${this.gameId}.player.${this.currentPlayerId}`)
                    .listen('.player.excluded', (data) => {
                        this.isExcluded      = true;
                        this.exclusionReason = data.reason;
                        setTimeout(() => {
                            window.location.href = '/lobby?error=' + encodeURIComponent('Tu as été exclu de cette partie.');
                        }, 3000);
                    });

                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') { this.resync(); }
                });
                setTimeout(() => { this.resync(); }, 500);
            },

            async resync() {
                try {
                    const res = await fetch(`/game/${this.gameId}/lobby/state`, {
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    });
                    if (!res.ok) return;
                    const json = await res.json();
                    if (!json.success) return;
                    if (json.data.status !== 'waiting') {
                        window.location.href = `/game/${this.gameCode}/role-reveal`;
                        return;
                    }
                    this.players = json.data.players;
                    this.animateProgress(this.players.length);
                    this.pulseEmptySlots();
                    if (json.data.slots_remaining === 0) {
                        window.location.href = `/game/${this.gameCode}/role-reveal`;
                    }
                } catch { }
            },

            animateProgress(count) {
                const pct = (count / this.maxPlayers) * 100;
                gsap.to('#progress-fill', { width: pct + '%', duration: 0.5, ease: 'power2.out' });
            },

            pulseEmptySlots() {
                this.$nextTick(() => {
                    document.querySelectorAll('.slot-pulse').forEach((el) => {
                        gsap.to(el, { opacity: 0.15, duration: 1, repeat: -1, yoyo: true, ease: 'power1.inOut' });
                    });
                });
            },

            openExcludeModal() {
                this.excludeTarget = null; this.excludeReason = ''; this.excludeError = '';
                this.showExcludeModal = true;
            },

            closeExcludeModal() {
                this.showExcludeModal = false; this.excludeError = '';
            },

            async confirmExclude() {
                this.excludeError = '';
                if (!this.excludeTarget) { this.excludeError = 'Sélectionne un joueur.'; return; }
                if (!this.excludeReason.trim()) { this.excludeError = 'Le motif est obligatoire.'; return; }
                this.excluding = true;
                try {
                    const res = await fetch(`/game/${this.gameId}/exclude/${this.excludeTarget.id}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                        body: JSON.stringify({ reason: this.excludeReason }),
                    });
                    const json = await res.json();
                    if (json.success) { this.closeExcludeModal(); return; }
                    if (res.status === 422 && json.errors?.reason) {
                        this.excludeError = json.errors.reason[0];
                    } else {
                        this.excludeError = json.message || 'Une erreur est survenue.';
                    }
                } catch { this.excludeError = 'Impossible de contacter le serveur.'; }
                finally { this.excluding = false; }
            },

            async copyLink() {
                const url = `${window.location.origin}/lobby?code=${this.gameCode}`;
                try { await navigator.clipboard.writeText(url); }
                catch {
                    const el = document.createElement('textarea');
                    el.value = url; document.body.appendChild(el); el.select();
                    document.execCommand('copy'); document.body.removeChild(el);
                }
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 1500);
            },
        };
    }
</script>
@endpush
