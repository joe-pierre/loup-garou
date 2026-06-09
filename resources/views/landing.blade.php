<!DOCTYPE html>
<html lang="fr" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loup-Garou Undu — Joue en ligne avec tes amis</title>
    <meta name="description" content="Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.">
    {{-- SEO / Open Graph --}}
    <meta property="og:title"       content="Loup-Garou Undu — Joue en ligne avec tes amis">
    <meta property="og:description" content="Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.">
    <meta property="og:type"        content="website">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- GSAP + ScrollTrigger via CDN (chargés avant landing.js) --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
    @vite(['resources/css/app.css', 'resources/js/landing.js'])
    <style>
        html { background-color: #0a0f1e; }
        .anim-hidden { opacity: 0; }
    </style>
</head>
<body class="bg-night-deep text-parchment font-body overflow-x-hidden">

    {{-- ════════════════════════════════════════════════════════════════
         NAV FIXE
    ═══════════════════════════════════════════════════════════════════ --}}
    <nav class="fixed top-0 left-0 right-0 z-50 flex items-center justify-between px-6 py-4"
         style="background:linear-gradient(to bottom, rgba(10,15,30,0.95) 0%, transparent 100%);">
        <div class="flex items-center gap-2">
            <span class="text-xl" aria-hidden="true">🐺</span>
            <span class="font-medieval font-bold text-gold text-sm tracking-widest hidden sm:inline">
                LOUP-GAROU UNDU
            </span>
        </div>
        @auth
        <a href="/lobby"
           class="px-4 py-2 rounded-lg font-medieval text-xs font-semibold transition-all hover:opacity-80 focus:ring-2 focus:ring-gold focus:outline-none"
           style="background-color:rgba(201,168,76,0.15); border:1px solid rgba(201,168,76,0.35); color:#c9a84c;">
            Jouer →
        </a>
        @else
        <a href="/auth/google"
           class="px-4 py-2 rounded-lg font-medieval text-xs font-semibold transition-all hover:opacity-80 focus:ring-2 focus:ring-gold focus:outline-none"
           style="background-color:rgba(201,168,76,0.12); border:1px solid rgba(201,168,76,0.3); color:#c9a84c;">
            Se connecter
        </a>
        @endauth
    </nav>

    {{-- ════════════════════════════════════════════════════════════════
         SECTION 1 — HERO
    ═══════════════════════════════════════════════════════════════════ --}}
    <section id="hero" class="relative min-h-screen flex items-center justify-center overflow-hidden">

        {{-- Fond atmosphérique (placeholder sans image) --}}
        <div id="hero-bg"
             class="absolute inset-0 origin-center"
             aria-hidden="true"
             style="
                 background:
                     radial-gradient(ellipse 80% 60% at 50% 40%, rgba(201,168,76,0.04) 0%, transparent 70%),
                     radial-gradient(ellipse 120% 80% at 30% 80%, rgba(124,58,237,0.06) 0%, transparent 60%),
                     linear-gradient(180deg, #030712 0%, #0a0f1e 40%, #0d1020 100%);
             ">
            {{-- Étoiles simulées (SVG inline léger) --}}
            <svg class="absolute inset-0 w-full h-full opacity-40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                @for($i = 0; $i < 80; $i++)
                @php
                    $x  = rand(0, 100);
                    $y  = rand(0, 100);
                    $r  = round(rand(1, 3) * 0.4, 1);
                    $op = round(rand(3, 9) * 0.1, 1);
                @endphp
                <circle cx="{{ $x }}%" cy="{{ $y }}%" r="{{ $r }}" fill="white" opacity="{{ $op }}"/>
                @endfor
            </svg>

            {{-- Lune --}}
            <div class="absolute top-24 right-[12%] w-20 h-20 rounded-full opacity-60"
                 style="background:radial-gradient(circle at 35% 35%, #e8e0d0, #c9a84c50);
                        box-shadow: 0 0 40px rgba(201,168,76,0.15);"></div>

            {{-- Silhouette village (CSS) --}}
            <div class="absolute bottom-0 left-0 right-0 flex items-end justify-center gap-1 opacity-20 pointer-events-none">
                @foreach([32,48,28,60,36,52,24,44,30,56,38] as $h)
                <div class="flex-shrink-0 w-8 bg-current"
                     style="height:{{ $h }}px; color:#1a2340; border-radius:2px 2px 0 0;"></div>
                @endforeach
            </div>
        </div>

        {{-- Overlay gradient bas --}}
        <div class="absolute bottom-0 left-0 right-0 h-48 pointer-events-none"
             style="background:linear-gradient(to bottom, transparent, #0a0f1e);"
             aria-hidden="true"></div>

        {{-- Contenu hero --}}
        <div id="hero-content" class="relative z-10 text-center px-4 max-w-3xl mx-auto">
            <p id="hero-eyebrow"
               class="font-medieval text-xs tracking-[0.35em] mb-4 anim-hidden"
               style="color:rgba(201,168,76,0.6);">
                MULTIJOUEUR EN TEMPS RÉEL
            </p>

            <h1 id="hero-title"
                class="font-medieval font-bold mb-6 leading-none anim-hidden"
                style="font-size:clamp(3rem,10vw,7rem); color:#c9a84c;
                       text-shadow: 0 0 60px rgba(201,168,76,0.3);">
                🐺 LOUP-GAROU
            </h1>

            <p id="hero-sub"
               class="text-xl md:text-2xl italic mb-10 anim-hidden"
               style="color:rgba(232,224,208,0.75);">
                Le village a peur la nuit.
            </p>

            <div id="hero-cta" class="anim-hidden">
                @auth
                <a href="/lobby"
                   class="inline-block px-10 py-4 rounded-2xl font-medieval font-bold text-base
                          transition-all duration-200 hover:scale-105 hover:shadow-gold-lg
                          focus:ring-2 focus:ring-gold focus:outline-none"
                   style="background-color:#c9a84c; color:#0a0f1e;
                          box-shadow: 0 0 20px rgba(201,168,76,0.3);">
                    Jouer maintenant
                </a>
                @else
                <a href="/auth/google"
                   class="inline-block px-10 py-4 rounded-2xl font-medieval font-bold text-base
                          transition-all duration-200 hover:scale-105 hover:shadow-gold-lg
                          focus:ring-2 focus:ring-gold focus:outline-none"
                   style="background-color:#c9a84c; color:#0a0f1e;
                          box-shadow: 0 0 20px rgba(201,168,76,0.3);">
                    Jouer maintenant
                </a>
                <p class="mt-3 text-xs" style="color:rgba(232,224,208,0.4);">
                    Connexion Google requise — gratuit
                </p>
                @endauth
            </div>
        </div>

        {{-- Indicateur scroll --}}
        <div class="absolute bottom-8 left-1/2 -translate-x-1/2 flex flex-col items-center gap-1"
             style="color:rgba(201,168,76,0.35);" aria-hidden="true">
            <span class="text-xs font-medieval tracking-widest">DÉFILER</span>
            <svg class="w-4 h-4 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         SECTION 2 — COMMENT ÇA MARCHE
    ═══════════════════════════════════════════════════════════════════ --}}
    <section id="section-explain" class="py-24 px-4">
        <div class="max-w-4xl mx-auto">
            <h2 class="font-medieval text-center text-2xl font-semibold mb-12"
                style="color:rgba(201,168,76,0.8);">
                Comment jouer ?
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                @php
                    $blocks = [
                        ['🌙', 'Phase Nuit',     'Les loups-garous se réveillent et choisissent leur victime. La voyante inspecte un joueur en secret.',       'rgba(124,58,237,0.15)', 'rgba(124,58,237,0.4)'],
                        ['☀️', 'Phase Jour',     'Le village délibère et vote pour éliminer un suspect. Méfiez-vous des loups qui se cachent parmi vous !', 'rgba(201,168,76,0.1)',  'rgba(201,168,76,0.3)'],
                        ['🏆', 'Village gagne', 'Éliminez tous les loups-garous avant qu\'il ne soit trop tard. La vérité éclatera au grand jour.',           'rgba(22,163,74,0.12)',  'rgba(22,163,74,0.4)'],
                        ['🐺', 'Loups gagnent', 'Les loups doivent être aussi nombreux que les villageois. Semer le doute est leur meilleure arme.',          'rgba(139,0,0,0.18)',    'rgba(139,0,0,0.45)'],
                    ];
                @endphp

                @foreach($blocks as [$icon, $title, $desc, $bg, $border])
                <div class="explain-block rounded-2xl p-6 anim-hidden"
                     style="background-color:{{ $bg }}; border:1px solid {{ $border }};">
                    <div class="text-3xl mb-3" aria-hidden="true">{{ $icon }}</div>
                    <h3 class="font-medieval font-semibold text-base mb-2"
                        style="color:#e8e0d0;">{{ $title }}</h3>
                    <p class="text-sm leading-relaxed" style="color:rgba(232,224,208,0.65);">
                        {{ $desc }}
                    </p>
                </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         SECTION 3 — RÔLES
    ═══════════════════════════════════════════════════════════════════ --}}
    <section id="section-roles" class="py-24 px-4"
             style="background:linear-gradient(180deg, #0a0f1e 0%, #080c18 50%, #0a0f1e 100%);">
        <div class="max-w-5xl mx-auto">
            <h2 class="font-medieval text-center text-2xl font-semibold mb-3"
                style="color:rgba(201,168,76,0.8);">
                Les rôles
            </h2>
            <p class="text-center text-sm mb-12" style="color:rgba(232,224,208,0.45);">
                Chaque joueur reçoit un rôle secret au début de la partie.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">

                {{-- Loup-Garou --}}
                <div class="role-card rounded-2xl overflow-hidden anim-hidden"
                     style="background:linear-gradient(160deg, #2d0a0a 0%, #1a0000 100%);
                            border:1px solid rgba(139,0,0,0.45);">
                    <div class="h-28 flex items-center justify-center text-5xl"
                         style="background:rgba(139,0,0,0.2);" aria-hidden="true">🐺</div>
                    <div class="p-4">
                        <h3 class="font-medieval font-bold text-sm mb-1" style="color:#f87171;">Loup-Garou</h3>
                        <p class="text-xs leading-relaxed" style="color:rgba(232,224,208,0.6);">
                            Chaque nuit, éliminez un villageois sans vous trahir le jour. Restez discrets pour survivre.
                        </p>
                    </div>
                </div>

                {{-- Voyante --}}
                <div class="role-card rounded-2xl overflow-hidden anim-hidden"
                     style="background:linear-gradient(160deg, #1e0b3a 0%, #150828 100%);
                            border:1px solid rgba(124,58,237,0.4);">
                    <div class="h-28 flex items-center justify-center text-5xl"
                         style="background:rgba(124,58,237,0.15);" aria-hidden="true">🔮</div>
                    <div class="p-4">
                        <h3 class="font-medieval font-bold text-sm mb-1" style="color:#a78bfa;">Voyante</h3>
                        <p class="text-xs leading-relaxed" style="color:rgba(232,224,208,0.6);">
                            Chaque nuit, inspectez le rôle secret d'un joueur. Guidez le village sans vous dévoiler.
                        </p>
                    </div>
                </div>

                {{-- Villageois --}}
                <div class="role-card rounded-2xl overflow-hidden anim-hidden"
                     style="background:linear-gradient(160deg, #0b2010 0%, #071409 100%);
                            border:1px solid rgba(22,163,74,0.4);">
                    <div class="h-28 flex items-center justify-center text-5xl"
                         style="background:rgba(22,163,74,0.12);" aria-hidden="true">🪓</div>
                    <div class="p-4">
                        <h3 class="font-medieval font-bold text-sm mb-1" style="color:#4ade80;">Villageois</h3>
                        <p class="text-xs leading-relaxed" style="color:rgba(232,224,208,0.6);">
                            Votez avec sagesse pour éliminer les loups. Votre force, c'est le nombre et la raison.
                        </p>
                    </div>
                </div>

                {{-- Maire --}}
                <div class="role-card rounded-2xl overflow-hidden anim-hidden"
                     style="background:linear-gradient(160deg, #2a1f00 0%, #1a1400 100%);
                            border:1px solid rgba(201,168,76,0.45);">
                    <div class="h-28 flex items-center justify-center text-5xl"
                         style="background:rgba(201,168,76,0.1);" aria-hidden="true">👑</div>
                    <div class="p-4">
                        <h3 class="font-medieval font-bold text-sm mb-1" style="color:#c9a84c;">Maire</h3>
                        <p class="text-xs leading-relaxed" style="color:rgba(232,224,208,0.6);">
                            Élu en début de partie, votre vote compte double. En cas de mort, désignez votre successeur.
                        </p>
                    </div>
                </div>

            </div>
        </div>
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         SECTION 4 — CTA FINALE
    ═══════════════════════════════════════════════════════════════════ --}}
    <section id="section-cta" class="py-16 px-4 text-center">
        <p class="font-medieval text-xs tracking-widest mb-5"
           style="color:rgba(201,168,76,0.5);">
            🎲 6 JOUEURS MINIMUM · ⏱ ~15 MINUTES PAR PARTIE
        </p>

        <h2 class="font-medieval font-bold mb-8 leading-tight"
            style="font-size:clamp(1.75rem,5vw,3rem); color:#e8e0d0;">
            Prêt à rejoindre le village ?
        </h2>

        @auth
        <a href="/lobby"
           class="inline-block px-6 sm:px-12 py-4 rounded-2xl font-medieval font-bold text-base mb-4
                  transition-all duration-200 hover:scale-105 hover:shadow-gold-lg
                  focus:ring-2 focus:ring-gold focus:outline-none"
           style="background-color:#c9a84c; color:#0a0f1e;
                  box-shadow:0 0 25px rgba(201,168,76,0.25);">
            Créer une partie
        </a>
        @else
        <a href="/auth/google"
           class="inline-flex items-center gap-3 px-6 sm:px-12 py-4 rounded-2xl font-medieval font-bold text-base mb-4
                  transition-all duration-200 hover:scale-105 hover:shadow-gold-lg
                  focus:ring-2 focus:ring-gold focus:outline-none"
           style="background-color:#c9a84c; color:#0a0f1e;
                  box-shadow:0 0 25px rgba(201,168,76,0.25);">
            <svg class="w-5 h-5" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="#0a0f1e" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#0a0f1e" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#0a0f1e" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#0a0f1e" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Connexion avec Google
        </a>
        <p class="text-xs" style="color:rgba(232,224,208,0.4);">
            Connexion avec Google requise · Gratuit
        </p>
        @endauth
    </section>

    {{-- ════════════════════════════════════════════════════════════════
         FOOTER
    ═══════════════════════════════════════════════════════════════════ --}}
    <footer class="py-8 px-4 text-center border-t" style="border-color:rgba(201,168,76,0.1);">
        <p class="text-xs" style="color:rgba(232,224,208,0.3);">
            Loup-Garou Undu — Jeu de société en ligne · v1.1
        </p>
    </footer>

</body>
</html>
