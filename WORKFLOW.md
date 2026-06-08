# WORKFLOW.md — Guide de reprise du projet
> ⚠️ Fichier destiné au développeur uniquement. Claude Code ne doit pas lire ce fichier.

---

## Avant chaque session

1. **Régénérer `CODE_SNAPSHOT.md`** via le script externe
   → C'est l'index structurel du code. Sans lui, Claude Code lit 70 fichiers au lieu de 2-3.

2. **Lancer Claude Code** dans le dossier du projet
   → `CLAUDE.md` est lu automatiquement — ne pas le coller manuellement.

3. **Ouvrir `TODO.md`** pour identifier la prochaine tâche à faire (`- [ ]`)
   → Les prompts correspondants sont dans `TASK_PROMPTS_REMAINING.md`

4. **Coller le prompt de la tâche** dans Claude Code et laisser travailler.

---

## Pendant la session

- Coller **un prompt à la fois**
- Si Claude Code dévie, corriger avec :
```
Stop. Tu viens de [décrire l'erreur].
La règle dans CONVENTIONS.md dit [citer la règle].
Corrige uniquement [fichier concerné] sans toucher aux autres fichiers.
```

---

## Après chaque tâche

Claude Code met à jour automatiquement (c'est dans `CLAUDE.md`) :
- `TODO.md` → coche `[x]` la tâche terminée
- `DECISIONS.md` → si bug complexe ou choix technique
- `BUGS_AND_ROADMAP.md` → si bug simple ou idée d'amélioration

---

## Liaisons entre fichiers

```
CLAUDE.md  ──────────────────────────────────────────────────────┐
(lu auto)                                                         │
  │  définit les règles de lecture et d'écriture de →            │
  ├──→ TODO.md              avancement des tâches                 │
  ├──→ DECISIONS.md         bugs complexes + choix techniques     │
  ├──→ BUGS_AND_ROADMAP.md  bugs simples + idées futures          │
  └──→ CODE_SNAPSHOT.md     index du code (lecture seule)         │
                                                                  │
SPEC.md ──────────────────────────────────────────────────────────┤
  référence fonctionnelle et technique complète                   │
  lu par Claude Code au démarrage + sur demande                   │
                                                                  │
CONVENTIONS.md ───────────────────────────────────────────────────┤
  règles de codage strictes                                       │
  lu par Claude Code au démarrage + cité en cas de déviation      │
                                                                  │
TASK_PROMPTS_REMAINING.md ────────────────────────────────────────┘
  prompts des tâches A→D restantes
  tu copies → tu colles dans Claude Code
  jamais lu automatiquement
```

---

## Nouvelle tâche imprévue ?

1. Décris-la à **Claude.ai** (ce chat) en langage naturel
2. Claude.ai rédige le prompt formaté
3. Tu l'ajoutes dans `TASK_PROMPTS_REMAINING.md`
4. Tu le colles dans Claude Code au bon moment

---

## Tâches restantes (v1.1)

| Tâche | Contenu |
|---|---|
| A | UI : police, `cancelled.blade.php`, `spectator.blade.php` |
| B | Tests phases 5→9 + race conditions |
| C | Responsive toutes les vues |
| D | Audit final sécurité + recette complète |

---

## Fichiers que Claude Code ne doit pas modifier

| Fichier | Raison |
|---|---|
| `CODE_SNAPSHOT.md` | Généré par script externe — écrasé à chaque session |
| `SPEC.md` | Source de vérité fonctionnelle — sauf §10 police (tâche A) |
| `WORKFLOW.md` | Destiné au développeur uniquement |
| `TASK_PROMPTS.md` | Historique archivé — remplacé par TASK_PROMPTS_REMAINING.md |
