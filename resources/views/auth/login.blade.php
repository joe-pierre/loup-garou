<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loup-Garou Undu — Connexion</title>
    <meta name="description" content="Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.">
    @include('partials.pwa-head')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=EB+Garamond:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'EB Garamond', serif; background-color: #0a0f1e; }
        h1, h2, h3 { font-family: 'Cinzel', serif; }
        #login-card { opacity: 0; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center relative overflow-hidden"
      style="background-color: #0a0f1e;">

    {{-- Fond illustré flouté --}}
    <div class="absolute inset-0 bg-cover bg-center blur-sm opacity-20"
         style="background-image: url('/images/village-night.jpg');"></div>

    {{-- Overlay sombre --}}
    <div class="absolute inset-0" style="background-color: rgba(10,15,30,0.7);"></div>

    {{-- Carte centrale --}}
    <div id="login-card"
         class="relative z-10 w-full max-w-md mx-4 rounded-2xl p-8 flex flex-col items-center gap-6"
         style="background-color: rgba(10,15,30,0.9); border: 1px solid #c9a84c;">

        {{-- Logo / Titre --}}
        <div class="flex flex-col items-center gap-2">
            <svg class="w-14 h-14" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="28" cy="28" r="27" stroke="#c9a84c" stroke-width="2"/>
                <path d="M28 10 C20 18, 10 22, 14 34 C18 46, 38 46, 42 34 C46 22, 36 18, 28 10Z"
                      fill="#c9a84c" opacity="0.85"/>
                <circle cx="22" cy="30" r="2.5" fill="#0a0f1e"/>
                <circle cx="34" cy="30" r="2.5" fill="#0a0f1e"/>
            </svg>
            <h1 class="text-3xl font-bold tracking-wider" style="color: #c9a84c;">
                Loup-Garou Undu
            </h1>
            <p class="text-sm tracking-widest uppercase" style="color: #e8e0d0; opacity: 0.6;">
                Joue en ligne avec tes amis
            </p>
        </div>

        {{-- Message d'erreur flash --}}
        @if (session('error'))
            <div class="w-full rounded-lg px-4 py-3 text-sm text-center"
                 style="background-color: rgba(139,0,0,0.25); border: 1px solid #8b0000; color: #fca5a5;">
                {{ session('error') }}
            </div>
        @endif

        {{-- Bouton Google --}}
        <a href="{{ route('auth.google') }}"
           class="w-full flex items-center justify-center gap-3 px-6 py-3 rounded-lg font-medium text-base transition-transform hover:scale-[1.02] active:scale-[0.98]"
           style="background-color: #ffffff; color: #111827;">
            {{-- Logo SVG officiel Google --}}
            <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
            </svg>
            Continuer avec Google
        </a>

        <p class="text-xs text-center" style="color: #e8e0d0; opacity: 0.4;">
            En te connectant, tu acceptes les règles du jeu.
        </p>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script>
        gsap.fromTo('#login-card',
            { opacity: 0, y: 30 },
            { opacity: 1, y: 0, duration: 0.8, ease: 'power2.out' }
        );
    </script>
</body>
</html>
