## Couche 1 — Assets JS / Build

### ✅ Bug 1 — alpinejs non installé
- Symptôme : erreur Vite "Failed to resolve import alpinejs"
- Cause : package absent de node_modules
- Fix : npm install alpinejs
- Statut : ✅ Résolu

### ✅ Bug 2 — Vite manifest not found
- Symptôme : page blanche après connexion Google
- Cause : npm run build jamais exécuté, manifest.json absent
- Fix : @vite dans le layout + npm run build
- Statut : ✅ Résolu

---

## Couche 2 — Lobby (Écran 3)

### ✅ Bug 3 — FOUC Alpine.js
- Symptôme : flash du HTML brut pendant une fraction de seconde
- Cause : règle [x-cloak] chargée trop tard dans le DOM
- Fix : <style>[x-cloak]{display:none!important}</style>
         en premier enfant de <head>, x-cloak sur le <main x-data>
- Statut : ✅ Résolu

### ✅ Bug 4 — Carte "Rejoindre" masquée sur desktop
- Symptôme : seule la carte "Créer" visible après init Alpine
- Cause : logique d'onglets mobile appliquée aussi sur desktop
- Fix : md:!block sur les deux panneaux
- Statut : ✅ Résolu

### ✅ Bug 5 — Panneaux quasi-invisibles au chargement
- Symptôme : les deux cartes s'assombrissent après le flash initial
- Cause : gsap.from() pose opacity:0 immédiatement sur les éléments
- Fix : gsap.fromTo() + #panel-create, #panel-join { opacity: 0 } en CSS
- Statut : ✅ Résolu

### ✅ Bug 6 — gsap.from() opacity global (toutes les vues)
- Symptôme : éléments quasi-invisibles au chargement sur
  toutes les pages animées (lobby, waiting-room, etc.)
- Cause : gsap.from({ opacity: 0 }) pose opacity:0 immédiatement
  sans état CSS initial, créant un assombrissement visible
- Fix : gsap.fromTo() + opacity:0 en CSS sur chaque élément animé
- Vues concernées : lobby/index.blade.php, lobby/waiting-room.blade.php
  + toutes les autres vues à vérifier
- Statut : ✅ Résolu

---

## Prochaine étape
Tester la Couche 2 — fonctionnel :
- [ ] Créer une partie → redirige vers /game/{code}/lobby
- [ ] Rejoindre avec un code valide → redirige vers /game/{code}/lobby
- [ ] Rejoindre avec un code invalide → message d'erreur affiché
- [ ] Pseudo vide → message de validation affiché
- [ ] OTP : navigation automatique entre les cases
- [ ] OTP : pré-remplissage via ?code=XXXXXX dans l'URL