<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loup-Garou Undu — Phase Nuit</title>
    <meta name="description" content="Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=EB+Garamond:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'EB Garamond', serif; background-color: #030712; }
        h1, h2, h3, .font-cinzel { font-family: 'Cinzel', serif; }

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
    </style>
</head>
<body class="min-h-screen" style="background-color: #030712; color: #e8e0d0;">

    <header class="flex items-center justify-between px-6 py-4 border-b" style="border-color: rgba(201,168,76,0.1);">
        <span class="font-cinzel text-xl font-bold" style="color: #c9a84c;">Loup-Garou Undu</span>
        <span class="text-sm font-cinzel" style="color: rgba(201,168,76,0.7);">
            NUIT — Round {{ $game->round }}
        </span>
    </header>

    <main
        class="max-w-2xl mx-auto px-4 py-10"
        x-data="nightScreen()"
        x-init="init()"
    >
        {{-- Placeholder tâche 29 --}}
        <div class="text-center py-20">
            <p class="font-cinzel text-2xl mb-2" style="color: rgba(201,168,76,0.7);">Phase Nuit</p>
            <p class="text-sm opacity-40">Interface complète disponible en tâche 29</p>
        </div>

        {{-- ═══════════════════════════════════════════════════
             MODALE SUCCESSION DU MAIRE
             Même structure que day.blade.php
        ════════════════════════════════════════════════════ --}}
        <div
            x-show="successionOpen"
            x-transition.opacity
            class="fixed inset-0 flex items-center justify-center z-50 px-4"
            style="background-color: rgba(3,7,18,0.9);"
        >
            <div class="succession-modal w-full max-w-sm p-6" x-ref="successionModal">

                <div class="text-center mb-5">
                    <p class="font-cinzel text-xl font-bold mb-1" style="color: #c9a84c;">👑 Succession du Maire</p>
                    <p class="text-sm opacity-60" x-text="'Ancien maire : ' + dyingMayorPseudo"></p>
                </div>

                {{-- Vue MAIRE MORT : désignation --}}
                <div x-show="isDyingMayor">
                    <p class="text-sm mb-3 opacity-70">Désigne ton successeur :</p>

                    <div class="flex flex-col gap-2 mb-5" style="max-height:240px;overflow-y:auto;">
                        @foreach($alivePlayers as $p)
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
                        class="w-full py-3 rounded-xl font-cinzel font-semibold text-sm disabled:opacity-40"
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
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script>
        const GAME_ID          = {{ $game->id }};
        const MY_PLAYER_ID     = {{ $player->id }};
        const MY_IS_MAYOR      = {{ $player->is_mayor ? 'true' : 'false' }};
        const MY_IS_ALIVE      = {{ $player->is_alive ? 'true' : 'false' }};
        const SUCCESSION_TIMER = {{ config('game.timers.mayor_succession', 15) }};

        function nightScreen() {
            return {
                successionOpen:   false,
                dyingMayorPseudo: '',
                isDyingMayor:     false,
                timerSeconds:     SUCCESSION_TIMER,
                successionTarget: null,
                submitting:       false,
                _timerInterval:   null,

                init() {
                    window.Echo.channel(`game.${GAME_ID}`)
                        .listen('.mayor.succession.started', (data) => {
                            this.openSuccessionModal(data);
                        })
                        .listen('.mayor.succession.done', () => {
                            this.closeSuccessionModal();
                        });
                },

                openSuccessionModal(data) {
                    this.dyingMayorPseudo = data.dying_mayor_pseudo;
                    this.timerSeconds     = data.timer;
                    this.isDyingMayor     = MY_IS_MAYOR && !MY_IS_ALIVE;
                    this.successionTarget = null;
                    this.submitting       = false;
                    this.successionOpen   = true;

                    this.$nextTick(() => {
                        gsap.from(this.$refs.successionModal, {
                            opacity: 0, y: 30, duration: 0.4, ease: 'power2.out',
                        });

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
                    if (! this.successionTarget || this.submitting) return;
                    this.submitting = true;

                    try {
                        const res = await fetch(`/game/${GAME_ID}/mayor/succession`, {
                            method:  'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept':       'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ target_player_id: this.successionTarget }),
                        });
                        const json = await res.json();
                        if (json.success) this.closeSuccessionModal();
                    } catch {
                        // silencieux
                    } finally {
                        this.submitting = false;
                    }
                },
            };
        }
    </script>
</body>
</html>
