<!DOCTYPE html>
<html lang="fr">
<head>
    <style>[x-cloak] { display: none !important; }
    #lobby-title, #panel-create, #panel-join { opacity: 0; }</style>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loup-Garou Undu — Lobby</title>
    <meta name="description" content="Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=EB+Garamond:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: 'EB Garamond', serif; background-color: #0a0f1e; }
        h1, h2, h3, .font-cinzel { font-family: 'Cinzel', serif; }
        .otp-input::-webkit-outer-spin-button,
        .otp-input::-webkit-inner-spin-button { -webkit-appearance: none; }
        .otp-input { -moz-appearance: textfield; }
    </style>
</head>
<body class="min-h-screen" style="background-color: #0a0f1e; color: #e8e0d0;">

    {{-- Header --}}
    <header class="flex items-center justify-between px-6 py-4 border-b" style="border-color: rgba(201,168,76,0.2);">
        <a href="/" class="font-cinzel text-xl font-bold" style="color: #c9a84c;">Loup-Garou Undu</a>
        <div class="flex items-center gap-4">
            <span class="text-sm" style="color: #e8e0d0; opacity: 0.6;">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm px-3 py-1 rounded border transition-opacity hover:opacity-80"
                        style="border-color: rgba(201,168,76,0.4); color: #e8e0d0;">
                    Déconnexion
                </button>
            </form>
        </div>
    </header>

    <main x-cloak
          class="max-w-4xl mx-auto px-4 py-10"
          x-data="lobbyApp()"
          x-init="init()">

        {{-- Titre --}}
        <div id="lobby-title" class="text-center mb-10">
            <h1 class="font-cinzel text-4xl font-bold mb-2" style="color: #c9a84c;">Rejoins la partie</h1>
            <p style="color: #e8e0d0; opacity: 0.6;">Crée une nouvelle partie ou rejoins-en une avec un code.</p>
        </div>

        {{-- Messages flash --}}
        @if (session('error'))
            <div class="mb-6 rounded-lg px-4 py-3 text-sm text-center"
                 style="background-color: rgba(139,0,0,0.25); border: 1px solid #8b0000; color: #fca5a5;">
                {{ session('error') }}
            </div>
        @endif

        {{-- Onglets mobile --}}
        <div class="flex md:hidden mb-6 rounded-lg overflow-hidden border" style="border-color: rgba(201,168,76,0.3);">
            <button @click="activeTab = 'create'"
                    :style="activeTab === 'create' ? 'background-color:#c9a84c;color:#0a0f1e;' : 'background-color:transparent;color:#e8e0d0;'"
                    class="flex-1 py-2 text-sm font-cinzel font-semibold transition-colors">
                Créer
            </button>
            <button @click="activeTab = 'join'"
                    :style="activeTab === 'join' ? 'background-color:#c9a84c;color:#0a0f1e;' : 'background-color:transparent;color:#e8e0d0;'"
                    class="flex-1 py-2 text-sm font-cinzel font-semibold transition-colors">
                Rejoindre
            </button>
        </div>

        {{-- Colonnes --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            {{-- ── PANNEAU CRÉER ── --}}
            <div id="panel-create"
                 x-show="activeTab === 'create' || window.innerWidth >= 768"
                 class="rounded-2xl p-6 flex flex-col gap-5"
                 style="background-color: #111827; border: 1px solid rgba(201,168,76,0.35);">

                <h2 class="font-cinzel text-xl font-semibold" style="color: #c9a84c;">Créer une partie</h2>

                <form @submit.prevent="submitCreate" class="flex flex-col gap-5">

                    {{-- Nombre de joueurs --}}
                    <div>
                        <label class="block text-sm mb-2" style="color: #e8e0d0; opacity: 0.75;">
                            Nombre de joueurs
                        </label>
                        <div class="grid grid-cols-4 gap-2">
                            <template x-for="n in [6, 8, 10, 12]" :key="n">
                                <button type="button"
                                        @click="createForm.maxPlayers = n"
                                        :style="createForm.maxPlayers === n
                                            ? 'background-color:#c9a84c;color:#0a0f1e;border-color:#c9a84c;'
                                            : 'background-color:transparent;color:#e8e0d0;border-color:#c9a84c;'"
                                        class="py-2 rounded-lg border font-semibold text-sm transition-colors"
                                        x-text="n">
                                </button>
                            </template>
                        </div>
                        <p x-show="createErrors.maxPlayers" x-text="createErrors.maxPlayers"
                           class="mt-1 text-xs" style="color: #fca5a5;"></p>
                    </div>

                    {{-- Pseudo --}}
                    <div>
                        <label for="create-pseudo" class="block text-sm mb-2" style="color: #e8e0d0; opacity: 0.75;">
                            Ton pseudo
                        </label>
                        <input id="create-pseudo"
                               type="text"
                               x-model="createForm.pseudo"
                               maxlength="20"
                               placeholder="Ex: LoupSolitaire"
                               class="w-full px-4 py-2 rounded-lg text-sm outline-none focus:ring-2"
                               style="background-color: #0a0f1e; border: 1px solid #c9a84c;
                                      color: #e8e0d0;"
                               :class="{ 'border-red-700': createErrors.pseudo }">
                        <p x-show="createErrors.pseudo" x-text="createErrors.pseudo"
                           class="mt-1 text-xs" style="color: #fca5a5;"></p>
                    </div>

                    {{-- Submit --}}
                    <button type="submit"
                            :disabled="creating"
                            class="w-full py-3 rounded-lg font-cinzel font-semibold text-sm transition-opacity disabled:opacity-50"
                            style="background-color: #c9a84c; color: #0a0f1e;">
                        <span x-show="!creating">Créer la partie</span>
                        <span x-show="creating">Création en cours…</span>
                    </button>

                    <p x-show="createError" x-text="createError"
                       class="text-sm text-center" style="color: #fca5a5;"></p>
                </form>
            </div>

            {{-- ── PANNEAU REJOINDRE ── --}}
            <div id="panel-join"
                 x-show="activeTab === 'join' || window.innerWidth >= 768"
                 class="rounded-2xl p-6 flex flex-col gap-5"
                 style="background-color: #111827; border: 1px solid rgba(201,168,76,0.35);">

                <h2 class="font-cinzel text-xl font-semibold" style="color: #c9a84c;">Rejoindre une partie</h2>

                <form @submit.prevent="submitJoin" class="flex flex-col gap-5">

                    {{-- Input OTP 6 cases --}}
                    <div>
                        <label class="block text-sm mb-3" style="color: #e8e0d0; opacity: 0.75;">
                            Code de la partie
                        </label>
                        <div class="flex gap-2 justify-between" @paste.prevent="onPaste($event)">
                            <template x-for="i in [1, 2, 3, 4, 5, 6]" :key="i">
                                <input
                                    type="text"
                                    inputmode="text"
                                    maxlength="1"
                                    :id="'otp-' + i"
                                    x-model="joinForm.codeChars[i - 1]"
                                    @input="onOtpInput($event, i)"
                                    @keydown="onOtpKeydown($event, i)"
                                    class="otp-input w-10 h-12 text-center text-lg font-bold rounded-lg outline-none focus:ring-2"
                                    style="background-color: #0a0f1e; border: 1px solid rgba(201,168,76,0.4);
                                           color: #c9a84c; caret-color: #c9a84c;"
                                    autocomplete="off"
                                    spellcheck="false">
                            </template>
                        </div>
                        <p x-show="joinErrors.code" x-text="joinErrors.code"
                           class="mt-1 text-xs" style="color: #fca5a5;"></p>
                    </div>

                    {{-- Pseudo --}}
                    <div>
                        <label for="join-pseudo" class="block text-sm mb-2" style="color: #e8e0d0; opacity: 0.75;">
                            Ton pseudo
                        </label>
                        <input id="join-pseudo"
                               type="text"
                               x-model="joinForm.pseudo"
                               maxlength="20"
                               placeholder="Ex: VillageoisRusé"
                               class="w-full px-4 py-2 rounded-lg text-sm outline-none focus:ring-2"
                               style="background-color: #0a0f1e; border: 1px solid rgba(201,168,76,0.3);
                                      color: #e8e0d0;"
                               :class="{ 'border-red-700': joinErrors.pseudo }">
                        <p x-show="joinErrors.pseudo" x-text="joinErrors.pseudo"
                           class="mt-1 text-xs" style="color: #fca5a5;"></p>
                    </div>

                    {{-- Submit --}}
                    <button type="submit"
                            :disabled="joining"
                            class="w-full py-3 rounded-lg font-cinzel font-semibold text-sm transition-opacity disabled:opacity-50"
                            style="background-color: #c9a84c; color: #0a0f1e;">
                        <span x-show="!joining">Rejoindre la partie</span>
                        <span x-show="joining">Connexion en cours…</span>
                    </button>

                    <p x-show="joinError" x-text="joinError"
                       class="text-sm text-center" style="color: #fca5a5;"></p>
                </form>
            </div>

        </div>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script>
        function lobbyApp() {
            return {
                activeTab: new URLSearchParams(window.location.search).has('code') ? 'join' : 'create',

                createForm:  { pseudo: '', maxPlayers: 6 },
                createErrors: {},
                createError: '',
                creating:    false,

                joinForm:    { pseudo: '', codeChars: Array(6).fill('') },
                joinErrors:  {},
                joinError:   '',
                joining:     false,

                init() {
                    // Pré-remplir le code si ?code=XXXXXX
                    const params = new URLSearchParams(window.location.search);
                    const code   = (params.get('code') || '').toUpperCase().slice(0, 6);
                    if (code) {
                        this.joinForm.codeChars = [...code.padEnd(6, '')];
                    }

                    // Animations GSAP
                    gsap.fromTo('#lobby-title',
                        { opacity: 0, y: 20 },
                        { opacity: 1, y: 0, duration: 0.6, ease: 'power2.out' }
                    );
                    gsap.fromTo('#panel-create',
                        { opacity: 0, y: 40 },
                        { opacity: 1, y: 0, duration: 0.7, delay: 0.1, ease: 'power2.out' }
                    );
                    gsap.fromTo('#panel-join',
                        { opacity: 0, y: 40 },
                        { opacity: 1, y: 0, duration: 0.7, delay: 0.2, ease: 'power2.out' }
                    );
                },

                // ── OTP helpers ──

                onOtpInput(event, index) {
                    const raw = event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');

                    // Mobile : le navigateur peut coller plusieurs caractères dans un seul input
                    // (quand le clavier système intercepte le paste avant l'event paste du DOM)
                    if (raw.length > 1) {
                        const chars = [...raw.padEnd(6, '').slice(0, 6)];
                        // Remplir depuis la case courante
                        for (let i = 0; i < chars.length; i++) {
                            this.joinForm.codeChars[index - 1 + i] = chars[i] || '';
                        }
                        this.$nextTick(() => {
                            for (let i = 1; i <= 6; i++) {
                                const el = document.getElementById('otp-' + i);
                                if (el) el.value = this.joinForm.codeChars[i - 1] || '';
                            }
                            const focusIdx = Math.min(index - 1 + raw.length, 6);
                            document.getElementById('otp-' + focusIdx)?.focus();
                        });
                        return;
                    }

                    this.joinForm.codeChars[index - 1] = raw.slice(-1);
                    event.target.value = this.joinForm.codeChars[index - 1];
                    if (raw && index < 6) {
                        document.getElementById('otp-' + (index + 1))?.focus();
                    }
                },

                onOtpKeydown(event, index) {
                    if (event.key === 'Backspace' && !this.joinForm.codeChars[index - 1] && index > 1) {
                        document.getElementById('otp-' + (index - 1))?.focus();
                    }
                    if (event.key === 'ArrowLeft' && index > 1) {
                        document.getElementById('otp-' + (index - 1))?.focus();
                    }
                    if (event.key === 'ArrowRight' && index < 6) {
                        document.getElementById('otp-' + (index + 1))?.focus();
                    }
                },

                onPaste(event) {
                    const text = (event.clipboardData || window.clipboardData)
                        .getData('text')
                        .toUpperCase()
                        .replace(/[^A-Z0-9]/g, '')
                        .slice(0, 6);

                    const chars = [...text.padEnd(6, '')];
                    this.joinForm.codeChars = chars;

                    // Sur mobile, x-model ne synchronise pas les inputs DOM après un paste
                    // système — on force la valeur de chaque input directement.
                    this.$nextTick(() => {
                        for (let i = 1; i <= 6; i++) {
                            const el = document.getElementById('otp-' + i);
                            if (el) el.value = chars[i - 1] || '';
                        }
                        // Focus sur la dernière case remplie (ou la 6ème)
                        const focusIdx = Math.min(text.length, 6);
                        document.getElementById('otp-' + (focusIdx || 1))?.focus();
                    });
                },

                // ── Soumissions ──

                async submitCreate() {
                    this.createErrors = {};
                    this.createError  = '';

                    if (!this.createForm.pseudo || this.createForm.pseudo.length < 2) {
                        this.createErrors.pseudo = 'Le pseudo doit faire au moins 2 caractères.';
                        return;
                    }

                    this.creating = true;
                    try {
                        const res = await fetch('/game', {
                            method:  'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept':       'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                                                ?? '{{ csrf_token() }}',
                            },
                            body: JSON.stringify({
                                pseudo:      this.createForm.pseudo,
                                max_players: this.createForm.maxPlayers,
                            }),
                        });

                        const json = await res.json();

                        if (res.status === 201 && json.success) {
                            window.location.href = '/game/' + json.data.code + '/lobby';
                            return;
                        }

                        if (res.status === 422 && json.errors) {
                            this.createErrors = {
                                pseudo:     json.errors.pseudo?.[0] ?? '',
                                maxPlayers: json.errors.max_players?.[0] ?? '',
                            };
                        } else {
                            this.createError = json.message || 'Une erreur est survenue.';
                        }
                    } catch {
                        this.createError = 'Impossible de contacter le serveur.';
                    } finally {
                        this.creating = false;
                    }
                },

                async submitJoin() {
                    this.joinErrors = {};
                    this.joinError  = '';

                    const code = this.joinForm.codeChars.join('').trim();
                    if (code.length !== 6) {
                        this.joinErrors.code = 'Le code doit faire exactement 6 caractères.';
                        return;
                    }
                    if (!this.joinForm.pseudo || this.joinForm.pseudo.length < 2) {
                        this.joinErrors.pseudo = 'Le pseudo doit faire au moins 2 caractères.';
                        return;
                    }

                    this.joining = true;
                    try {
                        const res = await fetch('/game/' + code + '/join', {
                            method:  'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept':       'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                                                ?? '{{ csrf_token() }}',
                            },
                            body: JSON.stringify({ pseudo: this.joinForm.pseudo }),
                        });

                        const json = await res.json();

                        if (json.success) {
                            window.location.href = '/game/' + code + '/lobby';
                            return;
                        }

                        if (res.status === 422 && json.errors) {
                            this.joinErrors.pseudo = json.errors.pseudo?.[0] ?? '';
                        } else {
                            this.joinError = json.message || 'Une erreur est survenue.';
                        }
                    } catch {
                        this.joinError = 'Impossible de contacter le serveur.';
                    } finally {
                        this.joining = false;
                    }
                },
            };
        }
    </script>
</body>
</html>