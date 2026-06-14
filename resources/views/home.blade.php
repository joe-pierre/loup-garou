<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Loup-Garou Undu</title>

  <!-- Tailwind CDN -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- GSAP + ScrollTrigger -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>

  <!-- Alpine.js -->
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700;900&family=EB+Garamond:ital,wght@0,400;0,500;1,400&display=swap"
    rel="stylesheet"
  />

  <style>
    :root {
      --bg-main: #0a0f1e;
      --bg-panel: #111827;
      --gold: #c9a84c;
      --parchment: #e8e0d0;
      --blood: #8b0000;
    }

    body {
      background-color: var(--bg-main);
      color: var(--parchment);
      font-family: "EB Garamond", serif;
      overflow-x: hidden;
    }

    .font-title {
      font-family: "Cinzel", serif;
    }

    /* ===== HERO : dégradé radial sombre ===== */
    .hero-bg {
      background: radial-gradient(
        ellipse at 50% 30%,
        #1a0a2e 0%,
        #0a0f1e 70%
      );
    }

    /* ===== LUNE en CSS pur ===== */
    .moon {
      position: absolute;
      top: 8%;
      right: 12%;
      width: 130px;
      height: 130px;
      border-radius: 50%;
      background: radial-gradient(circle at 35% 35%, #f5e6a8, #c9a84c 65%, #8a6f2a);
      box-shadow:
        0 0 40px 10px rgba(201, 168, 76, 0.4),
        0 0 90px 30px rgba(201, 168, 76, 0.15);
    }
    .moon::after {
      content: "";
      position: absolute;
      top: 18%;
      left: 22%;
      width: 22px;
      height: 22px;
      border-radius: 50%;
      background: rgba(138, 111, 42, 0.4);
      box-shadow:
        40px 25px 0 -4px rgba(138, 111, 42, 0.35),
        15px 60px 0 2px rgba(138, 111, 42, 0.3);
    }

    /* ===== ÉTOILES ===== */
    .star {
      position: absolute;
      background: #fff;
      border-radius: 50%;
      opacity: 0.7;
      animation: twinkle 4s infinite ease-in-out;
    }
    @keyframes twinkle {
      0%, 100% { opacity: 0.2; }
      50% { opacity: 0.9; }
    }

    /* ===== BRUME / FOG en bas ===== */
    .fog {
      position: absolute;
      bottom: 0;
      left: 0;
      width: 100%;
      height: 35%;
      background: linear-gradient(
        to top,
        rgba(10, 15, 30, 0.95) 0%,
        rgba(10, 15, 30, 0.5) 40%,
        rgba(10, 15, 30, 0) 100%
      );
      pointer-events: none;
    }

    /* ===== BOUTONS ===== */
    .btn-primary {
      background-color: var(--gold);
      color: var(--bg-main);
      transition: background-color 0.3s ease, transform 0.2s ease;
    }
    .btn-primary:hover {
      background-color: #e0c068;
      transform: translateY(-2px);
    }
    .btn-secondary {
      border: 1px solid var(--gold);
      color: var(--gold);
      background: transparent;
      transition: background-color 0.3s ease, transform 0.2s ease;
    }
    .btn-secondary:hover {
      background-color: rgba(201, 168, 76, 0.1);
      transform: translateY(-2px);
    }

    /* ===== CARTES RÔLES : flip effect ===== */
    .flip-card {
      perspective: 1000px;
      height: 280px;
    }
    .flip-inner {
      position: relative;
      width: 100%;
      height: 100%;
      transition: transform 0.6s ease;
      transform-style: preserve-3d;
    }
    .flip-card:hover .flip-inner {
      transform: rotateY(180deg);
    }
    .flip-face {
      position: absolute;
      inset: 0;
      backface-visibility: hidden;
      -webkit-backface-visibility: hidden;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
      border-radius: 8px;
      background-color: var(--bg-panel);
    }
    .flip-back {
      transform: rotateY(180deg);
    }

    /* ===== PARTICULES CTA ===== */
    .particle {
      position: absolute;
      width: 3px;
      height: 3px;
      background: var(--gold);
      border-radius: 50%;
      opacity: 0.6;
      pointer-events: none;
    }
  </style>
</head>

<body>
    <nav class="fixed top-0 left-0 right-0 z-50 flex items-center justify-between px-6 py-4" style="background:linear-gradient(to bottom, rgba(10,15,30,0.95) 0%, transparent 100%);">
        <div class="flex items-center gap-2">
            <span class="text-xl" aria-hidden="true">🐺</span>
            <span class="font-medieval font-bold text-gold text-sm tracking-widest hidden sm:inline">
                LOUP-GAROU UNDU
            </span>
        </div>
        <a href="{{ route('auth.google') }}" class="px-4 py-2 rounded-lg font-medieval text-xs font-semibold transition-all hover:opacity-80 focus:ring-2 focus:ring-gold focus:outline-none" style="background-color:rgba(201,168,76,0.12); border:1px solid rgba(201,168,76,0.3); color:#c9a84c;">
            Se connecter
        </a>
    </nav>

  <!-- ============================================= -->
  <!-- 1. HERO SECTION                                -->
  <!-- ============================================= -->
  <section class="hero-bg relative h-screen flex flex-col items-center justify-center text-center px-4 overflow-hidden">
    <!-- Lune -->
    <div class="moon"></div>

    <!-- Étoiles (générées en JS) -->
    <div id="stars" class="absolute inset-0"></div>

    <!-- Brume -->
    <div class="fog"></div>

    <!-- Contenu -->
    <div class="relative z-10 max-w-3xl">
      <h1
        id="hero-title"
        class="font-title font-black text-5xl sm:text-6xl lg:text-7xl tracking-wide"
        style="color: var(--gold)"
      >
        🐺 LOUP-GAROU UNDU
      </h1>
      <p
        id="hero-subtitle"
        class="mt-6 text-xl sm:text-2xl italic"
        style="color: var(--parchment)"
      >
        Le village a peur la nuit
      </p>

      <div id="hero-buttons" class="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
        @guest
        <a
          href="{{ route('auth.google') }}"
          class="btn-primary font-title font-semibold px-8 py-3 rounded-md tracking-wide"
        >
          Jouer maintenant
        </a>
        @endguest
        @auth
        <a
          href="{{ route('lobby') }}"
          class="btn-primary font-title font-semibold px-8 py-3 rounded-md tracking-wide"
        >
          Jouer maintenant
        </a>
        @endauth
        <a
          href="#concept"
          class="btn-secondary font-title font-semibold px-8 py-3 rounded-md tracking-wide"
        >
          Comment jouer ?
        </a>
      </div>
    </div>
  </section>

  <!-- ============================================= -->
  <!-- 2. SECTION "C'EST QUOI LE JEU ?"               -->
  <!-- ============================================= -->
  <section id="concept" class="py-24 px-4" style="background-color: #0d1426">
    <div class="max-w-6xl mx-auto">
      <h2 class="font-title text-4xl sm:text-5xl text-center mb-16" style="color: var(--gold)">
        C'est quoi le jeu ?
      </h2>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="concept-card p-6 rounded-lg text-center" style="background-color: var(--bg-panel); border: 1px solid rgba(201,168,76,0.3)">
          <div class="text-5xl mb-4">🌙</div>
          <h3 class="font-title text-xl mb-2" style="color: var(--gold)">La nuit</h3>
          <p class="text-sm opacity-80">Les loups-garous dévorent un villageois.</p>
        </div>

        <div class="concept-card p-6 rounded-lg text-center" style="background-color: var(--bg-panel); border: 1px solid rgba(201,168,76,0.3)">
          <div class="text-5xl mb-4">☀️</div>
          <h3 class="font-title text-xl mb-2" style="color: var(--gold)">Le jour</h3>
          <p class="text-sm opacity-80">Le village vote pour éliminer un suspect.</p>
        </div>

        <div class="concept-card p-6 rounded-lg text-center" style="background-color: var(--bg-panel); border: 1px solid rgba(201,168,76,0.3)">
          <div class="text-5xl mb-4">🏆</div>
          <h3 class="font-title text-xl mb-2" style="color: var(--gold)">Le village gagne</h3>
          <p class="text-sm opacity-80">Si tous les loups sont éliminés.</p>
        </div>

        <div class="concept-card p-6 rounded-lg text-center" style="background-color: var(--bg-panel); border: 1px solid rgba(201,168,76,0.3)">
          <div class="text-5xl mb-4">🐺</div>
          <h3 class="font-title text-xl mb-2" style="color: var(--gold)">Les loups gagnent</h3>
          <p class="text-sm opacity-80">S'ils égalent le nombre de villageois.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ============================================= -->
  <!-- 3. SECTION "LES RÔLES"                         -->
  <!-- ============================================= -->
  <section class="py-24 px-4" style="background-color: var(--bg-main)">
    <div class="max-w-6xl mx-auto">
      <h2 class="font-title text-4xl sm:text-5xl text-center mb-4" style="color: var(--gold)">
        Les Rôles
      </h2>
      <p class="text-center mb-16 italic opacity-70">Survolez une carte pour découvrir son pouvoir.</p>

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">

        <!-- Loup-Garou -->
        <div class="role-card flip-card">
          <div class="flip-inner">
            <div class="flip-face" style="border: 1px solid rgba(255,68,68,0.5)">
              <div class="text-6xl mb-4">🐺</div>
              <h3 class="font-title text-2xl" style="color: #ff4444">Loup-Garou</h3>
            </div>
            <div class="flip-back flip-face" style="border: 1px solid rgba(255,68,68,0.5)">
              <h3 class="font-title text-xl mb-3" style="color: #ff4444">Loup-Garou</h3>
              <p class="text-sm opacity-85">La nuit, les loups se réveillent et choisissent une victime. Leur but : égaler le nombre de villageois.</p>
            </div>
          </div>
        </div>

        <!-- Voyante -->
        <div class="role-card flip-card">
          <div class="flip-inner">
            <div class="flip-face" style="border: 1px solid rgba(167,139,250,0.5)">
              <div class="text-6xl mb-4">🔮</div>
              <h3 class="font-title text-2xl" style="color: #a78bfa">Voyante</h3>
            </div>
            <div class="flip-back flip-face" style="border: 1px solid rgba(167,139,250,0.5)">
              <h3 class="font-title text-xl mb-3" style="color: #a78bfa">Voyante</h3>
              <p class="text-sm opacity-85">Chaque nuit, elle peut inspecter l'identité secrète d'un joueur. Une alliée précieuse... si elle survit.</p>
            </div>
          </div>
        </div>

        <!-- Villageois -->
        <div class="role-card flip-card">
          <div class="flip-inner">
            <div class="flip-face" style="border: 1px solid rgba(74,222,128,0.5)">
              <div class="text-6xl mb-4">🪓</div>
              <h3 class="font-title text-2xl" style="color: #4ade80">Villageois</h3>
            </div>
            <div class="flip-back flip-face" style="border: 1px solid rgba(74,222,128,0.5)">
              <h3 class="font-title text-xl mb-3" style="color: #4ade80">Villageois</h3>
              <p class="text-sm opacity-85">Sans pouvoir spécial, mais son vote compte autant que les autres. La force du nombre.</p>
            </div>
          </div>
        </div>

        <!-- Maire -->
        <div class="role-card flip-card">
          <div class="flip-inner">
            <div class="flip-face" style="border: 1px solid rgba(201,168,76,0.5)">
              <div class="text-6xl mb-4">👑</div>
              <h3 class="font-title text-2xl" style="color: var(--gold)">Maire</h3>
            </div>
            <div class="flip-back flip-face" style="border: 1px solid rgba(201,168,76,0.5)">
              <h3 class="font-title text-xl mb-3" style="color: var(--gold)">Maire</h3>
              <p class="text-sm opacity-85">Élu par le village au premier jour. Son vote compte double lors des égalités.</p>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- ============================================= -->
  <!-- 4. SECTION CTA FINAL "UNE PARTIE ?"            -->
  <!-- ============================================= -->
  <section class="relative py-32 px-4 overflow-hidden text-center" style="background-color: var(--bg-main)">
    <!-- Particules -->
    <div id="particles" class="absolute inset-0"></div>

    <div class="relative z-10 max-w-2xl mx-auto">
      <h2 class="font-title text-4xl sm:text-5xl mb-6" style="color: var(--gold)">
        Prêt à rejoindre le village ?
      </h2>
      <p class="text-lg sm:text-xl mb-10 opacity-85">
        De 6 à 12 joueurs. Une partie dure entre 10 et 30 minutes.
      </p>
      @guest
      <a
        href="{{ route('auth.google') }}"
        class="btn-primary inline-block font-title font-semibold px-10 py-4 rounded-md tracking-wide text-lg"
      >
        Jouer maintenant
      </a>
      @endguest
      @auth
      <a
        href="{{ route('lobby') }}"
        class="btn-primary inline-block font-title font-semibold px-10 py-4 rounded-md tracking-wide text-lg"
      >
        Jouer maintenant
      </a>
      @endauth
    </div>
  </section>

  <!-- ============================================= -->
  <!-- 5. FOOTER                                      -->
  <!-- ============================================= -->
  <footer class="py-8 text-center" style="background-color: #060b17; border-top: 1px solid rgba(201,168,76,0.2)">
    <div class="text-3xl mb-3">🐺</div>
    <p class="text-sm" style="color: rgba(232,224,208,0.5)">
      Loup-Garou Undu © <script>document.write(new Date().getFullYear())</script> <br> Made with ❤️ by JoPi aka Plynthiou. <br> All rights reserved.
    </p>
  </footer>

  <!-- ============================================= -->
  <!-- SCRIPTS                                        -->
  <!-- ============================================= -->
  <script>
    gsap.registerPlugin(ScrollTrigger);

    // ===== Génération des étoiles =====
    (function generateStars() {
      const container = document.getElementById("stars");
      for (let i = 0; i < 80; i++) {
        const s = document.createElement("div");
        s.className = "star";
        const size = Math.random() * 2 + 1;
        s.style.width = size + "px";
        s.style.height = size + "px";
        s.style.left = Math.random() * 100 + "%";
        s.style.top = Math.random() * 70 + "%";
        s.style.animationDelay = Math.random() * 4 + "s";
        container.appendChild(s);
      }
    })();

    // ===== Génération des particules flottantes (CTA) =====
    (function generateParticles() {
      const container = document.getElementById("particles");
      for (let i = 0; i < 25; i++) {
        const p = document.createElement("div");
        p.className = "particle";
        p.style.left = Math.random() * 100 + "%";
        p.style.top = Math.random() * 100 + "%";
        container.appendChild(p);

        gsap.to(p, {
          y: -(Math.random() * 60 + 30),
          x: (Math.random() - 0.5) * 40,
          opacity: 0,
          duration: Math.random() * 4 + 3,
          repeat: -1,
          delay: Math.random() * 4,
          ease: "power1.out",
        });
      }
    })();

    // ===== HERO : fade in + slide up (stagger) =====
    gsap.from(
      ["#hero-title", "#hero-subtitle", "#hero-buttons"],
      {
        opacity: 0,
        y: 40,
        duration: 1,
        ease: "power3.out",
        stagger: 0.25,
        delay: 0.3,
      }
    );

    // ===== Section concept : cascade au scroll =====
    gsap.from(".concept-card", {
      scrollTrigger: {
        trigger: "#concept",
        start: "top 75%",
      },
      opacity: 0,
      y: 50,
      duration: 0.8,
      ease: "power2.out",
      stagger: 0.15,
    });

    // ===== Section rôles : apparition en stagger =====
    gsap.from(".role-card", {
      scrollTrigger: {
        trigger: ".role-card",
        start: "top 80%",
      },
      opacity: 0,
      y: 50,
      duration: 0.8,
      ease: "power2.out",
      stagger: 0.15,
    });
  </script>
</body>
</html>
