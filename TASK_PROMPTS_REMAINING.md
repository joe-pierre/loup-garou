# TASK_PROMPTS_REMAINING.md — Tâches restantes v1.1

> Tâches 1→37 terminées. Ce fichier contient uniquement ce qu'il reste à faire.
> Coller un prompt à la fois dans Claude Code.

---

## TÂCHE A — UI : Police de corps + cancelled.blade.php + spectator.blade.php

```
AVANT DE COMMENCER :
git checkout -b feature/tache-A-ui-vues

Contexte : lis SPEC.md §10 (Design), CONVENTIONS.md §Partials Alpine, TODO.md Phase 10.

Fais dans l'ordre :

1. POLICE DE CORPS — choisir et uniformiser
   - Inspecte app.css et les vues Blade pour trouver toutes les occurrences de "EB Garamond" et "Crimson Text"
   - Décision : conserver EB Garamond (déjà en place côté CSS), supprimer toutes les références à Crimson Text
   - Mettre à jour SPEC.md §10 pour refléter ce choix

2. cancelled.blade.php
   - Créer resources/views/game/cancelled.blade.php
   - @extends layout principal
   - Affiche : titre "Partie annulée", message explicatif (trop de joueurs inactifs), bouton CTA retour vers /
   - Pas de données dynamiques nécessaires — vue statique
   - Style cohérent avec les autres vues (variables CSS existantes, pas de nouvelles classes)

3. spectator.blade.php
   - Créer resources/views/game/spectator.blade.php
   - @extends layout principal
   - Vue lecture seule pour joueurs morts : voit le chat village (channel general), ne voit pas le chat loups
   - Pas de formulaire de vote, pas d'action disponible
   - Affiche la liste des joueurs vivants/morts (depuis gameState store Alpine)
   - Écoute les events WebSocket game.{gameId} pour mise à jour temps réel (phase, éliminations)
   - Utilise $watch sur gameState.phase pour afficher la phase courante
   - Aucune propriété dupliquée depuis le store central gameState

CONTRAINTES :
- Aucune logique métier dans les vues
- Aucune variable Blade injectée dans un store Alpine local
- Respecter §Partials Alpine de CONVENTIONS.md

Quand c'est fait :
1. Liste les fichiers créés ou modifiés
2. Fais un commit : `git add -A && git commit -m "feat(ui): police EB Garamond unifiée, cancelled et spectator blade"`
3. Ne merge pas sur dev.
```

---

## TÂCHE B — Tests : Phases 5→9 (nuit, jour, chat, race conditions)

> Regroupe les tâches 38 et 39 de TASK_PROMPTS.md + couverture manquante phases 5→9.

```
AVANT DE COMMENCER :
git checkout -b feature/tache-B-tests

Contexte : lis SPEC.md §4 (votes, anonymat), §13 (race conditions), CONVENTIONS.md §Tests.
Tâches phases 5→9 terminées. Lis CODE_SNAPSHOT.md pour identifier les services et controllers concernés.

Crée les fichiers suivants dans l'ordre :

1. tests/Unit/Models/GameActionTest.php
   → scopeAnonymized() ne retourne pas player_id dans les colonnes
   → scopeAnonymized() retourne toutes les lignes (pas de filtre sur le type)
   → scopeAnonymized() retourne bien target_player_id, type, weight, round, phase

2. tests/Unit/Events/EventPayloadTest.php
   → MayorVoteCast payload ne contient pas player_id
   → DayVoteCast payload ne contient pas player_id
   → SeerResult broadcasté uniquement sur channel privé (pas Channel public)
   → WerewolfChatMessage broadcasté uniquement sur game.{id}.werewolves

3. tests/Feature/Game/NightPhaseTest.php
   → Voyante ne peut pas s'inspecter elle-même (403)
   → Loup ne peut pas voter pour un autre loup (403)
   → Action voyante après expiration timer → ignorée
   → Vote nuit hors phase night → 409

4. tests/Feature/Game/DayPhaseTest.php
   → Un joueur ne vote pas pour lui-même (403)
   → Égalité vote jour → aucun joueur éliminé, événement NoElimination broadcasté
   → Vote maire avec weight=2 correctement compté
   → Vote hors phase day → 409

5. tests/Feature/Game/ChatTest.php
   → Message loups visible uniquement sur channel werewolves
   → Joueur villageois ne peut pas écrire sur channel werewolves (403)
   → Message après mort → 403

6. tests/Feature/Game/RaceConditionTest.php
   → joinGame race : max_players=6, 6 insertions quasi-simultanées → exactement 6 joueurs, pas 7
   → startGame race : startGame() ne peut pas être appelé deux fois (status change atomique)
   → mayorVote race : deux votes simultanés du même joueur → un seul inséré
   → dayVote race mayor : weight=2 correct même si changement de maire concurrent

CONVENTIONS : factories, RefreshDatabase, pas de mocks sauf pour les broadcasts.

Quand c'est fait :
1. Liste les fichiers créés et le nombre de tests par fichier
2. Fais un commit : `git add -A && git commit -m "test: couverture phases 5→9 nuit, jour, chat, race conditions"`
3. Ne merge pas sur dev.
```

---

## TÂCHE C — Responsive

```
AVANT DE COMMENCER :
git checkout -b feature/tache-C-responsive

Contexte : lis SPEC.md §10 (Design, breakpoints), CONVENTIONS.md §CSS.
Lis CODE_SNAPSHOT.md pour identifier toutes les vues Blade.

Applique le responsive sur toutes les vues dans l'ordre de priorité :
1. waiting-room.blade.php
2. role-reveal.blade.php
3. mayor-election.blade.php
4. night.blade.php
5. day.blade.php
6. finished.blade.php
7. cancelled.blade.php
8. spectator.blade.php
9. Landing page (Écran 1)

Pour chaque vue :
- Mobile first (min-width breakpoints)
- Breakpoints : sm=640px, md=768px, lg=1024px (Tailwind standard)
- Aucune nouvelle classe CSS — utiliser uniquement les utilitaires Tailwind existants
- Tester mentalement : liste des joueurs, boutons de vote, chat loups, timer affiché

CONTRAINTES :
- Ne pas modifier la logique Alpine.js
- Ne pas modifier les controllers ni les services
- Modifier uniquement les classes HTML dans les vues Blade

Quand c'est fait :
1. Liste les vues modifiées
2. Fais un commit : `git add -A && git commit -m "feat(ui): responsive mobile-first toutes les vues"`
3. Ne merge pas sur dev.
```

---

## TÂCHE D — Recette finale et audit sécurité

> Correspond à la Tâche 40 de TASK_PROMPTS.md — à exécuter en dernier.

```
AVANT DE COMMENCER :
git checkout -b feature/tache-D-audit-final

Contexte : toutes les tâches précédentes terminées. Lis SPEC.md en entier une dernière fois.

Vérifie et corrige dans l'ordre :

SÉCURITÉ :
1. routes/channels.php — chaque channel privé a bien sa vérification d'appartenance
2. Chaque Controller vérifie game_id + player_id cohérents (anti-spoofing)
3. Aucune réponse publique n'expose un rôle de joueur vivant
4. Middleware 'auth' présent sur toutes les routes protégées

ACCESSIBILITÉ :
5. prefers-reduced-motion respecté dans app.css ET dans les handlers GSAP de game-state.js
6. Contraste WCAG AA vérifié sur : #c9a84c sur #0a0f1e, #e8e0d0 sur #111827, #ff4444 sur #1a0000
7. focus:ring-2 focus:ring-[#c9a84c] présent sur tous les éléments interactifs
8. aria-label sur tous les boutons d'action, role="log" sur les zones de chat

FONCTIONNEL :
9. Flux complet (landing → auth → lobby → partie 6 joueurs → fin) sans erreur console
10. Flux reconnexion : couper la connexion en cours de nuit et de jour, vérifier restauration via /state
11. Partage lien d'invite : copier le lien, l'ouvrir dans un autre navigateur, vérifier pré-remplissage code
12. Paste input 6 cases : coller un code seul (ABC123) + coller une URL complète (?code=ABC123)
13. Suppression en cascade : créer une partie, la terminer, attendre scheduler ou appeler games:clean manuellement

PRODUCTION :
14. config/queue.php → driver=redis en production
15. Supervisor : workers + Reverb configurés
16. php artisan config:cache && route:cache && view:cache en production
17. RoleDistributor extensible : ajouter un rôle fictif 'witch'=>1 dans config/game.php et vérifier que distribute() l'intègre sans modifier la logique core

Quand c'est fait :
1. Génère un rapport final : liste les points OK et ceux nécessitant une correction
2. Fais un commit : `git add -A && git commit -m "audit: recette finale sécurité, accessibilité, production"`
3. Ne merge pas sur dev.
```
