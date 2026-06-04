<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loup-Garou Undu — Spectateur</title>
    <meta name="description" content="Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=EB+Garamond:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'EB Garamond', serif; background-color: #0a0f1e; color: #e8e0d0; }
        h1, h2, .font-cinzel { font-family: 'Cinzel', serif; }

        .game-screen { filter: grayscale(30%); }

        .death-banner {
            background-color: #1a0000;
            border: 1px solid rgba(139,0,0,0.6);
            border-radius: 0.75rem;
            padding: 0.875rem 1.25rem;
            display: flex; align-items: center; gap: 0.75rem;
        }

        .player-row {
            background-color: #111827;
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 0.5rem;
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.6rem 0.875rem;
        }
        .avatar {
            width: 2rem; height: 2rem; border-radius: 50%;
            background-color: rgba(201,168,76,0.1);
            border: 1px solid rgba(201,168,76,0.25);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Cinzel', serif; font-weight: 600;
            font-size: 0.7rem; color: #c9a84c; flex-shrink: 0;
        }
        .avatar.dead { opacity: 0.35; filter: grayscale(100%); }

        .role-badge-werewolf { background: rgba(139,0,0,0.25); color: #f87171; }
        .role-badge-hidden   { background: rgba(107,114,128,0.15); color: rgba(232,224,208,0.35); }

        .tab-btn {
            font-family: 'Cinzel', serif; font-size: 0.75rem;
            padding: 0.4rem 1.1rem; border-radius: 0.375rem;
            color: rgba(232,224,208,0.45); transition: all 0.2s;
        }
        .tab-btn.active {
            background-color: rgba(201,168,76,0.12);
            color: #c9a84c;
            border: 1px solid rgba(201,168,76,0.3);
        }

        .chat-area {
            background-color: #0d1117;
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 0.75rem;
            height: 220px; overflow-y: auto;
            padding: 0.75rem;
            display: flex; flex-direction: column; gap: 0.4rem;
        }
        .chat-msg { font-size: 0.875rem; }
        .chat-msg .pseudo { font-weight: 600; color: #c9a84c; margin-right: 0.3rem; }
        .chat-msg.wolf .pseudo { color: #f87171; }

        .readonly-input {
            background-color: rgba(0,0,0,0.3);
            border: 1px solid rgba(139,0,0,0.3);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            font-style: italic;
            color: rgba(232,224,208,0.35);
            font-size: 0.875rem;
            text-align: center;
        }
    </style>
</head>

@php
    $isWolf       = $player->isWerewolf();
    $gameCode     = $game->code;
    $myPseudo     = $player->pseudo;
@endphp

<body class="min-h-screen">

<div class="game-screen" id="game-screen">

    <header class="flex items-center justify-between px-5 py-3 border-b" style="border-color:rgba(139,0,0,0.25);">
        <span class="font-cinzel font-bold" style="color:#c9a84c;">Loup-Garou Undu</span>
        <span class="font-cinzel text-xs" style="color:rgba(232,224,208,0.4);">
            {{ strtoupper($game->status) }} — Round {{ $game->round }}
        </span>
    </header>

    <div class="max-w-xl mx-auto px-4 py-6" x-data="spectatorScreen()" x-init="init()">

        {{-- ══════════ BANDEAU MORT ══════════ --}}
        <div class="death-banner mb-5">
            <span class="text-2xl">💀</span>
            <div>
                <p class="font-cinzel font-semibold text-sm" style="color:#ef4444;">Tu es mort.</p>
                <p class="text-xs italic" style="color:rgba(232,224,208,0.5);">Observe la partie en silence.</p>
            </div>
        </div>

        {{-- ══════════ ONGLETS ══════════ --}}
        @if($isWolf)
        <div class="flex gap-2 mb-4">
            <button class="tab-btn" :class="{ active: tab === 'players' }" @click="tab = 'players'">Joueurs</button>
            <button class="tab-btn" :class="{ active: tab === 'chat' }"    @click="tab = 'chat'">Chat 💬</button>
            <button class="tab-btn" :class="{ active: tab === 'wolves' }"  @click="tab = 'wolves'">Loups 🐺</button>
        </div>
        @else
        <div class="flex gap-2 mb-4">
            <button class="tab-btn" :class="{ active: tab === 'players' }" @click="tab = 'players'">Joueurs</button>
            <button class="tab-btn" :class="{ active: tab === 'chat' }"    @click="tab = 'chat'">Chat 💬</button>
        </div>
        @endif

        {{-- ══════════ JOUEURS ══════════ --}}
        <div x-show="tab === 'players'" x-cloak>
            <div class="flex flex-col gap-1.5">
                @foreach($allPlayers as $p)
                @php
                    // Loups morts voient les rôles des loups (vivants ou non)
                    $showRole = $isWolf && $p->isWerewolf();
                @endphp
                <div class="player-row">
                    <div class="avatar {{ $p->is_alive ? '' : 'dead' }}">
                        {{ strtoupper(substr($p->pseudo, 0, 1)) }}
                    </div>
                    <div class="flex-1 flex items-center gap-1.5">
                        <span class="text-sm" style="color:{{ $p->is_alive ? '#e8e0d0' : 'rgba(232,224,208,0.4)' }}">
                            {{ $p->pseudo }}
                        </span>
                        @if($p->is_mayor) <span class="text-xs">👑</span> @endif
                    </div>
                    @if($showRole)
                        <span class="text-xs px-2 py-0.5 rounded-full role-badge-werewolf">🐺 Loup</span>
                    @else
                        <span class="text-xs px-2 py-0.5 rounded-full role-badge-hidden">
                            {{ $p->is_alive ? '?' : '💀' }}
                        </span>
                    @endif
                </div>
                @endforeach
            </div>
        </div>

        {{-- ══════════ CHAT GÉNÉRAL (lecture seule) ══════════ --}}
        <div x-show="tab === 'chat'" x-cloak>
            <div class="chat-area mb-3" id="general-chat">
                <template x-for="msg in generalMessages" :key="msg.id">
                    <p class="chat-msg">
                        <span class="pseudo" x-text="msg.pseudo + ' :'"></span>
                        <span x-text="msg.message"></span>
                    </p>
                </template>
                <p x-show="generalMessages.length === 0" class="text-xs text-center" style="color:rgba(232,224,208,0.3);margin:auto;">
                    Aucun message pour l'instant.
                </p>
            </div>
            <div class="readonly-input">💀 Tu es mort. Observe en silence.</div>
        </div>

        {{-- ══════════ CHAT LOUPS (lecture seule, ex-loups uniquement) ══════════ --}}
        @if($isWolf)
        <div x-show="tab === 'wolves'" x-cloak>
            <div class="chat-area mb-3" id="wolf-chat">
                <template x-for="msg in wolfMessages" :key="msg.id">
                    <p class="chat-msg wolf">
                        <span class="pseudo" x-text="msg.pseudo + ' :'"></span>
                        <span x-text="msg.message"></span>
                    </p>
                </template>
                <p x-show="wolfMessages.length === 0" class="text-xs text-center" style="color:rgba(232,224,208,0.3);margin:auto;">
                    Aucun message loup.
                </p>
            </div>
            <div class="readonly-input">🐺 Tu es mort. Lecture seule.</div>
        </div>
        @endif

    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script>
const GAME_ID   = {{ $game->id }};
const GAME_CODE = '{{ $game->code }}';
const IS_WOLF   = {{ $isWolf ? 'true' : 'false' }};

function spectatorScreen() {
    return {
        tab:             'players',
        generalMessages: [],
        wolfMessages:    [],

        init() {
            // Filtre grayscale en entrée
            gsap.to('#game-screen', { filter: 'grayscale(30%)', duration: 1.2, ease: 'power2.out' });

            // Chat général (lecture seule)
            window.Echo.channel(`game.${GAME_ID}`)
                .listen('.chat.message.sent', (data) => {
                    if (data.channel === 'general') {
                        this.generalMessages.push({ id: Date.now(), ...data });
                        this.$nextTick(() => {
                            const el = document.getElementById('general-chat');
                            if (el) el.scrollTop = el.scrollHeight;
                        });
                    }
                })
                .listen('.game.finished', (data) => {
                    setTimeout(() => {
                        window.location.href = data.winner_team !== null
                            ? `/game/${GAME_CODE}/finished`
                            : `/game/${GAME_CODE}/cancelled`;
                    }, 2000);
                });

            // Chat loups (ex-loups uniquement)
            if (IS_WOLF) {
                window.Echo.private(`game.${GAME_ID}.werewolves`)
                    .listen('.werewolf.chat.message', (data) => {
                        this.wolfMessages.push({ id: Date.now(), ...data });
                        this.$nextTick(() => {
                            const el = document.getElementById('wolf-chat');
                            if (el) el.scrollTop = el.scrollHeight;
                        });
                    });
            }
        },
    };
}
</script>

</body>
</html>
