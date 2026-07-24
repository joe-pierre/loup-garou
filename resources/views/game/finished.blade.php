@extends('layouts.game')

@section('title', 'Loup-Garou Undu — Fin de partie')

@php
// Thème à 3 branches dérivé de winner_team (villagers/werewolves/lovers) — 'lovers'
// (SPEC_CUPIDON.md §6) retombait auparavant sur le thème "Loups" par défaut d'un
// simple booléen $isVillage. Couleur amoureux : rose #f472b6, déjà standardisée
// pour Cupidon dans tout le projet (DECISIONS.md "Cupidon absent de toutes les
// tables rôle→icône/couleur/label"), jamais réinventée ici.
$theme = match ($game->winner_team) {
    'villagers' => [
        'bg_gradient'  => '#0a2e16',
        'banner_bg'    => '#0f1a0a',
        'border'       => '#16a34a',
        'title_color'  => '#4ade80',
        'emoji'        => '🏆',
        'title'        => 'Le Village a gagné !',
        'subtitle'     => 'Tous les loups ont été démasqués.',
        'header_label' => '🏆 VICTOIRE DU VILLAGE',
        'confetti1'    => '#c9a84c',
        'confetti2'    => '#4ade80',
    ],
    'lovers' => [
        'bg_gradient'  => '#2e0a1e',
        'banner_bg'    => '#1a0012',
        'border'       => '#f472b6',
        'title_color'  => '#f472b6',
        'emoji'        => '💞',
        'title'        => 'Les Amoureux ont gagné !',
        'subtitle'     => 'Envers et contre tout, leur amour a triomphé.',
        'header_label' => '💞 VICTOIRE DES AMOUREUX',
        'confetti1'    => '#c9a84c',
        'confetti2'    => '#f472b6',
    ],
    default => [ // 'werewolves'
        'bg_gradient'  => '#2e0a0a',
        'banner_bg'    => '#1a0000',
        'border'       => '#8b0000',
        'title_color'  => '#ff4444',
        'emoji'        => '🐺',
        'title'        => 'Les Loups ont gagné !',
        'subtitle'     => 'La meute règne sur le village.',
        'header_label' => '🐺 VICTOIRE DES LOUPS',
        'confetti1'    => '#8b0000',
        'confetti2'    => '#ff4444',
    ],
};
$roleLabel = fn(?string $r) => match($r) {
    'villager' => '🧑‍🌾 Villageois',
    'werewolf' => '🐺 Loup-Garou',
    'seer'     => '🔮 Voyante',
    'witch'    => '🧙‍♀️ Sorcière',
    'hunter'   => '🏹 Chasseur',
    'cupidon'  => '💘 Cupidon',
    default    => $r ?? '?',
};
$roleClass = fn(?string $r) => match($r) {
    'werewolf' => 'role-badge-werewolf',
    'seer'     => 'role-badge-seer',
    'witch'    => 'role-badge-witch',
    'hunter'   => 'role-badge-hunter',
    'cupidon'  => 'role-badge-cupidon',
    default    => 'role-badge-villager',
};
@endphp

@push('styles')
<style>
    body {
        font-family: 'EB Garamond', serif;
        background: radial-gradient(ellipse at 50% 20%, {{ $theme['bg_gradient'] }} 0%, #030712 75%);
        color: #e8e0d0; min-height: 100vh;
    }
    .victory-banner {
        background-color: {{ $theme['banner_bg'] }};
        border: 2px solid {{ $theme['border'] }};
        border-radius: 1.25rem; text-align: center; padding: 2rem 1.5rem;
        opacity: 0; transform: scale(0.7);
    }
    .victory-title {
        font-family: 'Cinzel', serif;
        font-size: clamp(1.5rem, 5vw, 2.5rem); font-weight: 700;
        color: {{ $theme['title_color'] }};
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
    .role-badge-witch    { background: rgba(52,147,211,0.2); color: #3493d3; }
    .role-badge-hunter   { background: rgba(247,191,36,0.2); color: #fbbf24; }
    .role-badge-cupidon  { background: rgba(244,114,182,0.2); color: #f472b6; }
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
        {{ $theme['header_label'] }}
    </span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto px-4 py-8 min-h-screen" style="color: #e8e0d0;">

    {{-- ══════════ BANNIÈRE VICTOIRE ══════════ --}}
    <div class="entrance text-center mb-8">
        <div class="text-7xl mb-4">
            {{ $theme['emoji'] }}
        </div>
        <h1 class="font-title text-4xl sm:text-6xl mb-4"
            style="color:{{ $theme['title_color'] }};">
            {{ $theme['title'] }}
        </h1>
        <p class="text-lg italic"
           style="color:rgba(232,224,208,0.7); font-family:'EB Garamond',serif;">
            {{ $theme['subtitle'] }}
        </p>
        <p class="text-sm mt-3" style="color:rgba(232,224,208,0.4);">
            Partie {{ $game->code }}
            @if($game->finished_at)
                — {{ $game->finished_at->format('d/m/Y H:i') }}
            @endif
        </p>
    </div>

    {{-- ══════════ RÉVÉLATION JOUEURS ══════════ --}}
    <div class="entrance w-full max-w-2xl mx-auto mb-8 overflow-x-auto">
        <table class="w-full text-left rounded-xl overflow-hidden"
               style="border:1px solid rgba(201,168,76,0.2);">
            <thead>
                <tr style="background-color:#0d1426;">
                    <th class="font-title text-sm px-2 sm:px-4 py-3"
                        style="color:#c9a84c;">Joueur</th>
                    <th class="font-title text-sm px-2 sm:px-4 py-3"
                        style="color:#c9a84c;">Rôle</th>
                    <th class="font-title text-sm px-2 sm:px-4 py-3 text-right"
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
                        'witch'    => '#3493d3',
                        'hunter'   => '#fbbf24',
                        'cupidon'  => '#f472b6',
                        default    => '#e8e0d0',
                    };
                    $roleLabel2 = match($p->role) {
                        'werewolf' => 'Loup-Garou',
                        'seer'     => 'Voyante',
                        'villager' => 'Villageois',
                        'witch'    => 'Sorcière',
                        'hunter'   => 'Chasseur',
                        'cupidon'  => 'Cupidon',
                        default    => $p->role,
                    };
                    $rowBg = $loop->even
                        ? 'background-color:#0d1426;'
                        : 'background-color:#111827;';
                @endphp
                <tr style="{{ $rowBg }}border-top:1px solid rgba(201,168,76,0.1);">
                    <td class="px-2 sm:px-4 py-3 text-sm" style="color:#e8e0d0;">
                        {{ $p->pseudo }}
                        @if($p->is_mayor)
                            <span class="ml-1 text-xs" style="color:#c9a84c;">👑</span>
                        @endif
                    </td>
                    <td class="px-2 sm:px-4 py-3 text-sm font-semibold"
                        style="color:{{ $roleColor }};">
                        {{ $roleLabel2 }}
                    </td>
                    <td class="px-2 sm:px-4 py-3 text-sm text-right"
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
    const CONFETTI_COLOR_1 = '{{ $theme['confetti1'] }}';
    const CONFETTI_COLOR_2 = '{{ $theme['confetti2'] }}';

    document.addEventListener('DOMContentLoaded', () => {
        gsap.fromTo('.entrance',
            { opacity: 0, y: 50, scale: .95 },
            { opacity: 1, y: 0, scale: 1, duration: .9, ease: 'back.out(1.4)', stagger: .2, delay: .2,
              onStart: () => setTimeout(spawnParticles, 500) }
        );
    });

    function spawnParticles() {
        const color  = CONFETTI_COLOR_1;
        const color2 = CONFETTI_COLOR_2;
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
