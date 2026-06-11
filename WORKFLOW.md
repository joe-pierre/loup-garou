# WORKFLOW.md — Guide de reprise et de conduite du projet
> ⚠️ Fichier destiné au développeur uniquement.
> Claude Code ne doit pas lire ce fichier.

---

## 1. Vue d'ensemble

```mermaid
stateDiagram-v1.1.1
    [*] --> Waiting
    Waiting --> MayorElection
    MayorElection --> Night
    Night --> NightResolution
    NightResolution --> CheckVictimNight
    CheckVictimNight --> MayorSuccession : victime = maire
    CheckVictimNight --> RemoveSeer : victime = voyante
    CheckVictimNight --> VictoryCheck
    MayorSuccession --> RemoveSeer
    MayorSuccession --> VictoryCheck
    RemoveSeer --> VictoryCheck
    VictoryCheck --> Finished : victoire
    VictoryCheck --> Day : aucune victoire
    Day --> DayVote
    DayVote --> DayResolution
    DayResolution --> CheckVictimDay
    CheckVictimDay --> MayorSuccessionDay : éliminé = maire
    CheckVictimDay --> RemoveSeerDay : éliminé = voyante
    CheckVictimDay --> VictoryCheckDay
    MayorSuccessionDay --> RemoveSeerDay
    MayorSuccessionDay --> VictoryCheckDay
    RemoveSeerDay --> VictoryCheckDay
    VictoryCheckDay --> Finished : victoire
    VictoryCheckDay --> Night : continuer
    Finished --> [*]
```

---

## 2. Avant chaque session Claude Code

1. **Régénérer `CODE_SNAPSHOT.md`** via le script externe: [ `./z_tools/project_structure.sh` ]
   → Index structurel du code. Sans lui, Claude Code lit 70 fichiers au lieu de 2-3.

2. **Ouvrir `TODO.md`** → identifier la prochaine tâche `- [ ]`

3. **Ouvrir `TASK_PROMPTS_REMAINING.md`** → copier le prompt correspondant

4. **Lancer Claude Code** dans le dossier du projet
   → `CLAUDE.md` est lu automatiquement. Ne jamais le coller manuellement.

---

## 3. Pendant la session Claude Code

- Coller **un prompt à la fois**
- Laisser Claude Code terminer avant d'intervenir
- Si Claude Code dévie, corriger avec :

```
Stop. Tu viens de [décrire l'erreur].
La règle dans CONVENTIONS.md dit [citer la règle].
Corrige uniquement [fichier concerné] sans toucher aux autres fichiers.
```

Claude Code met à jour automatiquement à la fin de chaque tâche :
- `TODO.md` → coche `[x]` la tâche terminée
- `DECISIONS.md` → si bug complexe ou choix technique
- `BUGS_AND_ROADMAP.md` → si bug simple ou idée d'amélioration

---

## 4. Après chaque tâche (Git)

Claude Code crée la branche et fait le commit.
**Toi tu fais uniquement le merge** après vérification :

```bash
git checkout dev
git merge feature/tache-X-nom
```

⚠️ **Une seule exception** : si Claude Code casse quelque chose de visible
(page blanche, erreur 500) → corriger immédiatement sur la même branche
avant de continuer. Pas besoin d'attendre la fin de la tâche.

---

## 5. Ordre d'implémentation des tâches A→D

Les tâches sont **interdépendantes** — respecter impérativement cet ordre :

```
Tâche A (UI : vues manquantes)
    ↓ merge sur dev
Tâche B (Tests phases 5→9)
    ↓ merge sur dev
Tâche C (Responsive)
    ↓ merge sur dev
Tâche D (Audit final)
    ↓ merge sur dev
    ↓
Phase de test et correction
```

**Pourquoi cet ordre ?**
- A avant C : la Tâche C modifie les mêmes vues Blade que la Tâche A
- B avant D : la Tâche D vérifie que les tests passent
- D en dernier : audit sur une base de code stable et complète

Ne jamais corriger des bugs métier découverts entre deux tâches.
Les noter dans `BUGS_AND_ROADMAP.md` et les traiter après la Tâche D.

---

## 6. Lancer les tests automatisés

**Quand :** après que la Tâche D soit mergée sur `dev`.

```bash
php artisan test
```

**Règle :** ne pas passer au test manuel si des tests automatisés échouent.
Corriger d'abord les tests en échec via Claude.ai (voir section 9).

---

## 7. Gérer les divergences test auto / test manuel

Cas : test automatisé ✅ mais test manuel ❌ sur le même scénario.

**3 causes possibles :**
- Le test auto est mal écrit (mock trop large, assertion trop permissive)
- Le test auto ne couvre pas le cas réel (WebSocket, timing, navigateur)
- Erreur de manipulation lors du test manuel

**Comment trancher :**

```
Test manuel échoue
        ↓
Relis le test auto → teste-t-il exactement le même scénario ?
        ↓
OUI → le test auto est probablement mal écrit
    → soumettre à Claude.ai avec le template bug (section 9)
        ↓
NON → le test auto ne couvre pas ce cas
    → ajouter le cas manquant via Claude.ai
        ↓
DOUTE → reproduire le test manuel 2 fois de suite
    → échoue encore → c'est un vrai bug → section 9
    → passe → erreur de manipulation → continuer
```

---

## 8. Phase de test manuel

**Quand :** après que tous les tests automatisés passent.

**Ordre de test recommandé :**
1. Flux complet : landing → auth → lobby → partie 6 joueurs → fin
2. Flux reconnexion : couper la connexion en cours de nuit et de jour
3. Partage lien d'invite : ouvrir dans un autre navigateur
4. Paste input 6 cases : code seul + URL complète
5. Test responsive : mobile, tablette, desktop
6. Test spectateur : joueur mort voit le bon chat
7. Test annulation : > 50% joueurs inactifs

**Outils :**
- Console navigateur ouverte en permanence (surveiller les erreurs JS)
- Deux navigateurs différents simultanément (simuler plusieurs joueurs)
- Onglet Network → WS pour surveiller les events WebSocket reçus

---

## 9. Résolution de bugs via Claude.ai

**Principe :** un nouveau chat Claude.ai par bug. Jamais tout le contexte —
uniquement l'extrait de code concerné (100-200 lignes max).

**Template prompt bug :**

```
Contexte : jeu Loup-Garou en ligne, Laravel 11, Alpine.js, Laravel Reverb.

Fichier(s) concerné(s) : [nom du ou des fichiers]

Ce que je constate :
[Décrire en langage naturel ce qui se passe]

Ce que j'attends :
[Décrire en langage naturel le comportement souhaité]

Extrait de code :
[Coller les 100-200 lignes pertinentes]

Règles à respecter :
- Logique métier uniquement dans app/Services/
- Format API : { "success": true|false, "data": {}, "message": "" }
- Pas de logique dans les vues Blade
- [Ajouter toute règle spécifique de CONVENTIONS.md si pertinente]
```

**Après avoir obtenu la correction :**
1. Créer une branche : `git checkout -b fix/nom-du-bug`
2. Appliquer la correction dans Claude Code
3. Relancer `php artisan test`
4. Merger sur `dev` si les tests passent

---

## 10. Audits

À réaliser **après** la résolution des bugs — inutile d'auditer du code instable.

### Audit sécurité + accessibilité
Déjà couvert par la Tâche D. Si des corrections ont été faites en section 9,
relancer le checklist de la Tâche D manuellement sur les points concernés.

### Audit performance — après la v1.1 en prod
**Quoi :** requêtes N+1, temps de réponse endpoints votes (< 200ms), mémoire queue worker
**Comment :** installer Laravel Telescope en local, jouer une partie complète, analyser les requêtes lentes

```bash
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

### Audit WebSocket — après la v1.1 en prod
**Quoi :** fuites de données entre channels, reconnexion après coupure, timer serveur vs client
**Comment :** ouvrir la console navigateur sur deux comptes simultanément,
onglet Network → WS, observer les events reçus par chaque rôle

---

## 11. Mise à jour finale des fichiers Claude.ai

**Quand :** toutes les tâches A→D terminées, bugs corrigés, tests qui passent,
tout mergé sur `dev`.

**Fichiers à re-uploader :**

| Fichier | Raison |
|---|---|
| `TODO.md` | Toutes les tâches cochées `[x]` |
| `DECISIONS.md` | Nouveaux bugs/choix ajoutés par Claude Code |
| `BUGS_AND_ROADMAP.md` | Bugs corrigés pendant A→D et phase de test |
| `SPEC.md` | §10 mis à jour (police EB Garamond — Tâche A) |

**Fichiers qui ne changent pas — ne pas re-uploader :**
`CLAUDE.md`, `CONVENTIONS.md`, `WORKFLOW.md`, `README.md`

---

## 12. Liaisons entre fichiers

```
CLAUDE.md  (lu automatiquement par Claude Code)
  │
  ├──→ SPEC.md                 référence fonctionnelle complète (lecture seule)
  ├──→ CONVENTIONS.md          règles de codage strictes (lecture seule)
  ├──→ CODE_SNAPSHOT.md        index du code (lecture seule, jamais modifié)
  ├──→ TODO.md                 avancement des tâches (mis à jour par Claude Code)
  ├──→ DECISIONS.md            bugs complexes + choix techniques (mis à jour par Claude Code)
  └──→ BUGS_AND_ROADMAP.md     bugs simples + idées futures (mis à jour par Claude Code)

TASK_PROMPTS_REMAINING.md      prompts tâches A→D (copier-coller dans Claude Code)
WORKFLOW.md                    ce fichier — développeur uniquement, jamais lu par Claude Code
```

---

## Fichiers que Claude Code ne doit jamais modifier

| Fichier | Raison |
|---|---|
| `CODE_SNAPSHOT.md` | Généré par script externe — écrasé à chaque session |
| `SPEC.md` | Source de vérité fonctionnelle (sauf §10 — Tâche A) |
| `WORKFLOW.md` | Développeur uniquement |
| `TASK_PROMPTS.md` | Archivé — remplacé par `TASK_PROMPTS_REMAINING.md` |
