<!DOCTYPE html>
<html lang="fr" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Loup-Garou Undu')</title>
    <meta name="description" content="Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.">
    <meta name="csrf-token"   content="{{ csrf_token() }}">
    @isset($player)
    <meta name="player-id"    content="{{ $player->id }}">
    <meta name="player-pseudo" content="{{ $player->pseudo }}">
    <meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key', '') }}">
    @endisset
    {{-- GSAP (synchrone : disponible dans tous les handlers Alpine) --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    {{-- Vite bundle (inclut Alpine + gameState + timerState) --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
:root {
    --bg-main: #0a0f1e;
    --bg-panel: #111827;
    --gold: #c9a84c;
    --parchment: #e8e0d0;
    --blood: #8b0000;
}
.star {
    position: absolute;
    background: #fff;
    border-radius: 50%;
    opacity: 0.7;
    animation: twinkle 4s infinite ease-in-out;
}
@keyframes twinkle {
    0%, 100% { opacity: 0.2; }
    50%       { opacity: 0.9; }
}
.moon {
    position: absolute;
    top: 8%; left: 50%;
    transform: translateX(-50%);
    width: 110px; height: 110px;
    border-radius: 50%;
    background: radial-gradient(
        circle at 35% 35%,
        #f5e6a8, #c9a84c 65%, #8a6f2a
    );
    box-shadow:
        0 0 40px 10px rgba(201,168,76,.35),
        0 0 90px 30px rgba(201,168,76,.12);
}
.moon::after {
    content: "";
    position: absolute;
    top: 18%; left: 22%;
    width: 18px; height: 18px;
    border-radius: 50%;
    background: rgba(138,111,42,.4);
    box-shadow:
        34px 22px 0 -4px rgba(138,111,42,.35),
        12px 52px 0 2px rgba(138,111,42,.3);
}
.btn-secondary {
    border: 1px solid var(--gold);
    color: var(--gold);
    background: transparent;
    transition: background-color .3s, transform .2s;
}
.btn-secondary:hover {
    background-color: rgba(201,168,76,.1);
    transform: translateY(-2px);
}
.fog {
    position: absolute;
    bottom: 0; left: 0;
    width: 100%; height: 35%;
    background: linear-gradient(
        to top,
        rgba(3,7,18,0.95) 0%,
        rgba(3,7,18,0.5) 40%,
        rgba(3,7,18,0) 100%
    );
    pointer-events: none;
}
    </style>
    @stack('styles')
</head>
<body class="h-full bg-night-deep text-parchment font-body">

    {{-- ═══════════════ HEADER FIXE ═══════════════ --}}
    <header
        class="fixed top-0 left-0 right-0 z-40 h-14 flex items-center justify-between px-4 md:px-6 border-b"
        style="background-color:#111827; border-color:rgba(201,168,76,0.2);"
        role="banner"
    >
        {{-- Logo --}}
        <div class="flex items-center gap-2 flex-shrink-0">
            <span class="text-xl" aria-hidden="true">🐺</span>
            <span class="font-medieval font-bold text-gold hidden sm:inline text-sm tracking-wide">
                Loup‑Garou Undu
            </span>
        </div>

        {{-- Phase + round (injecté par la vue enfant) --}}
        <div class="flex items-center gap-2 text-xs font-medieval" id="header-phase">
            @yield('header-phase')
        </div>

        {{-- Joueur courant --}}
        <div class="flex items-center gap-2 flex-shrink-0">
            @isset($player)
            <div
                class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-medieval font-bold flex-shrink-0 focus:ring-2 focus:ring-gold"
                style="background-color:rgba(201,168,76,0.12); border:1px solid rgba(201,168,76,0.3); color:#c9a84c;"
                aria-label="Joueur : {{ $player->pseudo }}"
            >
                {{ strtoupper(substr($player->pseudo, 0, 1)) }}
            </div>
            <span class="text-sm hidden md:inline" style="color:rgba(232,224,208,0.65);">
                {{ $player->pseudo }}
            </span>
            @endisset
        </div>
    </header>

    {{-- ═══════════════ CONTENU PRINCIPAL ═══════════════ --}}
    {{-- gameState est monté sur un div fantôme hors du flux des vues --}}
    {{-- pour éviter que son scope Alpine pollue les x-data locaux (nightScreen, dayScreen) --}}
    @isset($game)
    <div
        id="game-state-root"
        x-data="gameState({{ $game->id }}, {{ auth()->id() }})"
        data-game-code="{{ $game->code }}"
        data-player-id="{{ $player->id ?? 0 }}"
        style="visibility:hidden;position:absolute;width:0;height:0;overflow:hidden;"
        aria-hidden="true"
    ></div>
    @endisset
    <main class="pt-14 min-h-screen pb-16 md:pb-0" role="main" id="main-content">
        @yield('content')
    </main>

    {{-- ═══════════════ FOOTER NAV MOBILE ═══════════════ --}}
    <nav
        class="md:hidden fixed bottom-0 left-0 right-0 z-40 h-14 flex items-stretch border-t"
        style="background-color:#111827; border-color:rgba(201,168,76,0.15);"
        role="navigation"
        aria-label="Navigation mobile"
    >
        @yield('footer-nav')
    </nav>

    {{-- ═══════════════ TOAST GLOBAL ═══════════════ --}}
    <x-toast />

    <script>
        @isset($game)
        window.gameId   = {{ $game->id }};
        window.GAME_ID  = {{ $game->id }};
        window.GAME_CODE = '{{ $game->code }}';
        @endisset
        @isset($player)
        window.playerId     = {{ $player->id }};
        window.MY_PLAYER_ID = {{ $player->id }};
        @endisset
    </script>
    @stack('scripts')
</body>
</html>