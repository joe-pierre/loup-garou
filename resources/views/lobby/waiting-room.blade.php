<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loup-Garou Undu — Salle d'attente</title>
    <meta name="description" content="Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=EB+Garamond:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'EB Garamond', serif; background-color: #0a0f1e; }
        h1, h2, h3, .font-cinzel { font-family: 'Cinzel', serif; }

        .slot-empty {
            border: 2px dashed rgba(201,168,76,0.3);
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
        }
        .slot-pulse {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 9999px;
            background-color: rgba(201,168,76,0.08);
        }
        .player-card {
            background-color: #111827;
            border: 1px solid rgba(201,168,76,0.25);
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
        }
        .avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 9999px;
            background-color: rgba(201,168,76,0.15);
            border: 1px solid rgba(201,168,76,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Cinzel', serif;
            font-weight: 600;
            font-size: 0.875rem;
            color: #c9a84c;
            flex-shrink: 0;
        }
        .progress-bar-track {
            background-color: rgba(201,168,76,0.12);
            border-radius: 9999px;
            height: 6px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background-color: #c9a84c;
            border-radius: 9999px;
        }
    </style>
</head>
<body class="min-h-screen" style="background-color: #0a0f1e; color: #e8e0d0;">

    {{-- Header --}}
    <header class="flex items-center justify-between px-6 py-4 border-b" style="border-color: rgba(201,168,76,0.2);">
        <a href="/" class="font-cinzel text-xl font-bold" style="color: #c9a84c;">Loup-Garou Undu</a>
        <div class="flex items-center gap-4">
            <span class="text-sm" style="color: #e8e0d0; opacity: 0.6;">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm px-3 py-1 rounded border transition-opacity hover:opacity-80"
                        style="border-color: rgba(201,168,76,0.4); color: #e8e0d0;">
                    Déconnexion
                </button>
            </form>
        </div>
    </header>

    <main
        class="max-w-2xl mx-auto px-4 py-10"
        x-data="waitingRoom()"
        x-init="init()"
    >
        {{-- Code de partie --}}
        <div id="wr-header" class="text-center mb-8">
            <p class="text-sm mb-2" style="color: #e8e0d0; opacity: 0.5; letter-spacing: 0.1em;">CODE DE LA PARTIE</p>
            <div class="flex items-center justify-center gap-4">
                <span
                    class="font-cinzel text-4xl font-bold tracking-widest"
                    style="color: #c9a84c; letter-spacing: 0.25em;"
                >{{ $game->code }}</span>
                <button
                    @click="copyLink()"
                    class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-cinzel font-semibold transition-all"
                    style="background-color: rgba(201,168,76,0.1); border: 1px solid rgba(201,168,76,0.35); color: #c9a84c;"
                    :style="copied ? 'background-color: rgba(22,163,74,0.15); border-color: rgba(22,163,74,0.5); color: #86efac;' : ''"
                >
                    <span x-show="!copied">Copier le lien</span>
                    <span x-show="copied">Lien copié !</span>
                </button>
            </div>
            <p class="mt-3 text-sm" style="color: #e8e0d0; opacity: 0.45;">
                Partage ce lien à tes amis pour qu'ils rejoignent la partie.
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
            <p class="text-center mt-3 text-sm font-cinzel" style="color: #c9a84c;" x-text="statusMessage"></p>
        </div>

        {{-- Liste des joueurs --}}
        <div id="wr-players" class="mb-6 flex flex-col gap-3">
            <template x-for="player in players" :key="player.id">
                <div class="player-card">
                    <div class="avatar" x-text="player.pseudo.charAt(0).toUpperCase()"></div>
                    <span class="flex-1 text-base" style="color: #e8e0d0;" x-text="player.pseudo"></span>
                    <span
                        x-show="player.is_host"
                        class="text-xs font-cinzel px-2 py-0.5 rounded"
                        style="background-color: rgba(201,168,76,0.15); color: #c9a84c; border: 1px solid rgba(201,168,76,0.3);"
                    >Host</span>
                    <span
                        x-show="player.id === currentPlayerId"
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

        {{-- Bouton exclure (host uniquement) — modale tâche 6 --}}
        @if($currentPlayer->is_host)
        <div class="text-center mt-4">
            <button
                class="text-xs px-4 py-2 rounded-lg transition-opacity hover:opacity-75"
                style="background-color: rgba(139,0,0,0.2); border: 1px solid rgba(139,0,0,0.4); color: #fca5a5;"
                @click="showExcludeModal = true"
                x-show="players.length > 1"
            >
                Exclure un joueur
            </button>
        </div>

        {{-- Modale exclusion (placeholder tâche 6) --}}
        <div
            x-show="showExcludeModal"
            x-transition
            class="fixed inset-0 flex items-center justify-center z-50"
            style="background-color: rgba(0,0,0,0.7);"
            @click.self="showExcludeModal = false"
        >
            <div class="rounded-2xl p-6 max-w-sm w-full mx-4" style="background-color: #111827; border: 1px solid rgba(139,0,0,0.4);">
                <h3 class="font-cinzel text-lg font-semibold mb-4" style="color: #fca5a5;">Exclure un joueur</h3>
                <p class="text-sm mb-4" style="color: #e8e0d0; opacity: 0.6;">
                    Cette fonctionnalité sera disponible dans la tâche 6.
                </p>
                <button @click="showExcludeModal = false"
                        class="w-full py-2 rounded-lg text-sm font-cinzel"
                        style="background-color: rgba(201,168,76,0.1); border: 1px solid rgba(201,168,76,0.3); color: #c9a84c;">
                    Fermer
                </button>
            </div>
        </div>
        @endif
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script>
        function waitingRoom() {
            return {
                players:         @json($players->values()),
                maxPlayers:      {{ $game->max_players }},
                currentPlayerId: {{ $currentPlayer->id }},
                gameCode:        '{{ $game->code }}',
                copied:          false,
                showExcludeModal: false,

                get emptySlots() {
                    return Math.max(0, this.maxPlayers - this.players.length);
                },

                get statusMessage() {
                    const remaining = this.maxPlayers - this.players.length;
                    if (remaining === 0) return 'La partie commence !';
                    if (remaining === 1) return 'Plus qu\'un joueur !';
                    return `Plus que ${remaining} joueur${remaining > 1 ? 's' : ''} pour commencer`;
                },

                init() {
                    // Animations GSAP initiales
                    gsap.from('#wr-header',   { opacity: 0, y: 20, duration: 0.6, ease: 'power2.out' });
                    gsap.from('#wr-progress', { opacity: 0, y: 20, duration: 0.6, delay: 0.1, ease: 'power2.out' });
                    gsap.from('#wr-players',  { opacity: 0, y: 20, duration: 0.6, delay: 0.2, ease: 'power2.out' });

                    // Barre de progression initiale
                    this.animateProgress(this.players.length);

                    // Pulsation des slots vides
                    this.pulseEmptySlots();

                    // Écoute WebSocket
                    window.Echo.channel(`game.{{ $game->id }}`)
                        .listen('.player.joined', (data) => {
                            this.players    = data.players;
                            this.animateProgress(this.players.length);
                            this.pulseEmptySlots();

                            // Redirection automatique quand la partie est complète
                            if (data.slots_remaining === 0) {
                                setTimeout(() => {
                                    window.location.href = `/game/${this.gameCode}/role-reveal`;
                                }, 1500);
                            }
                        });
                },

                animateProgress(count) {
                    const pct = (count / this.maxPlayers) * 100;
                    gsap.to('#progress-fill', {
                        width: pct + '%',
                        duration: 0.5,
                        ease: 'power2.out',
                    });
                },

                pulseEmptySlots() {
                    this.$nextTick(() => {
                        document.querySelectorAll('.slot-pulse').forEach((el) => {
                            gsap.to(el, {
                                opacity: 0.15,
                                duration: 1,
                                repeat: -1,
                                yoyo: true,
                                ease: 'power1.inOut',
                            });
                        });
                    });
                },

                async copyLink() {
                    const url = `${window.location.origin}/lobby?code=${this.gameCode}`;
                    try {
                        await navigator.clipboard.writeText(url);
                    } catch {
                        // Fallback pour les navigateurs sans clipboard API
                        const el = document.createElement('textarea');
                        el.value = url;
                        document.body.appendChild(el);
                        el.select();
                        document.execCommand('copy');
                        document.body.removeChild(el);
                    }
                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 1500);
                },
            };
        }
    </script>
</body>
</html>
