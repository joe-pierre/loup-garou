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
                <div class="card-face card-front {{ $player->role }}" id="card-front">
                    @switch($player->role)
                        @case('werewolf')
                            <div class="role-icon">🐺</div>
                            <div class="role-name" style="color: #fca5a5;">Loup-Garou</div>
                            <div class="role-desc" style="color: #fca5a5;">
                                Chaque nuit, les loups choisissent une victime. Restez discrets le jour.
                            </div>
                            @break
                        @case('seer')
                            <div class="role-icon">👁️</div>
                            <div class="role-name" style="color: #c4b5fd;">Voyante</div>
                            <div class="role-desc" style="color: #c4b5fd;">
                                Chaque nuit, tu découvres la vraie nature d'un joueur de ton choix.
                            </div>
                            @break
                        @default
                            <div class="role-icon">🏘️</div>
                            <div class="role-name" style="color: #e8e0d0;">Villageois</div>
                            <div class="role-desc" style="color: #e8e0d0;">
                                Identifie et éliminine les loups-garous avant qu'ils ne vous déciment.
                            </div>
                    @endswitch
                </div>
            </div>

            {{-- Alliés loups — visibles après reveal --}}
            @if($player->isWerewolf() && count($allies) > 0)
            <div x-show="revealed" x-transition class="mt-5 text-center">
                <p class="text-xs mb-2" style="color: #e8e0d0; opacity: 0.4; letter-spacing: 0.05em;">TES ALLIÉS LOUPS</p>
                <div class="flex flex-wrap gap-2 justify-center">
                    @foreach($allies as $ally)
                        <span class="ally-chip">🐺 {{ $ally['pseudo'] }}</span>
                    @endforeach
                </div>
            </div>
            @endif
        </div>

        {{-- Bouton "Entrer" (visible après reveal) --}}
        <div x-show="revealed" x-transition class="flex flex-col items-center gap-4 w-full max-w-xs">
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

            {{-- Progression des joueurs prêts --}}
            <div class="w-full">
                <div class="flex justify-between text-xs mb-1" style="color: #e8e0d0; opacity: 0.4;">
                    <span x-text="nbReady + ' joueur' + (nbReady > 1 ? 's' : '') + ' prêt' + (nbReady > 1 ? 's' : '')"></span>
                    <span x-text="total + ' total'"></span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill" :style="'width:' + (nbReady / total * 100) + '%'"></div>
                </div>
                <p x-show="nbReady === total" class="text-center text-xs mt-2 font-cinzel" style="color: #c9a84c;">
                    L'élection du Maire commence !
                </p>
            </div>
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
                role:        '{{ $player->role }}',
                roleName:    ROLE_NAMES['{{ $player->role }}'] ?? '{{ $player->role }}',
                nbReady:     {{ $nbReady }},
                total:       {{ $total }},
                readyDone:   {{ $player->is_ready ? 'true' : 'false' }},
                submitting:  false,

                init() {
                    // Entrée
                    gsap.fromTo('#rr-title',
                        { opacity: 0, y: -20 },
                        { opacity: 1, y: 0, duration: 0.6, ease: 'power2.out' }
                    );
                    gsap.fromTo('#card-wrap',
                        { opacity: 0, y: 40 },
                        { opacity: 1, y: 0, duration: 0.7, delay: 0.1, ease: 'power2.out' }
                    );

                    // Compte à rebours avant flip automatique
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
                            // Redirection vers l'écran d'élection (tâche 11)
                            setTimeout(() => {
                                window.location.href = '/game/{{ $game->code }}/mayor-election';
                            }, 1000);
                        });
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
