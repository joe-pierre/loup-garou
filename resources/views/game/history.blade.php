<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loup-Garou Undu — Historique {{ $game->code }}</title>
    <meta name="description" content="Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=EB+Garamond:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'EB Garamond', serif; background-color: #0a0f1e; color: #e8e0d0; }
        h1, h2, h3, .font-cinzel { font-family: 'Cinzel', serif; }

        .card {
            background-color: #111827;
            border: 1px solid rgba(201,168,76,0.2);
            border-radius: 0.875rem;
        }

        .tab-btn {
            font-family: 'Cinzel', serif;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            padding: 0.5rem 1.5rem;
            border-radius: 0.5rem;
            transition: all 0.2s;
            color: rgba(232,224,208,0.5);
        }
        .tab-btn.active {
            background-color: rgba(201,168,76,0.15);
            color: #c9a84c;
            border: 1px solid rgba(201,168,76,0.35);
        }

        .role-villager { color: #e8e0d0; }
        .role-werewolf { color: #8b0000; }
        .role-seer     { color: #7c3aed; }
        .role-witch    { color: #3493d3; }
        .role-hunter   { color: #fbbf24; }

        .role-badge-villager { background-color: rgba(232,224,208,0.1); color: #e8e0d0; }
        .role-badge-werewolf { background-color: rgba(139,0,0,0.2);     color: #e57373; }
        .role-badge-seer     { background-color: rgba(124,58,237,0.2);  color: #a78bfa; }
        .role-badge-witch    { background-color: rgba(52,147,211,0.2); color: #3493d3; }
        .role-badge-hunter   { background-color: rgba(247,191,36,0.2); color: #fbbf24; }

        .winner-villagers  { background-color: rgba(22,163,74,0.2);   color: #4ade80;  border: 1px solid rgba(22,163,74,0.4); }
        .winner-werewolves { background-color: rgba(139,0,0,0.25);    color: #f87171;  border: 1px solid rgba(139,0,0,0.4); }
        .winner-cancelled  { background-color: rgba(107,114,128,0.2); color: #9ca3af; border: 1px solid rgba(107,114,128,0.3); }

        /* Timeline */
        .timeline-track {
            position: relative;
            padding-left: 2.5rem;
        }
        .timeline-track::before {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(to bottom, rgba(201,168,76,0.5), rgba(201,168,76,0.05));
        }
        .timeline-node {
            position: absolute;
            left: 0;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
            border: 2px solid;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.6rem;
            transform: translateX(0);
        }
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
            opacity: 0; /* GSAP animera l'entrée */
        }
        .timeline-item:last-child { margin-bottom: 0; }

        .node-election { background-color: rgba(201,168,76,0.15); border-color: #c9a84c; color: #c9a84c; }
        .node-night    { background-color: rgba(3,7,18,0.6);       border-color: #7c3aed; color: #7c3aed; }
        .node-day      { background-color: rgba(201,168,76,0.1);   border-color: rgba(201,168,76,0.5); color: #c9a84c; }
        .node-finish   { background-color: rgba(22,163,74,0.15);   border-color: #16a34a; color: #16a34a; }
        .node-finish-wolves { background-color: rgba(139,0,0,0.2); border-color: #8b0000; color: #ef4444; }

        /* Player row */
        .player-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 1rem;
            border-bottom: 1px solid rgba(201,168,76,0.07);
            opacity: 0; /* GSAP */
        }
        .player-row:last-child { border-bottom: none; }
        .avatar {
            width: 2.25rem; height: 2.25rem;
            border-radius: 50%;
            background-color: rgba(201,168,76,0.1);
            border: 1px solid rgba(201,168,76,0.3);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Cinzel', serif; font-weight: 600;
            font-size: 0.8rem; color: #c9a84c; flex-shrink: 0;
        }
        .avatar.dead { opacity: 0.4; filter: grayscale(100%); }
        #players-section { opacity: 0; }
    </style>
</head>
<body class="min-h-screen">

@php
    $winnerTeam = $game->winner_team;
    $winnerClass = match($winnerTeam) {
        'villagers'  => 'winner-villagers',
        'werewolves' => 'winner-werewolves',
        default      => 'winner-cancelled',
    };
    $winnerLabel = match($winnerTeam) {
        'villagers'  => '🏆 Village',
        'werewolves' => '🐺 Loups',
        default      => '🏁 Annulée',
    };
    $roleLabel = fn(?string $r) => match($r) {
        'villager' => '🏘 Villageois',
        'werewolf' => '🐺 Loup-Garou',
        'seer'     => '🔮 Voyante',
        'witch'    => '🧙‍♀️ Sorcière',
        'hunter'   => '🏹 Chasseur',
        default    => $r ?? '?',
    };
    $roleClass = fn(?string $r) => match($r) {
        'villager' => 'role-badge-villager',
        'werewolf' => 'role-badge-werewolf',
        'seer'     => 'role-badge-seer',
        'witch'    => 'role-badge-witch',
        'hunter'   => 'role-badge-hunter',
        default    => 'role-badge-villager',
    };
    $myPseudo = $myPlayer?->pseudo ?? '';
    $gameDate = $game->finished_at?->format('d/m/Y H:i') ?? '';
    $durationLabel = $duration !== null
        ? ($duration >= 60 ? floor($duration / 60) . 'h ' . ($duration % 60) . 'min' : $duration . ' min')
        : null;
@endphp

<div x-data="{ tab: 'players' }" class="max-w-2xl mx-auto px-4 py-8">

    {{-- ══════════════ HEADER ══════════════ --}}
    <div id="history-header" class="text-center mb-8" style="opacity:0">

        <p class="font-cinzel text-xs tracking-widest mb-2" style="color:rgba(201,168,76,0.5);">
            HISTORIQUE DE PARTIE
        </p>

        <h1 class="font-cinzel text-2xl font-bold mb-3" style="color:#c9a84c;">
            {{ $game->code }}
        </h1>

        <div class="flex items-center justify-center gap-3 flex-wrap">
            <span class="inline-block px-3 py-1 rounded-full text-sm font-semibold {{ $winnerClass }}">
                {{ $winnerLabel }}
            </span>

            @if($gameDate)
                <span class="text-sm" style="color:rgba(232,224,208,0.45);">{{ $gameDate }}</span>
            @endif

            @if($durationLabel)
                <span class="text-sm" style="color:rgba(232,224,208,0.45);">⏱ {{ $durationLabel }}</span>
            @endif
        </div>
    </div>

    {{-- ══════════════ ONGLETS ══════════════ --}}
    <div id="history-tabs" class="flex gap-2 mb-6 justify-center" style="opacity:0">
        <button
            class="tab-btn"
            :class="{ 'active': tab === 'players' }"
            @click="tab = 'players'"
        >Joueurs</button>
        <button
            class="tab-btn"
            :class="{ 'active': tab === 'timeline' }"
            @click="tab = 'timeline'"
        >Déroulé</button>
    </div>

    {{-- ══════════════ JOUEURS ══════════════ --}}
    <div x-show="tab === 'players'" x-cloak>
        <div class="card overflow-hidden" id="players-section">

            @forelse($players as $player)
            <div class="player-row">
                {{-- Avatar --}}
                <div class="avatar {{ $player->is_alive ? '' : 'dead' }}">
                    {{ strtoupper(substr($player->pseudo, 0, 1)) }}
                </div>

                {{-- Pseudo + badges --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-medium text-sm truncate" style="color:#e8e0d0;">
                            {{ $player->pseudo }}
                        </span>
                        @if($player->is_mayor)
                            <span class="text-xs">👑</span>
                        @endif
                        @if(!$player->is_alive)
                            <span class="text-xs">💀</span>
                        @endif
                    </div>
                </div>

                {{-- Rôle --}}
                <span class="text-xs px-2 py-0.5 rounded-full font-medium whitespace-nowrap {{ $roleClass($player->role) }}">
                    {{ $roleLabel($player->role) }}
                </span>

                {{-- Vivant/Mort --}}
                <span class="text-sm">
                    {{ $player->is_alive ? '✅' : '❌' }}
                </span>
            </div>
            @empty
                <p class="text-center py-8 text-sm" style="color:rgba(232,224,208,0.4);">Aucun joueur enregistré.</p>
            @endforelse

        </div>
    </div>

    {{-- ══════════════ DÉROULÉ ══════════════ --}}
    <div x-show="tab === 'timeline'" x-cloak>
        <div class="timeline-track" id="timeline-section">

            @foreach($timeline as $entry)
            @php
                $nodeClass = match($entry['type']) {
                    'election' => 'node-election',
                    'night'    => 'node-night',
                    'day'      => 'node-day',
                    'finish'   => ($entry['winner_team'] === 'werewolves') ? 'node-finish-wolves' : 'node-finish',
                    default    => 'node-day',
                };
                $nodeIcon = match($entry['type']) {
                    'election' => '👑',
                    'night'    => '🌙',
                    'day'      => '☀',
                    'finish'   => '🏁',
                    default    => '•',
                };
            @endphp

            <div class="timeline-item">
                {{-- Nœud --}}
                <div class="timeline-node {{ $nodeClass }}" style="top: 0.15rem;">
                    <span style="font-size:0.65rem;">{{ $nodeIcon }}</span>
                </div>

                {{-- Contenu --}}
                <div class="card px-4 py-3 ml-2">
                    <p class="font-cinzel text-xs font-semibold mb-2"
                       style="color:{{ in_array($entry['type'], ['night']) ? '#a78bfa' : '#c9a84c' }};">
                        {{ $entry['label'] }}
                    </p>

                    @if($entry['type'] === 'election')
                        @if(!empty($entry['mayor']))
                            <p class="text-sm">
                                Maire élu :
                                <span class="font-semibold" style="color:#c9a84c;">{{ $entry['mayor']['pseudo'] }}</span>
                                @if($entry['was_random'])
                                    <span class="text-xs ml-1" style="color:rgba(232,224,208,0.4);">(tirage au sort)</span>
                                @endif
                            </p>
                        @else
                            <p class="text-sm" style="color:rgba(232,224,208,0.5);">Élection non résolue.</p>
                        @endif

                    @elseif($entry['type'] === 'night')
                        @if(!empty($entry['killed']))
                            <p class="text-sm">
                                Tué cette nuit :
                                <span class="font-semibold" style="color:#ef4444;">{{ $entry['killed']['pseudo'] }}</span>
                                @if($entry['killed']['role'])
                                    <span class="text-xs ml-1 px-1.5 py-0.5 rounded-full {{ $roleClass($entry['killed']['role']) }}">
                                        {{ $roleLabel($entry['killed']['role']) }}
                                    </span>
                                @endif
                            </p>
                        @else
                            <p class="text-sm" style="color:rgba(232,224,208,0.5);">Personne tué cette nuit.</p>
                        @endif

                    @elseif($entry['type'] === 'day')
                        @if($entry['result'] === 'eliminated' && !empty($entry['eliminated']))
                            <p class="text-sm">
                                Éliminé par le village :
                                <span class="font-semibold" style="color:#f97316;">{{ $entry['eliminated']['pseudo'] }}</span>
                                @if($entry['eliminated']['role'])
                                    <span class="text-xs ml-1 px-1.5 py-0.5 rounded-full {{ $roleClass($entry['eliminated']['role']) }}">
                                        {{ $roleLabel($entry['eliminated']['role']) }}
                                    </span>
                                @endif
                            </p>
                        @elseif($entry['result'] === 'equality')
                            <p class="text-sm" style="color:rgba(232,224,208,0.6);">Égalité — personne éliminé.</p>
                        @else
                            <p class="text-sm" style="color:rgba(232,224,208,0.6);">Aucun vote exprimé.</p>
                        @endif

                        @if(!empty($entry['succession']))
                            <p class="text-sm mt-1" style="color:rgba(232,224,208,0.6);">
                                👑 Nouveau maire :
                                <span class="font-semibold" style="color:#c9a84c;">{{ $entry['succession']['pseudo'] }}</span>
                            </p>
                        @endif

                    @elseif($entry['type'] === 'finish')
                        <p class="text-sm font-semibold">{{ $entry['label'] }}</p>

                    @endif
                </div>
            </div>
            @endforeach

        </div>
    </div>

    {{-- ══════════════ BOUTONS ══════════════ --}}
    <div id="history-buttons" class="flex gap-3 mt-8 justify-center" style="opacity:0">
        <a
            href="/lobby{{ $myPseudo ? '?pseudo=' . urlencode($myPseudo) : '' }}"
            class="px-6 py-3 rounded-xl font-cinzel font-semibold text-sm transition-all hover:opacity-90"
            style="background-color:#c9a84c;color:#0a0f1e;"
        >
            🔄 Rejouer
        </a>
        <a
            href="/"
            class="px-6 py-3 rounded-xl font-cinzel font-semibold text-sm transition-all hover:opacity-90"
            style="background-color:#111827;border:1px solid rgba(201,168,76,0.35);color:#c9a84c;"
        >
            🏠 Accueil
        </a>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const tl = gsap.timeline({ defaults: { ease: 'power2.out' } });

    // Header + onglets
    tl.to('#history-header', { opacity: 1, y: 0, duration: 0.6, from: { y: -20 } })
      .to('#history-tabs',   { opacity: 1, duration: 0.4 }, '-=0.3')
      .to('#history-buttons',{ opacity: 1, duration: 0.4 }, '-=0.2');

    // Joueurs (section active par défaut)
    gsap.fromTo('#players-section',
        { opacity: 0, y: 20 },
        { opacity: 1, y: 0, duration: 0.5, delay: 0.4 }
    );
    gsap.to('.player-row', {
        opacity: 1,
        y: 0,
        duration: 0.35,
        stagger: 0.06,
        delay: 0.5,
        from: { y: 10 },
    });

    // Timeline : animée au changement d'onglet (via Alpine watch)
    document.querySelectorAll('[x-data]')[0]?._x_dataStack?.[0];

    // Observer les changements d'onglet via MutationObserver sur display
    const timelineSection = document.getElementById('timeline-section');
    if (timelineSection) {
        const observer = new MutationObserver(() => {
            const items = timelineSection.querySelectorAll('.timeline-item');
            if (items.length && getComputedStyle(timelineSection).display !== 'none') {
                gsap.to(items, {
                    opacity: 1,
                    x: 0,
                    duration: 0.35,
                    stagger: 0.08,
                    ease: 'power2.out',
                    from: { x: -15 },
                });
                observer.disconnect();
            }
        });
        observer.observe(timelineSection, { attributes: true, attributeFilter: ['style', 'class'] });
    }
});
</script>

</body>
</html>
