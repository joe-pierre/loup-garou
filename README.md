# Loup-Garou Undu

Jeu Loup-Garou multijoueur en temps réel. Crée une partie, invite tes amis et découvre ton rôle.

---

## Stack technique

| Composant | Technologie |
|---|---|
| Backend | Laravel 11 (PHP 8.3+) |
| WebSocket | Laravel Reverb |
| Frontend | Blade + Alpine.js + GSAP |
| CSS | Tailwind CSS |
| Base de données | MySQL 8+ |
| Auth | Google OAuth (Socialite) |
| Queue (dev / prod) | database / Redis |

---

## Installation

### Prérequis
- PHP 8.3+
- Node.js 20+
- MySQL 8+
- Compte Google Cloud (OAuth)

### Étapes

```bash
# 1. Cloner et installer les dépendances
git clone <repo>
cd loup-garou-undu
composer install
npm install

# 2. Configurer l'environnement
cp .env.example .env
php artisan key:generate

# 3. Configurer .env (voir section Variables d'environnement)

# 4. Migrer et seeder la base de données
php artisan migrate:fresh --seed

# 5. Générer les clés VAPID (push notifications)
php artisan webpush:vapid
```

### Variables d'environnement requises

```env
DB_DATABASE=loup_garou

BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database

GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback

REVERB_APP_ID=loup-garou-local
REVERB_APP_KEY=loup-garou-key-local
REVERB_APP_SECRET=loup-garou-secret-local

VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
```

---

## Démarrage local

Lancer les 4 processus dans des terminaux séparés :

```bash
# Terminal 1 — Serveur Laravel
php artisan serve

# Terminal 2 — WebSocket Reverb
php artisan reverb:start

# Terminal 3 — Queue worker
php artisan queue:work

# Terminal 4 — Assets frontend
npm run dev

# Ou utiliser le combo
./z_tools/build_local.sh
```

L'application est accessible sur `http://localhost:8000`.

---

## Règles du jeu

### Objectif
- **Village** : éliminer tous les loups-garous
- **Loups** : être en nombre égal ou supérieur aux villageois

### Déroulement d'une partie
1. **Salle d'attente** — 6 à 12 joueurs rejoignent via un code
2. **Révélation des rôles** — chaque joueur découvre son rôle en privé
3. **Élection du Maire** — vote collectif, le maire a un poids de 2 au vote jour
4. **Nuit** — la Voyante inspecte un joueur, les Loups votent une victime
5. **Jour** — débat collectif puis vote d'élimination
6. Répéter nuit/jour jusqu'à la victoire d'un camp

### Rôles (v1.1)
| Rôle | Camp | Pouvoir |
|---|---|---|
| Villageois | Village | Aucun |
| Loup-Garou | Loups | Vote la nuit pour éliminer |
| Voyante | Village | Inspecte le rôle d'un joueur chaque nuit |
| Maire | — | Élu en début de partie, vote compte double le jour |

### Composition par défaut
| Joueurs | Loups | Voyante | Villageois |
|---|---|---|---|
| 6 | 1 | 1 | 4 |
| 8 | 2 | 1 | 5 |
| 10 | 2 | 1 | 7 |
| 12 | 3 | 1 | 8 |

---

## Architecture

```
app/
├── Http/Controllers/Game/   ← valident la request, appellent les Services
├── Services/                ← toute la logique métier
│   ├── GameService.php
│   ├── PhaseManager.php
│   ├── VoteService.php
│   ├── RoleDistributor.php
│   ├── WinConditionChecker.php
│   └── ChatService.php
├── Jobs/                    ← gestion des timers uniquement
├── Events/Game/             ← events WebSocket
└── Models/                  ← User, Game, GamePlayer, GameAction, ChatMessage, Exclusion
```

**Règle stricte :** toute logique métier est dans `Services/`, jamais dans les Controllers.

---

## Tests

```bash
php artisan test
```

Couverture : Auth, Lobby, Phases nuit/jour, Sorcière, Chasseur, Succession maire,
Race conditions, Workflow, Timers configurables, Rôles v1.2.

---

## Versioning

| Version | Contenu |
|---|---|
| v1.1 ✅ | Villageois, Loup-Garou, Voyante, Maire électif |
| v1.2 ✅ | Sorcière, Chasseur — timers + composition rôles configurables — canal fantômes |
| v1.3+ (futur) | Loup Blanc, Cupidon, Petite Fille |

---

## Documentation développeur

| Fichier | Rôle |
|---|---|
| `SPEC.md` | Spécification complète (modèle de données, règles métier, endpoints, WebSocket) |
| `CONVENTIONS.md` | Règles de codage (nommage, format API, Alpine.js) |
| `DECISIONS.md` | Journal des choix techniques et bugs complexes résolus |
| `BUGS_AND_ROADMAP.md` | Bugs corrigés + améliorations futures |
| `TODO.md` | Avancement des tâches |
| `CLAUDE.md` | Contexte pour Claude Code (lu automatiquement à chaque session) |
