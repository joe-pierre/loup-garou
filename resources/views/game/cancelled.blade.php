<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loup-Garou Undu — Partie annulée</title>
    <meta name="description" content="Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=EB+Garamond:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'EB Garamond', serif; background-color: #0a0f1e; color: #e8e0d0; }
        h1, h2, .font-cinzel { font-family: 'Cinzel', serif; }

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
    </style>
</head>
<body class="min-h-screen">

<div class="max-w-xl mx-auto px-4 py-10">

    {{-- ══════════ BANNIÈRE ══════════ --}}
    <div class="cancelled-banner mb-8" id="cancelled-banner" style="opacity:0">
        <p class="font-cinzel text-2xl font-bold mb-2" style="color:#f97316;">🏁 PARTIE ANNULÉE</p>
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
    <h2 class="font-cinzel text-xs tracking-widest mb-3 text-center" style="color:rgba(249,115,22,0.5);">
        JOUEURS
    </h2>

    <div class="flex flex-col gap-2 mb-8" id="players-list">
        @foreach($allPlayers as $p)
        <div class="player-row" style="opacity:0">
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
    <div class="flex gap-3 justify-center" id="action-buttons" style="opacity:0">
        <a href="/lobby?pseudo={{ urlencode($player->pseudo) }}"
           class="px-6 py-3 rounded-xl font-cinzel font-semibold text-sm transition-all hover:opacity-90"
           style="background-color:#c9a84c;color:#0a0f1e;">
            🔄 Rejouer
        </a>
        <a href="/"
           class="px-6 py-3 rounded-xl font-cinzel font-semibold text-sm transition-all hover:opacity-90"
           style="background-color:#111827;border:1px solid rgba(201,168,76,0.3);color:#c9a84c;">
            🏠 Accueil
        </a>
    </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const tl = gsap.timeline({ defaults: { ease: 'power2.out' } });

    tl.to('#cancelled-banner', { opacity: 1, y: 0, duration: 0.5, from: { y: -15 } })
      .to('.player-row',       { opacity: 1, x: 0, stagger: 0.07, duration: 0.3, from: { x: -10 } }, '+=0.2')
      .to('#action-buttons',   { opacity: 1, duration: 0.4 }, '-=0.1');
});
</script>

</body>
</html>
