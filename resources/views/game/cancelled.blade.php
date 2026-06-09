@extends('layouts.game')

@section('title', 'Loup-Garou Undu — Partie annulée')

@push('styles')
<style>
    body { background-color: #0a0f1e; }
    .cancelled-banner {
        background-color: #111827;
        border: 2px solid #f97316;
        border-radius: 1.25rem;
        text-align: center;
        padding: 2rem 1.5rem;
    }
    .player-row {
        background-color: #111827;
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 0.75rem;
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.75rem 1rem;
    }
    .avatar {
        width: 2.25rem; height: 2.25rem; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-family: 'Cinzel', serif; font-weight: 600;
        font-size: 0.75rem; flex-shrink: 0;
    }
    .avatar.active   { background: rgba(22,163,74,0.15); border: 1px solid rgba(22,163,74,0.4); color: #4ade80; }
    .avatar.inactive { background: rgba(107,114,128,0.15); border: 1px solid rgba(107,114,128,0.3); color: #6b7280; filter: grayscale(100%); }
    .entrance { opacity: 0; }
</style>
@endpush

@section('header-phase')
    <span class="text-xs font-medieval tracking-widest" style="color: rgba(249,115,22,0.7);">
        🏁 PARTIE ANNULÉE
    </span>
@endsection

@section('content')
<div class="max-w-xl mx-auto px-4 py-10">

    {{-- ══════════ BANNIÈRE ══════════ --}}
    <div class="cancelled-banner entrance mb-8" id="cancelled-banner">
        <p class="font-medieval text-2xl font-bold mb-2" style="color:#f97316;">🏁 PARTIE ANNULÉE</p>
        <p class="text-sm" style="color:rgba(232,224,208,0.5);">
            Trop de joueurs se sont déconnectés simultanément.
        </p>
        <p class="text-xs mt-2" style="color:rgba(249,115,22,0.5);">
            Partie {{ $game->code }}
            @if($game->finished_at)
                — {{ $game->finished_at->format('d/m/Y H:i') }}
            @endif
        </p>
    </div>

    {{-- ══════════ JOUEURS — rôles NON révélés ══════════ --}}
    <h2 class="font-medieval text-xs tracking-widest mb-3 text-center" style="color:rgba(249,115,22,0.5);">
        JOUEURS
    </h2>

    <div class="flex flex-col gap-2 mb-8" id="players-list">
        @foreach($allPlayers as $p)
        <div class="player-row entrance">
            <div class="avatar {{ $p->is_inactive ? 'inactive' : 'active' }}">
                {{ strtoupper(substr($p->pseudo, 0, 1)) }}
            </div>
            <div class="flex-1">
                <span class="text-sm font-medium" style="color:#e8e0d0;">{{ $p->pseudo }}</span>
                @if($p->is_mayor) <span class="text-xs ml-1">👑</span> @endif
            </div>
            <span class="text-xs px-2 py-0.5 rounded-full"
                  style="{{ $p->is_inactive ? 'background:rgba(107,114,128,0.15);color:#6b7280' : 'background:rgba(22,163,74,0.12);color:#4ade80' }}">
                {{ $p->is_inactive ? '💤 Inactif' : '✅ Connecté' }}
            </span>
        </div>
        @endforeach
    </div>

    {{-- ══════════ BOUTONS ══════════ --}}
    <div class="entrance flex gap-3 justify-center" id="action-buttons">
        <a href="/lobby?pseudo={{ urlencode($player->pseudo) }}"
           class="px-6 py-3 rounded-xl font-medieval font-semibold text-sm transition-all hover:opacity-90"
           style="background-color:#c9a84c;color:#0a0f1e;">
            🔄 Rejouer
        </a>
        <a href="/"
           class="px-6 py-3 rounded-xl font-medieval font-semibold text-sm transition-all hover:opacity-90"
           style="background-color:#111827;border:1px solid rgba(201,168,76,0.3);color:#c9a84c;">
            🏠 Accueil
        </a>
    </div>

</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        gsap.fromTo('.entrance',
            { opacity: 0, y: 20 },
            { opacity: 1, y: 0, duration: 0.5, stagger: 0.07, ease: 'power2.out' }
        );
    });
</script>
@endpush
