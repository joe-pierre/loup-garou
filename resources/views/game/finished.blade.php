<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loup-Garou Undu — Fin de partie</title>
    <meta name="description" content="Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=EB+Garamond:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'EB Garamond', serif; background-color: #0a0f1e; color: #e8e0d0; }
        h1, h2, .font-cinzel { font-family: 'Cinzel', serif; }

        @php $isVillage = $game->winner_team === 'villagers'; @endphp

        .victory-banner {
            background-color: {{ $isVillage ? '#0f1a0a' : '#1a0000' }};
            border: 2px solid {{ $isVillage ? '#16a34a' : '#8b0000' }};
            border-radius: 1.25rem;
            text-align: center;
            padding: 2rem 1.5rem;
            opacity: 0;
            transform: scale(0.7);
        }
        .victory-title {
            font-family: 'Cinzel', serif;
            font-size: clamp(1.5rem, 5vw, 2.5rem);
            font-weight: 700;
            color: {{ $isVillage ? '#4ade80' : '#ff4444' }};
            letter-spacing: 0.05em;
        }

        .player-card {
            background-color: #111827;
            border: 1px solid rgba(201,168,76,0.2);
            border-radius: 0.75rem;
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.75rem 1rem;
            opacity: 0;
        }
        .avatar {
            width: 2.5rem; height: 2.5rem; border-radius: 50%;
            background-color: rgba(201,168,76,0.1);
            border: 1px solid rgba(201,168,76,0.3);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Cinzel', serif; font-weight: 600;
            font-size: 0.8rem; color: #c9a84c; flex-shrink: 0;
        }
        .avatar.dead { opacity: 0.45; filter: grayscale(100%); }

        .role-badge-villager { background: rgba(232,224,208,0.1); color: #e8e0d0; }
        .role-badge-werewolf { background: rgba(139,0,0,0.25); color: #f87171; }
        .role-badge-seer     { background: rgba(124,58,237,0.2); color: #a78bfa; }
    </style>
</head>
<body class="min-h-screen">

@php
    $isVillage  = $game->winner_team === 'villagers';
    $myPseudo   = $player->pseudo;
    $roleLabel  = fn(?string $r) => match($r) {
        'villager' => '🏘 Villageois',
        'werewolf' => '🐺 Loup-Garou',
        'seer'     => '🔮 Voyante',
        default    => $r ?? '?',
    };
    $roleClass  = fn(?string $r) => match($r) {
        'werewolf' => 'role-badge-werewolf',
        'seer'     => 'role-badge-seer',
        default    => 'role-badge-villager',
    };
@endphp

<div class="max-w-2xl mx-auto px-4 py-8">

    {{-- ══════════ BANNIÈRE VICTOIRE ══════════ --}}
    <div class="victory-banner mb-8" id="victory-banner">
        <p class="victory-title mb-2">
            {{ $isVillage ? '🏆 VICTOIRE DU VILLAGE' : '🐺 LES LOUPS ONT GAGNÉ' }}
        </p>
        <p class="text-sm mt-2" style="color:rgba(232,224,208,0.55);">
            Partie {{ $game->code }}
            @if($game->finished_at)
                — {{ $game->finished_at->format('d/m/Y H:i') }}
            @endif
        </p>
    </div>

    {{-- ══════════ RÉVÉLATION JOUEURS ══════════ --}}
    <h2 class="font-cinzel text-sm tracking-widest mb-4 text-center" style="color:rgba(201,168,76,0.6);">
        RÉVÉLATION DES RÔLES
    </h2>

    <div class="flex flex-col gap-2 mb-8" id="players-list">
        @foreach($allPlayers as $p)
        <div class="player-card">
            <div class="avatar {{ $p->is_alive ? '' : 'dead' }}">
                {{ strtoupper(substr($p->pseudo, 0, 1)) }}
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="font-medium text-sm" style="color:#e8e0d0;">{{ $p->pseudo }}</span>
                    @if($p->is_mayor) <span class="text-xs">👑</span> @endif
                    @if(!$p->is_alive) <span class="text-xs">💀</span> @endif
                </div>
            </div>
            <span class="text-xs px-2 py-0.5 rounded-full font-medium whitespace-nowrap {{ $roleClass($p->role) }}">
                {{ $roleLabel($p->role) }}
            </span>
            <span class="text-sm ml-1">{{ $p->is_alive ? '✅' : '❌' }}</span>
        </div>
        @endforeach
    </div>

    {{-- ══════════ BOUTONS ══════════ --}}
    <div class="flex gap-3 justify-center" id="action-buttons" style="opacity:0">
        <a href="/lobby?pseudo={{ urlencode($myPseudo) }}"
           class="px-6 py-3 rounded-xl font-cinzel font-semibold text-sm transition-all hover:opacity-90"
           style="background-color:#c9a84c;color:#0a0f1e;">
            🔄 Rejouer
        </a>
        <a href="/"
           class="px-6 py-3 rounded-xl font-cinzel font-semibold text-sm transition-all hover:opacity-90"
           style="background-color:#111827;border:1px solid rgba(201,168,76,0.35);color:#c9a84c;">
            🏠 Accueil
        </a>
        <a href="/game/{{ $game->code }}/history"
           class="px-6 py-3 rounded-xl font-cinzel font-semibold text-sm transition-all hover:opacity-90"
           style="background-color:#111827;border:1px solid rgba(201,168,76,0.2);color:rgba(201,168,76,0.6);">
            📜 Historique
        </a>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script>
const IS_VILLAGE = {{ $isVillage ? 'true' : 'false' }};

document.addEventListener('DOMContentLoaded', () => {
    const tl = gsap.timeline();

    // Bannière : scale 0.7→1
    tl.to('#victory-banner', {
        opacity: 1,
        scale: 1,
        duration: 0.7,
        ease: 'back.out(1.4)',
    });

    // Particules
    tl.add(() => spawnParticles(), '-=0.2');

    // Joueurs en cascade
    tl.to('.player-card', {
        opacity: 1,
        y: 0,
        duration: 0.35,
        stagger: 0.1,
        ease: 'power2.out',
        from: { y: 20 },
    }, '+=0.3');

    // Boutons
    tl.to('#action-buttons', { opacity: 1, duration: 0.4 }, '-=0.1');
});

function spawnParticles() {
    const color  = IS_VILLAGE ? '#c9a84c' : '#8b0000';
    const color2 = IS_VILLAGE ? '#4ade80' : '#ff4444';
    const count  = 40;

    for (let i = 0; i < count; i++) {
        const el       = document.createElement('div');
        const useColor = Math.random() > 0.5 ? color : color2;
        const size     = 5 + Math.random() * 7;
        el.style.cssText = `
            position:fixed; width:${size}px; height:${size}px;
            border-radius:${Math.random() > 0.5 ? '50%' : '2px'};
            background-color:${useColor}; pointer-events:none; z-index:200;
            left:${10 + Math.random() * 80}vw; top:-20px; opacity:0.9;
        `;
        document.body.appendChild(el);

        gsap.to(el, {
            y: window.innerHeight + 40,
            x: (Math.random() - 0.5) * 250,
            rotation: Math.random() * 720,
            opacity: 0,
            duration: 1.8 + Math.random() * 2,
            delay: Math.random() * 1.2,
            ease: 'power1.in',
            onComplete: () => el.remove(),
        });
    }
}
</script>

</body>
</html>
