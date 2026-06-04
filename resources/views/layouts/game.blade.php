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
    {{-- Alpine.js (defer = disponible avant DOMContentLoaded) --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    {{-- GSAP --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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

    @stack('scripts')
</body>
</html>
