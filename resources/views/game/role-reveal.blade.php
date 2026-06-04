<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loup-Garou Undu — Ton rôle</title>
    <meta name="description" content="Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=EB+Garamond:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'EB Garamond', serif; background-color: #0a0f1e; }
        h1, h2, h3, .font-cinzel { font-family: 'Cinzel', serif; }

        #rr-title  { opacity: 0; }
        #rr-timer  { opacity: 0; }
        #card-wrap { perspective: 1200px; opacity: 0; }
        #role-card {
            width: 240px;
            height: 340px;
            border-radius: 1.25rem;
            position: relative;
            cursor: pointer;
            transform-origin: center;
        }

        .card-face {
            position: absolute;
            inset: 0;
            border-radius: 1.25rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            padding: 1.5rem;
            backface-visibility: hidden;
        }

        .card-back {
            background: linear-gradient(135deg, #111827 0%, #1a2340 100%);
            border: 2px solid rgba(201,168,76,0.4);
            box-shadow: 0 0 40px rgba(201,168,76,0.08);
        }
        .card-back .question-mark {
            font-family: 'Cinzel', serif;
            font-size: 5rem;
            font-weight: 700;
            color: rgba(201,168,76,0.3);
            line-height: 1;
        }

        .card-front {
            display: none;
            border: 2px solid;
        }
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
        .role-name {
            font-family: 'Cinzel', serif;
            font-size: 1.35rem;
            font-weight: 700;
            text-align: center;
        }
        .role-desc {
            font-family: 'EB Garamond', serif;
            font-size: 0.85rem;
            text-align: center;
            opacity: 0.65;
            line-height: 1.4;
        }

        .ally-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            background-color: rgba(139,0,0,0.2);
            border: 1px solid rgba(139,0,0,0.4);
            color: #fca5a5;
        }

        .progress-track {
            background-color: rgba(201,168,76,0.12);
            border-radius: 9999px;
            height: 4px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background-color: #c9a84c;
            border-radius: 9999px;
            transition: width 0.5s ease;
        }
    </style>
</head>
<body class="min-h-screen flex flex-col" style="background-color: #0a0f1e; color: #e8e0d0;">

    {{-- Header --}}
    <header class="flex items-center justify-between px-6 py-4 border-b" style="border-color: rgba(201,168,76,0.2);">
        <span class="font-cinzel text-xl font-bold" style="color: #c9a84c;">Loup-Garou Undu</span>
        <span class="text-sm" style="color: #e8e0d0; opacity: 0.5;">{{ $player->pseudo }}</span>
    </header>

    <main
        class="flex-1 flex flex-col items-center justify-center px-4 py-10"
        x-data="roleReveal()"
        x-init="init()"
    >
        {{-- Titre --}}
        <div id="rr-title" class="text-center mb-8">
            <p class="text-sm mb-1" style="color: #e8e0d0; opacity: 0.45; letter-spacing: 0.1em;">TON RÔLE DANS CETTE PARTIE</p>
            <h1 class="font-cinzel text-2xl font-bold" style="color: #c9a84c;">
                <span x-show="!revealed">Retournement dans <span x-text="countdown"></span>s…</span>
                <span x-show="revealed" x-text="roleName"></span>
            </h1>
        </div>

        {{-- Timer 60s + compteur joueurs prêts (toujours visible) --}}
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
            <p x-show="nbReady === total && total > 0" class="text-center text-xs mt-2 font-cinzel" style="color: #c9a84c;">
                L'élection du Maire commence !
            </p>
        </div>

        {{-- Carte --}}
        <div id="card-wrap" class="mb-8 flex flex-col items-center">
            <div id="role-card" @click="flipCard()">
                {{-- Dos --}}
                <div class="card-face card-back" id="card-back">
                    <div class="question-mark">?</div>
                    <p class="text-xs text-center" style="color: rgba(201,168,76,0.4); font-family: 'Cinzel', serif; letter-spacing: 0.1em;">
                        CLIQUE POUR RÉVÉLER
                    </p>
                </div>

                {{-- Face — rendu selon le rôle --}}
                {{-- La classe de rôle est liée dynamiquement pour réagir au fetch syncRole() --}}
                <div class="card-face card-front" :class="role" id="card-front">
                    {{-- Loup-Garou --}}
                    <div class="role-icon" x-show="role === 'werewolf'">🐺</div>
                    <div class="role-name" x-show="role === 'werewolf'" style="color: #fca5a5;">Loup-Garou</div>
                    <div class="role-desc" x-show="role === 'werewolf'" style="color: #fca5a5;">
                        Chaque nuit, les loups choisissent une victime. Restez discrets le jour.
                    </div>
                    {{-- Voyante --}}
                    <div class="role-icon" x-show="role === 'seer'">👁️</div>
                    <div class="role-name" x-show="role === 'seer'" style="color: #c4b5fd;">Voyante</div>
                    <div class="role-desc" x-show="role === 'seer'" style="color: #c4b5fd;">
                        Chaque nuit, tu découvres la vraie nature d'un joueur de ton choix.
                    </div>
                    {{-- Villageois --}}
                    <div class="role-icon" x-show="role !== 'werewolf' && role !== 'seer'">🏘️</div>
                    <div class="role-name" x-show="role !== 'werewolf' && role !== 'seer'" style="color: #e8e0d0;">Villageois</div>
                    <div class="role-desc" x-show="role !== 'werewolf' && role !== 'seer'" style="color: #e8e0d0;">
                        Identifie et éliminine les loups-garous avant qu'ils ne vous déciment.
                    </div>
                </div>
            </div>

            {{-- Alliés loups — Alpine-reactive pour réagir au fetch syncRole() --}}
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
                class="w-full py-3 rounded-xl font-cinzel font-semibold text-base transition-all disabled:opacity-50"
                style="background-color: #c9a84c; color: #0a0f1e;"
                :style="readyDone ? 'background-color: rgba(22,163,74,0.3); color: #86efac; border: 1px solid rgba(22,163,74,0.4);' : ''"
            >
                <span x-show="!readyDone && !submitting">Entrer dans la partie</span>
                <span x-show="submitting">Confirmation…</span>
                <span x-show="readyDone">Tu es prêt !</span>
            </button>
        </div>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script>
        const ROLE_NAMES = {
            villager: 'Villageois',
            werewolf: 'Loup-Garou',
            seer:     'Voyante',
        };

        function roleReveal() {
            return {
                revealed:    false,
                countdown:   5,
                gameTimer:   60,
                role:        '{{ $player->role }}',
                roleName:    ROLE_NAMES['{{ $player->role }}'] ?? '{{ $player->role }}',
                nbReady:     {{ $nbReady }},
                total:       {{ $total }},
                readyDone:   {{ $player->is_ready ? 'true' : 'false' }},
                submitting:  false,
                allies:      @json($allies),

                init() {
                    // Entrée
                    gsap.fromTo('#rr-title',
                        { opacity: 0, y: -20 },
                        { opacity: 1, y: 0, duration: 0.6, ease: 'power2.out' }
                    );
                    gsap.fromTo('#rr-timer',
                        { opacity: 0 },
                        { opacity: 1, duration: 0.5, delay: 0.05, ease: 'power2.out' }
                    );
                    gsap.fromTo('#card-wrap',
                        { opacity: 0, y: 40 },
                        { opacity: 1, y: 0, duration: 0.7, delay: 0.1, ease: 'power2.out' }
                    );

                    // Barre du timer 60s — GSAP anime la largeur en continu
                    gsap.to('#game-timer-fill', { width: '0%', duration: 60, ease: 'none' });

                    // Décompte 60s → redirection automatique (fallback si WS manqué)
                    // Stocké sur this pour que redirect() puisse le clearer depuis l'extérieur
                    this._gameTick = setInterval(() => {
                        this.gameTimer--;
                        if (this.gameTimer <= 0) {
                            clearInterval(this._gameTick);
                            this.redirect();
                        }
                    }, 1000);

                    // Compte à rebours avant flip automatique (5s)
                    const tick = setInterval(() => {
                        this.countdown--;
                        if (this.countdown <= 0) {
                            clearInterval(tick);
                            this.flipCard();
                        }
                    }, 1000);

                    // Écoute WebSocket
                    window.Echo.channel('game.{{ $game->id }}')
                        .listen('.player.ready', (data) => {
                            this.nbReady = data.nb_ready;
                        })
                        .listen('.mayor.election.started', () => {
                            setTimeout(() => { this.redirect(); }, 1000);
                        });

                    // Fallback : si le rôle était null côté serveur (race condition),
                    // on le récupère depuis /state et on met à jour la carte
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
                            headers: {
                                'Accept':       'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                        });
                        if (! res.ok) return;
                        const json = await res.json();
                        if (! json.success) return;
                        const data = json.data;

                        // Met à jour le rôle si Blade avait retourné null
                        if (data.my_role && ! this.role) {
                            this.role     = data.my_role;
                            this.roleName = ROLE_NAMES[this.role] ?? this.role;
                            if (Array.isArray(data.allies) && data.allies.length) {
                                this.allies = data.allies;
                            }
                        }

                        // Redirige uniquement si la phase a DÉPASSÉ role-reveal.
                        // 'electing_mayor' est absent : c'est le status normal pendant role-reveal,
                        // on ne redirige pas vers mayor-election ici (géré par le listener WS
                        // .mayor.election.started + le gameTick 60s).
                        const targets = {
                            'night':    '/game/{{ $game->code }}/night',
                            'day':      '/game/{{ $game->code }}/day',
                            'finished': '/game/{{ $game->code }}/finished',
                        };
                        const target = targets[data.phase];
                        if (target && window.location.pathname !== target) {
                            window.location.href = target;
                        }
                    } catch {
                        // silencieux
                    }
                },

                flipCard() {
                    if (this.revealed) return;

                    gsap.to('#role-card', {
                        rotateY: 90,
                        duration: 0.4,
                        ease: 'power2.in',
                        onComplete: () => {
                            // Swap des faces
                            document.getElementById('card-back').style.display  = 'none';
                            document.getElementById('card-front').style.display = 'flex';
                            this.revealed = true;

                            gsap.fromTo('#role-card',
                                { rotateY: -90 },
                                { rotateY: 0, duration: 0.4, ease: 'power2.out' }
                            );
                        },
                    });
                },

                async markReady() {
                    if (this.readyDone || this.submitting) return;
                    this.submitting = true;

                    try {
                        const res = await fetch('/game/{{ $game->id }}/ready', {
                            method:  'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept':       'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                        });
                        const json = await res.json();
                        if (json.success) this.readyDone = true;
                    } catch {
                        // silencieux — le joueur peut réessayer
                    } finally {
                        this.submitting = false;
                    }
                },
            };
        }
    </script>
</body>
</html>
