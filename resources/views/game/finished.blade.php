@extends('layouts.game')

@section('title', 'Loup-Garou Undu — Fin de partie')

@php
$isVillage = $game->winner_team === 'villagers';
$roleLabel = fn(?string $r) => match($r) {
    'villager' => '🏘 Villageois',
    'werewolf' => '🐺 Loup-Garou',
    'seer'     => '🔮 Voyante',
    default    => $r ?? '?',
};
$roleClass = fn(?string $r) => match($r) {
    'werewolf' => 'role-badge-werewolf',
    'seer'     => 'role-badge-seer',
    default    => 'role-badge-villager',
};
@endphp

@push('styles')
<style>
    body {
        font-family: 'Crimson Text', serif;
        background: radial-gradient(ellipse at 50% 20%, {{ $isVillage ? '#0a2e16' : '#2e0a0a' }} 0%, #030712 75%);
        color: #e8e0d0; min-height: 100vh;
    }
    .victory-banner {
        background-color: {{ $isVillage ? '#0f1a0a' : '#1a0000' }};
        border: 2px solid {{ $isVillage ? '#16a34a' : '#8b0000' }};
        border-radius: 1.25rem; text-align: center; padding: 2rem 1.5rem;
        opacity: 0; transform: scale(0.7);
    }
    .victory-title {
        font-family: 'Cinzel', serif;
        font-size: clamp(1.5rem, 5vw, 2.5rem); font-weight: 700;
        color: {{ $isVillage ? '#4ade80' : '#ff4444' }};
        letter-spacing: 0.05em;
    }
    .player-card {
        background-color: #111827; border: 1px solid rgba(201,168,76,0.2);
        border-radius: 0.75rem;
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.75rem 1rem; opacity: 0;
    }
    .avatar {
        width: 2.5rem; height: 2.5rem; border-radius: 50%;
        background-color: rgba(201,168,76,0.1); border: 1px solid rgba(201,168,76,0.3);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Cinzel', serif; font-weight: 600;
        font-size: 0.8rem; color: #c9a84c; flex-shrink: 0;
    }
    .avatar.dead { opacity: 0.45; filter: grayscale(100%); }
    .role-badge-villager { background: rgba(232,224,208,0.1); color: #e8e0d0; }
    .role-badge-werewolf { background: rgba(139,0,0,0.25); color: #f87171; }
    .role-badge-seer     { background: rgba(124,58,237,0.2); color: #a78bfa; }
    .btn-primary {
        background-color: #c9a84c;
        color: #0a0f1e;
        transition: background-color .3s, transform .2s;
    }
    .btn-primary:hover  { background-color: #e0c068; transform: translateY(-2px); }
    .btn-secondary {
        border: 1px solid #c9a84c;
        color: #c9a84c;
        background: transparent;
        transition: background-color .3s, transform .2s;
    }
    .btn-secondary:hover { background-color: rgba(201,168,76,.1); transform: translateY(-2px); }
    .entrance { opacity: 0; }
</style>
@endpush

@section('header-phase')
    <span class="text-xs font-medieval tracking-widest" style="color: rgba(201,168,76,0.7);">
        {{ $isVillage ? '🏆 VICTOIRE DU VILLAGE' : '🐺 VICTOIRE DES LOUPS' }}
    </span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto px-4 py-8 min-h-screen" style="color: #e8e0d0;">

    {{-- ══════════ BANNIÈRE VICTOIRE ══════════ --}}
    <div class="entrance text-center mb-8">
        <div class="text-7xl mb-4">
            {{ $isVillage ? '🏆' : '🐺' }}
        </div>
        <h1 class="font-title text-4xl sm:text-6xl mb-4"
            style="color:{{ $isVillage ? '#4ade80' : '#ff4444' }};">
            {{ $isVillage ? 'Le Village a gagné !' : 'Les Loups ont gagné !' }}
        </h1>
        <p class="text-lg italic"
           style="color:rgba(232,224,208,0.7); font-family:'Crimson Text',serif;">
            {{ $isVillage ? 'Tous les loups ont été démasqués.' : 'La meute règne sur le village.' }}
        </p>
        <p class="text-sm mt-3" style="color:rgba(232,224,208,0.4);">
            Partie {{ $game->code }}
            @if($game->finished_at)
                — {{ $game->finished_at->format('d/m/Y H:i') }}
            @endif
        </p>
    </div>

    {{-- ══════════ RÉVÉLATION JOUEURS ══════════ --}}
    <div class="entrance w-full max-w-2xl mx-auto mb-8">
        <table class="w-full text-left rounded-xl overflow-hidden"
               style="border:1px solid rgba(201,168,76,0.2);">
            <thead>
                <tr style="background-color:#0d1426;">
                    <th class="font-title text-sm px-4 py-3"
                        style="color:#c9a84c;">Joueur</th>
                    <th class="font-title text-sm px-4 py-3"
                        style="color:#c9a84c;">Rôle</th>
                    <th class="font-title text-sm px-4 py-3 text-right"
                        style="color:#c9a84c;">Statut</th>
                </tr>
            </thead>
            <tbody>
                @foreach($players as $p)
                @php
                    $roleColor = match($p->role) {
                        'werewolf' => '#ff4444',
                        'seer'     => '#a78bfa',
                        'villager' => '#4ade80',
                        default    => '#e8e0d0',
                    };
                    $roleLabel2 = match($p->role) {
                        'werewolf' => 'Loup-Garou',
                        'seer'     => 'Voyante',
                        'villager' => 'Villageois',
                        default    => $p->role,
                    };
                    $rowBg = $loop->even
                        ? 'background-color:#0d1426;'
                        : 'background-color:#111827;';
                @endphp
                <tr style="{{ $rowBg }}border-top:1px solid rgba(201,168,76,0.1);">
                    <td class="px-4 py-3 text-sm" style="color:#e8e0d0;">
                        {{ $p->pseudo }}
                        @if($p->is_mayor)
                            <span class="ml-1 text-xs" style="color:#c9a84c;">👑</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm font-semibold"
                        style="color:{{ $roleColor }};">
                        {{ $roleLabel2 }}
                    </td>
                    <td class="px-4 py-3 text-sm text-right"
                        style="color:{{ $p->is_alive ? '#4ade80' : '#ef4444' }};">
                        {{ $p->is_alive ? 'Vivant' : 'Mort 💀' }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ══════════ BOUTONS ══════════ --}}
    <div class="entrance flex gap-3 justify-center flex-wrap" id="action-buttons">
        <a href="/lobby?pseudo={{ urlencode($player->pseudo) }}"
           class="px-6 py-3 rounded-xl font-medieval font-semibold text-sm transition-all hover:opacity-90"
           style="background-color:#c9a84c;color:#0a0f1e;">
            🔄 Rejouer
        </a>
        <a href="/"
           class="px-6 py-3 rounded-xl font-medieval font-semibold text-sm transition-all hover:opacity-90"
           style="background-color:#111827;border:1px solid rgba(201,168,76,0.35);color:#c9a84c;">
            🏠 Accueil
        </a>
        <a href="/game/{{ $game->code }}/history"
           class="px-6 py-3 rounded-xl font-medieval font-semibold text-sm transition-all hover:opacity-90"
           style="background-color:#111827;border:1px solid rgba(201,168,76,0.2);color:rgba(201,168,76,0.6);">
            📜 Historique
        </a>
    </div>

</div>
@endsection

@push('scripts')
<script>
    const IS_VILLAGE = {{ $isVillage ? 'true' : 'false' }};

    document.addEventListener('DOMContentLoaded', () => {
        gsap.fromTo('.entrance',
            { opacity: 0, y: 50, scale: .95 },
            { opacity: 1, y: 0, scale: 1, duration: .9, ease: 'back.out(1.4)', stagger: .2, delay: .2,
              onStart: () => setTimeout(spawnParticles, 500) }
        );
    });

    function spawnParticles() {
        const color  = IS_VILLAGE ? '#c9a84c' : '#8b0000';
        const color2 = IS_VILLAGE ? '#4ade80' : '#ff4444';
        for (let i = 0; i < 40; i++) {
            const el   = document.createElement('div');
            const use  = Math.random() > 0.5 ? color : color2;
            const size = 5 + Math.random() * 7;
            el.style.cssText = `position:fixed;width:${size}px;height:${size}px;border-radius:${Math.random()>.5?'50%':'2px'};background-color:${use};pointer-events:none;z-index:200;left:${10+Math.random()*80}vw;top:-20px;opacity:.9;`;
            document.body.appendChild(el);
            gsap.to(el, {
                y: window.innerHeight + 40, x: (Math.random() - 0.5) * 250,
                rotation: Math.random() * 720, opacity: 0,
                duration: 1.8 + Math.random() * 2, delay: Math.random() * 1.2,
                ease: 'power1.in', onComplete: () => el.remove(),
            });
        }
    }
</script>
@endpush
