# BUGS CORRIGÉS

### [x] 2026-06-06 — AlpineJS non installé

- **Symptôme :** erreur Vite "Failed to resolve import alpinejs"
- **Cause :** package absent de node_modules
- **Fix :** `npm install alpinejs`

### [x] 2026-06-06 — Vite manifest not found

- **Symptôme :** page blanche après connexion Google
- **Cause :** `npm run build` jamais exécuté, `manifest.json` absent
- **Fix :** `@vite` dans le layout + `npm run build`

### [x] 2026-06-06 — FOUC Alpine.js

- **Symptôme :** flash du HTML brut pendant une fraction de seconde au chargement
- **Cause :** règle `[x-cloak]` chargée trop tard dans le DOM
- **Fix :** `<style>[x-cloak]{display:none!important}</style>` en premier enfant de `<head>`, `x-cloak` sur le `<main x-data>`

### [x] 2026-06-06 — Carte "Rejoindre" masquée sur desktop

- **Symptôme :** seule la carte "Créer" visible après init Alpine
- **Cause :** logique d'onglets mobile appliquée aussi sur desktop
- **Fix :** `md:!block` sur les deux panneaux

### [x] 2026-06-06 — Panneaux quasi-invisibles au chargement

- **Symptôme :** les deux cartes s'assombrissent après le flash initial
- **Cause :** `gsap.from()` pose `opacity:0` immédiatement sur les éléments sans état CSS initial
- **Fix :** `gsap.fromTo()` + `#panel-create, #panel-join { opacity: 0 }` en CSS

### [x] 2026-06-06 — gsap.from() opacity global (toutes les vues)

- **Symptôme :** éléments quasi-invisibles au chargement sur toutes les pages animées
- **Cause :** `gsap.from({ opacity: 0 })` sans état CSS initial crée un assombrissement visible
- **Fix :** `gsap.fromTo()` + `opacity:0` en CSS sur chaque élément animé — vues concernées : `lobby/index.blade.php`, `lobby/waiting-room.blade.php` + toutes les autres vues animées

### [x] 2026-06-06 — Phase nuit bloquée

- **Symptôme :** la phase nuit ne progressait pas après le vote des loups
- **Cause :** broadcast émis à l'intérieur d'une `DB::transaction` — le broadcast part avant que la transaction soit committée
- **Fix :** déplacer les broadcasts après la transaction dans `PhaseManager::startDay()` et `PhaseManager::startNight()`

---

### [x] 2026-06-09 — GSAP entry non protégé prefers-reduced-motion (night + day)

- **Symptôme :** utilisateurs `prefers-reduced-motion` voyaient quand même les animations d'entrée GSAP
- **Cause :** les appels `gsap.fromTo('.reveal', ...)` à l'init n'étaient pas wrappés dans un check JS matchMedia
- **Fix :** ajout de `if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches)` dans night.blade.php (script global) et day.blade.php (init())

---

# ROADMAP (idées / améliorations futures)

- [ ] Délai voyante : réduire de ~8s à ~5s — broadcaster `SeerTurnStarted` avec `delay(5s)` côté serveur, supprimer le `setTimeout` client (v1.2)
- [ ] Timers configurables par partie depuis la waiting-room (v1.2)
- [ ] Rôles v1.2 : Sorcière, Chasseur
- [ ] Rôles v1.3+ : Loup Blanc, Cupidon, Petite Fille
- [ ] `ProcessMayorSuccession` : flag `shouldStartNight` pour distinguer mort nuit vs mort jour
