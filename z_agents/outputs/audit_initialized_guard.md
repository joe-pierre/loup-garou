# Audit Guard _initialized — 2026-06-18

## Résumé
- Fichiers analysés : 5
- Composants conformes : 2
- BUGS CRITIQUES : 0
- Non concernés : 3

---

## Détail par fichier

### resources/views/game/day.blade.php

#### `dayScreen().init()`
- Statut : ✅ CONFORME
- Raison : Le guard `_initialized` est présent en premières instructions, avant tous les `window.addEventListener()`.
- Ligne approximative : 491–493
- `window.addEventListener()` recensés dans init() : `day-started`, `day-vote-cast`, `chat-message`, `i-was-eliminated`, `i-was-saved`, `mayor-succession-started`, `mayor-succession-done`, `hunter-turn-started`, `no-elimination`, `player-eliminated` (10 listeners).

---

### resources/views/game/night.blade.php

#### `nightScreen().init()`
- Statut : ✅ CONFORME
- Raison : Le guard `_initialized` est présent en premières instructions, avant tous les `window.addEventListener()`.
- Ligne approximative : 659–661
- `window.addEventListener()` recensés dans init() : `i-was-eliminated`, `i-was-saved`, `mayor-succession-started`, `mayor-succession-done`, conditionnels selon le rôle : `seer-turn-started`, `seer-result`, `witch-turn-started`, `witch-acted`, `hunter-turn-started`, `werewolves-turn-started`, `wolves-vote-cast`, `werewolf-chat-message` (12 listeners max selon le rôle).

---

### resources/views/game/mayor-election.blade.php

#### `mayorElection().init()`
- Statut : ⚪ NON CONCERNÉ
- Raison : Aucun `window.addEventListener()` dans init() — les souscriptions passent par `window.Echo.channel().listen()` (API Laravel Echo, non concernée par la règle).
- Ligne approximative : 206

---

### resources/views/game/spectator.blade.php

#### Composant principal (`gameState()`)
- Statut : ⚪ NON CONCERNÉ
- Raison : Aucun composant Alpine local ne définit un `init()` avec `window.addEventListener()`. La vue délègue entièrement à `gameState()` (game-state.js). Le commentaire ligne 192 le confirme explicitement.
- Ligne approximative : 88–96

#### Composant inline `{ tab: 'players' }` (ligne 98)
- Statut : ⚪ NON CONCERNÉ
- Raison : Pas de `init()` défini dans ce composant inline.

---

### resources/js/game-state.js

#### `gameState().init()`
- Statut : ⚪ NON CONCERNÉ
- Raison : `init()` ne contient **pas directement** de `window.addEventListener()`. Les abonnements Echo passent par `initWebSocket()`, qui possède son propre guard `_wsInitialized` (ligne 115–116).
- Ligne approximative : 60

> ⚠️ **Note de vigilance (risque indirect) :** `init()` appelle `_setupBeforeUnload()` (ligne 86), qui contient `window.addEventListener('beforeunload', …)`. Si Alpine déclenchait `init()` plusieurs fois, ce listener serait empilé et enverrait plusieurs beacons `sendBeacon` à la déconnexion. Dans le contexte actuel, ce cas est évité car `gameState()` est instancié une seule fois par page (il est le composant racine sur spectator.blade.php et n'existe pas sur les autres vues en tant que root). Risque à surveiller si `gameState()` venait à être utilisé sur un composant enfant imbriqué.

---

## Conclusion

Aucun BUG CRITIQUE détecté. Les deux seuls composants avec des `window.addEventListener()` directs dans leur `init()` (`dayScreen` et `nightScreen`) sont correctement protégés par le guard `_initialized`.
