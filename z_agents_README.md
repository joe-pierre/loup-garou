# z_agents — Agents IA pour Loup-Garou Undu

Ce dossier contient un système d'agents IA piloté par Claude Code. L'idée est simple : plutôt que d'ouvrir Claude Code et de lui décrire manuellement ce qu'il faut analyser à chaque session, on automatise les analyses répétitives avec des scripts.

---

## Pourquoi ce système existe

Le projet a accumulé de la complexité (phases de jeu, timers, WebSocket, race conditions). Certaines vérifications doivent être faites régulièrement :

- Est-ce que tous les `init()` Alpine ont bien leur guard `_initialized` ?
- Est-ce qu'un nouveau code utilise `config('game.timers.x')` au lieu de `$game->timer('x')` ?
- Est-ce qu'un broadcast s'est glissé dans une transaction DB ?
- Quels tests manquent sur ce Service ?

Faire ces vérifications à la main à chaque PR prend du temps et on en rate. Ces agents les font à ta place, en 30 secondes, et produisent un rapport structuré.

---

## Structure du dossier

```
z_agents/
├── orchestrate.sh          ← script principal, le seul fichier à lancer
├── context/
│   └── rules_extract.md    ← règles du projet injectées dans TOUS les agents
├── prompts/
│   ├── audit_conformity.md ← prompt de l'auditeur de conformité
│   ├── detect_bugs.md      ← prompt du détecteur de bugs
│   ├── generate_tests.md   ← prompt du générateur de tests manquants
│   └── resolve_bug.md      ← prompt du proposeur de corrections
├── outputs/                ← rapports générés
│   └── YYYYMMDD_HHMMSS_*.md
└── logs/                   ← logs d'exécution (gitignore recommandé)
    └── *.log
```

---

## Les quatre types d'agents

### `audit` — Vérification de conformité

**Ce qu'il fait :** lit les fichiers listés et vérifie qu'ils respectent les règles du projet (CLAUDE.md, CONVENTIONS.md, RISK_GUARDS.md). Ne modifie rien.

**Quand l'utiliser :** avant un merge, après avoir modifié plusieurs fichiers en même temps, ou quand on veut être sûr qu'une règle est bien appliquée partout.

```bash
./z_agents/orchestrate.sh audit alpine      # guard _initialized Alpine
./z_agents/orchestrate.sh audit timers      # règle $game->timer()
./z_agents/orchestrate.sh audit controllers # controllers = valider + appeler Service
./z_agents/orchestrate.sh audit services    # logique métier dans les Services
./z_agents/orchestrate.sh audit jobs        # jobs = timers uniquement + guards round
./z_agents/orchestrate.sh audit all         # tous en parallèle
```

**Output :** `outputs/TIMESTAMP_audit_NOM.md` avec statut CONFORME / BUG CRITIQUE / NON CONCERNÉ pour chaque élément.

---

### `detect` — Détection de bugs potentiels

**Ce qu'il fait :** analyse une zone du code en cherchant des race conditions, des broadcasts dans des transactions, des guards manquants, des violations des RISK_GUARDS. Ne modifie rien.

**Quand l'utiliser :** après avoir modifié une zone critique (séquence nocturne, vote, WebSocket), ou quand un comportement étrange est observé en prod.

```bash
./z_agents/orchestrate.sh detect night_sequence  # séquence Seer→Wolves→Witch→Hunter
./z_agents/orchestrate.sh detect day_vote        # résolution vote jour + succession maire
./z_agents/orchestrate.sh detect websocket       # canaux Echo, double abonnement
./z_agents/orchestrate.sh detect game_service    # GameService (broadcasts, joinGame)
./z_agents/orchestrate.sh detect win_condition   # conditions de victoire
```

**Output :** `outputs/TIMESTAMP_bugs_NOM.md` avec un rapport structuré : bugs CRITIQUES, bugs POTENTIELS, conformités vérifiées, et — important — une section `## Fichiers concernés` utilisée par `fix`.

---

### `tests` — Tests manquants

**Ce qu'il fait :** compare un fichier source (Service ou Job) avec son fichier de tests existant, identifie les méthodes non couvertes et les cas limites manquants, génère le code PHPUnit complet. Ne modifie rien.

**Quand l'utiliser :** après avoir écrit un nouveau Service ou ajouté une méthode à un Service existant.

```bash
./z_agents/orchestrate.sh tests VoteService
./z_agents/orchestrate.sh tests PhaseManager
./z_agents/orchestrate.sh tests GameService
./z_agents/orchestrate.sh tests PhaseGuard
./z_agents/orchestrate.sh tests WitchTest
./z_agents/orchestrate.sh tests HunterTest
```

**Output :** `outputs/TIMESTAMP_tests_missing_NOM.md` avec le code complet des tests à ajouter.

⚠️ **Les tests générés sont une proposition, pas une vérité absolue.** Lis-les, adapte-les si nécessaire, puis copie-les dans le bon fichier de test.

---

### `fix` — Proposition de correction

**Ce qu'il fait :** lit un rapport produit par `detect`, ouvre les fichiers concernés, propose une correction minimale et précise. **Ne modifie rien automatiquement** — il produit une proposition en markdown.

**Quand l'utiliser :** après `detect`, quand tu veux une correction concrète pour un bug identifié.

```bash
./z_agents/orchestrate.sh fix z_agents/outputs/20260618_130553_bugs_night_sequence.md
```

**Output :** `outputs/TIMESTAMP_fix_proposal.md` avec le code avant/après pour chaque fichier.

---

## Comment ça fonctionne techniquement

1. Le script lit `context/rules_extract.md` (les règles du projet).
2. Il concatène ces règles avec le prompt de l'agent (`prompts/*.md`).
3. Il appelle `claude --dangerously-skip-permissions -p "..." -- fichier1 fichier2 ...`
4. Claude Code lit les fichiers, exécute l'analyse, écrit le rapport dans `outputs/`.
5. Les logs d'exécution vont dans `logs/`.

Les agents `audit`, `detect` et `tests` sont **en lecture seule** : ils lisent des fichiers mais n'en modifient aucun. L'agent `fix` produit une proposition en markdown — c'est toi qui appliques les changements.

---

## Adapter les listes de fichiers

Les fichiers analysés par chaque cible sont définis directement dans `orchestrate.sh`. Pour ajouter un fichier à une analyse existante :

```bash
# Exemple : ajouter ProcessMayorElection à l'audit jobs
# Dans cmd_audit(), case jobs) :
    "app/Jobs/ProcessMayorElection.php" \   # ← ajouter cette ligne
```

Pour créer une nouvelle cible (exemple : `audit lobby` pour la waiting-room) :

```bash
# Dans cmd_audit(), ajouter avant le *)
    lobby)
      run_agent "audit_lobby" \
        "$SCRIPT_DIR/prompts/audit_conformity.md" \
        "$OUTPUTS_DIR/${TIMESTAMP}_audit_lobby.md" \
        "resources/views/game/waiting-room.blade.php" \
        "app/Http/Controllers/Game/LobbyController.php"
      ;;
```

---

## Adapter les règles (`rules_extract.md`)

Ce fichier est injecté dans **tous** les agents avant leur prompt spécifique. Il contient les règles critiques du projet (guard Alpine, règle des timers, transactions, canaux WebSocket).

Si tu ajoutes une nouvelle règle à CLAUDE.md ou CONVENTIONS.md, ajoute-la aussi ici pour qu'elle soit connue de tous les agents.

**Format attendu :** texte simple, règles formulées clairement, exemples de code si nécessaire.

---

## Adapter les prompts (`prompts/*.md`)

Chaque fichier de prompt définit le comportement d'un agent et le format de son rapport. Tu peux les modifier pour :

- Ajouter une vérification spécifique au projet
- Changer le format de sortie (plus ou moins détaillé)
- Ajouter des exemples de patterns à détecter

Les prompts sont indépendants : modifier `detect_bugs.md` n'affecte pas `audit_conformity.md`.

---

## Correspondance des rapports générés

Le rapport `20260618_130553_bugs_night_sequence.md` a été produit par :
```bash
./z_agents/orchestrate.sh detect night_sequence
```

Le rapport `20260618_130239_audit_alpine.md` a été produit par :
```bash
./z_agents/orchestrate.sh audit alpine
```

---

## ⚠️ Sécurité et bonnes pratiques

### Avant de lancer `fix`

**Toujours committer avant.**

```bash
git commit -am "snapshot avant fix agent"
./z_agents/orchestrate.sh fix z_agents/outputs/RAPPORT.md
```

Si la correction est mauvaise, un `git checkout .` suffit à tout annuler. Sans commit préalable, les modifications de l'agent écrasent ton code sans filet.

### Ne jamais appliquer `fix` sans lire le rapport

L'agent produit une proposition dans `outputs/TIMESTAMP_fix_proposal.md`. Lis-la en entier avant de copier-coller quoi que ce soit. L'agent peut se tromper sur le contexte ou proposer une correction trop large.

### Ne jamais lancer plusieurs `fix` en parallèle

Deux agents `fix` qui tournent en même temps peuvent modifier les mêmes fichiers dans des directions contradictoires. Lance-les un par un, valide, committe, puis passe au suivant.

### `audit` et `detect` peuvent tourner en parallèle

Ces agents sont en lecture seule. `audit all` le fait déjà automatiquement (chaque sous-agent tourne en `&`). Tu peux aussi lancer plusieurs `detect` en parallèle dans des terminaux séparés sans risque.

### Surveille les logs en cas d'échec

Si un agent échoue silencieusement (fichier de sortie vide ou partiel), le log correspondant est dans `logs/NOM_TIMESTAMP.log`. Cherche les erreurs d'authentification Claude Code ou les timeouts réseau.

```bash
tail -f z_agents/logs/orchestrator_TIMESTAMP.log
```

### Versionner `z_agents/`

Le dossier `z_agents/` doit être dans ton dépôt Git, sauf `outputs/` et `logs/` que tu peux ignorer (ils sont régénérés à chaque run).

```gitignore
# dans .gitignore
z_agents/outputs/
z_agents/logs/
```

Les fichiers à versionner : `orchestrate.sh`, `context/`, `prompts/`. Ce sont tes "recettes" d'analyse — ils font partie du projet au même titre que `CLAUDE.md`.

---

## Ordre de travail recommandé

Pour traiter un bug identifié dans le rapport `bugs_night_sequence.md` :

```bash
# 1. Committer l'état actuel
git commit -am "avant correction GameService broadcasts"

# 2. Lire le rapport detect existant
cat z_agents/outputs/20260618_130553_bugs_night_sequence.md

# 3. Demander une proposition de correction
./z_agents/orchestrate.sh fix z_agents/outputs/20260618_130553_bugs_night_sequence.md

# 4. Lire la proposition AVANT de toucher au code
cat z_agents/outputs/TIMESTAMP_fix_proposal.md

# 5. Appliquer manuellement les changements pertinents

# 6. Vérifier que les tests passent
php artisan test

# 7. Relancer un detect pour confirmer que le bug est corrigé
./z_agents/orchestrate.sh detect game_service

# 8. Committer
git commit -am "fix: broadcasts hors transactions dans GameService"
```
