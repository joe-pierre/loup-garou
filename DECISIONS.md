## [RÉSOLU] Écran de fin de partie et historique affichaient "Loups"/"Annulée" au lieu de "Amoureux" pour `winner_team = 'lovers'`

**Contexte :** suivi direct des deux entrées suivantes (SFICZ8 puis RIQPAZ), toujours sur `fix/lovers-victory-witch-deferred-resolution` — `resources/views/game/finished.blade.php`, `resources/views/game/history.blade.php`, `app/Services/HistoryService.php`, `resources/views/admin/games/show.blade.php`, `resources/views/admin/users/show.blade.php`, `tests/Feature/Game/GameHistoryServiceTest.php`. Une fois les deux bugs backend corrigés (victoire amoureux désormais bien détectée et jamais écrasée), un audit indépendant de l'affichage a révélé que même une victoire amoureux **correctement persistée en base** n'aurait jamais été correctement affichée au joueur.

**Symptôme / Problème :** Deux binaires distincts, jamais mis à jour lors de l'introduction de `winner_team = 'lovers'` (Cupidon, v1.3) :
1. `finished.blade.php` : `$isVillage = $game->winner_team === 'villagers'` pilotait TOUTE la mise en forme (couleurs, emoji, titre, sous-titre, libellé d'en-tête, couleurs de confettis) via un simple ternaire — toute valeur différente de `'villagers'` (donc `'werewolves'` ET `'lovers'`) retombait sur le thème "Loups" (rouge, 🐺, "Les Loups ont gagné !"). Une victoire des amoureux légitimement enregistrée en base aurait donc affiché "Les Loups ont gagné !" à l'écran — un symptôme visuellement identique à celui observé dans la partie SFICZ8, mais dont la cause ici est purement côté affichage, indépendante des deux bugs backend déjà corrigés.
2. `history.blade.php` (`$winnerClass`/`$winnerLabel`) ET `HistoryService::buildTimeline()` (label de l'entrée `finish`) : tous deux des `match()` à deux branches explicites (`villagers`, `werewolves`) avec un `default` qui affiche "🏁 Annulée" / "🏁 Partie annulée" — `'lovers'` tombait dans ce `default`, affichant donc une victoire des amoureux comme une **annulation**, exactement le symptôme rapporté dans la partie SFICZ8 (`/history` affichant "Annulée"). Ce `default` n'est censé s'appliquer qu'à `winner_team === null` (vraie annulation) ; `'lovers'` n'aurait jamais dû y tomber.

**Cause / Alternatives :** Oubli de parité systématique lors de l'ajout de `'lovers'` à l'enum `WinnerTeam` (v1.3, Phase 37) — tous les points d'affichage dérivant un thème/libellé depuis `winner_team` avaient été audités pour les rôles (Phases 46-48, grep `witch`+`hunter`) mais jamais pour les **valeurs de `winner_team` elles-mêmes**, un axe de variation distinct qui n'était pas dans le périmètre de ces audits-là. Aucune alternative — ajout pur des branches manquantes, sur le modèle exact des branches `villagers`/`werewolves` déjà en place, couleur rose `#f472b6` déjà standardisée pour Cupidon (DECISIONS.md "Cupidon absent de toutes les tables rôle→icône/couleur/label") réutilisée telle quelle, jamais réinventée.

**Fix / Décision :**
1. `finished.blade.php` : `$isVillage` (booléen) remplacé par `$theme` (tableau associatif via `match($game->winner_team)`, 3 branches explicites `villagers`/`lovers`/`default` [werewolves]) regroupant `bg_gradient`, `banner_bg`, `border`, `title_color`, `emoji`, `title`, `subtitle`, `header_label`, `confetti1`, `confetti2`. Tous les usages de `$isVillage ? X : Y` remplacés par `$theme['clé']`. Thème amoureux : fond `#2e0a1e` (dégradé sombre teinté rose), bordure/titre `#f472b6`, emoji `💞` (cohérent avec l'usage déjà établi "Cupidon a frappé", `night.blade.php`), titre "Les Amoureux ont gagné !", confettis or `#c9a84c` + rose `#f472b6` (même schéma "accent doré + couleur du camp" que le thème village `#c9a84c`+`#4ade80`). `IS_VILLAGE` (JS) supprimé, remplacé par `CONFETTI_COLOR_1`/`CONFETTI_COLOR_2` injectés directement depuis `$theme`.
2. `history.blade.php` : branche `'lovers' => 'winner-lovers'` / `'💞 Amoureux'` ajoutée à `$winnerClass`/`$winnerLabel` ; nouvelle classe CSS `.winner-lovers` (rose, même famille que `.role-badge-cupidon` déjà existante) ; nœud de timeline `'finish'` étendu en `match()` à 3 branches (`werewolves`/`lovers`/`default`), nouvelle classe `.node-finish-lovers`.
3. `HistoryService::buildTimeline()` : branche `'lovers' => '💞 Victoire des Amoureux'` ajoutée au `match()` du label de l'entrée `finish`.
4. `resources/views/admin/games/show.blade.php` et `admin/users/show.blade.php` : branche `lovers` ajoutée pour cohérence (rose), écrans admin de moindre priorité mais mêmes binaires trouvés lors de l'audit demandé "ne pas se limiter à finished.blade.php".
5. `summary.blade.php` (écran intermédiaire "Ce qui s'est passé", affiché ~8s avant redirection vers `/finished`) vérifié : ne dérive aucune couleur/thème de `winner_team`, entièrement neutre — aucune modification nécessaire.
6. Tests : `tests/Feature/Game/GameHistoryServiceTest.php` — 2 tests ajoutés (`test_build_timeline_finish_label_victoire_amoureux` sur `HistoryService` directement, `test_page_history_affiche_victoire_amoureux_pas_annulee` sur la route réelle `game.history`, les deux rouges avant fix — "🏁 Partie annulée"/"🏁 Annulée" au lieu du libellé amoureux — verts après). `finished.blade.php` vérifié par rendu direct (`view()->render()`) pour les 3 valeurs de `winner_team`, aucun test automatisé dédié demandé (tâche d'affichage pur, cohérent avec le traitement des bugs d'affichage précédents du projet — ex. Phase 47). `npm run build` sans erreur. 296/296 tests verts (294 avant + 2 nouveaux, aucun cassé).

**Leçon :** Un audit "toutes les tables rôle→affichage" (Phases 46-48) ne couvre pas automatiquement "toutes les tables *résultat de partie*→affichage" — ce sont deux axes de variation orthogonaux (`role` vs `winner_team`) sur le même genre de `match()`/ternaire à risque de `default` silencieux. Après l'ajout d'une nouvelle valeur à un enum consommé par l'UI (ici `WinnerTeam::lovers`), grep spécifiquement toutes les occurrences du nom de la colonne/propriété concernée (`winner_team`/`winnerTeam`) dans `resources/`, pas seulement les tables déjà connues pour un axe différent (`role`) — un `default` qui retombe sur "annulée" est particulièrement traître car il ne se contente pas d'afficher une valeur fausse (comme un `default => 'Villageois'` visible), il affiche un **statut de partie entièrement différent et déjà signifiant** (annulation), créant la même confusion que les bugs backend qu'il masque.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Victoire des Amoureux non déclenchée quand une résolution différée de la Sorcière fait tomber l'effectif à 2 (partie SFICZ8)

**Contexte :** `fix/lovers-victory-witch-deferred-resolution` — `app/Services/RoleActions/WitchAction.php`, `app/Jobs/ProcessHunterTurn.php`, `app/Services/WinConditionChecker.php`, `tests/Feature/Game/CupidonTest.php`, `tests/Unit/Services/WinConditionCheckerTest.php`, `tests/Feature/Game/NightResyncTest.php`. Bug remonté depuis la partie SFICZ8 (24/07/2026) : couple Cupidon (Loup-Garou + Voyante) formé au round 1, dernier autre joueur vivant (Chasseur) tué en Nuit 3 — la partie aurait dû se terminer immédiatement en "Victoire des Amoureux" (SPEC_CUPIDON.md §6) mais a continué jusqu'à un "Jour 3" incohérent, pour finir avec deux résultats divergents : `/history` affichait "Annulée", l'écran de fin affichait "Les Loups ont gagné !".

**Symptôme / Problème :** Deux anomalies distinctes, investiguées ensemble.

1. **Victoire amoureux non déclenchée.** `WinConditionChecker::check()` est correct isolément (vérifié par relecture + tests existants) et déjà appelé après toute élimination *immédiate* (`ProcessNightActions`, `VoteService::dispatchDayVoteConsequences()`, `ProcessHunterAutoAction`, `PhaseManager::endNight()`). **Mais `WitchAction.php` ne l'appelait JAMAIS**, dans aucune de ses trois branches d'élimination différée (sorcière elle-même via `witchDiedFromWolves`, maire en sursis via `deferredMayorVictim`, victime ordinaire via `finalizeOrdinaryVictim()`) — ni dans `act()` (chemin manuel `pass`/`kill`), ni dans `finalizeTimedOutVictim()` (chemin timeout `ProcessWitchAutoAction`). Reproduit par test : une Sorcière ciblée par les loups qui ne se sauve pas et empoisonne le Chasseur à la place élimine les deux derniers autres joueurs en une seule action `WitchAction::act('kill')`, faisant tomber l'effectif à 2 amoureux mutuels sans jamais déclencher `check()`.
   Second gap, dans le même périmètre : `ProcessHunterTurn::handle()` ne vérifiait la victoire qu'en repli (branche "chasseur déjà résolu/invalide"), jamais AVANT de donner activement la main au Chasseur pour son tir. Un Chasseur valide dont la mort a déjà fait tomber l'effectif à 2 amoureux recevait donc quand même son tour (`HunterTurnStarted` broadcasté), en violation de SPEC_CUPIDON.md §6 ("court-circuite... y compris un tir de Chasseur en attente").
   **Note d'investigation :** le scénario *littéral* du rapport (Chasseur strictement seul autre survivant, aucune Sorcière en vie au moment de sa mort) a été testé séparément (`test_victoire_amoureux_deja_correcte_quand_chasseur_seul_autre_survivant_sans_sorciere`) et fonctionne déjà correctement avec le code existant, avant même ce fix — `ProcessNightActions::handle()` appelle `check()` immédiatement après une élimination non différée. Le gap réel exige qu'une Sorcière soit encore en vie au moment critique ; la partie SFICZ8 en avait probablement une (non mentionnée dans le rapport utilisateur, qui ne nommait que les rôles "notables").

2. **Incohérence "Annulée" (historique) vs "Les Loups ont gagné" (écran de fin).** `GameController::finished()`/`history()` lisent `$game->winner_team` directement en base à chaque requête (aucun cache) — l'écran de fin n'a donc pu afficher "Les Loups ont gagné" qu'à un instant où cette valeur était réellement en base. Cause structurelle identifiée : **`WinConditionChecker::check()` était le seul point de mutation d'état terminal de toute la codebase sans `lockForUpdate()` ni garde contre un statut déjà `'finished'`** — contrairement à `GameService::cancelGame()`, `VoteService::resolveMayorElection()`/`resolveDayVoteWinner()`, qui suivent tous le pattern `lockForUpdate()` + guard. `check()` faisait un `$game->refresh()` (lecture non verrouillée) suivi d'un `$game->update()` sans aucune re-vérification de l'état courant, ce qui permettait à un appel tardif de `check()` (dispatché par un Job en vol) d'écraser aveuglément un résultat déjà persisté par un `cancelGame()` concurrent (déclenché par `CheckReconnectionTimeout` sur un joueur inactif — avec 2 survivants, un seul joueur inactif suffit à dépasser le seuil de 50 %). **Le mécanisme exact de bascule observé en prod (résultat final "Annulée" après un affichage "Les Loups ont gagné") n'a pas pu être reconstitué avec certitude par analyse statique** — l'absence de verrou rend ce genre de race non déterministe et dépendant du timing réel des workers de queue, impossible à figer dans un test synchrone à dispatch manuel. Ce qui est certain : cette absence de verrou est un écart de robustesse réel et non intentionnel par rapport au reste de la codebase, qui rend ce type d'incohérence possible par construction.

**Cause / Alternatives :** Pas d'alternative envisagée pour le point 1 — c'est un oubli de parité avec le reste de la codebase (chaque élimination doit être suivie d'un `check()`, règle déjà établie et documentée ailleurs) plutôt qu'un choix. Pour le point 2, alternative envisagée : reproduire le VRAI scénario de race en test avec des threads/processus concurrents réels — rejetée, hors de portée de la suite de tests synchrone du projet (`QUEUE_CONNECTION=sync`/`Queue::fake()` partout) et disproportionnée par rapport au gain (le fix structurel — atomicité + idempotence — est correct et suffisant indépendamment de la reconstitution exacte de la race).

**Fix / Décision :**
1. `WitchAction` : `WinConditionChecker` injecté au constructeur. `check($game)` appelé à la toute fin de `act()` (après `finalizeOrdinaryVictim()`, couvre les 3 branches d'élimination différée en un seul point) et à la toute fin de `finalizeTimedOutVictim()` (chemin timeout). Comportement des branches heal/kill/pass inchangé — le check() est un ajout pur, jamais un court-circuit de la logique existante.
2. `ProcessHunterTurn::handle()` : `winChecker->check($game)` déplacé tout en haut de la méthode (avant le calcul de `$fromNight` et le chargement du Chasseur), au lieu d'être uniquement dans la branche de repli. Le Chasseur ne reçoit plus jamais son tour (`HunterTurnStarted`) si la partie est déjà terminée.
3. `WinConditionChecker::check()` réécrit : lecture + décision + persistance désormais atomiques dans un `DB::transaction()` avec `Game::where('id', ...)->lockForUpdate()->first()`, avec un guard d'idempotence explicite (`$locked->status === 'finished'` → no-op immédiat) — même pattern que `cancelGame()`/`resolveMayorElection()`/`resolveDayVoteWinner()`. Broadcasts et notifications restent hors transaction (RISK_GUARDS Guard #5). Comportement observable inchangé pour tous les cas déjà couverts par les tests existants (6 tests `WinConditionCheckerTest` préexistants toujours verts).
4. Tests : `tests/Feature/Game/CupidonTest.php` — 2 tests ajoutés (`test_victoire_amoureux_declenchee_apres_resolution_sorciere_qui_fait_tomber_effectif_a_deux` reproduisant le gap réel confirmé en échouant sur le code d'origine ; `test_victoire_amoureux_deja_correcte_quand_chasseur_seul_autre_survivant_sans_sorciere` en non-régression du scénario littéral, déjà correct). `tests/Unit/Services/WinConditionCheckerTest.php` — 1 test ajouté (`test_ne_reecrit_pas_une_partie_deja_annulee`, idempotence face à une annulation déjà persistée). `tests/Feature/Game/NightResyncTest.php` — effectif vivant réaliste (1 loup, 2 villageois) ajouté à `test_process_hunter_turn_persiste_night_sub_phase_et_deadline()`, dont le état de partie minimal (aucun joueur vivant hors le Chasseur mort) déclenchait désormais à tort une victoire villageoise avec le nouveau check() en tête de `ProcessHunterTurn`. 291/291 tests verts (283 avant + 2 Cupidon + 1 WinConditionChecker + 5 assertions supplémentaires sur les tests existants, aucun cassé). Suite complète exécutée avec Reverb démarré localement (6 échecs `WinConditionCheckerTest` préexistants sur `dev` sans Reverb, indépendants de cette tâche — voir DECISIONS.md "Cupidon Étape 7").

**Leçon :** Un `check()` de condition de victoire dispatché après *chaque* élimination immédiate mais oublié sur *une seule* voie d'élimination différée (ici : `WitchAction`, qui a 3 branches de résolution différée distinctes) laisse un bug invisible en test tant qu'aucun scénario ne combine "victoire potentielle" + "dernière élimination résolue via ce chemin précis" — exactement le même type d'angle mort que documenté pour `ProcessWitchAutoAction` (voir entrée précédente "Victime des loups jamais éliminée..."). Plus largement : toute mutation d'un état *terminal* de partie (`status = 'finished'`) doit systématiquement suivre le pattern `lockForUpdate()` + garde d'idempotence contre l'état déjà terminal, au même titre que les autres mutations d'état protégées dans ce projet — un point de mutation qui y déroge, même correct en isolation, devient une source de race dès qu'un second mécanisme (ici `cancelGame()` déclenché par inactivité) peut légitimement finir la partie en parallèle.

**Statut :** ✅ Résolu (point 1, cause confirmée par test) / point 2 durci puis confirmé — voir l'entrée suivante "Victoire des Amoureux annulée à tort par une course avec cancelGame()" pour la cause racine réellement identifiée (partie RIQPAZ).

---

## [RÉSOLU] Victoire des Amoureux annulée à tort par une course avec `cancelGame()` pendant une résolution de vote/nuit en cours (partie RIQPAZ) — cause racine confirmée de l'anomalie #2 "Annulée" vs "Les Loups ont gagné"

**Contexte :** suivi direct de l'entrée précédente ("Victoire des Amoureux non déclenchée... partie SFICZ8"), toujours sur `fix/lovers-victory-witch-deferred-resolution` — `app/Services/GameService.php` (`cancelGame()`), `tests/Feature/Game/CancelGameTest.php`, `tests/Feature/Game/CupidonTest.php`. Le fix précédent avait durci `WinConditionChecker::check()` (atomicité + idempotence) pour l'anomalie #2 sans avoir pu reconstituer l'enchaînement exact observé en prod. L'utilisateur a depuis reproduit le bug en local via un chemin **différent** de SFICZ8 (partie RIQPAZ) : couple Cupidon formé au round 1, puis un **vote de jour** (pas une résolution Sorcière/Chasseur nocturne) fait tomber l'effectif à exactement les 2 amoureux — résultat obtenu : "Partie annulée" au lieu de "Victoire des Amoureux", alors que les deux joueurs restants sont vivants et actifs.

**Symptôme / Problème :** Reproduit d'abord un test **direct et déterministe** du chemin vote de jour seul (`test_victoire_amoureux_declenchee_par_vote_de_jour_qui_fait_tomber_effectif_a_deux`, 3 joueurs vivants dont le couple, vote majoritaire élimine le troisième via `VoteService::resolveDayVote()`) : ce test **passe** avec le code déjà en place — la voie d'élimination par vote de jour elle-même n'a aucun défaut de logique, `dispatchDayVoteConsequences()` appelle bien `check()` juste après l'élimination, exactement comme documenté. Ceci exclut un bug de logique pure dans `VoteService`.

La cause racine réelle a été trouvée en examinant `GameService::cancelGame()` (déclenché par `CheckReconnectionTimeout` sur un joueur inactif) au lieu de `WinConditionChecker`. `CheckReconnectionTimeout::handle()` ne tente d'appeler `cancelGame()` QUE si sa lecture (hors verrou, en tout début de méthode) du statut de la partie est dans `['night', 'day', 'electing_mayor']` — **excluant explicitement** les statuts intermédiaires `'processing_day'`/`'processing_night'` (ainsi que `'wolves_turn'`), signe clair que l'intention du code est de ne jamais annuler une partie dont une résolution est activement en cours. Mais `cancelGame()` lui-même **ne revalide jamais cette exclusion** dans sa propre transaction verrouillée — son garde n'excluait que `['finished', 'waiting']` (`whereNotIn`), un ensemble bien plus large que ce que `CheckReconnectionTimeout` autorise réellement. C'est un TOCTOU (time-of-check to time-of-use) classique : la décision "le statut permet l'annulation" est prise HORS verrou dans le Job, mais jamais revérifiée AU MOMENT de l'écriture, dans le verrou de `cancelGame()`.

Séquence exacte reconstituée (et prouvée par test, voir Fix ci-dessous) : un `CheckReconnectionTimeout` a lu le statut de la partie RIQPAZ pendant qu'il valait encore `'day'` (passant donc son propre garde) ; entre cette lecture et l'acquisition effective de son verrou dans `cancelGame()`, `VoteService::resolveDayVoteWinner()` a eu le temps de tourner intégralement dans une transaction concurrente : elle a éliminé Brave 2 (le dernier autre joueur) ET posé le statut à `'processing_day'` — un statut que le garde `whereNotIn(['finished', 'waiting'])` de `cancelGame()` laisse parfaitement passer. `cancelGame()` a donc acquis son verrou juste après, trouvé un statut `'processing_day'` non exclu par son garde trop permissif, et a tranché la partie en "annulée" — AVANT que `dispatchDayVoteConsequences()` (qui n'avait pas encore repris la main après le commit de `resolveDayVoteWinner()`) n'ait la moindre chance d'appeler `WinConditionChecker::check()` et de détecter la victoire des amoureux. Le hardening de `check()` (entrée précédente) empêche bien un `check()` tardif d'écraser cette annulation une fois posée — mais ne peut rien contre le fait que l'annulation elle-même s'est produite trop tôt, pendant une résolution encore en vol.

**Cause / Alternatives :** Pas d'alternative — c'est un défaut de garde asymétrique entre deux mécanismes censés se coordonner (le pré-check du Job d'un côté, le garde transactionnel du Service de l'autre), qui divergent sur l'ensemble des statuts qu'ils considèrent "annulables". Alternative rejetée : élargir la liste de statuts exclus par `CheckReconnectionTimeout::handle()` plutôt que de resserrer `cancelGame()` — insuffisant, puisque le vrai problème est que la fenêtre entre la lecture du Job et l'écriture du Service n'est jamais revalidée ; resserrer uniquement le pré-check du Job n'aurait rien changé à la race elle-même (le Job peut toujours lire un statut valide qui devient `'processing_*'` une fraction de seconde plus tard, avant que `cancelGame()` n'acquière son verrou).

**Fix / Décision :**
1. `GameService::cancelGame()` — garde changé de `whereNotIn('status', ['finished', 'waiting'])` à `whereIn('status', ['night', 'day', 'electing_mayor'])`, reprenant EXACTEMENT l'ensemble déjà utilisé par `CheckReconnectionTimeout::handle()` pour décider s'il doit même tenter l'appel. Les deux couches sont désormais symétriques, et la revalidation a lieu atomiquement sous le même `lockForUpdate()` que l'écriture — ferme la fenêtre TOCTOU par construction (le Job peut toujours lire un statut permissif puis le voir changer avant d'acquérir son verrou, mais `cancelGame()` revalide alors correctement et devient un no-op).
2. Effet de bord assumé, cohérent avec l'intention préexistante du code : une partie ne peut désormais plus être annulée pendant `'processing_night'`/`'processing_day'`/`'wolves_turn'` — ce n'est pas une régression, c'est l'alignement de `cancelGame()` sur ce que `CheckReconnectionTimeout` autorisait déjà implicitement (son propre garde excluait déjà ces statuts, silencieusement, côté Job). Un `CheckReconnectionTimeout` qui se heurte à ce nouveau no-op ne perd pas la détection d'inactivité : la partie reste active, un futur `CheckReconnectionTimeout` (prochaine déconnexion, ou tout simplement le fait que la partie ait entre-temps une résolution qui se termine et repasse en statut canonique) pourra retenter l'annulation si le joueur reste réellement inactif.
3. Tests : `tests/Feature/Game/CancelGameTest.php` — 2 tests ajoutés (`test_cancel_game_annule_a_tort_une_partie_en_cours_de_resolution_vote_jour`, `test_cancel_game_annule_a_tort_une_partie_en_cours_de_resolution_nuit`), reproduisant directement la fenêtre de course au niveau du garde de `cancelGame()` (statut `'processing_day'`/`'processing_night'` posé, `cancelGame()` appelé directement) — les deux échouaient sur le code d'origine (`cancelGame()` réussissait à annuler), passent après le fix. `tests/Feature/Game/CupidonTest.php` — 1 test ajouté (`test_victoire_amoureux_declenchee_par_vote_de_jour_qui_fait_tomber_effectif_a_deux`), confirmant en non-régression que le chemin "vote de jour" isolé (sans course) fonctionnait déjà correctement — utile pour circonscrire le bug au garde de `cancelGame()` plutôt qu'à `VoteService`. 294/294 tests verts (291 avant + 3 nouveaux, aucun cassé), suite complète avec Reverb démarré localement.

**Leçon :** Quand un Job pré-filtre les statuts sur lesquels il autorise une action avant d'appeler un Service, ce pré-filtre n'est qu'un indice de l'ensemble "sûr" — il ne protège de rien tant que le Service appelé ne revalide pas le MÊME ensemble, atomiquement, sous le verrou qui protège son écriture. Deux gardes qui devraient être identiques mais sont exprimés différemment (`whereNotIn` d'un côté avec 2 exclusions, `in_array` de l'autre avec 3 inclusions) sont un signal fort de désynchronisation potentielle — même si chacun semble correct isolément (les deux tests `cancelGame()` déjà existants passaient), leur écart laisse une fenêtre de course invisible à toute suite de tests qui n'exerce que l'un des deux mécanismes à la fois. Avant de clore une investigation de race comme "gap structurel probable mais non confirmé", chercher spécifiquement les paires de gardes censées protéger le même invariant à deux endroits différents du code (Job + Service, Controller + Service, etc.) et vérifier qu'ils acceptent exactement le même ensemble — c'est ce contrôle, pas une relecture supplémentaire de `WinConditionChecker` seul, qui a mené à la cause racine ici.

**Statut :** ✅ Résolu — cause racine confirmée par test (reproduction directe de la fenêtre de course), remplace la mention "non confirmé" de l'entrée précédente.

---

## [RÉSOLU] Victime des loups jamais éliminée quand la Sorcière ne clique pas (timeout)

**Contexte :** `fix/witch-timeout-victim-not-eliminated` — `app/Services/RoleActions/WitchAction.php`, `app/Jobs/ProcessWitchAutoAction.php`, `tests/Feature/Game/WitchTest.php`. Bug remonté depuis deux parties de test en prod, indépendant du fix [[fix-night-phase-resync]] livré juste avant.

**Symptôme / Problème :** un joueur tué par les loups apparaissait bien dans le "Déroulé" de l'historique ("Tué cette nuit : X" — le `GameAction night_resolve` créé par `ProcessNightActions` trace correctement la victime potentielle), mais restait marqué vivant (`is_alive = true`) pendant toute la partie, y compris à l'écran final, dès que la Sorcière n'avait cliqué sur rien avant l'expiration de son timer. Seules les éliminations résolues par une action manuelle de la Sorcière (heal/kill/pass via `POST /witch/act`) fonctionnaient.

**Cause / Alternatives :** La mort d'une victime des loups est volontairement différée tant que la Sorcière n'a pas décidé de la sauver (SPEC_TIMERS.md, pattern action volontaire / job auto). Deux chemins clôturent son tour : l'action manuelle (`WitchAction::act()`, branche `pass`) qui finalise correctement la victime (sorcière elle-même, maire en sursis, ou victime ordinaire) avec broadcasts et `hunter_pending` ; et le timeout automatique (`ProcessWitchAutoAction`), qui créait un `GameAction witch_pass` puis dispatchait `ProcessNightEnd` **sans jamais reprendre la logique de finalisation** — la victime restait vivante indéfiniment. Aucune alternative envisagée : c'est un oubli de parité entre les deux chemins de résolution d'un même pattern (déjà documenté comme risque générique dans RISK_GUARDS.md Guard #2/#4 pour d'autres rôles, mais jamais audité spécifiquement pour ce couple `WitchAction::act()`/`ProcessWitchAutoAction`).

**Fix / Décision :**
1. Extraction de la logique de finalisation de la branche `pass` de `WitchAction::act()` (contexte `night_resolve` : sorcière victime non sauvée, maire en sursis, victime ordinaire) en 3 méthodes privées réutilisables dans `WitchAction` : `resolveDeferredVictim()` (DB, à appeler DANS une transaction déjà ouverte par l'appelant — préserve l'atomicité exacte du chemin manuel), `broadcastDeferredVictim()` (events, hors transaction) et `finalizeOrdinaryVictim()` (DB + broadcast, hors transaction, cas victime ni sorcière ni maire). La branche `pass` de `act()` appelle désormais `resolveDeferredVictim()` à la place du code dupliqué ; comportement observable strictement inchangé (mêmes requêtes, même ordre de broadcast — vérifié qu'aucun cas ne peut faire coexister `witchDiedFromWolves` et `deferredMayorVictim` simultanément, un seul maire existant par partie).
2. Nouvelle méthode publique `WitchAction::finalizeTimedOutVictim(Game, GamePlayer)` : ouvre sa propre transaction pour `resolveDeferredVictim()`, puis appelle `broadcastDeferredVictim()` et `finalizeOrdinaryVictim()` hors transaction — même découpage transaction/broadcasts que `act()`, dans une transaction séparée puisque le Job n'a pas de transaction englobante à réutiliser.
3. `ProcessWitchAutoAction::handle()` reçoit désormais `WitchAction` par injection de méthode. Le flag `$shouldFinalize` (vrai uniquement si CE job a créé le `witch_pass`, càd si le guard anti-doublon `alreadyActed` était faux) conditionne l'appel à `finalizeTimedOutVictim()` — si une action manuelle a gagné la course avant le timeout, le job ne re-finalise jamais (pas de double élimination/broadcast).
4. Branche `kill` de `act()` non touchée : elle contient une copie quasi identique de cette même logique (déjà dupliquée avant ce fix, cf. code source), volontairement laissée en l'état — le prompt de tâche scope explicitement l'extraction à la branche `pass`, ne pas étendre le refactor à `kill` pour limiter le risque de régression sur un chemin qui fonctionne déjà.
5. Tests : 5 nouveaux dans `WitchTest.php` — victime ordinaire, sorcière elle-même, maire en sursis (déclenche succession), maire-chasseur en sursis (hunter_pending créé, succession NON déclenchée — priorité tir > succession, RISK_GUARDS.md Guard #2), et guard anti-doublon (action manuelle déjà posée → timeout ne refinalise rien). Les 2 tests existants qui invoquaient `ProcessWitchAutoAction::handle()` directement (bypass de l'injection Laravel) ont dû être mis à jour pour passer `app(WitchAction::class)` explicitement, comme le fait déjà le reste de la suite pour les autres Jobs (`ProcessNightActions`, etc.). 288/288 tests verts (283 avant + 5 nouveaux).
6. Vérification manuelle via `php artisan tinker` (transaction rollback, hors suite de tests) : victime `is_alive` passe bien de `true` à `false` après exécution directe de `ProcessWitchAutoAction::handle()` sur un scénario reproduisant exactement le bug de prod.

**Leçon :** Quand un pattern "action volontaire / job auto" (SPEC_TIMERS.md §2-3) a une branche de résolution complexe côté action manuelle (ici : 3 cas de victime différée selon `night_resolve`), vérifier systématiquement que le job de timeout correspondant reproduit la MÊME logique de finalisation, pas seulement le même `GameAction` de traçabilité — un job de timeout qui se contente de poser l'enregistrement d'audit (`witch_pass`) sans reprendre les effets de bord (élimination, succession, `hunter_pending`) laisse un bug invisible en tests si aucun scénario ne simule explicitement "le joueur n'agit jamais avant l'expiration". Factoriser la logique de finalisation dans le Service (jamais dans le Job, cf. règle CLAUDE.md "Jobs : gestion des timers uniquement") permet aux deux chemins de rester garantis identiques par construction plutôt que par discipline de copier-coller.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Resynchronisation des sous-phases de nuit après refresh/reconnexion

**Contexte :** `fix/night-phase-resync` — migration `add_night_sub_phase_to_games_table`, `app/Models/Game.php`, `app/Services/NightResyncService.php` (nouveau), `app/Http/Controllers/Game/GameController.php` (`state()`), `app/Services/VoteService.php` (`getNightVoteState()` visibilité), `app/Services/PhaseManager.php` (`startNight()`/`startDay()`), `app/Jobs/Process{Seer,Werewolves,Witch,Hunter,Cupidon}Turn.php`, `resources/js/game-state.js`, `resources/views/game/night.blade.php`, `tests/Feature/Game/NightResyncTest.php`.

**Symptôme / Problème :** un loup choisissait sa cible, la page restait figée, et après rechargement il se retrouvait sur l'écran générique d'attente au lieu de l'écran loups — aucun vote enregistré, personne tué cette nuit-là. Cause racine : `nightPhase` (dans `nightScreen()` de `night.blade.php`) est initialisé à `'village_sleeping'` à chaque chargement de page et ne progresse que via des events Echo one-shot (`werewolves-turn-started`, etc.), jamais persistés côté serveur au-delà du statut de haut niveau (`games.status`). Un refresh ou une coupure réseau pendant une sous-phase active faisait perdre l'état sans aucun moyen de rattrapage.

**Cause / Alternatives :** Le mécanisme de resynchro partiel déjà en place (`GameController::state()` → `seer_turn_active`/`werewolves_turn_active`) ne couvrait que 2 des 5 rôles actifs (jamais sorcière/chasseur/cupidon, dont les Jobs — `ProcessWitchTurn`, `ProcessHunterTurn`, `ProcessCupidonTurn` — ne mettaient même pas à jour `phase_deadline`) et reposait sur une heuristique fragile (`phase_deadline` encore dans le futur), cassée dès que la latence du queue worker dépassait le timer réel. Alternative rejetée : dériver la sous-phase active depuis l'historique `game_actions` seul (sans nouvelle colonne) — impossible pour Cupidon/Voyante dont le timeout sans action volontaire ne crée délibérément aucune ligne (SPEC_CUPIDON.md §1), donc aucun moyen de distinguer "tour encore actif" de "tour expiré, phase suivante" sans une vérité serveur explicite.

**Fix / Décision :**
1. Nouvelle colonne `games.night_sub_phase` (nullable), mise à jour par chacun des 5 `ProcessXTurn` au moment exact où ils broadcastent `XTurnStarted` (`phase_deadline` également posé pour Witch/Hunter/Cupidon, qui ne le faisaient jamais avant — Seer/Werewolves le faisaient déjà). Purgée à `null` à chaque entrée dans un nouveau round de nuit (`PhaseManager::startNight()`, `VoteService::resolveMayorElection()` pour le round 1) et à la sortie de nuit (`PhaseManager::startDay()`), pour ne jamais laisser une valeur d'un round précédent fuiter dans le round suivant.
2. `NightResyncService::currentSubPhase(Game, GamePlayer): ?array` — lecture seule, ne modifie aucun état, ne touche à aucune logique de résolution/vote. Retourne `null` sauf si `night_sub_phase` correspond exactement au rôle du joueur demandeur (le Chasseur est le seul cas où "correspondre" exige `!is_alive` plutôt que `is_alive`, son tour n'ayant lieu qu'après sa mort). Payload par rôle : `already_acted` (lu depuis `game_actions`, jamais recalculé autrement), `remaining_seconds` (déduit de `phase_deadline`, jamais une durée pleine relancée), et le payload minimal nécessaire à l'écran (cibles éligibles + état de vote pour les loups, victime/potions pour la sorcière, résultat d'inspection si déjà agi pour la voyante).
3. Câblé dans `GameController::state()` (déjà l'endpoint de resynchro canonique, appelé au chargement de page ET par `game-state.js::_reconnect()`) sous une nouvelle clé `night_action`, additive — `seer_turn_active`/`werewolves_turn_active` et leur heuristique fragile existante sont laissés totalement inchangés (3 tests `ReconnectionTest` les asserte directement) : périmètre strictement limité au nouveau mécanisme, pas de refactor de l'existant qui fonctionne déjà par ailleurs.
4. Gap volontairement non couvert : le Chasseur peut aussi tirer depuis la phase JOUR (maire-chasseur éliminé au vote) — `NightResyncService` ne s'applique qu'à `$game->isNightPhase()` par construction (le titre de la tâche et la spec scopent explicitement "sous-phases de **nuit**"). Un refresh pendant un tir de chasseur en phase jour reste un gap préexistant, non aggravé ni corrigé ici — consigné dans BUGS_AND_ROADMAP.md.
5. Côté client, **un seul chemin d'application** (`nightScreen().applyNightResync()`) pour les deux sources (event Echo live ET rattrapage `/state`) — les 5 listeners `X-turn-started` ont été refactorés pour construire un payload synthétique (`already_acted: false`, `remaining_seconds` = durée pleine) et le passer à `applyNightResync()` plutôt que dupliquer leur propre logique de reset. Une garde `isNewPhase` (`this.nightPhase !== data.phase`) décide si les champs de reset (sélection en cours, chat, timer) doivent être appliqués — le premier chemin à fixer `nightPhase` sur la bonne valeur gagne de façon déterministe ; les appels suivants (resync après un event déjà reçu, ou vice-versa) ne rafraîchissent que les champs sûrs (`already_acted`, état des votes). Analyse de la course possible : au chargement de page, `_loadState()` (HTTP) s'exécute intégralement AVANT `initWebSocket()` (souscription Echo) dans `game-state.js::init()` — un broadcast déjà émis avant la souscription ne sera jamais redélivré (pub/sub, pas de replay), donc aucune collision possible à l'ouverture de page. À la reconnexion Echo, la fenêtre de course est réelle mais étroite (broadcast arrivant pendant le court intervalle entre la resouscription Echo et la réponse de `_loadState()`) — la garde `isNewPhase` la rend inoffensive dans les deux ordres d'arrivée.
6. Timer recalculé : `_startTimerBar(selector, fullSeconds, remainingSeconds)` pose la largeur initiale de la barre GSAP à `(remaining/full)*100%` puis anime vers 0% sur `remainingSeconds` — jamais une animation pleine durée relancée depuis 100%.
7. Tests : `tests/Feature/Game/NightResyncTest.php` (23 tests) — pour chacun des 5 rôles : refresh sans action soumise, refresh avec action déjà soumise, reconnexion (`POST /reconnect` puis `GET /state`, même endpoint que le refresh côté client — il n'existe pas de mécanisme de resynchro distinct pour "reconnexion Echo" par construction) ; 2 cas négatifs (rôle ne correspondant pas à la sous-phase active, statut hors nuit malgré une valeur `night_sub_phase` non purgée) ; 6 tests de persistance directe (chaque `ProcessXTurn` pose bien `night_sub_phase`/`phase_deadline`, `startNight()` purge bien le round précédent). 283/283 tests verts (260 avant + 23 nouveaux, aucun cassé).
8. Scénario manuel non rejoué en conditions réelles : l'application exige une authentification Google OAuth réelle (SPEC.md §2, aucun contournement de login en environnement local) — impossible à automatiser sans identifiants réels ni ajouter une route de test dédiée (hors périmètre demandé). Vérifications de substitution effectuées : rendu direct de `night.blade.php` via `view()->render()` sans exception, validation syntaxique de chaque bloc `<script>` extrait du rendu (`node --check`), `npm run build` sans erreur, et les 23 tests `NightResyncTest` reproduisent exactement les conditions du bug rapporté (vote loup en base sans confirmation client, puis GET /state simulant le refresh). Rejeu interactif humain recommandé avant mise en prod.

**Leçon :** Un mécanisme de resynchro basé sur une heuristique dérivée (`phase_deadline` encore futur) plutôt qu'une vérité explicite persistée se casse silencieusement dès que la latence réelle (queue worker, réseau) dépasse l'hypothèse implicite du calcul — et ce genre de régression ne se voit qu'en production, jamais dans une suite de tests qui n'exerce pas de vrai délai. Quand un rôle a un comportement de timeout qui n'écrit délibérément rien en base (Cupidon, SPEC_CUPIDON.md §1), la resynchro ne peut pas se contenter de relire `game_actions` : il faut une colonne dédiée qui reflète la dernière transition de sous-phase, mise à jour exactement au même endroit où l'event WebSocket correspondant est broadcasté (jamais recalculée ailleurs, sous peine de désynchronisation entre les deux). Unifier le chemin "event live" et le chemin "resync" en une seule fonction cliente élimine par construction tout risque de double-reset entre les deux sources, plutôt que de dupliquer la logique et espérer que les deux copies restent synchronisées dans le temps.

**Statut :** ✅ Résolu

---

## [CHOIX] Vote jour rendu public — inverse la règle d'anonymat, symétrique au vote maire (Bug 5)

**Contexte :** `feature/day-vote-public` — `app/Events/Game/DayVoteCast.php`, `app/Http/Controllers/Game/VoteController.php` (`day()`), `app/Http/Controllers/Game/GameController.php` (`history()`), `app/Services/HistoryService.php` (`buildTimeline()`), `resources/js/game-state.js` (`_handleDayVoteCast`), `resources/views/game/history.blade.php`, `tests/Unit/Events/EventPayloadTest.php`, `SPEC.md` §"Visibilité des votes".

**Symptôme / Problème :** Le vote jour était anonyme par design depuis l'origine du projet (`DayVoteCast` ne transportait jamais l'auteur, `history()` appliquait `anonymized()` sur `day_vote`) — un choix de design volontaire, documenté comme distinct du vote maire (rendu public par le Bug 5, corrigé le 24/06). Décision utilisateur explicite d'inverser cette règle : le vote jour doit désormais avoir le même niveau de transparence que le vote maire.

**Cause / Alternatives :** Ce n'est pas un bug ni un oubli de symétrie — c'est un changement de règle de jeu assumé, demandé explicitement par l'utilisateur, qui annule l'argument initial ("tension sociale différente de l'élection du maire : accusation publique vs vote secret"). Aucune alternative technique à trancher : le pattern à suivre était déjà entièrement défini par `MayorVoteCast`/`castMayorVote()`/le bloc `mayor_vote sans anonymized()` de `history()`/le bloc `vote_details` de l'entrée `election` dans `HistoryService` — reproduit à l'identique pour `day_vote`.

**Fix / Décision :**
1. `DayVoteCast` : constructeur étendu avec `voterPseudo`/`targetPseudo` (mêmes types que `MayorVoteCast`), ajoutés au payload `broadcastWith()`. Docblock de classe réécrit (retrait des mentions anonymisation/`⚠️ Pas de player_id`, remplacé par la note de visibilité publique symétrique).
2. `VoteController::day()` : récupère le pseudo de la cible via `GamePlayer::find($targetId)->pseudo` (pas de pseudo pré-chargé disponible côté `castDayVote()`, contrairement à `castMayorVote()` qui retourne déjà les pseudos dans ses totaux) et passe `$player->pseudo` comme pseudo du votant.
3. `GameController::history()` : `day_vote` sorti de la requête `$otherActions` (`anonymized()`), nouvelle requête dédiée `$dayVoteActions` sans `anonymized()`, mergée dans `$actions` au même titre que `$mayorVoteActions`.
4. `HistoryService::buildTimeline()` : `$dayEntry` reçoit une clé `vote_details` (array de `{voter_pseudo, target_pseudo}`), construite depuis `$dayVotes` exactement comme `vote_details` pour l'entrée `election` depuis `$mayorVotes`.
5. `history.blade.php` : bloc `vote_details` dupliqué à l'identique dans la branche `@elseif($entry['type'] === 'day')`.
6. `game-state.js` : `_handleDayVoteCast` affiche un toast si `voter_pseudo`/`target_pseudo` présents, emoji 🗳️ (distinct de 👑 réservé au maire) — même structure conditionnelle que `_handleMayorVoteCast`.
7. `SPEC.md` : bloc "Vote jour — anonyme" remplacé par "Vote jour — public" sur le modèle du bloc maire, avec une note de décision datée (2026-07-23) expliquant l'inversion assumée. Table des events (§6) mise à jour pour `DayVoteCast`.
8. Tests : `EventPayloadTest::test_day_vote_cast_payload_ne_contient_pas_player_id` mis à jour pour construire `DayVoteCast` avec les 2 nouveaux paramètres et vérifier la présence de `voter_pseudo`/`target_pseudo` (comme l'équivalent maire). Aucun autre test n'assertait spécifiquement l'anonymat du vote jour (`GameActionTest` teste le scope `anonymized()` générique avec `day_vote` comme simple exemple de données, pas une assertion sur son usage dans `history()` — non modifié). `weight = 2` maire sur `day_vote` non touché : seule la visibilité change, la résolution du vote (`VoteService::castDayVote`/`resolveDayVoteWinner`) est restée intacte. 254 tests verts (hors 6 échecs pré-existants `WinConditionCheckerTest`, dépendance à Reverb non démarré localement, sans rapport avec cette tâche).

**Leçon :** Un bloc de code documenté comme "décision de design volontaire" (distinct d'un bug déjà corrigé sur un système symétrique) reste réversible sur demande explicite de l'utilisateur — l'inversion se fait alors en dupliquant fidèlement le pattern déjà validé sur le système frère (ici : vote maire) plutôt qu'en réinventant la structure. Vérifier systématiquement les points de duplication implicite entre deux systèmes symétriques (event, controller, historique, timeline, vue, toast) avant de déclarer la tâche terminée — un seul point oublié (ex. le toast JS ou `vote_details` dans la timeline) aurait laissé la transparence incomplète malgré un payload déjà public.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Cupidon absent du résultat d'inspection Voyante — repli silencieux sur "❓"/"Rôle inconnu" dans night.blade.php

**Contexte :** suite de la Phase 46 ([[cupidon-role-display-tables]] si référencé ailleurs) — `resources/views/game/night.blade.php`, fonctions `roleEmoji(role)`/`roleLabel(role)` de `nightScreen()`, utilisées par l'écran `nightPhase === 'seer_result'`.

**Symptôme / Problème :** une Voyante inspectant un joueur Cupidon voyait un "❓" (repli de `roleEmoji`) à la place de l'icône 💘, et le label "Rôle inconnu." à la place d'un texte dédié. Ce composant n'avait pas été corrigé lors de la Phase 46 (audit des 9 tables rôle→icône/couleur/label) car son pattern diffère des 9 tables déjà traitées : `roleEmoji`/`roleLabel` sont deux objets JS `{role: valeur}` avec repli `?? '❓'` / `?? 'Rôle inconnu.'`, alors que le bug déjà corrigé dans `role-reveal.blade.php` était une condition en liste blanche d'exclusions (`role !== 'x' && ...`). Le grep de la Phase 46 (`witch`+`hunter` combinés) aurait dû trouver ces deux objets (ils contiennent bien `witch:` et `hunter:`) — l'écart n'est pas un problème de grep mais un oubli du composant lui-même dans le recensement initial des "9 tables", ce composant n'ayant apparemment pas été inclus dans le périmètre alors qu'il correspond au même type de structure clé→valeur.

**Cause / Alternatives :** Cause : le composant du résultat d'inspection Voyante (`seer_result`) est un cas déjà connu et corrigé une première fois pour un bug voisin — DECISIONS.md "Voyante affichait Innocent pour Chasseur et Sorcière" (2026-06-23), qui a introduit `roleEmoji`/`roleLabel` avec seulement 5 rôles (`werewolf`, `villager`, `seer`, `witch`, `hunter`) car Cupidon n'existait pas encore à cette date. La Phase 46 (ajout de Cupidon aux tables de rôle) est arrivée un mois plus tard et n'a pas ré-audité ce composant précis. Aucune alternative — simple ajout des deux entrées manquantes.

**Fix / Décision :** `cupidon: '💘'` ajouté à `roleEmoji()` et `cupidon: 'Cupidon — Innocent.'` ajouté à `roleLabel()` (même format que les 5 entrées existantes : `'Rôle — Innocent.'` pour les rôles village, phrase distincte pour `werewolf`). Couleur `#f472b6` déjà standardisée pour Cupidon (DECISIONS.md Phase 46) non réintroduite ici : la couleur du texte du résultat (`#f87171` rouge / `#4ade80` vert) reste pilotée par le booléen binaire `seerResult.isWerewolf` (`data.role === 'werewolf'`), non modifié — un Cupidon inspecté reste affiché en vert "innocent", cohérent avec tous les rôles village-side existants (aucune 3e couleur introduite dans ce composant, la spec ne le demandait pas).

Audit de précaution (Étape 4 de la tâche) : grep `default:`/`match (`/`switch (` combinés à `role` sur `resources/` et `app/` a révélé 2 emplacements supplémentaires avec exactement le même bug (repli silencieux sur `Villageois` pour Cupidon dans un `match` PHP) :
1. `day.blade.php` ligne ~101 — message d'en-tête "C'était un ..." pour la victime de la nuit précédente, `match($nightVictim->role) { 'werewolf' => ..., 'seer' => ..., default => 'Villageois' }` — ne couvre même pas witch/hunter, donc n'aurait pas été trouvé par le grep `witch`+`hunter` de la Phase 46 (raison identique à celle documentée dans l'entrée Phase 46 : grep basé sur la présence des deux mots-clés ensemble).
2. `GameController::state()` (`app/Http/Controllers/Game/GameController.php`, endpoint `/state` utilisé pour le polling client) — `revealed_role_label` couvre bien werewolf/seer/witch/hunter mais pas `cupidon`, default `'Villageois'`. Celui-ci contient pourtant `witch`+`hunter` et aurait dû être trouvé par le grep de la Phase 46 — laissé de côté à l'époque car le grep semble avoir été limité à `resources/` (vues), sans couvrir les Controllers PHP.

Ces deux emplacements n'ont **pas** été corrigés dans cette tâche : le prompt de tâche scope explicitement "ce composant spécifiquement" (le résultat d'inspection Voyante) et demande de rapporter, pas de corriger, une éventuelle 3e occurrence. Consignés dans BUGS_AND_ROADMAP.md pour un futur fix ciblé.

**Leçon :** Un audit "grep X+Y combinés" scope son résultat aux fichiers où le grep a effectivement tourné — si le périmètre du grep exclut un dossier (ici `app/Http/Controllers/`, contrairement à `resources/`), un site de correspondance exacte au motif recherché peut être manqué sans que ce soit un problème de motif de recherche. Après un audit "9 tables trouvées par grep", ne pas assumer l'exhaustivité sans vérifier le périmètre réel de la commande (dossiers inclus) — et refaire un second grep plus large (`match (`/`switch (`/`default:` combinés à `role`, sans co-occurrence obligatoire de deux rôles) est le seul moyen de couvrir aussi les cas où un rôle n'a jamais été ajouté du tout (day.blade.php ne connaissait même pas witch/hunter).

**Statut :** ✅ Résolu

---

## [RÉSOLU] Cupidon absent de toutes les tables rôle→icône/couleur/label d'affichage — repli silencieux sur Villageois dans role-reveal.blade.php

**Contexte :** `feat/cupidon-role-display` — grep exhaustif de `witch`+`hunter` sur `resources/` et `app/` demandé pour localiser toutes les tables de correspondance rôle → icône/couleur/label. Cupidon est fonctionnellement complet depuis les Phases 37→45 (TODO.md) mais n'avait jamais été ajouté à aucune table d'affichage visuel du rôle.

**Symptôme / Problème :** 9 tables trouvées où Cupidon manquait (`mayor-election.blade.php`, `role-card.blade.php`, `player-list.blade.php`, `finished.blade.php` ×2, `summary.blade.php`, `history.blade.php`, `day.blade.php` ×2, `role-reveal.blade.php`, `game-state.js`). Dans la plupart des cas le symptôme était bénin (label brut `cupidon` affiché, ou couleur de repli beige `#e8e0d0`). Mais dans `role-reveal.blade.php` (écran de révélation du rôle en tout début de partie), le bloc Villageois était affiché via `x-show="role !== 'werewolf' && role !== 'seer' && role !== 'witch' && role !== 'hunter'"` — une condition en liste blanche inversée. Un joueur Cupidon ne correspondait à aucune des quatre exclusions et tombait donc dans ce bloc par défaut : icône 🧑‍🌾, nom "Villageois", description villageois — un joueur Cupidon voyait un rôle entièrement faux à l'écran le plus visible du jeu (première impression de partie), sans aucune erreur ni log.

**Cause / Alternatives :** Cause : la condition du bloc Villageois est une liste blanche d'exclusions maintenue manuellement, jamais mise à jour lors de l'ajout de Cupidon (Phases 37-45), contrairement aux tables `match()` PHP qui échouent de façon plus visible (label brut affiché) plutôt que de se faire passer pour un autre rôle. Alternative envisagée : inverser la logique en liste blanche positive (`role === 'villager'`) pour éviter ce type de régression à l'ajout d'un futur rôle (v1.4+ Loup Blanc, Petite Fille) — non retenue ici pour rester strictement dans le périmètre demandé (« ne change aucune icône/couleur/label des rôles existants ») ; le risque est noté dans BUGS_AND_ROADMAP.md (roadmap) plutôt que corrigé maintenant.

**Fix / Décision :** Couleur rose déjà utilisée dans le projet retrouvée par grep (`#f472b6`, 4 occurrences dans `night.blade.php` pour l'UI Cupidon spécifique : tour de jeu, sélection de cible, révélation d'amoureux) — confirmée sans conflit avec les couleurs des autres rôles (Voyante `#a78bfa`/`#7c3aed` violet, Sorcière `#3493d3` bleu, Chasseur `#fbbf24` or, Loup-Garou `#f87171`/`#ff4444`/`#8b0000` rouge, Villageois `#e8e0d0`/`#4ade80` selon contexte). Cette même valeur hex ajoutée telle quelle (jamais réinventée) dans les 9 tables, avec l'icône `💘` (nouvelle mais cohérente avec `💞` déjà présent dans le contexte Cupidon de `night.blade.php`). Dans `role-reveal.blade.php`, la condition Villageois étendue avec `&& role !== 'cupidon'` et un bloc dédié Cupidon ajouté à la suite du bloc Chasseur, suivant exactement le même patron (icône/nom/description conditionnés par `x-show`, pas de classe CSS `.card-front.cupidon` dédiée — Sorcière et Chasseur n'en ont pas non plus, seuls Loup-Garou/Voyante/Villageois en ont une, incohérence pré-existante non touchée).

**Leçon :** Quand un nouveau rôle est ajouté au projet, une condition en liste blanche d'exclusions (`role !== 'a' && role !== 'b' && ...`) pour un bloc "par défaut" est un point de régression silencieux — contrairement à un `match()` PHP avec `default`, qui expose au moins le nom brut du rôle inconnu, ce pattern fait passer le nouveau rôle pour un rôle existant sans aucun signal. Après tout ajout de rôle, grep spécifiquement `!== 'roleA' && !== 'roleB'` (ou `!=`) en plus du grep habituel sur les `match()`/tableaux associatifs, qui ne suffit pas à couvrir ce genre de garde.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Cupidon jamais déclenché au round 1 — chemin d'élection du maire oublié

**Contexte :** `fix/cupidon-missing-from-mayor-election-path` — `app/Jobs/ProcessMayorElection.php`, `app/Services/PhaseManager.php`, `tests/Feature/Game/ProcessMayorElectionTest.php`.

**Symptôme / Problème :** La condition « round 1 + Cupidon distribué → dispatcher `ProcessCupidonTurn` plutôt que `ProcessSeerTurn` » (SPEC_CUPIDON.md §3, ajoutée Phase 40) n'existait que dans `PhaseManager::startNight()`, qui gère les transitions `day`/`processing_day` → `night` (rounds 2+). Le tout premier round de la partie transite par `electing_mayor` → `night` via `ProcessMayorElection::handle()`, qui dispatchait `ProcessSeerTurn` inconditionnellement (ligne 89) — Cupidon n'était donc jamais déclenché au round 1, alors que c'est précisément le seul round où il agit (round 1 exclusivement, guard `PhaseGuard::canCupidonLink()`). Aucune partie avec Cupidon activé ne pouvait jamais former de couple.

**Cause / Alternatives :** Deux points d'entrée distincts vers la nuit (`electing_mayor` → `night` la première fois, `day`/`processing_day` → `night` ensuite), chacun avec son propre Job, et la condition Cupidon n'avait été branchée que dans un seul (Phase 40, dont le prompt ne mentionnait que `PhaseManager::startNight()`). Alternative rejetée : dupliquer le bloc `if`/`else` dans `ProcessMayorElection::handle()` — aurait recréé le même risque d'oubli au prochain rôle « premier tour de nuit » (ex. futur v1.4+).

**Fix / Décision :** Factorisation dans `PhaseManager::dispatchNightOpeningTurn(Game $game, string $timerName)` — même logique (`round === 1 && Cupidon distribué` → `ProcessCupidonTurn`, sinon `ProcessSeerTurn`), le nom du timer passé en paramètre plutôt que figé en dur car les deux appelants utilisent des timers sémantiquement différents (`night_start_delay` depuis `startNight()`, `mayor_reveal` depuis `ProcessMayorElection` — laisse le temps à l'UI d'afficher `MayorElected` avant la première nuit). `ProcessMayorElection::handle()` reçoit `PhaseManager` par injection de méthode (comme `VoteService`) et appelle `dispatchNightOpeningTurn($result['game'], 'mayor_reveal')` à la place du dispatch direct — le try/catch autour des broadcasts `MayorElected`/`NightStarted` est resté strictement inchangé. Grep `ProcessSeerTurn::dispatch` sur tout `app/` : 3 autres call sites trouvés (`ProcessCupidonTurn`, `ProcessCupidonAutoAction`, `CupidonAction::link()`) mais tous sont des dispatches *après* que le tour de Cupidon a déjà eu lieu ou a expiré — pas des points de décision « ouverture de nuit » — laissés inchangés à dessein.

**Leçon :** Quand une condition de branchement est ajoutée à un seul point d'entrée d'un flux qui en a plusieurs (ici : deux chemins distincts vers `night`, un seul pour round 1), toujours `grep` le nom du Job/event visé (`ProcessSeerTurn::dispatch` ici) dans tout `app/` avant de considérer la tâche terminée — et distinguer les call sites qui *décident* du prochain tour de ceux qui se contentent d'y *enchaîner* après coup (ces derniers ne doivent pas passer par la méthode factorisée).

**Statut :** ✅ Résolu

---

## [CHOIX] Cupidon activé par défaut — annule le défaut désactivé décidé en Étape 4

**Contexte :** `fix/cupidon-role-settings-missing` — `config/game.php` (`roles.cupidon`), `app/Services/RoleDistributor.php` (docblock), `tests/Feature/Game/RoleSettingsTest.php`, `resources/views/game/waiting-room.blade.php`. Suite directe de la tâche "Cupidon absent des rôles configurables côté host" (voir BUGS_AND_ROADMAP.md) : une fois `cupidon` ajouté à `GameSettingsService::validateRoleSettings()` et à la modale host, l'utilisateur a explicitement demandé que Cupidon soit activé par défaut, au même titre que witch/hunter.

**Symptôme / Problème :** Ce choix annule directement la décision documentée en "Cupidon Étape 4" (`config/game.php roles.cupidon => 0`), qui reposait sur l'argument "zéro régression, aucun host n'a demandé cette activation" — argument qui ne tient plus puisque l'hôte peut désormais explicitement le configurer, et que l'utilisateur demande maintenant la parité avec witch/hunter.

**Cause / Alternatives :** Aucune alternative réelle — instruction explicite et sans ambiguïté de l'utilisateur. Seul arbitrage technique : `computeCounts()` (`RoleDistributor`) inclut Cupidon dans la même passe que witch/hunter, donc toute partie déjà testée avec un décompte implicite `villager = total - (loups+voyante+witch+hunter)` voit son nombre de villageois réduit d'une unité dès que Cupidon est actif par défaut. Vérifié par grep qu'aucun test hors `RoleSettingsTest` n'appelle `RoleDistributor::distribute()` directement — seul ce fichier avait des assertions de comptage à corriger (`test_role_distributor_remplit_villageois_automatiquement` : villageois 3→2, cupidon ajouté aux assertions).

**Fix / Décision :** `config/game.php roles.cupidon => 1`. `waiting-room.blade.php` (`roleSettings()`) : fallback `roles.cupidon` `?? 0` → `?? 1`, cohérent avec witch/hunter. `RoleDistributor` docblock mis à jour (retrait de la mention "désactivé par défaut"). Test `test_role_distributor_ninclut_pas_cupidon_par_defaut` remplacé par `test_role_distributor_inclut_cupidon_par_defaut` (assertion inversée) ; nouveau test `test_host_peut_desactiver_cupidon` ajouté (le host garde la capacité de désactiver Cupidon via `{cupidon: 0}`, seul le défaut change) ; `test_role_distributor_remplit_villageois_automatiquement` mis à jour (villageois 3→2, assertion cupidon=1 ajoutée). L'entrée DECISIONS.md "Cupidon Étape 4" n'est pas modifiée (elle documente un choix qui était correct au moment où il a été pris, avant que la configurabilité host n'existe) — cette entrée-ci documente le renversement explicite.

**Leçon :** Une décision "zéro régression" prise à un stade où une fonctionnalité n'est pas encore configurable (ici : Cupidon sans UI host) n'est pas figée — dès que la configurabilité existe, l'argument initial ne s'applique plus et le défaut peut légitimement changer sur demande explicite. Avant de changer un défaut de rôle lu par `RoleDistributor::computeCounts()`, toujours `grep` les tests qui appellent `distribute()` directement (pas seulement ceux qui passent par un flux HTTP complet) — un changement de défaut redistribue silencieusement les villageois restants et casse les assertions de comptage exact, pas seulement les assertions de présence/absence du rôle changé.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Cupidon Étape 7 — Tests d'intégration bout en bout : Event::fake()+Queue::fake() avec chaînage manuel des Jobs, plutôt qu'un flux 100% réel

**Contexte :** `test/cupidon-integration` — `tests/Feature/Game/CupidonTest.php` (nouveau, 9 tests). Étape 7 de l'implémentation Cupidon (SPEC_CUPIDON.md §8 tâche 7), validation finale avant le tag v1.3.0. Toutes les briques Cupidon étaient déjà en place et mergées sur `dev` (schéma, cascade, action, intégration nuit, win condition, frontend — Phases 37 à 42 du TODO), avec 243 tests verts.

**Symptôme / Problème :** Le prompt de tâche demande explicitement "pas des mocks unitaires isolés comme dans les étapes précédentes — de vraies parties factory jusqu'au bout". Cette formulation pouvait se lire comme "n'utiliser aucun fake" (`Event::fake()`/`Queue::fake()`), ce qui aurait eu deux conséquences : (1) `QUEUE_CONNECTION=sync` en test exécute chaque `Job::dispatch()->delay(...)` immédiatement et en cascade complète dès le premier appel — impossible de simuler "action volontaire avant le timeout" puisque le Job de timeout s'exécuterait avant que le test ait pu poster l'action HTTP ; (2) plusieurs events (`GameFinished`, `PhaseAnnouncement`) ne sont protégés par aucun `try/catch` autour de leur `broadcast()` (contrairement à `PlayerJoined`/`MayorElected`/`NightStarted`, corrigés par les fixes "broadcast non protégé" documentés plus bas dans ce fichier) — sans Reverb réellement démarré en local, ces broadcasts auraient fait échouer les tests avec une `BroadcastException`, ce qui a été effectivement observé sur `WinConditionCheckerTest` (préexistant, non lié à cette tâche) quand Reverb n'était pas lancé pendant l'exécution de la suite complète.

**Cause / Alternatives :** Toute la suite Feature existante (`NightPhaseTest`, `DayPhaseTest`, `WitchTest`, `HunterTest`, `CupidonNightIntegrationTest`, `CupidonJobsTest`) utilise déjà systématiquement `Event::fake()` + `Queue::fake()`, puis invoque manuellement chaque `Job->handle()` dans l'ordre exact de production (au lieu de compter sur le `delay()` réel, impossible à attendre en test). C'est ce pattern — déjà la convention établie du projet pour un test "d'intégration" — qui a été repris ici, et non un flux 100 % non-mocké. La distinction que demandait le prompt n'est pas "faker ou non les events/jobs", mais "driver une vraie partie factory de bout en bout à travers les vrais Services (`CupidonAction`, `PlayerEliminationService`, `VoteService`, `WinConditionChecker`, `PhaseManager`) plutôt que de tester une seule méthode isolée avec des guards en boîte noire" — ce que `CupidonActionTest` (Unit) et `CupidonJobsTest` (dispatch isolé d'un seul Job) ne font pas. Alternative envisagée (laisser tourner Reverb réel sans aucun fake) — rejetée : ajoute une dépendance externe fragile à la suite de tests (déjà observée comme source d'échec sur `WinConditionCheckerTest`), pour un bénéfice nul (aucune assertion du prompt ne porte sur le contenu réel des payloads WebSocket).

**Fix / Décision :** `CupidonTest.php` couvre les 6 scénarios demandés (couple round 1 avant la Voyante, auto-sélection, timeout sans couple + non-réapparition au round 2, cascade de mort testée aux 4 points d'entrée réels — loups/`ProcessNightActions`, sorcière/`WitchAction::act('kill')`, vote jour/`VoteService::resolveDayVote()`, chasseur/`HunterAction::shoot()` —, victoire amoureux loup+villageois avec priorité sur le calcul classique, et partie sans Cupidon strictement inchangée). Pour les 4 sous-tests de cascade, `lover_player_id` est posé directement via `GamePlayer::update()` (comme le fait déjà `WinConditionCheckerTest::linkLovers()`) plutôt que via `CupidonAction::link()` — l'objet de ce scénario est de vérifier que les points d'entrée réels d'élimination déclenchent bien la cascade, pas de retester les guards de `CupidonAction` (déjà couverts ailleurs). Pour les scénarios 1, 2, 3 et 5 (qui impliquent le tour de Cupidon lui-même), la séquence complète est bien déclenchée via `PhaseManager::startNight()` → `ProcessCupidonTurn`/`ProcessCupidonAutoAction` → `POST /game/{id}/cupidon/link` réel → `ProcessSeerTurn` → `ProcessWerewolvesTurn` → `POST /vote/night` réel → `ProcessNightActions` → `ProcessNightEnd`, jusqu'à la vraie transition de jour en base. Reverb a été démarré localement (`php artisan reverb:start`) pour que la suite complète (252 tests, y compris `WinConditionCheckerTest` non modifié par cette tâche) passe à 100 % : voir ROADMAP dans `BUGS_AND_ROADMAP.md` pour la suggestion de corriger cette fragilité préexistante indépendamment.

**Leçon :** Quand un prompt dit "de vraies parties factory jusqu'au bout, pas des mocks isolés", vérifier d'abord la convention déjà établie dans les fichiers de test voisins avant de l'interpréter comme "aucun fake" — ici, `Event::fake()`/`Queue::fake()` + chaînage manuel des Jobs EST le pattern d'intégration du projet (`QUEUE_CONNECTION=sync` empêcherait de toute façon de simuler un timing réaliste action-volontaire-avant-timeout sans eux). Un test qui n'utilise aucun fake et broadcaste réellement (`WinConditionCheckerTest`) rend la suite dépendante d'un service externe (Reverb) démarré ou non sur la machine qui exécute `php artisan test` — une fragilité à corriger séparément (voir ROADMAP), pas une raison d'appliquer le même anti-pattern à de nouveaux tests.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Cupidon Étape 6 — Frontend : notification amoureux en modale directe, sélection libre incluant soi-même

**Contexte :** `feat/cupidon-frontend` — `routes/web.php`, `app/Http/Requests/CupidonLinkRequest.php` (nouveau), `app/Http/Controllers/Game/ActionController.php`, `app/Services/GameService.php`, `resources/views/game/night.blade.php`, `resources/js/game-state.js`. Étape 6 de l'implémentation Cupidon (SPEC_CUPIDON.md §7, §8 tâche 6), suite de l'Étape 5 ([[cupidon-win-condition]] si référencé ailleurs). Backend déjà complet et testé (243 tests verts avant cette étape) : `CupidonAction::link()`, `ProcessCupidonTurn`/`ProcessCupidonAutoAction`, intégration `PhaseManager::startNight()` et `WinConditionChecker` tous en place — seule la couche HTTP + UI manquait.

**Symptôme / Problème :** Deux écarts à trancher, non résolus par le prompt de tâche tel quel :
1. Le pattern Hunter (modèle explicite imposé par le prompt) filtre systématiquement le joueur courant de la liste de cibles (`$players->filter(fn($p) => $p->id !== $player->id && ...)`) et valide `Rule::notIn($selfId)` côté `FormRequest`. Or SPEC_CUPIDON.md §1/§4 autorise explicitement Cupidon à se choisir lui-même comme un des deux amoureux — copier le pattern Hunter à l'identique aurait cassé l'auto-sélection déjà supportée côté backend (`CupidonAction::link()` ne rejette que `target1Id === target2Id`, jamais une cible égale à `$cupidon->id`).
2. Le pattern de notification personnelle "après résultat sorcière" (`_applyDayStarted()` dans `game-state.js`) pousse le message dans `sessionStorage` plutôt que de le dispatcher directement, car ce handler déclenche une redirection GSAP qui détruit la page `/night` avant qu'un composant toast Alpine ait pu monter. `LoverRevealed` n'a pas ce problème — il arrive pendant le tour de Cupidon (round 1, avant tout `NightStarted`/`DayStarted`), sur une page `/night` déjà stable. Fallait-il quand même utiliser le détour `sessionStorage` par prudence, ou dispatcher directement ?

**Cause / Alternatives :**
1. Copier `HunterShootRequest` à l'identique (avec `Rule::notIn($selfId)`) aurait introduit une régression fonctionnelle invisible aux tests existants (aucun test HTTP Cupidon n'existait avant cette étape) mais bloquante au premier essai réel d'auto-sélection. Alternative retenue : la liste de cibles dans `night.blade.php` n'exclut que `is_alive` (pas `id !== $player->id`), et `CupidonLinkRequest` valide uniquement que les deux cibles sont vivantes et **distinctes entre elles** (`different:target1_player_id`), jamais distinctes de Cupidon — cohérent avec les guards déjà en place dans `CupidonAction::link()` (SPEC_CUPIDON.md §4 point 4 : "`target1Id` ou `target2Id` peut valoir `$cupidon->id`").
2. Utiliser le détour `sessionStorage` partout "par cohérence visuelle" avec le pattern sorcière aurait ajouté de la complexité sans bénéfice : ce détour existe pour contourner un problème précis (redirection immédiate qui détruit la page), absent ici. Alternative retenue : `LoverRevealed` est reçu via `window.dispatchEvent` classique (identique au pattern `seer-result`/`witch-turn-started`) et affiché dans une modale dédiée (`loverRevealed` + `loverPartnerPseudo`), sur le modèle visuel de la modale succession déjà existante (`succession-modal`), avec auto-fermeture à 8s et bouton "J'ai compris" — cohérent avec SPEC_CUPIDON.md §7 ("toast/écran personnel"). Le listener est enregistré **sans condition de rôle** (contrairement aux autres tours privés gated par `isSeer`/`isWitch`/etc.) car n'importe quel rôle peut être un amoureux, pas seulement Cupidon.

**Fix / Décision :**
1. Route `POST /game/{id}/cupidon/link` ajoutée dans le même groupe `auth` + `throttle:60,1` que `hunter/shoot`, juste avant dans `routes/web.php`.
2. `CupidonLinkRequest` créée sur le modèle de `HunterShootRequest`, avec deux champs (`target1_player_id`, `target2_player_id`), tous deux validés vivants + dans la partie, et `different:target1_player_id` entre eux (pas de `notIn` sur l'id de Cupidon).
3. `GameService::cupidonLink()` ajoutée (délégation `app(CupidonAction::class)->link(...)`, même pattern que `hunterShoot()`/`witchAct()`) ; `ActionController::cupidonLink()` ajouté avant `seerCheck()` (ordre chronologique du tour de nuit) — plus simple que `hunterShoot()`/`seerCheck()` : `CupidonAction::link()` gère déjà en interne le broadcast `LoverRevealed` et le dispatch de `ProcessSeerTurn`, le Controller se contente d'appeler le Service et retourner `success`.
4. `night.blade.php` : nouveau bloc `nightPhase === 'cupidon_turn' && isCupidon` (position 0, avant le bloc Voyante) avec sélection multiple (clic toggle jusqu'à 2 cibles, `cupidonSelectedTargets: []`), sur le modèle visuel exact du panel Chasseur (`.hunter-panel` → `.cupidon-panel`, palette rose `#ec4899`/`#f472b6` inédite dans SPEC.md §10 mais cohérente avec les autres panels à couleur dédiée par rôle). Modale "Tu es amoureux de X" ajoutée en fin de composant, gated uniquement par `loverRevealed` (aucune condition de rôle).
5. `game-state.js` : deux nouveaux `.listen()` sur le canal privé joueur déjà souscrit (`game.{gameId}.player.{playerId}`) — `.cupidon.turn.started` et `.lover.revealed` — aucun nouveau canal, aucune nouvelle souscription Echo (réutilise l'abonnement existant, cf. `routes/channels.php` inchangé).
6. Entrée `cupidon_turn` ajoutée à la map `messages` du `$watch('nightPhase', ...)` de `night.blade.php` pour cohérence avec seer/werewolves/witch/hunter — actif seulement en théorie : comme les entrées existantes, ce `$watch` local à `nightScreen()` ne peut techniquement se déclencher que sur le client du rôle actif lui-même (`nightPhase` de `nightScreen()` n'est jamais modifié pour les autres joueurs, `SeerTurnStarted`/`WerewolvesTurnStarted`/`WitchTurnStarted`/`HunterTurnStarted`/`CupidonTurnStarted` étant tous privés) — comportement pré-existant, non modifié, ajouté par cohérence de pattern et non par nécessité fonctionnelle démontrée.
7. Docblock de `CupidonTurnStarted.php` corrigé (mentionnait encore "non branché dans PhaseManager", stale depuis la Phase 40 — voir BUGS_AND_ROADMAP.md).
8. `php artisan test` : 243/243 verts (inchangé, aucun test HTTP automatisé nouveau — la tâche demande explicitement une checklist de test manuel, pas de tests automatisés). `npm run build` vérifié sans erreur.

**Leçon :** Quand un prompt de tâche dit "sur le modèle exact de X", vérifier d'abord si la spec fonctionnelle du nouveau rôle diverge du modèle sur un point précis (ici : auto-sélection autorisée) avant de copier le pattern intégralement — copier aveuglément un guard de validation (`notIn($selfId)`) qui a du sens pour le modèle mais pas pour le nouveau cas aurait cassé une capacité déjà backée côté serveur, sans qu'aucun test existant ne le révèle. Pour une notification personnelle privée, ne pas systématiquement réutiliser un détour technique (`sessionStorage`) conçu pour contourner un problème précis (redirection qui détruit la page) quand ce problème n'existe pas pour le nouvel event — vérifier le contexte de déclenchement réel (ici : milieu de nuit, page stable) avant de dupliquer une complexité qui n'a pas de raison d'être.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Cupidon Étape 5 — WinConditionChecker camp amoureux, bloc dupliqué plutôt que refactor du calcul loups/village

**Contexte :** `feat/cupidon-win-condition` — `app/Services/WinConditionChecker.php`, `app/Notifications/GameFinishedNotification.php`. Étape 5 de l'implémentation Cupidon (SPEC_CUPIDON.md §6, §8 tâche 5), suite de l'Étape 4 ([[cupidon-night-integration]]).

**Symptôme / Problème :** Deux écarts à trancher, non résolus par le prompt de tâche tel quel :
1. Le prompt exige "zéro régression" et "ne touche à aucune ligne du calcul loups/village existant — uniquement ajouter la vérification amoureux AVANT, avec un retour anticipé" — mais la victoire amoureux partage un bloc de fin de partie quasi identique (guard `canTransition('finish')`, `buildLastAction()`, `update()`, deux `broadcast()`, `Notification::send()`) à celui déjà présent pour loups/village.
2. `SPEC_CUPIDON.md §6` ne précise ni le message de l'overlay `PhaseAnnouncement` ni le titre de la notification push pour la victoire amoureux — seuls les messages loups/village existent (`SPEC_TRANSITIONS.md §3.2` : "Le village a triomphé !" / "Les loups ont dévoré le village !").

**Cause / Alternatives :**
1. Extraire une méthode privée commune (`finish(Game, string, string)`) aurait déplacé les lignes existantes du calcul loups/village dans une nouvelle méthode — contredit littéralement "ne touche à aucune ligne du calcul loups/village existant". Alternative retenue : dupliquer le bloc de fin de partie (guard + update + broadcasts + notification) dans un nouveau bloc `if` inséré avant le calcul existant, avec retour anticipé (`return true`) si les 2 derniers vivants sont mutuellement `lover_player_id` l'un de l'autre. Le calcul loups/village qui suit n'est syntaxiquement pas modifié (aucune ligne changée), seul un nouveau bloc est inséré avant. Compromis assumé : légère duplication de code au profit du risque de régression minimal, conforme à la contrainte explicite du prompt et à la priorité CLAUDE.md "zéro régression" pour cette étape.
2. Message d'annonce et titre de notification pour `lovers` : aucune source ne les définissait. Choisis par cohérence de style avec les deux couples existants (`village` / `loups`) : overlay "Les amoureux ont triomphé du destin !", notification push "💞 Les amoureux ont gagné !" (ajoutée dans `GameFinishedNotification::toWebPush()` match, en plus du `default` générique déjà présent). Alternative (laisser le `default` générique "🏁 Partie terminée" pour la notification) — rejetée : dégraderait l'UX de la fonctionnalité ajoutée sans raison, changement d'une seule ligne dans un fichier déjà couvert par le `match` existant, aucun risque de régression sur les cas `villagers`/`werewolves`.

**Fix / Décision :**
1. `WinConditionChecker::check()` : nouveau bloc en tête de méthode — `if ($game->aliveCount() === 2)`, récupère les 2 joueurs vivants via `alivePlayers()->get()`, vérifie `lover_player_id` mutuel. Si vrai : même guard `canTransition('finish')`, `buildLastAction()`, `update(['winner_team' => 'lovers'])`, broadcast `PhaseAnnouncement`/`GameFinished`, `Notification::send()` (try/catch identique), puis `return true`. Fonctionne quel que soit le camp des 2 amoureux (loup+loup, loup+villageois, villageois+villageois) — aucun filtre par rôle, uniquement `lover_player_id`. Le calcul loups/village existant (lignes `$aliveWerewolves`/`$aliveOthers` et son `if/elseif/else`) n'a subi aucune modification.
2. `GameFinishedNotification::toWebPush()` : ajout du cas `'lovers' => '💞 Les amoureux ont gagné !'` dans le `match`, avant le `default`.
3. Tests : `tests/Unit/Services/WinConditionCheckerTest.php` créé (6 tests) — victoire amoureux villageois+villageois, loup+villageois, loup+loup (vérifie priorité sur le calcul loups qui aurait aussi été vrai), non-régression village gagne (2 villageois non amoureux), non-régression loups gagnent (1 loup + 1 villageois non amoureux), et garde `nb_vivants !== 2` ne déclenche jamais la victoire amoureux même si les 2 amoureux sont vivants parmi d'autres survivants. 243 tests verts (237 avant + 6 nouveaux, aucun cassé).

**Leçon :** Quand un prompt de tâche impose explicitement "ne touche à aucune ligne du code existant" pour une raison de zéro-régression, respecter cette contrainte au pied de la lettre même si elle produit une duplication de code techniquement évitable par extraction — la duplication assumée est un compromis correct quand elle est bornée (un seul bloc, pattern déjà stable ailleurs) et que l'alternative (refactor) réintroduit un risque que la tâche cherche justement à éliminer. Quand une spec fonctionnelle couvre la logique serveur mais reste silencieuse sur des textes UI dérivés (message d'overlay, titre de notification), les choisir par cohérence stricte de style avec les cas existants plutôt que de les laisser au comportement `default` générique — un `match` déjà en place pour des cas similaires est le signal qu'un nouveau cas y est attendu.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Cupidon Étape 4 — Intégration PhaseManager, défaut désactivé, chaînage complet vers ProcessSeerTurn

**Contexte :** `feat/cupidon-night-integration` — `app/Services/PhaseManager.php` (`startNight()`), `app/Services/RoleDistributor.php`, `config/game.php`, `app/Jobs/ProcessCupidonTurn.php`, `app/Jobs/ProcessCupidonAutoAction.php`, `app/Services/RoleActions/CupidonAction.php`. Étape 4 de l'implémentation Cupidon (SPEC_CUPIDON.md §3, §8 tâche 4), suite de l'Étape 3 ([[cupidon-action-and-jobs]], CupidonAction + Jobs créés mais non branchés).

**Symptôme / Problème :** Trois décisions à trancher, non résolues par le prompt de tâche tel quel :
1. Le prompt suggérait un défaut « désactivé par défaut, comme witch/hunter l'étaient avant v1.2 si c'était le cas » pour `config('game.roles.cupidon')` — needed vérification de ce précédent historique.
2. Le prompt ne décrivait explicitement que le branchement dans `PhaseManager::startNight()` (dispatch conditionnel de `ProcessCupidonTurn` vs `ProcessSeerTurn`), sans mentionner de modification de `ProcessCupidonTurn`, `ProcessCupidonAutoAction` ou `CupidonAction::link()` — alors que ces trois fichiers, dans leur état issu de l'Étape 3, ne chaînent jamais vers `ProcessSeerTurn`.
3. Risque de cascade synchrone dans les tests unitaires de `CupidonAction::link()` (`QUEUE_CONNECTION=sync` en test) si un `dispatch()` était ajouté directement dans cette méthode.

**Cause / Alternatives :**
1. Vérification par grep de l'historique : `witch`/`hunter` n'ont **jamais** eu de valeur `0` dans `config/game.php` — ils valaient `1` dès leur introduction (voir BUGS_AND_ROADMAP.md "Sorcière et chasseur jamais distribués sans settings hôte explicites" : le `0` qui y apparaît est uniquement le défaut PHP du helper `config('game.roles.witch', 0)` en l'absence de clé, pas la valeur réellement configurée). La prémisse du prompt était donc fausse. Décision indépendante de cette prémisse : `cupidon` reste désactivé par défaut (`0`) car (a) l'objectif explicite de cette étape est « zéro régression » et activer Cupidon par défaut changerait la composition de **toutes** les parties existantes sans qu'aucun host ne l'ait demandé ; (b) `GameSettingsService::validateRoleSettings()` ne couvre que `witch`/`hunter` — aucune UI host ne permet actuellement de désactiver Cupidon si son défaut était `1`, contrairement à witch/hunter qui ont toujours eu une UI de configuration disponible en parallèle de leur défaut à `1`.
2. Sans chaînage vers `ProcessSeerTurn`, toute partie où Cupidon est distribué resterait bloquée indéfiniment au round 1 dès que `ProcessCupidonTurn`/`ProcessCupidonAutoAction` s'exécutent (aucun Job ne prend le relais) — un blocage total, pas une simple régression. L'Étape 3 (DECISIONS.md "Cupidon Étape 3") avait explicitement différé ce chaînage « à la tâche d'intégration PhaseManager » : c'est cette tâche. Alternative envisagée (dispatcher `ProcessSeerTurn` de façon systématique et redondante depuis `PhaseManager::startNight()`, comme le fait `ProcessSeerTurn` lui-même pour `ProcessWerewolvesTurn` avec un délai cumulé fixe) — rejetée : SPEC_CUPIDON.md §3 décrit explicitement le chaînage comme interne à la chaîne Cupidon (`ProcessCupidonAutoAction` et `CupidonAction::link()`), pas depuis `PhaseManager`, et cette dernière option aurait nécessité un délai fixe arbitraire (durée du tour Cupidon) au lieu de réagir à la résolution réelle du tour.
3. `CupidonAction::link()` n'a pas d'endpoint HTTP à ce stade (SPEC_CUPIDON.md §8 tâche 6, Frontend, non fait) — contrairement à `SeerAction`/`WitchAction`/`HunterAction` où le dispatch du Job suivant est fait depuis le Controller (jamais depuis la classe RoleAction elle-même), ce qui protège les tests unitaires de ces classes de toute cascade. Alternative (laisser `CupidonAction::link()` sans dispatch, dispatch reporté à la future tâche Frontend/endpoint) — rejetée : cela laisserait un chemin voulu par la spec silencieusement cassé (si Cupidon agit volontairement une fois l'endpoint ajouté, `ProcessCupidonAutoAction` verrait `cupidon_link` déjà posé et ne dispatcherait rien non plus → blocage identique). Retenu : ajouter le `dispatch()` dans `CupidonAction::link()` malgré l'absence de Controller, et neutraliser le risque de cascade synchrone en ajoutant `Queue::fake()` aux tests unitaires concernés (`CupidonActionTest`) plutôt qu'en évitant le dispatch.

**Fix / Décision :**
1. `config/game.php roles.cupidon => 0` (désactivé par défaut) + `RoleDistributor::getRoleConfig()` étendu avec le même pattern que witch/hunter (`$overrides['cupidon'] ?? config('game.roles.cupidon', 0)`, 0 ou 1 max).
2. `PhaseManager::startNight()` : dispatch conditionnel — `ProcessCupidonTurn` si `round === 1` ET un joueur `role='cupidon'` existe dans la partie, sinon `ProcessSeerTurn` directement (comportement v1.1/v1.2 strictement inchangé pour les parties sans Cupidon et pour tout round > 1). Un seul des deux Jobs est jamais dispatché par `startNight()` — jamais les deux en parallèle.
3. `ProcessCupidonTurn::handle()` : le cas Cupidon absent/mort/inactif dispatche désormais `ProcessSeerTurn` immédiatement (au lieu d'un no-op) — même principe que `ProcessSeerTurn` lui-même pour la Voyante absente.
4. `ProcessCupidonAutoAction::handle()` : le cas timeout sans action volontaire dispatche désormais `ProcessSeerTurn` (au lieu d'un no-op) — aucun couple formé (comportement assumé inchangé), mais la nuit continue. Le cas « déjà agi » reste un no-op (le dispatch a déjà eu lieu depuis `CupidonAction::link()`, évite un double dispatch).
5. `CupidonAction::link()` : dispatche `ProcessSeerTurn::dispatch($game->id, $game->round)->delay(0)` après la transaction, en cas d'action volontaire — même principe que `/seer/done` terminant immédiatement le tour.
6. Tests : `CupidonJobsTest` (3 tests renommés + assertions `Queue::assertPushed(ProcessSeerTurn::class)` ajoutées, 1 test de timeout corrigé), `CupidonActionTest` (`Queue::fake()` ajouté aux 2 tests de succès + au test de double-action dont le premier appel réussit), `CupidonNightIntegrationTest.php` créé (3 tests bout en bout : Cupidon distribué round 1 → ordre CupidonTurnStarted puis SeerTurnStarted ; sans Cupidon → SeerTurnStarted immédiat inchangé ; Cupidon distribué round 2 → pas de CupidonTurnStarted), `RoleSettingsTest` (2 tests : Cupidon inclus si configuré via `settings['roles']`, absent par défaut). 237 tests verts (232 avant + 5 nouveaux : 3 intégration + 2 RoleDistributor, aucun cassé — les renommages/ajouts d'assertions dans les tests existants ne changent pas le compte).

**Leçon :** Quand un prompt de tâche scope explicitement une seule couche (ici : PhaseManager) mais que la spec de référence (SPEC_CUPIDON.md §3) décrit une séquence complète impliquant d'autres fichiers déjà écrits lors d'une étape précédente, vérifier si ces autres fichiers contiennent un « chaînage différé explicitement documenté » (DECISIONS.md de l'étape précédente) avant de considérer la tâche terminée au périmètre littéral du prompt — un branchement partiel qui laisse un Job sans successeur produit un blocage total de partie, pas une simple imperfection. Quand une classe de Service (RoleAction) doit dispatcher un Job suivant mais n'a pas encore d'endpoint Controller (contrairement au pattern établi ailleurs dans le projet), ne pas laisser le dispatch de côté sous prétexte que le Controller n'existe pas encore — ajouter le dispatch dans le Service et neutraliser le risque de cascade synchrone dans les tests unitaires via `Queue::fake()`, plutôt que de reporter un chaînage requis par la spec.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Cupidon Étape 3 — CupidonAction + Jobs isolés, $game->timer() plutôt que config() direct

**Contexte :** `feat/cupidon-action-and-jobs` — `app/Services/RoleActions/CupidonAction.php`, `app/Jobs/ProcessCupidonTurn.php`, `app/Jobs/ProcessCupidonAutoAction.php`, `app/Services/PhaseGuard.php` (`canCupidonLink()`), `config/game.php`. Étape 3 de l'implémentation Cupidon (SPEC_CUPIDON.md §4, §3, §8 tâche 3), suite de l'Étape 2 (cascade de mort, [[cupidon-elimination-cascade]] si référencé ailleurs).

**Symptôme / Problème :** Deux écarts à trancher entre le prompt de tâche et les règles du projet : (1) le prompt décrivait `ProcessCupidonTurn`/`ProcessCupidonAutoAction` utilisant `config('game.timers.cupidon', 30)` directement pour le délai ; (2) SPEC_CUPIDON.md §3 décrit `ProcessCupidonAutoAction` comme devant enchaîner `ProcessSeerTurn` après un timeout sans action volontaire, alors que le prompt de cette étape dit seulement "NE FAIS RIEN" sans mentionner cet enchaînement.

**Cause / Alternatives :**
1. CLAUDE.md et RISK_GUARDS.md interdisent absolument `config('game.timers.x')` en dehors de `TimerCalculator` — règle déjà à l'origine d'un bug corrigé (DECISIONS.md "Appels config('game.timers.*') résiduels hors TimerCalculator"). `TimerCalculator::get()` retombe de toute façon sur `config("game.timers.{$key}", 30)` si `settings['timers']` est absent — utiliser `$game->timer('cupidon')` produit un comportement strictement identique en l'absence de configuration hôte, tout en respectant la règle du projet et en rendant le timer configurable "gratuitement" pour l'UI future. Alternative (suivre le prompt à la lettre avec `config()` direct) — rejetée, reproduirait sciemment un anti-pattern déjà documenté comme bug.
2. Pour l'enchaînement `ProcessSeerTurn` : le prompt de cette étape est explicite et volontairement plus étroit que SPEC_CUPIDON.md §3 (qui décrit le comportement une fois l'intégration `PhaseManager` faite). Ces Jobs sont explicitement "totalement inatteignables depuis le flux de jeu actuel" à la fin de cette étape — chaîner vers `ProcessSeerTurn` maintenant anticiperait une intégration prévue comme tâche séparée (SPEC_CUPIDON.md §8 tâche 4) et compliquerait le test isolé demandé ("sans dépendre de PhaseManager"). Alternative (chaîner dès maintenant) — rejetée, contredit littéralement la consigne "NE FAIS RIEN" du prompt et le principe de zéro-régression (rien ne doit changer dans le flux nocturne existant tant que l'intégration n'est pas explicitement faite).

**Fix / Décision :** `ProcessCupidonTurn`/`ProcessCupidonAutoAction` utilisent `$game->timer('cupidon')` (Jobs) — `config/game.php` reçoit `timers.cupidon => 30` **et** une entrée `limits.cupidon` (min 15, max 60, `host_configurable => true`), sur le modèle exact de `seer`/`witch`, pour rester cohérent avec `GameSettingsService::validateTimerSettings()` si l'UI host est branchée plus tard. `ProcessCupidonAutoAction::handle()` ne dispatche rien après ses guards, quel que soit le cas (déjà agi ou non) — le chaînage vers `ProcessSeerTurn` est explicitement différé à la tâche d'intégration `PhaseManager` (commentaire ajouté dans les deux Jobs). `CupidonAction::link()` ne broadcaste `LoverRevealed` qu'aux amoureux distincts de Cupidon (jamais à Cupidon lui-même, qui connaît déjà le résultat via la réponse HTTP de son action) — un seul broadcast si Cupidon s'est choisi lui-même, conforme à SPEC_CUPIDON.md §4/§7.

**Leçon :** Quand un prompt de tâche décrit une implémentation qui contredit une règle d'architecture déjà établie et documentée comme correctif de bug (ici `config()` direct vs `$game->timer()`), privilégier la règle du projet plutôt que la formulation littérale du prompt — surtout quand le résultat fonctionnel est identique. Quand un prompt scope une étape plus étroitement que la spec complète du rôle (ici : pas d'enchaînement `ProcessSeerTurn`), respecter le périmètre étroit explicite plutôt que d'anticiper la suite documentée ailleurs — l'étape suivante est précisément là pour ça, et anticiper créerait un chemin partiellement câblé difficile à distinguer d'un bug lors de la review.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] lover_player_id absent de $fillable — cascade de mort Cupidon silencieusement no-op

**Contexte :** `feat/cupidon-elimination-cascade` — `app/Services/PlayerEliminationService.php`, `app/Models/GamePlayer.php`. Étape 2 de l'implémentation Cupidon (SPEC_CUPIDON.md §5, suite de l'Étape 1 schéma + enums).

**Symptôme / Problème :** `PlayerEliminationService::eliminate()` enrichi avec la cascade récursive (`$player->lover_player_id` → élimination de l'amoureux vivant). Premier test écrit (`$playerA->update(['lover_player_id' => $playerB->id])` puis `eliminate($playerA)`) échouait : `$playerB` restait `is_alive = true` après l'appel, alors que le code de cascade semblait correct à la lecture.

**Cause / Alternatives :** La migration de l'Étape 1 (`add_lover_player_id_to_game_players_table`) a ajouté la colonne au schéma uniquement, sans mettre à jour `$fillable` sur `GamePlayer`. Eloquent ignore silencieusement une assignation de masse (`update()`/`create()`) sur une colonne absente de `$fillable` — aucune exception, aucun warning, juste une valeur qui ne part jamais en base. Le bug n'a donc pas été détecté à l'Étape 1 (aucun test n'exerçait encore la colonne). Aucune alternative envisagée : ajouter la colonne à `$fillable` est le seul fix possible, cohérent avec toutes les autres colonnes du modèle.

**Fix / Décision :** `lover_player_id` ajouté à `$fillable` dans `GamePlayer`. `PlayerEliminationService::eliminate()` reste tel que spécifié dans SPEC_CUPIDON.md §5 : pose `is_alive = false`, puis cascade récursive sur `lover_player_id` si l'amoureux existe et est vivant, avec garde défensive `$lover->id !== $player->id` contre une auto-référence accidentelle.

**Leçon :** Toute migration qui ajoute une colonne destinée à être renseignée via mass assignment (`update()`/`create()`) doit être accompagnée dans la **même tâche** de l'ajout de cette colonne à `$fillable` du modèle — une migration schema-only sans le modèle à jour est un bug silencieux garanti (pas d'exception à l'écriture), qui ne se révèle qu'au premier test ou usage réel de la colonne. Vérifier systématiquement `$fillable` dès qu'une nouvelle colonne FK/mutable est ajoutée à une table déjà couverte par un modèle Eloquent.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Partie bloquée en night — broadcasts MayorElected/NightStarted non protégés dans ProcessMayorElection

**Contexte :** `fix/broadcast-failure-blocks-startgame` — `app/Jobs/ProcessMayorElection.php`. Suite directe de l'entrée précédente (même incident Reverb, 58 échecs de broadcast constatés en logs le même jour, cause racine identique).

**Symptôme / Problème :** `MayorElected` et `NightStarted` implémentent tous deux `ShouldBroadcastNow` (broadcast synchrone, comme `PlayerJoined`). Dans `ProcessMayorElection::handle()`, les deux appels `broadcast(...)` n'étaient protégés par aucun `try/catch` : une exception Reverb sur l'un des deux interrompt le job avant `ProcessSeerTurn::dispatch()` (ligne ~73) — la partie reste bloquée en `status='night'` en base, sans que rien ne pilote la suite (le job ne sera jamais réexécuté puisqu'il s'est déjà exécuté avec succès jusqu'au point de l'exception, sans retry automatique).

**Cause / Alternatives :** Même cause racine que `PlayerJoined` (cf. entrée précédente) : un event `ShouldBroadcastNow` s'exécute de façon synchrone dans le job, une exception à cet endroit se comporte comme une exception métier. Aucune alternative envisagée — le pattern à appliquer était déjà validé et documenté (`try/catch` + `Log::warning()`), la seule question était l'encapsulation individuelle vs commune des deux broadcasts. Choix : deux blocs `try/catch` séparés (un par event) plutôt qu'un seul englobant les deux — un échec sur `MayorElected` ne doit pas empêcher la tentative de `NightStarted`, les deux events sont indépendants côté client.

**Fix / Décision :**
1. `ProcessMayorElection::handle()` : `broadcast(new MayorElected(...))` et `broadcast(new NightStarted(...))` chacun encapsulé dans son propre `try/catch (\Throwable $e)` avec `Log::warning()` (même format que `joinGame()` : contexte `game_id` + `player_id` pour `MayorElected`, `game_id` seul pour `NightStarted`). `ProcessSeerTurn::dispatch()` s'exécute désormais toujours après, quel que soit l'état des deux broadcasts.
2. Aucun autre fichier touché — périmètre strictement limité à `ProcessMayorElection.php`, conformément à la demande.
3. Test `ProcessMayorElectionTest::test_processseerturn_dispatche_meme_si_broadcast_mayorelected_echoue()` : même pattern de mock que `JoinGameTest` (binding `Illuminate\Contracts\Broadcasting\Factory`, exception forcée uniquement sur `MayorElected`). Vérifie que la partie transite bien vers `status='night'` et que `ProcessSeerTurn` est dispatché malgré l'échec. 207 tests verts (206 avant + 1 nouveau, aucun cassé).

**Leçon :** Le pattern "encapsuler un `ShouldBroadcastNow` en `try/catch` + `Log::warning()` pour ne jamais bloquer une transition critique" ne s'applique pas qu'au premier point de broadcast trouvé — vérifier systématiquement tous les jobs qui broadcastent un event `ShouldBroadcastNow` juste avant un `dispatch()` critique. `grep -rn "ShouldBroadcastNow" app/Events/` pour lister tous les events concernés et croiser avec leurs call sites serait le moyen le plus sûr d'éviter de découvrir ces bugs un par un via les logs de prod.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Partie bloquée en waiting + 404 role-reveal — broadcast PlayerJoined non protégé + redirection resync() sur mauvais critère

**Contexte :** `fix/broadcast-failure-blocks-startgame` — `app/Services/GameService.php` (`joinGame()`), `resources/views/game/waiting-room.blade.php` (`resync()`).

**Symptôme / Problème :** Diagnostic confirmé en amont : lors d'un incident réseau/Reverb au moment où le dernier slot d'une partie se remplit, `broadcast(new PlayerJoined(...))` lève une exception. Cette exception se propage hors de `joinGame()` et empêche l'appel à `startGame()` situé juste après — la partie reste bloquée en `status='waiting'` indéfiniment, alors que le `GamePlayer` du dernier arrivant est bien créé en base. Côté client, `resync()` (polling périodique de `/lobby/state`) redirige pourtant vers `/role-reveal` dès que `slots_remaining === 0`, sans vérifier que `status` a réellement changé — le joueur atterrit sur une route dont l'état serveur ne correspond à aucune transition réelle (404 / état incohérent), car `startGame()` n'a jamais tourné.

**Cause / Alternatives :** `PlayerJoined implements ShouldBroadcastNow` (`app/Events/Game/PlayerJoined.php`) : le broadcast est synchrone (`Dispatcher::broadcastEvent()` → `BroadcastFactory::queue()` exécuté immédiatement dans la requête HTTP), pas mis en file — toute exception à cet endroit interrompt directement le flux `joinGame()`. Aucun `try/catch` ne protégeait cet appel, contrairement au pattern déjà établi pour les guards silencieux de `ProcessNightActions`/`ProcessWerewolvesTurn` (cf. entrée "Rejets de guard silencieux..." plus bas dans ce fichier). Côté client, `resync()` dupliquait la même information sous deux formes : `status !== 'waiting'` (source de vérité serveur) ET `slots_remaining === 0` (dérivé, mais qui reste vrai même si `startGame()` n'a jamais transité le statut). Alternative envisagée pour la partie serveur : ne rien changer et compter sur une reprise automatique — rejetée, aucun mécanisme de retry n'existe pour `startGame()` une fois l'exception propagée, la partie resterait bloquée jusqu'à intervention manuelle.

**Fix / Décision :**
1. `GameService::joinGame()` : l'appel `broadcast(new PlayerJoined(...))` est encapsulé dans un `try/catch (\Throwable $e)` avec `Log::warning()` (contexte `game_id`, `player_id`, message d'exception) — même pattern que les guards silencieux documentés. L'exécution continue ensuite normalement vers `startGame($result['game'])`, qui n'est plus jamais court-circuité par un échec de broadcast. Aucune autre logique de `startGame()` (RoleDistributor, création des `game_players`, broadcast `GameStarted`) n'a été modifiée.
2. `resync()` (`waiting-room.blade.php`) : suppression de la branche `if (json.data.slots_remaining === 0) { redirect }`. Seule la branche déjà existante `if (json.data.status !== 'waiting') { redirect }` déclenche désormais la redirection — `status` est la seule source de vérité fiable de l'avancement réel de la partie. Vérifié par `grep` qu'aucun autre code n'appelle `resync()` ni ne dépend de ce comportement (seuls deux call sites internes à `waiting-room.blade.php` : `visibilitychange` et le `setTimeout` initial).
3. Test `JoinGameTest::test_partie_demarre_meme_si_broadcast_playerjoined_echoue()` : mock de `Illuminate\Contracts\Broadcasting\Factory` (binding conteneur) forçant une exception uniquement pour l'event `PlayerJoined`, laissant les autres broadcasts (`GameStarted`) en no-op. Vérifie que malgré l'échec, la partie transite bien vers `status='electing_mayor'`. 206 tests verts (205 avant + 1 nouveau, aucun cassé).

**Leçon :** Un event `ShouldBroadcastNow` s'exécute de façon synchrone dans le flux de la requête — une exception à cet endroit se comporte exactement comme une exception métier et peut interrompre une séquence critique si elle n'est pas explicitement protégée. Tout broadcast dont l'échec ne doit pas bloquer une transition de phase doit être encapsulé en `try/catch` + `Log::warning()`, au même titre que les guards silencieux de jobs. Côté client, ne jamais dériver une décision de navigation d'un champ secondaire (`slots_remaining`) quand un champ source de vérité (`status`) existe déjà dans la même réponse — la redondance entre les deux peut diverger exactement dans le cas où le champ source de vérité n'a pas encore été mis à jour côté serveur.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Chasseur Maire tué de nuit — succession déclenchée avant le tir dans ProcessNightActions et WitchAction

**Contexte :** `fix/night-mayor-succession-before-hunter-shot` — `app/Jobs/ProcessNightActions.php`, `app/Services/RoleActions/WitchAction.php`. Suite directe de l'entrée "Chasseur Maire — tir avant succession du maire" (fix `fix/bug-chasseur-maire-ordre-succession`, cas jour + `ProcessHunterTurn`/`ProcessHunterAutoAction`/`ProcessNightEnd`), qui n'avait jamais été porté sur le point d'entrée nuit de `ProcessNightActions`.

**Symptôme / Problème :** Un Chasseur-Maire tué de nuit (loups ou poison sorcière) voyait la succession du maire partir en quelques secondes (timer `mayor_succession`), bien avant que son propre tour de tir n'arrive (`ProcessNightEnd` attend `witch_timer + mayor_succession + 5s` avant de traiter `hunter_pending`). Côté joueur : écran "tu es éliminé" → modale "nouveau maire élu" hors d'ordre → retour au jour, sans jamais voir son panel de tir.

**Cause / Alternatives :** `ProcessNightActions::handle()` contenait un bloc `if ($victim?->is_mayor && ! $victimIsMayorWithWitchAvailable)` déclenchant `MayorSuccessionStarted` + `ProcessMayorSuccession::dispatch()` sans aucune condition sur `isHunter()` — contrairement à `VoteService::resolveDayVote()` où le fix précédent avait justement inversé la priorité (`hunter_pending` avant `is_mayor`). Alternative : centraliser toute la logique de succession dans une seule méthode partagée entre les 3 points d'entrée (jour, nuit directe, nuit différée sorcière) — écartée pour ce fix, chaque point d'entrée ayant des variables de contexte (transaction déjà ouverte, `$witch` en jeu, victime déjà persistée ou non) trop différentes pour une extraction sûre sans risque de régression ; à envisager si un 4e point d'entrée apparaît en v1.3+.

Audit du point 5 (recherche de tous les usages de `MayorSuccessionStarted`/`ProcessMayorSuccession::dispatch`) : le même bug existait, sous une forme aggravée, dans `WitchAction::act()` à **deux endroits distincts** :
1. Poison direct de la sorcière sur le Chasseur-Maire (`$mayorVictim = $target` sans check `isHunter()` — `hunter_pending` était bien créé par ailleurs, mais la succession partait quand même en parallèle).
2. Le maire en sursis (victime initiale des loups, différée car la sorcière avait encore son soin — cf. "Maire en sursis" plus bas dans ce fichier) resolu mort par la sorcière (`pass` ou `kill` d'un autre joueur) : **`hunter_pending` n'était jamais créé pour ce chemin**, en plus de l'absence de guard `isHunter()` — le Chasseur perdait purement et simplement son tour de tir, pas seulement son ordre.

`ProcessMayorElection.php` (élection du tout premier maire) audité et confirmé hors périmètre : aucune logique de mort/succession, uniquement l'élection initiale.

**Fix / Décision :**
1. `ProcessNightActions::handle()` : capture d'un flag `$hunterPending` (déjà calculable, posé à `true` dans le même bloc qui crée le `GameAction hunter_pending`). Le bloc de succession devient `if ($hunterPending) { /* rien, la chaîne hunter_pending gère la suite */ } elseif ($victim?->is_mayor && ! $victimIsMayorWithWitchAvailable) { ... }` — mutuellement exclusif, miroir exact de `VoteService::resolveDayVote()`.
2. `WitchAction::act()` : le poison direct (`$target->is_mayor`) ne pose plus `$mayorVictim` si `$target->isHunter()` (le `hunter_pending` existant suffit). Les deux blocs `mayorCandidate` (maire en sursis, branches `kill` et `pass`) créent désormais `hunter_pending` si `$mayorCandidate->isHunter()`, et alimentent une nouvelle variable `$deferredMayorVictim` (distincte de `$mayorVictim`, car sa diffusion `PlayerEliminated` n'est jamais faite par le Controller contrairement au poison direct). Hors transaction : `PlayerEliminated` est **toujours** broadcasté pour `$deferredMayorVictim` (le joueur meurt dans tous les cas), mais `MayorSuccessionStarted`/`ProcessMayorSuccession` ne le sont que si `! $deferredMayorVictim->isHunter()`.
3. Dans tous les cas où la succession est différée au profit du tir, `is_mayor` reste `true` en base jusqu'au tir (ou renoncement) — `ProcessNightEnd::handle()` le lit à ce moment précis pour transmettre `$isMayor` à `ProcessHunterTurn`, chaîne déjà correcte et non modifiée.
4. 3 tests de régression ajoutés : `HunterTest::test_chasseur_maire_tue_par_loups_nuit_succession_pas_declenchee_avant_tir` (mort directe par les loups sans sorcière disponible), `WitchTest::test_sorciere_empoisonne_chasseur_maire_succession_pas_declenchee` (poison direct), `WitchTest::test_maire_en_sursis_chasseur_non_sauve_par_sorciere_cree_hunter_pending` (maire en sursis, chemin où `hunter_pending` n'était même pas créé avant ce fix). 205 tests verts (202 avant + 3 nouveaux, aucun cassé).

**Leçon :** Quand un pattern de garde (ici : priorité tir Chasseur > succession Maire) est corrigé dans un point d'entrée, auditer systématiquement TOUS les points d'entrée qui déclenchent le même événement (`grep` sur le nom de l'event/job) avant de considérer le bug clos — un fix appliqué à un seul endroit laisse les autres chemins dans l'état bugué, parfois sous une forme pire (ici : `hunter_pending` carrément absent sur le chemin `WitchAction` "maire en sursis", pas seulement mal ordonné). Toute variable de mort différée (`$mayorVictim`, `$deferredMayorVictim`, etc.) qui déclenche à la fois un broadcast d'élimination ET une conséquence conditionnelle (succession) doit séparer les deux décisions dès qu'une des conséquences peut être court-circuitée par un autre rôle cumulé — ne pas réutiliser la même variable pour les deux si leurs conditions de déclenchement divergent.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Modale succession maire masquant le panel de tir du Chasseur (nuit et jour)

**Contexte :** `fix/hunter-mayor-succession-modal-overlap` — `resources/views/game/night.blade.php`, `resources/views/game/day.blade.php` (fonctions `nightScreen()` et `dayScreen()`).

**Symptôme / Problème :** Un joueur cumulant Chasseur et Maire, mort de nuit (loups ou poison), ne voyait jamais le panel "éliminer quelqu'un" de son tour de tir. Diagnostiqué sur `game_id=249`, round 2, joueur `Maba diakhouba`. Le bug était plus fréquent quand la sorcière agissait ce round-là, car cela retarde l'arrivée du tour chasseur.

**Cause / Alternatives :** `MayorSuccessionStarted` est broadcasté dès la mort du maire et ouvre immédiatement `successionOpen` (modale `fixed inset-0 z-50`, fond opaque). Le tour du chasseur arrive bien plus tard (mort → `hunter_pending` → `ProcessNightEnd` avec délai `witch_timer + mayor_succession_timer + 5s` → `ProcessHunterTurn` → `HunterTurnStarted`). Le listener `hunter-turn-started` de `nightScreen()`/`dayScreen()` bascule vers l'affichage du panel de tir (`nightPhase = 'hunter_turn'` / `hunterOpen = true`) mais ne touchait jamais `successionOpen` — les deux modales/panels partagent le même `z-index` et concernent le même joueur, donc la modale succession (plus récente dans le flux d'events, et en `day.blade.php` plus bas dans le DOM) reste visible par-dessus et bloque les clics. Backend correct et non modifié : le problème est purement un conflit d'affichage client entre deux états qui ne doivent jamais coexister pour un même joueur.

**Fix / Décision :** Dans les deux listeners `hunter-turn-started` (`night.blade.php` et `day.blade.php`), ajout de `this.successionOpen = false;` en première instruction, avant toute autre affectation. Le tour de tir du Chasseur ferme systématiquement la modale succession si elle est encore ouverte. Aucun changement backend (`ProcessNightActions`, `ProcessNightEnd`, `ProcessHunterTurn`, pattern `hunter_pending`) ni de délai de timer. Vérifié que `closeSuccessionModal()` (appelé sur `mayor-succession-done`) reste un no-op sûr si `successionOpen` est déjà `false` — pas de race condition inverse.

**Leçon :** Quand deux overlays plein écran de même `z-index` peuvent viser le même joueur à des moments différents (ici modale automatique "succession" vs panel d'action volontaire "tour de tir"), l'ouverture du second doit explicitement fermer le premier — ne pas supposer qu'un event de fermeture (`mayor-succession-done`) arrivera à temps, car son délai dépend de timers indépendants du tour concerné. Voir Guard #7 dans `RISK_GUARDS.md`.

**Statut :** ✅ Résolu

---

## [CHOIX] Classe abstraite RoleAction — guard anti-double-action partagé (SeerAction/WitchAction/HunterAction)

**Contexte :** `refactor/role-action-shared-guards` — `app/Services/RoleActions/RoleAction.php` (nouveau), `SeerAction.php`, `WitchAction.php`, `HunterAction.php`.

**Symptôme / Problème :** Les trois classes dupliquaient quasi à l'identique un guard anti-double-action (`GameAction::where(...)->lockForUpdate()->exists()` + `abort(409, ...)`), avec un message d'erreur légèrement différent pour `HunterAction` (`'Vous avez déjà tiré ce round.'`) par rapport à `SeerAction`/`WitchAction` (`'Vous avez déjà utilisé votre pouvoir ce round.'`). Objectif : préparer le terrain pour Cupidon (v1.3+) sans imposer d'interface stricte (signatures de `check()`/`act()`/`shoot()` incompatibles entre les trois classes).

**Cause / Alternatives :** Une interface PHP aurait forcé un contrat artificiel vu les signatures divergentes — écartée. Extraction d'une classe abstraite `RoleAction` avec une méthode protégée `guardNotAlreadyActed(Game $game, int $playerId, array $types)` — retenue. Cette méthode fige un message d'erreur unique, ce qui change le texte visible côté chasseur (`'tiré'` → `'utilisé votre pouvoir'`). Aucun test n'asserte sur ce texte ; validé explicitement avec l'utilisateur (garder le message générique plutôt qu'ajouter un paramètre `$message` qui aurait complexifié la signature demandée).

**Fix / Décision :** `RoleAction` créée avec la seule méthode `guardNotAlreadyActed()`. `SeerAction`, `WitchAction`, `HunterAction` en héritent ; leur bloc dupliqué remplacé par un appel à cette méthode, à l'intérieur de leur `DB::transaction()` existante, sans rien déplacer d'autre. Aucune signature publique changée. 202 tests verts après chacune des 3 migrations (Seer, Witch, Hunter testées séparément).

**Leçon :** Pour des classes aux signatures publiques incompatibles mais partageant un guard interne identique, préférer une classe abstraite avec une méthode protégée plutôt qu'une interface — évite un contrat artificiel tout en dédupliquant le code. Vérifier systématiquement si les messages d'erreur dupliqués sont réellement identiques avant de les unifier ; si un texte diffère et qu'aucun test ne le couvre, valider explicitement le choix avec l'utilisateur plutôt que de trancher silencieusement.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Découpage de VoteService::resolveDayVote() en trois méthodes privées

**Contexte :** `refactor/split-vote-service-resolve-day-vote` — `app/Services/VoteService.php`.

**Symptôme / Problème :** `resolveDayVote()` faisait 150 lignes concentrant calcul du résultat (transaction), broadcasts/notifications, et déclenchement des conséquences (victoire, chasseur, succession maire, nuit suivante) — signalé par AUDIT.md. Objectif : découper sans changer le comportement.

**Cause / Alternatives :** Les branches `randomVictim` (0 vote) et `eliminated` (élimination normale) ont des logiques de conséquences asymétriques : la branche `randomVictim` ne gère jamais la succession du maire même si la victime aléatoire était maire (seul `hunter_pending` est vérifié), alors que la branche `eliminated` gère `hunter_pending` puis `is_mayor` en fallback. Cette asymétrie est un comportement existant, non documenté ailleurs, qui n'entrait pas dans le périmètre de cette tâche (zéro régression demandé). Alternative envisagée : unifier les deux branches dans une seule méthode `dispatchDayVoteConsequences()` avec un paramètre `$victim` générique — rejetée après lecture du code, car elle aurait fait disparaître visuellement cette asymétrie et risqué de la « corriger » silencieusement lors d'une future modification.

**Fix / Décision :** `resolveDayVote()` orchestre désormais trois méthodes privées dans l'ordre exact d'origine : `resolveDayVoteWinner()` (transaction : élimination, égalité, ou 0 vote → tableau `['eliminated', 'noElimReason', 'randomVictim']`), `notifyDayVoteResult()` (broadcasts `NoElimination`/`RandomElimination`/`PlayerEliminated` + notification push, sans aucune transition de phase), `dispatchDayVoteConsequences()` (vérification de victoire, tir chasseur en attente, succession maire, ou nuit suivante — conserve la branche `randomVictim` sans gestion `is_mayor`, telle quelle). Le tableau de résultat `$result` est passé par valeur entre les trois méthodes plutôt que par référence (contrairement à l'original qui utilisait `&$eliminated` etc.) — plus lisible, sans risque car aucune méthode ne modifie plus l'état après la transaction. Aucune méthode publique de `VoteService` n'a changé de signature. 202 tests verts après découpage (198 avant + 4 déjà présents pour ce chemin, aucun cassé).

**Leçon :** Avant de fusionner deux branches de code qui « se ressemblent » lors d'un découpage, vérifier qu'elles font vraiment la même chose — une asymétrie de comportement entre deux chemins voisins (ici mayor succession absente du chemin `randomVictim`) doit être préservée explicitement dans le découpage, pas silencieusement unifiée. Le pattern "un tableau de résultat nommé passé entre méthodes privées" est réutilisable pour tout futur découpage d'une méthode `resolveX()` combinant transaction + effets de bord post-transaction.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Extraction GameSettingsService de GameService (God Service)

**Contexte :** `refactor/extract-game-settings-service` — `app/Services/GameService.php`, `app/Services/GameSettingsService.php`.

**Symptôme / Problème :** `GameService` concentrait toujours, en plus de l'orchestration générale (joinGame, startGame, markReady, excludePlayer, cancelGame), la validation et la persistance des paramètres hôte (timers, composition de rôles) — quatre méthodes sans rapport avec le cycle de vie d'une partie. Suite logique de l'extraction `RoleActions/*` déjà effectuée (voir entrée "Extraction SeerAction / WitchAction / HunterAction de GameService").

**Cause / Alternatives :** Mêmes options que pour l'extraction précédente : (1) extraction avec mise à jour de tous les appelants (LobbyController, FormRequests) — risque inutile, aucun gain fonctionnel. (2) Pattern Facade/délégation déjà validé — retenu, cohérence avec `RoleActions/*`.

**Fix / Décision :** `validateTimerSettings()`, `updateTimerSettings()`, `validateRoleSettings()`, `updateRoleSettings()` copiées telles quelles dans `app/Services/GameSettingsService.php` (nouvelle classe, aucune dépendance externe hors `Game`). `GameService` conserve les quatre méthodes publiques à l'identique (signature et PHPDoc), chacune déléguant via `app(GameSettingsService::class)->methode(...)`. Aucun appelant externe (`LobbyController`, `UpdateTimersRequest`, `UpdateRolesRequest`) modifié. 202 tests verts après extraction (198 avant + aucun ajouté, aucun cassé).

**Leçon :** Le pattern "délégation par le container" (`app(Xxx::class)->method(...)`) reste le chemin de migration à risque minimal pour extraire un groupe de méthodes cohérent d'un God Service, tant que le groupe ne partage pas d'état mutable avec le reste du service. Pour tout futur retrait de responsabilité de `GameService`, répliquer ce même pattern plutôt que de renommer/déplacer les appels chez les consommateurs.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Relation `targetPlayer` inexistante dans `PhaseManager::endNight()`

**Contexte :** `fix/targetplayer-relation-phasemanager` — `app/Services/PhaseManager.php`.

**Symptôme / Problème :** `ProcessNightEnd` levait `Call to undefined relationship [targetPlayer] on model [App\Models\GameAction]` à chaque exécution — partie 140 bloquée en `processing_night`.

**Cause / Alternatives :** La relation vers la cible d'une action est définie dans `GameAction` sous le nom `target` (méthode `target(): BelongsTo`, FK `target_player_id`). `endNight()` utilisait `targetPlayer` (inexistant) dans `->with()` et deux fois en lecture du résultat. Aucune alternative : il fallait simplement utiliser le bon nom de relation.

**Fix / Décision :** Remplacement des trois occurrences de `targetPlayer` par `target` dans `PhaseManager::endNight()`. Aucune autre occurrence dans `app/`.

**Leçon :** La relation vers `target_player_id` dans `GameAction` s'appelle `target`, pas `targetPlayer`. Toujours vérifier le nom de méthode dans le modèle avant d'utiliser `->with()`.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Fausse déconnexion loup entre pages — beforeunload race condition + retry reconnect

**Contexte :** `fix/reconnection-wolf-inactive` — `resources/js/game-state.js` (`_navigateTo`, `_reconnect`), `config/game.php`.

**Symptôme / Problème :** Un joueur loup pouvait être marqué `is_inactive = true` et `is_alive = false` par `CheckReconnectionTimeout` alors qu'il était en train de jouer. Observé en prod : toast "Undu est de retour !" cyclique dans plusieurs parties, partie 139 : loup `is_inactive=1` sans aucun `night_vote` en base — la nuit s'est terminée sans vote loup, le jeu a continué vers la sorcière.

**Cause / Alternatives :**
Deux problèmes combinés :
1. `_navigateTo()` appelait `window.location.href = url` de façon synchrone après `sessionStorage.setItem('__internalNavigation', '1')`. Sur Safari iOS et Chrome Android, `beforeunload` se déclenche avant que le moteur JS ait flushé le sessionStorage. Le handler lisait `null` au lieu de `'1'` et envoyait `/disconnect` — `CheckReconnectionTimeout` était dispatché avec 30s de délai.
2. `_reconnect()` terminait par `.catch(() => {})`. Sur réseau instable (mobile 4G), le fetch `/reconnect` échouait silencieusement, le token cache n'était pas invalidé, et 30s plus tard `CheckReconnectionTimeout` marquait le joueur mort sans savoir qu'il était revenu.
Alternative envisagée pour (1) : utiliser `pagehide` au lieu de `beforeunload` — rejeté, `pagehide` n'est pas fiable sur tous les navigateurs mobiles pour `sendBeacon`. Alternative pour (2) : vérifier le retour HTTP dans `_reconnect()` — insuffisant, le problème est l'absence de retry en cas d'échec réseau.

**Fix / Décision :**
1. `_navigateTo()` encapsule `window.location.href` dans `setTimeout(..., 0)` — garantit que la micro-task JS flush le sessionStorage avant que `beforeunload` soit déclenché, quel que soit le navigateur.
2. `_reconnect()` : remplacement de `.catch(() => {})` par une fonction `attempt(retries)` avec retry sur échec réseau (3 tentatives, 2s d'intervalle). Le reste de la méthode (guard `__internalNavigation`, réinitialisation `announcements`) est inchangé.
3. `config/game.php` : timer `reconnection` passé de 30s à 45s (valeur par défaut + limites min/max) pour absorber les retards réseau mobile entre le `/disconnect` et le `/reconnect` corrigé.

**Leçon :** `sessionStorage.setItem` est synchrone sur desktop mais peut être battu par `beforeunload` sur mobile — tout guard sessionStorage utilisé dans `beforeunload` doit être posé via un `setTimeout(0)` avant la navigation. Tout `fetch` dans une fonction de reconnexion doit avoir un retry explicite — un `.catch(() => {})` nu est toujours une dette silencieuse dans un contexte réseau mobile.

**Statut :** ✅ Résolu

---

## [CHOIX] Lisibilité cartes joueurs éliminés — fond rouge teinté plutôt qu'opacité globale

**Contexte :** `feat/dead-player-card-readability` — `resources/views/game/day.blade.php`, `resources/js/game-state.js`.

**Symptôme / Problème :** `.v-dead` combinait `opacity: 0.3` et `filter: grayscale(100%)` sur fond `#111827`. Le pseudo et le rôle révélé devenaient quasi illisibles, surtout sur mobile en pleine lumière.

**Cause / Alternatives :**
1. Augmenter `opacity` de `0.3` à `0.6` — rejeté : masquerait moins bien le statut mort visuellement ; la carte serait trop proche d'une carte vivante.
2. Conserver le grayscale, retirer uniquement l'opacité — rejeté : le grayscale seul sur fond très sombre reste mal contrasté.
3. Fond rouge teinté + bordure rouge subtile, sans modifier opacité/filter globaux — retenu : différencie clairement mort/vivant, préserve la lisibilité du texte, cohérent avec la charte rouge sang déjà utilisée dans le bandeau mort et le canal fantômes.

**Fix / Décision :** `.v-dead` → `background-color: rgba(139,0,0,0.10)` + `border-color: rgba(139,0,0,0.25) !important`, `opacity: 1`, `filter: none`. Opacité isolée sur l'avatar à `0.45`. Contraste pseudo/rôle : `rgba(232,224,208,0.4)` → `0.6`. Animation GSAP d'élimination : transition vers le fond rouge teinté (sans opacity/filter), avec fallback direct pour `prefers-reduced-motion`.

**Leçon :** Sur fond très sombre, une opacité globale < 0.5 + grayscale dégrade le contraste au-delà du seuil d'accessibilité. Préférer un signal coloré isolé (fond teinté, bordure) qui préserve le contraste du texte tout en différenciant visuellement les états.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Chat overlay mobile — footerDayNav() séparé de dayScreen() via window events

**Contexte :** `feat/chat-overlay-mobile` — `resources/views/game/day.blade.php`, `resources/views/components/announcement-overlay.blade.php`.

**Symptôme / Problème :** Les chats général et fantômes devaient être accessibles depuis un bouton fixe dans le footer nav mobile (rendu dans `<nav>` par le layout), mais les directives Alpine (`@click="toggleChat()"`, `:style="chatVisible ? ..."`) dans ce slot n'ont pas accès au scope `x-data="dayScreen()"` défini dans `<main>` — ils sont dans des éléments siblings hors du scope Alpine de la vue.

**Cause / Alternatives :**
1. Modifier `layouts/game.blade.php` pour envelopper `<main>` et `<nav>` dans un même `x-data` — rejeté : changement transversal au layout, risque de régression sur toutes les vues.
2. Utiliser `Alpine.store()` pour partager l'état — rejeté : refactor de `dayScreen()` significatif (remplacement de `this.chatVisible` par des proxies store dans tout le composant).
3. Exposer l'instance sur `window.__dayScreen` et lire depuis le slot — rejeté : les getters lisant depuis `window` ne sont pas réactifs dans Alpine, le footer ne se mettrait pas à jour.
4. Composant `footerDayNav()` séparé + communication par `window.dispatchEvent` — retenu : cohérent avec la convention du projet (`window.dispatchEvent` pour les events cross-composants, cf. CLAUDE.md et DECISIONS.md "Double abonnement Echo").

**Fix / Décision :** `footerDayNav()` est un composant Alpine léger (`x-data="footerDayNav()"`) dans le `@section('footer-nav')`. Il maintient une copie locale de l'état nécessaire (`chatVisible`, `deadChatVisible`, `unreadMessages`, `isAlive`) et l'écoute via `window.addEventListener('day-chat-state', ...)`. Ses boutons dispatchent `request-day-toggle-chat` / `request-day-toggle-dead-chat`. `dayScreen()` écoute ces requêtes et exécute les toggles ; il émet `day-chat-state` après chaque changement (`toggleChat`, `toggleDeadChat`, `_checkChatAuto`, `_resetChatState`, réception d'un message non lu, `i-was-eliminated`).

**Leçon :** Quand un slot de layout (footer-nav, header) doit réagir à l'état d'un composant Alpine défini dans `<main>`, la seule voie correcte sans modifier le layout est `window.dispatchEvent` + composant Alpine séparé dans le slot. Ne jamais tenter de lire des propriétés Alpine via `window.xxx` dans des bindings `:` ou `x-text` — les lectures non proxifiées par Alpine ne sont pas réactives. Le guard `_initialized` reste obligatoire uniquement dans `dayScreen().init()` (qui enregistre des listeners) ; `footerDayNav().init()` n'en a pas besoin car il n'est instancié qu'une fois par le slot.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Rejets de guard silencieux dans ProcessNightActions et ProcessWerewolvesTurn

**Contexte :** `fix/night-actions-guard-logging` — `app/Jobs/ProcessNightActions.php`, `app/Jobs/ProcessWerewolvesTurn.php`.

**Symptôme / Problème :** Dans la partie SXAKHE (game_id 138, nuit 5), le loup unique a voté mais aucune victime n'a été éliminée et la nuit s'est terminée normalement. Aucun log, aucune `failed_job`, aucune trace — le rejet du job par le guard était totalement silencieux. Impossible de distinguer un rejet légitime (job stale d'un round précédent, attendu) d'un rejet anormal (bug de séquencement).

**Cause / Alternatives :** Les deux jobs avaient un `if (! $game) { return; }` nu après la transaction `lockForUpdate()`. Ce pattern garantit l'idempotence (correct) mais masque toute anomalie (incorrect). Alternatives envisagées : (1) ne rien changer (rejet silencieux = comportement voulu pour un job stale) — rejeté, car le cas anormal (bug de statut, round désynchronisé) ne peut alors pas être diagnostiqué en prod ; (2) lever une exception — rejeté, un job stale qui échoue avec exception pollue la `failed_jobs` et déclenche des alertes injustifiées ; (3) `Log::warning()` uniquement — retenu, visible dans les logs sans bruit de faux positifs.

**Fix / Décision :** Remplacement du `return` silencieux par `Log::warning('ProcessXxx: rejeté par le guard (statut ou round incorrect)', ['game_id' => ..., 'round' => ...])` dans `ProcessNightActions` et `ProcessWerewolvesTurn`. Le comportement reste identique (le job ne s'exécute pas), mais le rejet est désormais traçable.

**Leçon :** Tout guard `if (! $game) { return; }` dans un job de phase doit être accompagné d'un `Log::warning()`. Le rejet légitime (job stale inter-rounds) et l'anomalie (bug de séquencement) sont identiques côté code — seul le log permet de les distinguer en post-mortem. Appliquer ce pattern à tout futur job de phase qui utilise `lockForUpdate()` avec rejet silencieux.

**Statut :** ✅ Résolu

---

## [RÉSOLU] ProcessWerewolvesTurn sans guard $round — nuit blanche loup unique

**Contexte :** `fix/wolves-solo-vote-stale-job` — `app/Jobs/ProcessWerewolvesTurn.php`, `app/Jobs/ProcessSeerTurn.php`, `app/Http/Controllers/Game/ActionController.php`.

**Symptôme / Problème :** Avec un seul loup en vie, le loup votait, l'interface confirmait, mais aucune victime n'était éliminée. La nuit se terminait silencieusement sans élimination.

**Cause / Alternatives :** `ProcessWerewolvesTurn` était le seul job de phase nuit sans paramètre `$round`. Son guard d'entrée (`status === 'night'`) ne permettait pas de rejeter un job stale d'une nuit précédente. Race condition entre le fallback timer (dispatché au départ du tour par `ProcessSeerTurn`) et le dispatch immédiat du vote (`castNightVote` → `ProcessNightActions::delay(1s)`) : le premier à exécuter la transaction passait `status → processing_night`. Le second `ProcessNightActions` — dispatché avec le bon round par le vote — était rejeté par son propre guard `whereIn('status', ['night', 'wolves_turn'])` sans aucun log, laissant la nuit sans victime.

**Fix / Décision :** Paramètre `public readonly int $round` ajouté au constructeur de `ProcessWerewolvesTurn`, guard `->where('round', $this->round)` ajouté dans le `lockForUpdate()`. Mise à jour des 3 appelants : `ProcessSeerTurn::handle()` (dispatch voyante morte/inactive + dispatch delay), `ActionController::seerCheck()` (dispatch après action manuelle). `ProcessSeerAutoAction` ne dispatche pas ce job — non concerné.

**Leçon :** Tout job de phase nuit dispatché avec un délai (timer) OU potentiellement doublonné doit recevoir `$round` et vérifier `round === $this->round` en entrée de transaction. C'est le seul moyen de rejeter un job stale sans masquer une vraie race condition dans les logs.

**Statut :** ✅ Résolu

---

## [CHOIX] Suppression du nom Google des payloads broadcast publics

**Contexte :** `fix/remove-google-name-from-broadcast` — `app/Events/Game/PlayerEliminated.php`, `HunterShot.php`, `RandomElimination.php`, `resources/js/game-state.js`.

**Symptôme / Problème :** Le champ `google_name` (issu de `users.name`, nom réel récupéré via OAuth Google) était inclus dans trois événements broadcast émis sur le canal public `game.{gameId}`. N'importe quel joueur connecté pouvait donc lire l'identité réelle de ses adversaires, y compris leur vrai nom.

**Cause / Alternatives :** Le champ avait été ajouté pour construire un toast `"💀 Jean aka Pseudo était le Loup-Garou"` côté client. Deux alternatives envisagées : (1) hasher ou tronquer le nom — rejeté, trop complexe et toujours partiel ; (2) supprimer purement et simplement — retenu.

**Fix / Décision :** Suppression de `google_name` et `target_google_name` des trois `broadcastWith()`. Toast réduit à `"💀 Pseudo était le Rôle"`. `users.name` reste stocké en base pour un futur dashboard admin (usage serveur uniquement).

**Leçon :** Ne jamais émettre de données d'authentification (email, nom OAuth, avatar) dans un canal broadcast public. Les pseudos de jeu suffisent côté client — les données d'identité réelle n'ont leur place que côté serveur ou dans des canaux privés authentifiés.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Toast "Reconnecté !" parasite sur connexion WebSocket initiale

**Contexte :** `fix/toast-reconnecte-ws-initial` — `resources/js/game-state.js`, section "Reconnexion WebSocket (Pusher/Reverb)".

**Symptôme / Problème :** À chaque navigation interne (nuit→jour, jour→nuit, etc.), le toast "Reconnecté !" s'affichait alors que le joueur n'avait jamais perdu la connexion. Le comportement survenait à chaque chargement de page, rendant le toast inutile et trompeur.

**Cause / Alternatives :** `conn.bind('connected', ...)` se déclenche à toute connexion Pusher/Reverb — y compris la connexion initiale établie par Echo au chargement de la page. Il n'existe pas de distinction native entre "première connexion" et "reconnexion après coupure" dans l'API Pusher. Alternative envisagée : utiliser l'état `conn.state` avant la connexion pour détecter une reconnexion réelle — rejetée, l'état change de façon asynchrone et est peu fiable au moment du binding.

**Fix / Décision :** Flag `_wsEverConnected` initialisé implicitement à `undefined` (falsy) sur `this`. Au premier `connected` : flag posé à `true`, return sans toast. Aux connexions suivantes : vérification du guard `sessionStorage.__internalNavigation` (cohérent avec `_handlePlayerDisconnected`, `_handlePlayerReconnected`, `_handlePlayerInactive`) puis toast affiché. Chaque page étant un nouveau contexte JS, `_wsEverConnected` est réinitialisé à chaque chargement — la première connexion par page est toujours silencieuse.

**Leçon :** Tout handler sur `conn.bind('connected', ...)` doit distinguer la connexion initiale (silencieuse) des reconnexions réelles (toast utile). Le pattern `_wsEverConnected` est applicable à tout futur binding Pusher sur `connected`. Ne pas supposer que `connected` implique une coupure préalable.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Chasseur Maire — tir avant succession du maire (inversion priorité hunter_pending > is_mayor)

**Contexte :** `fix/bug-chasseur-maire-ordre-succession` — `app/Services/VoteService.php`, `app/Jobs/ProcessHunterTurn.php`, `app/Jobs/ProcessHunterAutoAction.php`, `app/Jobs/ProcessNightEnd.php`, `app/Http/Controllers/Game/ActionController.php`.

**Symptôme / Problème :** Quand le Chasseur était aussi Maire et qu'il était éliminé (jour OU nuit), la succession du Maire était déclenchée immédiatement avant que le Chasseur ait pu tirer (ou renoncer). L'ordre correct (SPEC.md) est : tir du Chasseur (ou renoncement/timer) → succession ensuite.

**Cause / Alternatives :**
Dans `VoteService::resolveDayVote()`, le bloc `if ($eliminated->is_mayor)` prenait la priorité absolue via un `if/elseif` : si `is_mayor === true`, la branche `hunter_pending` n'était jamais atteinte. `ProcessHunterTurn` et `ProcessHunterAutoAction` ne transmettaient pas l'information `$isMayor` et ne pouvaient donc pas déclencher la succession après le tir. `ProcessNightEnd` dispatche `ProcessHunterTurn` sans `$isMayor`, même bug pour la mort nocturne. Alternative envisagée : déclencher la succession depuis `ProcessHunterTurn` directement — rejeté, ce job ne sait pas quand le tir a lieu (il déclenche juste le tour du chasseur, pas la résolution).

**Fix / Décision :**
1. `VoteService::resolveDayVote()` : capturer `$isMayor = $eliminated->is_mayor` avant le bloc conditionnel. Inversion de priorité : `if ($hunterPending)` en premier, `elseif ($isMayor)` en fallback. `$isMayor` passé à `ProcessHunterTurn::dispatch`.
2. `ProcessHunterTurn` : `bool $isMayor = false` ajouté au constructeur, transmis à `ProcessHunterAutoAction::dispatch`.
3. `ProcessHunterAutoAction` : `bool $isMayor = false` ajouté au constructeur. Quand `$isMayor=true`, après le check victoire : guard `hunter_shot` en DB (tir volontaire → ActionController a déjà broadcasté la succession → return). Si pas de hunter_shot (timer expiré) → `broadcast(MayorSuccessionStarted)` + `ProcessMayorSuccession::dispatch`. Pour le cas nuit, dispatch supplémentaire de `ProcessNightEnd` (délai succession + 5s) pour terminer la nuit après la succession (ProcessMayorSuccession avec `shouldStartNight=true` ne termine pas la nuit lui-même).
4. `ActionController::hunterShoot()` : lit `$isMayor = $hunter->is_mayor` avant le tir. Si `$isMayor=true` : broadcast `MayorSuccessionStarted` + dispatch `ProcessMayorSuccession` (+ `ProcessNightEnd` si contexte nuit). Passe `$isMayor` à `ProcessHunterAutoAction::dispatch` — ce dernier voit `hunter_shot` en DB et retourne sans re-déclencher (évite la double succession).
5. `ProcessNightEnd` : après extraction de `$hunterId`, lit `$hunter->is_mayor` et passe `$isMayor` à `ProcessHunterTurn::dispatch`.

**Leçon :** Quand un joueur cumule deux rôles avec des comportements post-mort (Chasseur tir + Maire succession), toujours traiter les deux comportements en séquence stricte : le plus actif (tir) d'abord, le passif (succession) ensuite. Ne jamais tester `is_mayor` avant `hunter_pending` dans un `if/elseif`. Transmettre le contexte `$isMayor` à travers tous les jobs de la chaîne pour éviter de perdre l'information entre les dispatches.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Messages sorcière non différenciés au matin — DayStarted enrichi + logique sessionStorage

**Contexte :** `fix/bug-messages-sorciere-matin` — `app/Events/Game/DayStarted.php`, `app/Services/PhaseManager.php`, `resources/js/game-state.js`.

**Symptôme / Problème :** Tous les joueurs recevaient le même toast générique "🧙 La sorcière a agi cette nuit." au matin, quelle que soit leur position dans l'action de la sorcière (sorcière elle-même, joueur sauvé par auto-soin, joueur empoisonné, spectateurs). La sorcière et le joueur empoisonné ne recevaient aucun message personnalisé.

**Cause / Alternatives :** `DayStarted::broadcastWith()` ne transportait que `witch_acted` (booléen) et `saved_player_id` — impossible d'identifier la sorcière ou le joueur empoisonné côté client. Le toast "☠️ La sorcière t'a empoisonné" était dans `handlePlayerEliminated()` (reason `witch_kill`), mais cette fonction s'exécute sur `/night` dont la page est détruite par la redirection GSAP avant que le composant toast puisse rendre — le toast était donc invisible dans tous les cas.

**Fix / Décision :** Trois champs ajoutés à `DayStarted` : `witch_player_id` (id de la sorcière ayant agi), `poisoned_player_id` (id du joueur empoisonné), `poisoned_player_pseudo` (pseudo du joueur empoisonné). `PhaseManager::endNight()` les calcule depuis les `game_actions` du round courant, hors de toute transaction `lockForUpdate()`. `game-state.js::_applyDayStarted()` : le bloc `witch_acted` générique est remplacé par une logique différenciée — sorcière (auto-soin ou poison), joueur empoisonné, et autres joueurs reçoivent chacun leur message via sessionStorage ou toast direct. Le bloc `if (e.reason === 'witch_kill')` dans `handlePlayerEliminated()` est supprimé : le toast n'était jamais visible côté client (page `/night` détruite avant rendu), et le sessionStorage sur `/day` est le seul canal fiable pour ce cas.

**Leçon :** Tout toast destiné à un joueur spécifique lors d'une transition de phase (redirection GSAP) doit passer par `sessionStorage` (`pending_toast_{playerId}`), jamais par `_dispatchToast()` dans le handler de l'event déclencheur de la redirection. Les toasts dans `handlePlayerEliminated()` ne sont fiables que si la page n'est pas détruite immédiatement après — vérifier systématiquement si la transition implique une navigation avant d'y placer un toast personnalisé.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Alpine v3 @event.window non déclenché pour les event names avec tirets dans les composants Blade

**Contexte :** `fix/bug-toast-show-toast-window-listener` — `resources/views/components/toast.blade.php`.

**Symptôme / Problème :** `window.dispatchEvent(new CustomEvent('show-toast', { detail: ... }))` ne déclenchait jamais `add()` dans le composant toast, malgré la présence du binding `@show-toast.window="add($event.detail)"` sur le `<div x-data>`. Le composant était bien dans le DOM, Alpine l'initialisait correctement (`window.__toastReady = true` était positionné, le buffer était consommé), mais les toasts ultérieurs dispatchés via `_dispatchToast()` n'apparaissaient jamais.

**Cause / Alternatives :** Alpine v3 traite les event names avec tirets dans la syntaxe `@event.window` de façon inconsistante lorsque le composant est rendu via un composant Blade anonyme (`<x-toast />`). La conversion camelCase/kebab-case peut échouer silencieusement — le listener `window.addEventListener` n'est jamais enregistré. Ce comportement ne se reproduit pas systématiquement avec des event names sans tiret (ex. `@keydown.window`).

Alternative : renommer l'event en `showtoast` ou `toast` (sans tiret) et mettre à jour tous les appelants — rejeté, risque de régression sur `_dispatchToast()` dans `game-state.js`.

**Fix / Décision :** Suppression de `@show-toast.window="add($event.detail)"` du `<div>`. Ajout de `window.addEventListener('show-toast', (e) => this.add(e.detail))` dans `init()` immédiatement après `window.__toastReady = true`. L'enregistrement impératif dans `init()` est garanti, indépendant du parser de syntaxe Alpine, et cohérent avec le pattern déjà utilisé dans `dayScreen.init()` / `nightScreen.init()` pour tous les CustomEvents.

**Leçon :** En Alpine v3, ne pas utiliser `@event.window` pour les event names contenant des tirets sur des composants Blade. Toujours préférer `window.addEventListener('event-name', ...)` dans `init()` pour les CustomEvents cross-composants. Le binding déclaratif `@event` reste valide pour les events Alpine natifs (ex. `@click`, `@keydown`), pas pour les `CustomEvent` dispatchés via `window.dispatchEvent()`.

**Statut :** ✅ Résolu

---

## [CHOIX] Votes maire publics — voter_pseudo + target_pseudo dans MayorVoteCast

**Contexte :** `fix/bug-votes-maire-temps-reel` — `app/Events/Game/MayorVoteCast.php`, `app/Http/Controllers/Game/VoteController.php`, `resources/js/game-state.js`, `tests/Unit/Events/EventPayloadTest.php`.

**Symptôme / Problème :** Pendant l'élection du Maire, les joueurs voyaient les totaux par candidat mis à jour en temps réel mais n'avaient aucune indication de qui venait de voter pour qui. L'expérience était opaque : impossible de suivre la dynamique de l'élection au moment où elle se déroule.

**Cause / Alternatives :**
1. Continuer à n'exposer que les totaux (`votes[]`) — résout l'anonymat mais sacrifie la lisibilité de l'élection.
2. Exposer `voter_pseudo` et `target_pseudo` dans `MayorVoteCast` (sans `player_id`) — choix retenu.

**Fix / Décision :** Changement de spec assumé : les votes maire sont désormais **publics** (auteur + cible visibles via leurs pseudos). Les votes JOUR restent entièrement anonymes (`DayVoteCast` non touché). `MayorVoteCast` : deux nouveaux champs `voterPseudo`/`targetPseudo` dans le constructeur et `broadcastWith()`. `VoteController::mayor()` : `$targetPseudo` extrait des totaux retournés (`collect($votes)->firstWhere('target_player_id', $targetId)['pseudo']`) — aucune requête DB supplémentaire. `game-state.js` : toast "👑 X a voté pour Y" (ou "pour lui-même") dans `_handleMayorVoteCast()`. La règle anti-spoofing est préservée : `player_id` toujours absent du payload.

**Leçon :** Le vote maire et le vote jour ont des régimes de visibilité distincts — ne jamais les traiter de manière identique. `MayorVoteCast` est public par nature (l'identité du maire est connue de tous), `DayVoteCast` est anonyme pour protéger les joueurs d'une pression sociale. La règle anti-spoofing (`player_id` toujours absent) s'applique aux deux : exposer un pseudo n'est pas la même chose qu'exposer un identifiant interne qui permettrait de manipuler les votes.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Conflit Alpine `:style` string / GSAP dans les barres de progression

**Contexte :** `fix/bug-progress-bar-role-reveal-mayor-election` — `resources/views/game/role-reveal.blade.php`, `resources/views/game/mayor-election.blade.php`.

**Symptôme / Problème :** Les barres de progression (`#game-timer-fill`, `#election-timer-fill`) restaient pleines et ne s'animaient pas, même si GSAP était correctement appelé.

**Cause / Alternatives :**
En Alpine.js v3, un binding `:style` avec une valeur de type *string* (ex. `':style="'background-color:' + color"`) appelle `el.style.cssText = value` en interne. Cela remplace l'*intégralité* du style inline — y compris la propriété `width` animée par GSAP — à chaque tick réactif (toutes les secondes ici). GSAP animait la largeur, Alpine la remettait à 100% une seconde plus tard.

Alternative envisagée : utiliser le composant `<x-game-timer>` — rejeté car ce composant a le même défaut (`:style="'width:100%; background-color:' + barColor"` inclut `width` dans la chaîne).

**Fix / Décision :** Suppression du binding `:style` Alpine sur les éléments de remplissage (`#reveal-timer-bar`, `#game-timer-fill`, `#election-timer-fill`). GSAP prend en charge à la fois `width` (via `gsap.to(bar, { width: '0%', ... })`) et `backgroundColor` (via `gsap.to(bar, { backgroundColor: '...', duration: 0.3 })`). Guard `prefers-reduced-motion` ajouté avant tout appel GSAP sur les barres. Pattern identique à `night.blade.php` (`#seer-timer-bar`).

**Leçon :** Ne jamais inclure `width` dans un binding `:style` Alpine sur un élément dont la largeur est animée par GSAP. Pour les changements de couleur conditionnels sur une barre de timer, utiliser `gsap.to(bar, { backgroundColor: ... })` dans le `setInterval` au passage des seuils (ex. 10s, 5s), pas un binding réactif Alpine.

**Statut :** ✅ Résolu

---

## [CHOIX] Persistance de random_elimination en DB plutôt que reconstruction depuis le broadcast

**Contexte :** `fix/add-random-elimination-history` — `app/Services/VoteService.php`, `app/Http/Controllers/Game/GameController.php`, `app/Services/HistoryService.php`.

**Symptôme / Problème :** L'historique de partie n'affichait pas la victime lors d'une élimination aléatoire (0 votes jour). `buildTimeline()` n'avait aucune source de données pour retrouver la victime dans ce cas — `random_elimination` n'était pas dans le `whereIn` et aucune `GameAction` ne représentait cet événement.

**Cause / Alternatives :**
1. Reconstruire la victime depuis le broadcast `RandomElimination` (événement WebSocket) — rejeté : les broadcasts ne sont pas persistés ; l'historique deviendrait dépendant de données éphémères non disponibles après rechargement ou reconnexion.
2. Persister l'élimination aléatoire en DB via un `GameAction random_elimination` au moment de l'élimination — retenu : cohérent avec les patterns `hunter_pending`, `night_resolve`, `mayor_succession` déjà en place.

**Fix / Décision :** `GameAction random_elimination` créé dans la branche 0-votes de `resolveDayVote()` avec `player_id = target_player_id = victim->id`. `random_elimination` ajouté au `whereIn` de `GameController::history()`. `HistoryService::buildTimeline()` lit l'action dans la branche `else` (aucun vote) et peuple `$dayEntry['eliminated']`.

**Leçon :** Tout événement devant apparaître dans l'historique doit être persisté en `game_actions` au moment où il se produit. Ne jamais reconstruire l'historique depuis les broadcasts WebSocket — ils sont éphémères et ne survivent pas aux reconnexions ou rechargements de page.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Maire en sursis — succession différée si sorcière avec soin disponible

**Contexte :** Phase 26 — `ProcessNightActions.php`, `WitchAction.php`.

**Symptôme / Problème :** Quand les loups tuaient le maire, `ProcessNightActions` broadcastait `MayorSuccessionStarted` et dispatchait `ProcessMayorSuccession` immédiatement après avoir marqué le maire mort — avant que la sorcière ait pu agir. Si la sorcière utilisait ensuite son soin, la succession était déjà partie et le maire était simultanément vivant et en cours de succession.

**Cause / Alternatives :**
1. Annuler la succession depuis `WitchAction::heal()` si en cours — rejeté : `ProcessMayorSuccession` est déjà en file, annulation complexe et non atomique.
2. Différer la mort du maire dans `ProcessNightActions`, symétrique au sursis sorcière — retenu : pattern déjà validé, cohérent.

**Fix / Décision :** `ProcessNightActions` résout `$witch` avant le bloc victime. Flag `$victimIsMayorWithWitchAvailable` (victime est maire + sorcière vivante avec soin). Si vrai : mort et succession différées (is_alive reste true, pas de broadcast). `WitchAction::kill()` et `::pass()` : après le check witch-as-victim, vérifier si le night_resolve cible un maire encore vivant (en sursis) ; si oui, marquer mort + broadcast `PlayerEliminated` + `MayorSuccessionStarted` + dispatch `ProcessMayorSuccession` hors transaction. `WitchAction::heal()` : rien de spécial — le maire est sauvé (`is_alive` reste true). Cas edge witch = maire : géré dans le bloc `$witchDiedFromWolves` (dispatch succession si `$witch->is_mayor`).

**Leçon :** Tout joueur dont la mort peut être annulée par une action ultérieure doit être mis "en sursis" dans le job de résolution automatique. La règle est identique pour la sorcière elle-même et pour le maire — généraliser le pattern à tout rôle protégeable.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Sorcière en sursis — ne pas marquer morte immédiatement si potion de soin disponible

**Contexte :** `feat/witch-self-heal-and-notifications` — `ProcessNightActions.php`, `ProcessWitchTurn.php`, `WitchAction.php`, `WitchActedPublic.php`, `PlayerEliminated.php`, `HunterShot.php`, `ActionController.php`, `night.blade.php`, `game-state.js`.

**Symptôme / Problème :** Règle officielle Loup-Garou : la sorcière PEUT se sauver elle-même si les loups la ciblent. L'implémentation précédente la marquait morte dans `ProcessNightActions` avant qu'elle voie son panel, et `WitchAction` refusait le `heal` quand la victime était la sorcière elle-même (`abort(403)`).

**Cause / Alternatives :**
1. Marquer la sorcière morte puis la ressusciter si elle utilise son soin — rejeté : enverrait un `PlayerEliminated` avant le tour sorcière, côté client le joueur serait affiché mort pendant qu'il joue.
2. Ne pas marquer la sorcière morte immédiatement si elle a encore son soin — retenu : elle est "en sursis". La mort est reportée à l'action `pass` ou `kill` dans `WitchAction`.

**Fix / Décision :** `ProcessNightActions` vérifie `victimIsWitchWithHeal` (is_alive + isWitch + heal non utilisée) avant de marquer morte. Si vrai : skip mort + skip `PlayerEliminated` + skip `hunter_pending` + skip notification push — mais `night_resolve` est créé dans tous les cas. `ProcessWitchTurn` autorise `healAvailable = true` quand `victim.id === witch.id` (suppression du guard `victim->id !== $witch->id`). `WitchAction::act()` : guard `abort(403)` supprimé pour l'auto-heal ; dans `pass` et `kill`, la sorcière est marquée morte si elle était la victime (`night_resolve.target_player_id === witch.id`), broadcast `PlayerEliminated` hors transaction via référence `&$witchDiedFromWolves`. `game-state.js` : guard dans `handlePlayerEliminated` pour ne pas traiter l'élimination personnelle si `nightPhase === 'witch_turn' && myRole === 'witch'` (elle sera gérée via `DayStarted`).

**Leçon :** Quand un joueur peut choisir de mourir ou survivre (action volontaire), ne jamais marquer le statut final dans le job de résolution automatique — reporter la mort à l'action du joueur lui-même. Le pattern `night_resolve` (persisté dans tous les cas) permet aux jobs suivants de reconnaître la victime sans re-calculer. Broadcast `PlayerEliminated` toujours hors transaction (`&$flag` + `broadcast` après `DB::transaction`).

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Extraction SeerAction / WitchAction / HunterAction de GameService (God Service)

**Contexte :** `refactor/extract-role-actions-from-game-service` — `app/Services/GameService.php`, `app/Services/RoleActions/SeerAction.php`, `app/Services/RoleActions/WitchAction.php`, `app/Services/RoleActions/HunterAction.php`

**Symptôme / Problème :** `GameService` était un God Service concentrant à la fois l'orchestration générale (joinGame, startGame, markReady, …) et les actions de rôles spéciaux (seerCheck, witchAct, hunterShoot). Audit Passe 3 "Faiblesse 1" : manque de maintenabilité, chaque nouveau rôle v1.3+ aurait alourdi ce fichier déjà de 835 lignes.

**Cause / Alternatives :** (1) Extraction complète avec mise à jour de tous les appelants (ActionController, Jobs, Services) — risque élevé, change l'API publique. (2) Pattern Facade/délégation : `GameService` conserve ses méthodes publiques identiques (zéro changement pour les appelants) et délègue vers des classes dédiées `RoleActions/*.php` — risque zéro de régression API. (3) Extraction en une seule passe sur les 3 actions — risque de régression accumulé non détectable par les tests intermédiaires.

**Fix / Décision :** Option 2 + extraction progressive en 3 étapes indépendantes (A→B→C), avec validation des tests entre chaque étape. `GameService::seerCheck/witchAct/hunterShoot` délèguent via `app(RoleActions\XxxAction::class)->method(...)`. Les nouvelles classes sont instanciées par le container Laravel — cohérent avec `app(VoteService::class)` déjà utilisé dans le projet. `WitchAction` reçoit `VoteService` en injection de constructeur (résolu automatiquement par Laravel). Aucun appelant externe (ActionController, Jobs) n'a été modifié — la signature publique de `GameService` reste identique.

**Leçon :** Pour extraire des méthodes d'un service central sans changer l'API publique, le pattern "délégation par le container" (`app(Xxx::class)->method(...)`) est le chemin de migration à risque minimal. Il permet de faire coexister l'ancienne signature et la nouvelle implémentation, et de valider chaque extraction indépendamment. Pour v1.3+, chaque nouveau rôle ajoute sa propre classe `RoleActions/XxxAction.php` sans toucher `GameService`.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] PhaseAnnouncement — adaptation `.announcement-text` et point d'ancrage startDay()

**Contexte :** `feat/phase-announcement-overlay` — `app/Events/Game/PhaseAnnouncement.php`, `app/Services/PhaseManager.php`, `resources/js/game-state.js`, `resources/views/components/announcement-overlay.blade.php`

**Symptôme / Problème :** Deux écarts mineurs entre la spec littérale (SPEC_TRANSITIONS.md) et le code réel.

**Cause / Alternatives :**
1. La spec §5.4 montre `gsap.fromTo('.announcement-text', ...)` mais le `<p>` du composant n'a pas la classe `announcement-text`. Sans elle, le sélecteur GSAP ne trouve rien et l'animation est silencieusement ignorée.
2. La spec §7 dit "broadcast `day_break` dans `PhaseManager::endNight()`" mais `DayStarted` est broadcasté dans `startDay()` (appelé par `endNight()`). Ajouter le broadcast dans `endNight()` aurait séparé `PhaseAnnouncement` de `DayStarted` par l'appel `startDay()`, risquant une inversion d'ordre selon les timings.

**Fix / Décision :**
1. `announcement-text` ajouté sur le `<p>` du composant overlay pour que GSAP trouve son sélecteur.
2. `PhaseAnnouncement(day_break)` ajouté dans `startDay()` juste avant `broadcast(new DayStarted(...))` — respecte la règle HORS transaction et l'ordre garanti PhaseAnnouncement → DayStarted.

**Leçon :** Quand la spec nomme une méthode comme point d'ancrage mais que le broadcast réel est dans une méthode appelée, toujours suivre l'emplacement réel du broadcast dans le code.

**Statut :** ✅ Résolu

---

## [CHOIX] PLAYERS_DATA conservé comme amorçage initial dans day.blade.php

**Contexte :** `fix/game-state-players-source-of-truth` — `resources/js/game-state.js`, `app/Http/Controllers/Game/GameController.php`, `resources/views/game/day.blade.php`

**Symptôme / Problème :** `game-state.js` déclarait `players: []` mais ne le peuplait jamais — `GameController::state()` ne retournait pas la liste des joueurs. Les vues maintenaient leur propre copie locale via `PLAYERS_DATA` (injection Blade). Violation de la règle "game-state.js = seul store source de vérité pour players[]".

**Cause / Alternatives :** Deux voies envisagées : (A) Supprimer `PLAYERS_DATA` et forcer la vue à attendre `_loadState()` avant d'afficher quoi que ce soit — risque de flash vide si l'appel XHR est lent. (B) Garder `PLAYERS_DATA` comme valeur d'amorçage initiale fournie par Blade (rendu serveur cohérent), et peupler le store via `_loadState()` pour que les futures vues puissent s'y brancher sans dupliquer leur logique.

**Fix / Décision :** Option B retenue. `PLAYERS_DATA` reste l'amorçage de `dayScreen.players` : c'est le rendu serveur initial, cohérent avec la page HTML livrée, sans flash vide. Ce n'est pas une duplication de source de vérité car `PLAYERS_DATA` n'est lu qu'une seule fois en bootstrap — toutes les mises à jour ultérieures passent par les mêmes CustomEvents (`player-eliminated`, `mayor-elected`, `mayor-succession-done`) que ceux qui mettent à jour `gameState.players`. L'audit a confirmé que `day.blade.php` écoute déjà ces events via `window.addEventListener` — aucune logique de mise à jour dupliquée. `GameController::state()` retourne maintenant `players` dans son payload, et `_loadState()` peuple `this.players` pour toute future vue qui voudra s'y brancher sans PLAYERS_DATA (ex. spectator.blade.php). `night.blade.php` et `spectator.blade.php` restent hors périmètre de cette tâche — voir ROADMAP.

**Leçon :** Le bootstrap Blade (PLAYERS_DATA) et le store central (gameState.players) ne sont pas en conflit si : (1) le bootstrap n'est lu qu'une fois en init(), (2) toutes les mises à jour en cours de vie passent par les CustomEvents du store central, (3) le store est également peuplé côté XHR pour les futures vues qui veulent s'y brancher directement.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Double abonnement Echo — mayor-election.blade.php et role-reveal.blade.php

**Contexte :** `fix/double-echo-subscription-election-views` — `resources/js/game-state.js`, `resources/views/game/mayor-election.blade.php`, `resources/views/game/role-reveal.blade.php`

**Symptôme / Problème :** Les deux vues ouvraient un second `window.Echo.channel()` sur `game.{gameId}` en parallèle de `game-state.js`. `mayor-election.blade.php` écoutait `.mayor.vote.cast`, `.mayor.elected` et `.night.started` — chaque event Reverb déclenchait deux handlers simultanés, dont deux redirections concurrentes vers `/night` (un `setTimeout` brut et l'animation GSAP coordonnée de `game-state.js`). `role-reveal.blade.php` écoutait `.player.ready` (absent de game-state.js) et `.mayor.election.started` (présent dans `_handleMayorElectionStarted` mais sans CustomEvent).

**Cause / Alternatives :** `_handleMayorVoteCast`, `handleMayorElected` et `_handleMayorElectionStarted` ne dispatchaient aucun `CustomEvent` window — les vues ne pouvaient pas se brancher sur game-state.js et devaient s'abonner directement à Echo. `.player.ready` n'était tout simplement pas géré dans le store central.

**Fix / Décision :** Pattern identique aux vues `day.blade.php` et `night.blade.php` déjà corrigées. Trois `window.dispatchEvent(new CustomEvent(...))` ajoutés dans les handlers concernés de `game-state.js`. Nouveau listener `.listen('.player.ready', e => this._handlePlayerReady(e))` + handler `_handlePlayerReady` ajoutés au canal public. Blocs `window.Echo.channel()` supprimés des deux vues, remplacés par `window.addEventListener('mayor-vote-cast' | 'mayor-elected' | 'mayor-election-started' | 'player-ready', ...)`. Guard `_initialized` ajouté dans les deux `init()`. Redirect `.night.started` dans `mayor-election.blade.php` supprimée sans remplacement — `handleNightStarted` dans `game-state.js` est monté dans le layout commun et couvre toutes les vues.

**Leçon :** Quand game-state.js gère un event Echo, il DOIT aussi dispatcher un CustomEvent window pour que les vues locales puissent réagir sans second abonnement. Vérifier systématiquement `_handleMayorVoteCast`, `handleMayorElected`, `_handleMayorElectionStarted` lors de tout ajout de vue en phase d'élection.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Victime loups et sorcière potentiellement différentes en cas d'égalité

**Contexte :** `fix/night-resolve-shared-victim` — `ProcessNightActions.php`, `ProcessWitchTurn.php`, `GameService::witchAct()`, `VoteService.php`

**Symptôme / Problème :** `ProcessNightActions::handle()` et `ProcessWitchTurn::handle()` appelaient chacun indépendamment `VoteService::resolveNightVote($game)`. En cas d'égalité parfaite entre deux cibles, cette méthode tire aléatoirement via `inRandomOrder()`. Les deux appels pouvaient retourner deux joueurs différents : la sorcière voyait une victime qui n'était pas celle effectivement éliminée par les loups.

**Cause / Alternatives :** Deux appels indépendants à une méthode non-déterministe. Alternative envisagée : rendre `resolveNightVote()` déterministe (ex. tri par ID) — rejetée, car changerait le comportement de tirage au sort sans garantie réelle d'unicité entre jobs en cas de re-dispatch.

**Fix / Décision :** Pattern `night_resolve` aligné sur `hunter_pending` déjà en place. `ProcessNightActions` crée un `GameAction` de type `night_resolve` (player_id = target_player_id = victime résolue) immédiatement après la résolution du vote et avant le dispatch de `ProcessWitchTurn`. `VoteService::resolveNightVoteFromAction()` lit cette action. `ProcessWitchTurn` et `GameService::witchAct(heal)` utilisent `resolveNightVoteFromAction()`. `resolveNightVote()` original conservé uniquement dans `ProcessNightActions` (source de vérité unique au moment de la résolution) et `PhaseManager::endNight()` (les night_vote ne changent plus à ce stade, le résultat est stable).

**Leçon :** Toute méthode non-déterministe appelée plusieurs fois dans la même séquence doit persister son résultat en base dès la première résolution. Les lectures ultérieures dans le même round lisent cette action, jamais la méthode brute.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Race condition : vote d'un second loup rejeté 409 si le premier déclenche ProcessNightActions

**Contexte :** `fix/wolf-vote-simultaneous` — `app/Services/VoteService.php` méthode `castNightVote()`

**Symptôme / Problème :** Avec 2 loups votant quasi-simultanément, le premier vote déclenchait `ProcessNightActions::dispatch()` sans délai. En quelques ms, le job transitait le statut de `wolves_turn` vers `processing_night`. Le POST du second loup arrivait alors avec `status = 'processing_night'`, exclu du guard `in_array($game->status, ['night', 'wolves_turn'])` → abort(409). Le second vote était silencieusement ignoré côté client (`wolfVoteLocked` déjà `true` pour le premier loup).

**Cause / Alternatives :** La résolution anticipée (tous loups ont voté) dispatchait le job immédiatement. En conditions réseau normales (quelques ms entre deux clics simultanés), la transaction `ProcessNightActions` pouvait s'exécuter avant l'arrivée du second POST. Alternative envisagée : mutex Redis — rejeté (sur-ingénierie, fragile si Redis redémarre). Le dispatch différé absorbe la fenêtre de réseau sans changer la logique.

**Fix / Décision :** `->delay(now()->addSecond())` sur `ProcessNightActions::dispatch()` dans `castNightVote()`. Le guard `whereIn('status', ['night', 'wolves_turn'])` de `ProcessNightActions` reste le verrou anti-double-fire (inchangé). Un second dispatch éventuel du timer loups trouvera `status = 'processing_night'` et retournera null.

**Leçon :** Toute résolution anticipée déclenchée depuis un endpoint HTTP (plusieurs joueurs peuvent appuyer quasi-simultanément) doit laisser une fenêtre d'au moins 1s avant que le job ne verrouille le statut. Le pattern : dispatch anticipé + délai minimal, timer auto + délai complet, guard atomique dans le job.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Nuits sans action absentes de la timeline + succession maire sans filtre de phase

**Contexte :** fix/history-and-death-banner — `app/Services/HistoryService.php`, `resources/views/game/history.blade.php`

**Symptôme / Problème :** (B) Les nuits sans victime (loups sans consensus) n'apparaissaient pas dans la timeline historique. (C) Un joueur mort par poison sorcière lors d'une nuit "silencieuse" n'avait aucune entrée dans l'historique. (D) La succession de maire n'indiquait pas l'ancien maire, et une succession nocturne se retrouvait dans le bloc jour du même round.

**Cause / Alternatives :** (B+C) `buildTimeline()` n'entrait dans le bloc `nightEntry` que si `$nightVotes->isNotEmpty() || $witchHeal || $witchKill || $hunterNight`. Une nuit où seul le poison sorcière agit mais où les loups n'ont pas voté tombait hors condition si `$witchKill` ne déclenchait pas l'entrée (cas de variable null vs collection vide). Les nuits sans aucune action étaient silencieusement omises. (D) La recherche `mayor_succession` ne filtrait pas par `phase`, donc une succession nocturne (`phase = 'night'`) était placée dans `$dayEntry['succession']`.

**Fix / Décision :** (B+C) Supprimer la condition `if (...)` qui enveloppait la création du `$nightEntry` — l'entrée est désormais créée et ajoutée pour **chaque round**, avec `wolf_no_agreement = true` si `$nightVotes->isEmpty()`. (D) Deux variables distinctes : `$successionDay` (`->where('phase','day')`) dans le bloc jour, `$successionNight` (`->where('phase','night')`) dans le bloc nuit. Le format de succession est enrichi avec `former_mayor` + `new_mayor` (deux snapshots) pour afficher "X a désigné Y comme successeur."

**Leçon :** Toute entrée de timeline représentant une phase de jeu doit être créée inconditionnellement pour chaque round — l'absence d'actions est une information métier valide ("les loups ne se sont pas mis d'accord"). Les actions liées à une phase doivent toujours être filtrées par `phase` pour éviter les placements incorrects dans la timeline.

**Statut :** ✅ Résolu

---

## [CHOIX] setInterval parallèle à GSAP pour wolfTimerSeconds dans nightScreen()

**Contexte :** feat/chat-toggle-ux — `resources/views/game/night.blade.php`

**Symptôme / Problème :** Le timer visuel du tour des loups (barre rouge) est animé par `gsap.to('#wolf-timer-bar', { width:'0%', duration: WOLVES_TIMER })`. GSAP n'expose pas de callback tick accessible par Alpine — impossible de vérifier les seuils 75s et 15s pour l'auto-affichage du chat loups directement depuis l'animation.

**Cause / Alternatives :** (1) Utiliser `gsap.ticker.add()` — coupling fort entre GSAP et Alpine, difficile à nettoyer. (2) Dériver le temps écoulé depuis `Date.now()` au moment du `werewolves-turn-started` — fragile si l'onglet est en arrière-plan. (3) Ajouter un `setInterval` de 1s dédié au tracking de `wolfTimerSeconds`, lancé et nettoyé conjointement avec l'animation GSAP.

**Fix / Décision :** Option 3 retenue : `_wolfTimerInterval` lancé dans le listener `werewolves-turn-started`, décrémente `wolfTimerSeconds` chaque seconde et appelle `_checkWolfChatAuto()`. Nettoyé quand `wolfTimerSeconds <= 0`. Pattern identique à `_startDayTimer()` dans `dayScreen()` où le setInterval gère à la fois l'UI et la logique auto-chat.

**Leçon :** Quand GSAP anime une barre visuelle pure, ne pas lui faire porter la logique métier temporelle — utiliser un `setInterval` séparé pour le tracking de secondes. Les deux coexistent sans conflit : GSAP pilote le DOM, l'interval pilote l'état Alpine.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] canChatWolves excluait wolves_turn — chat loups muet pendant le tour actif

**Contexte :** fix/wolf-chat-reception — `app/Services/PhaseGuard.php`, `tests/Feature/Game/ChatTest.php`, `tests/Unit/Services/PhaseGuardTest.php`.

**Symptôme / Problème :** Les messages envoyés par les loups pendant leur tour disparaissaient silencieusement côté client et n'étaient jamais broadcastés. `ChatService::sendMessage()` retournait 409 ; `sendWolfChat()` dans `night.blade.php` ne vérifiait pas le code HTTP (`fetch` sans `res.ok`) et vidait l'input avant l'appel, rendant l'échec invisible.

**Cause / Alternatives :** `PhaseGuard::canChatWolves()` retournait `$game->status === 'night'`. Mais `ProcessWerewolvesTurn` transite le statut `night → wolves_turn` en début de tour (pour garantir l'idempotence du job). Pendant tout le tour actif des loups, le statut est `wolves_turn` — le guard rejetait donc tous les messages loups exactement quand ils étaient censés être autorisés. `PhaseGuard::isNight()` couvrait déjà `wolves_turn` mais `canChatWolves` avait un périmètre plus restrictif sans raison documentée.

**Fix / Décision :** `canChatWolves` étendu à `in_array($game->status, ['night', 'wolves_turn'])`. `'night'` conservé pour couvrir le bref intervalle entre la transition seer→wolves avant que `ProcessWerewolvesTurn` démarre. Tests ajoutés pour couvrir les deux statuts autorisés et les statuts refusés.

**Leçon :** Toute méthode `canXxx()` de `PhaseGuard` doit couvrir TOUS les statuts où l'action est sémantiquement autorisée, pas seulement le statut « canonique ». Quand un job transite le statut avant d'émettre l'event déclencheur (pattern atomique), le statut intermédiaire doit être inclus dans le guard correspondant. Ajouter un test Feature avec `status = wolves_turn` en plus de `status = night` pour chaque action liée à la phase nuit.

**Statut :** ✅ Résolu

---

## [CHOIX] Roadmap Code Propre v1.2 — stratégie de refactoring sans régression

**Contexte :** Étapes 1→10 — refactoring progressif post-v1.2 de l'ensemble des fichiers PHP, JS, Blade. Audit final Étape 10 — `app/Http/Controllers/Game/VoteController.php`, `app/Services/VoteService.php`.

**Symptôme / Problème :** Code fonctionnel mais avec documentation insuffisante, magic strings, logique dupliquée. En particulier, `VoteController` contenait trois méthodes privées `_checkAllMayorVotesCast()`, `_checkAllDayVotesCast()`, `_checkAllNightVotesCast()` qui sont de la logique métier (requêtes DB + dispatch de jobs) au lieu de rester dans les Services.

**Cause / Alternatives :** (1) Refactoring global en un seul commit → risque élevé de régression sur ~40 fichiers. (2) Refactoring nul → dette technique croissante. (3) Pour les `_checkAll*` : `_checkAllDayVotesCast` et `_checkAllNightVotesCast` étaient de la logique dupliquée (déjà implémentée dans `VoteService::castDayVote()` et `VoteService::castNightVote()`). `_checkAllMayorVotesCast` était de la logique métier orpheline dans le Controller (non couverte par le Service).

**Fix / Décision :** 10 étapes progressives, uniquement additives dans les 8 premières (doc, Enums, helpers, extraction de service) — aucune modification de logique métier. Étape 9 = documentation routes/README. Étape 10 = audit final de conformité CLAUDE.md + correction des non-conformités résiduelles. VoteController nettoyé : logique "tous les joueurs ont voté → dispatch ProcessMayorElection" déplacée dans `VoteService::castMayorVote()` (pattern identique à `castDayVote()`/`castNightVote()`), méthodes dupliquées supprimées.

**Leçon :** Un refactoring sans tests à 100% vert à chaque étape est un refactoring risqué. La règle "php artisan test après chaque modification de fichier existant" a été respectée tout au long. Les méthodes privées d'un Controller ne doivent pas contenir de requêtes DB ou de dispatch de jobs — même "cachées" dans des méthodes privées, elles violent CLAUDE.md "Controllers : valider request + appeler Service, rien d'autre".

**Statut :** ✅ Résolu

---

## [CHOIX] Centralisation des guards de phase dans PhaseGuard.php

**Contexte :** Refactor `refactor/phase-guard` — `app/Services/PhaseGuard.php`, 6+ fichiers Services/Jobs/Controllers

**Symptôme / Problème :** Le pattern `in_array($game->status, ['night', 'wolves_turn', 'processing_night'])` et ses variantes apparaissaient dans 10+ fichiers. Tout ajout d'un statut intermédiaire (ex: `hunter_turn` en v1.3) nécessitait une chasse manuelle garantie d'être incomplète.

**Cause / Alternatives :** (1) Continuer les magic strings — risque d'oubli lors de l'ajout de v1.3. (2) Centraliser dans une classe statique `PhaseGuard` avec méthodes nommées par sémantique métier — un seul point de modification. (3) Utiliser les Enums déjà créés en Étape 4 — prématuré, les Enums sont encore en intégration progressive.

**Fix / Décision :** `PhaseGuard` créé avec 7 méthodes statiques : `isNight()`, `isNightOrProcessing()`, `isDay()`, `canWitchAct()`, `canHunterShoot()`, `canChatGeneral()`, `canChatWolves()`, `canChatDead()`. Les occurrences directement substituables remplacées dans 10 fichiers. Les 8 occurrences restantes (ensembles custom ou contraintes Workflow) documentées avec `// PhaseGuard ne couvre pas ce cas : [raison]`.

**Leçon :** Pour v1.3+, ajouter les nouveaux statuts intermédiaires uniquement dans `PhaseGuard.php`. Les méthodes `Game::isNightPhase()` et `Game::isDayPhase()` sont intentionnellement conservées (méthodes d'instance, évitent dépendance service → modèle).

**Statut :** 🔵 Choix assumé

---

## [CHOIX] Enums créés sans migration immédiate du code existant — stratégie progressive

**Contexte :** ROADMAP Étape 4 — `app/Enums/GameStatus.php`, `app/Enums/PlayerRole.php`, `app/Enums/ActionType.php`, `app/Enums/ChatChannel.php`, `app/Enums/WinnerTeam.php`

**Symptôme / Problème :** Les magic strings (`'waiting'`, `'night'`, `'werewolf'`, `'day_vote'`, etc.) sont répétées dans les Services, Jobs et Models sans constante partagée — risque de typo silencieuse et manque de lisibilité.

**Cause / Alternatives :** (1) Remplacer toutes les occurrences immédiatement (find/replace global) — risque de régression élevé : la surface de changement couvre ~20 fichiers (Services, Jobs, Models, FormRequests, Factories), et chaque remplacement devrait être testé. (2) Créer les Enums et les intégrer progressivement, fichier par fichier, à chaque prochaine tâche qui touche le fichier concerné — risque zéro de régression à ce stade, les Enums sont rétrocompatibles (backed string, même valeur).

**Fix / Décision :** Option 2 retenue. Les 5 Enums sont créés dans `app/Enums/` avec leurs méthodes helper (`isNightPhase()`, `isDayPhase()`, `isWerewolfSide()`, `isVillagerSide()`). Aucune modification du code applicatif existant. Chaque Enum porte un commentaire `// TODO : intégration progressive dans les Services, Jobs et Models (Étape 5+)`.

**Leçon :** Créer les Enums avant de les intégrer permet de vérifier leur exhaustivité (valeurs, helpers) sans risquer de régression. L'intégration progressive au fil des tâches suivantes est préférable à un big-bang de find/replace sur l'ensemble du codebase.

**Statut :** 🔵 Choix assumé

---

## [CHOIX] hunter_pending en GameAction au lieu de Cache volatile

**Contexte :** fix/hunter-pending-action — ProcessNightActions, ProcessNightEnd, GameService::witchAct(), VoteService::resolveDayVote()

**Symptôme / Problème :** `Cache::put("hunter_must_shoot_...")` perdu si Redis redémarre entre ProcessNightActions et ProcessNightEnd — le chasseur perdait son pouvoir silencieusement.

**Cause / Alternatives :** Cache volatile non persistant entre les redémarrages de workers. Alternative cache avec TTL plus long ne réglerait pas le problème de fond.

**Fix / Décision :** `GameAction` de type `hunter_pending` — persiste en DB, résiste aux redémarrages. Supprimé atomiquement par ProcessNightEnd/VoteService après lecture. Suppression du bloc `Cache::forget` pour le cas `heal` (inutile : si la sorcière sauve le chasseur, `hunter_pending` n'a pas été créé).

**Leçon :** Tout état intermédiaire qui doit survivre à un restart worker doit être en DB, jamais en cache volatile.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] ProcessWitchTurn guards skip — double ProcessNightEnd cassait la séquence nocturne

**Contexte :** fix/night-sequence-timing — app/Jobs/ProcessWitchTurn.php,
app/Jobs/ProcessNightActions.php, tests/Feature/Game/WitchTest.php.

**Symptôme / Problème :** quand les loups étaient en égalité et la sorcière sans
action disponible, la nuit entière était skippée. Voyante, loups et
sorcière ne voyaient jamais leur tour.

**Cause / Alternatives :** les guards "skip silencieux" de ProcessWitchTurn dispatchaient
ProcessNightEnd::delay(0). Ce job arrivait immédiatement, voyait
status='processing_night' (posé par ProcessNightActions), passait le
guard de ProcessNightEnd et appelait endNight(). La voyante et les loups
n'avaient pas encore joué car leurs timers n'étaient pas écoulés
(8s + seer_timer + wolves_timer).

Bug associé découvert : le délai du ProcessNightEnd dispatché par
ProcessNightActions était mayor_succession + 5s ≈ 20s. Ce délai ne
couvre pas witch_timer (jusqu'à 60s). Si witch_timer > 20s, ProcessNightEnd
arrivait avant la fin du tour sorcière et le coupait. Calculé désormais
dynamiquement : witch_timer + mayor_succession + 5s.

Alternative rejetée : ajouter un guard dans ProcessNightEnd vérifiant
qu'une action sorcière existe en base avant d'appeler endNight(). Rejeté
car cela rendrait ProcessNightEnd dépendant de la présence de la sorcière
— il ne passerait jamais si la sorcière est absente ou morte.

**Fix / Décision :** suppression des ProcessNightEnd::delay(0) dans les
guards de ProcessWitchTurn. Ces guards font un simple return. ProcessWitchAutoAction
conserve son ProcessNightEnd::delay(0) car il n'est déclenché qu'après
expiration complète du timer sorcière.

**Leçon :** tout dispatch ProcessNightEnd depuis un job
"skip" est dangereux si des jobs de phase antérieurs ont encore leurs
timers en cours. Le seul dispatcher légitime de ProcessNightEnd dans le
chemin principal est ProcessNightActions, avec un délai couvrant tous
les tours restants. ProcessWitchAutoAction est l'unique exception car
il est déclenché après son propre timer complet.

Dette technique identifiée : night_start_delay et les buffers de
timing arbitraires sont un symptôme d'une limitation architecturale plus
profonde — le backend ne sait pas si les clients sont prêts. Voir
BUGS_AND_ROADMAP.md section "Refactoring architectural planifié" pour
le plan de refactor complet (pattern ready acknowledgment).

**Statut :** ✅ Résolu

---

## [CHOIX] Toasts narratifs serveur — WitchActedPublic conditionné à action !== 'pass', calcul witchActed/savedPlayerId hors transaction

**Contexte :** feat/narrative-toasts-server — `app/Events/Game/WitchActedPublic.php` (nouveau), `app/Events/Game/DayStarted.php`, `app/Http/Controllers/Game/ActionController.php`, `app/Services/PhaseManager.php`, `resources/js/game-state.js`.
**Symptôme / Problème :** Deux décisions à figer pour ces toasts narratifs : (1) faut-il broadcaster `WitchActedPublic` quand la sorcière passe son tour (`action === 'pass'`) ? (2) où calculer `witchActed`/`savedPlayerId` pour enrichir `DayStarted`, sachant que `PhaseManager::startDay()` exécute son `DB::transaction(lockForUpdate())` et que `endNight()` l'appelle juste après avoir résolu `resolveNightVote()`.
**Cause / Alternatives :** (1) Broadcaster `WitchActedPublic` dans tous les cas (y compris `pass`) — rejeté, un "pass" n'est pas une action observable et révélerait indirectement que la sorcière a délibérément choisi de ne rien faire (information narrative inutile). (2) Calculer `witchActed`/`savedPlayerId` à l'intérieur du `DB::transaction(lockForUpdate())` de `startDay()` — rejeté, ce sont des lectures `game_actions` indépendantes du verrou sur `games`, et RISK_GUARDS Guard #5 interdit d'alourdir une transaction `lockForUpdate()` avec des requêtes non nécessaires à la cohérence du verrou.
**Fix / Décision :** `WitchActedPublic` broadcasté uniquement si `$result['action'] !== 'pass'`, juste après les broadcasts existants `WitchActed`/`PlayerEliminated` dans `ActionController::witchAct()`. `witchActed` et `savedPlayerId` calculés dans `PhaseManager::endNight()` via `$game->actions()` (relation Eloquent, cohérent avec le reste du codebase — pas de `DB::table()`), AVANT l'appel à `startDay($game, $victim, $witchActed, $savedPlayerId)` — donc hors de toute transaction `lockForUpdate()`. `DayStarted` reçoit ces deux nouveaux paramètres avec valeurs par défaut (`false`/`null`), aucun appelant existant à modifier.
**Leçon :** Quand une donnée à broadcaster nécessite une lecture `game_actions` supplémentaire pour enrichir un event existant (`DayStarted`), la calculer dans la méthode appelante (`endNight()`) avant le `DB::transaction(lockForUpdate())` de la méthode appelée (`startDay()`), jamais à l'intérieur — même si la lecture elle-même ne modifierait rien. Pour les events "narratifs publics" (qui ne révèlent aucune info de jeu), exclure explicitement les actions "neutres" (`pass`) du déclenchement du broadcast.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Double window.addEventListener dans init() — cause racine définitive des doublons chat

**Contexte :** fix/chat-double-listeners — `resources/views/game/day.blade.php`,
`resources/views/game/night.blade.php`, `resources/js/game-state.js`.

**Symptôme / Problème :** messages chat affichés deux fois pour tous les joueurs
sur /day. Persistait après tous les fixes précédents (suppression double abonnement
Echo dans les vues, tentative `['wss']` seul).

**Diagnostic exhaustif des pistes :**
- Double abonnement Echo dans les vues Blade → écarté, déjà corrigé.
- Double transport Pusher `['ws','wss']` → écarté définitivement. Test `['wss']` seul
  en prod : régression critique (temps réel cassé). Confirmé via `ss -tnp` : une seule
  connexion WebSocket active côté serveur. `enabledTransports: ['ws', 'wss']` est
  obligatoire dans cette config Nginx/Reverb et ne doit plus jamais être modifié.
- Cause racine confirmée via DevTools prod :
  `getEventListeners(window)['chat-message']?.length === 2`

**Cause :** Alpine.js appelle `init()` deux fois sur le composant `dayScreen()`.
`window.addEventListener()` s'accumule sans `removeEventListener()` correspondant.
Deux listeners actifs → chaque CustomEvent dispatché une fois est capturé deux fois.

**Fix :** guard `_initialized` en tête de chaque `init()` qui enregistre des listeners
sur `window`. Pattern à respecter sur tout futur composant Alpine qui utilise
`window.addEventListener()` dans son `init()` :

```js
init() {
    if (this._initialized) return;
    this._initialized = true;
    // window.addEventListener(...) ici
},
```

**Leçon :** Ne jamais enregistrer `window.addEventListener()` dans un `init()` Alpine
sans guard `_initialized`. Alpine peut appeler `init()` plusieurs fois sur le même
composant selon le cycle de vie du DOM. `window.addEventListener()` est cumulatif —
contrairement aux bindings Alpine (`@event`), il ne se remplace pas, il s'empile.
Tout composant qui écoute des CustomEvents sur window doit se protéger contre
les appels multiples de `init()`.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Double transport Pusher (ws + wss) — cause racine définitive des doublons WebSocket

**Contexte :** fix/pusher-double-transport — `resources/js/echo.js`.
**Symptôme / Problème :** tous les events WebSocket (chat général, chat loups, votes, etc.) étaient reçus deux fois côté client, malgré les fixes précédents qui avaient éliminé tous les doubles abonnements Echo applicatifs (cf. décisions "Double abonnement Echo game.{gameId}" et "Double abonnement Echo — modale succession fantôme").
**Cause / Alternatives :** `enabledTransports: ['ws', 'wss']` dans la config Echo/Pusher autorisait Pusher-js à ouvrir DEUX connexions simultanées (une non-TLS sur ws, une TLS sur wss), alors que `forceTLS: (VITE_REVERB_SCHEME ?? 'https') === 'https'` vaut `true` et qu'un seul port (443, via `wssPort`) est configuré. Chaque connexion active reçoit indépendamment tous les events broadcastés sur les canaux souscrits → chaque listener Echo se déclenche une fois par connexion, soit deux fois au total. Les fixes précédents (suppression des abonnements Echo dupliqués dans les vues) ont réduit le nombre de listeners par event de 4 à 2, masquant partiellement le symptôme sans l'éliminer.
**Fix / Décision :** `enabledTransports: ['wss']` — une seule connexion TLS, cohérente avec `forceTLS: true`.
**Leçon :** Avec `forceTLS: true`, `enabledTransports` ne doit contenir que `['wss']`. Conserver `['ws', 'wss']` permet à Pusher-js d'ouvrir deux connexions en parallèle, chacune recevant tous les broadcasts — un doublon "au niveau transport", indépendant de tout double abonnement applicatif. Si un bug de "tous les events arrivent en double" persiste après avoir vérifié qu'aucune vue ne s'abonne deux fois au même canal (cf. règle "un seul composant par canal"), vérifier `enabledTransports` en premier.
**Statut :** ✅ Résolu

---

## [CHOIX] Sorcière — tour maintenu si poison disponible même sans victime des loups

**Contexte :** fix/witch-turn-no-victim — ProcessWitchTurn.php, WitchTurnStarted.php,
night.blade.php, WitchTest.php, RISK_GUARDS.md Guard #3.
**Symptôme / Problème :** la sorcière ne pouvait pas utiliser son poison les nuits
où les loups étaient en égalité (pas de victime). Son tour était skippé silencieusement
via ProcessNightEnd, sans qu'elle soit informée ni qu'elle puisse agir. C'était
contraire aux règles officielles du Loup-Garou (la sorcière se réveille toujours)
et non intuitif pour les joueurs.
**Cause / Alternatives :** Guard #3 de RISK_GUARDS.md protégeait contre un blocage
de la nuit quand resolveNightVote() retourne null. Le guard était trop large — il
skippait le tour entier au lieu de distinguer les cas selon les potions disponibles.
(1) Conserver le skip total — rejeté, non conforme aux règles et frustrant.
(2) Toujours afficher le tour sorcière même potions épuisées — rejeté, inutile
et potentiellement bloquant si ProcessWitchAutoAction ne gère pas ce cas.
(3) Skip uniquement si deux potions épuisées OU (pas de victime ET soin seul
disponible) — retenu.
**Fix / Décision :** Option 3. ProcessWitchTurn distingue trois cas : (a) deux
potions épuisées → skip, (b) pas de victime + poison disponible → WitchTurnStarted
avec victim:null et heal_available:false, (c) victim présente → comportement
inchangé. WitchTurnStarted accepte désormais victim nullable. Le panel sorcière
côté client affiche un message adapté selon le cas. Les tests WitchTest.php
mis à jour pour couvrir les trois cas.
**Leçon :** un guard de sécurité contre un blocage (null check) ne doit pas
empêcher une action légitime du joueur. Toujours distinguer "pas de données"
(null victime) de "action impossible" (potions épuisées). RISK_GUARDS.md mis
à jour pour refléter le nouveau comportement attendu.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Double abonnement Echo game.{gameId} — cause racine des messages en doublon

**Contexte :** Phase 18 Prompt 1 — game-state.js, day.blade.php
**Symptôme / Problème :** messages chat doublés sur /day malgré un premier fix.
**Cause / Alternatives :** deux appels Echo.channel() sur le même canal déclenchent
tous les listeners deux fois côté Pusher/Reverb. Le premier fix avait supprimé un
listener .chat.message.sent explicite dans day.blade.php, mais day.blade.php
conservait un appel Echo.channel() pour .day.vote.cast — suffisant pour réactiver
le double déclenchement.
**Fix / Décision :** règle absolue — un seul composant s'abonne à Echo par canal
(game-state.js). Toute vue qui a besoin d'un event du canal public doit passer par
window.addEventListener sur un CustomEvent dispatché par game-state.js.
**Leçon :** ne jamais appeler window.Echo.channel() dans une vue Blade si game-state.js
s'abonne déjà au même canal. Même un seul .listen() supplémentaire sur le même canal
suffit à déclencher tous les handlers deux fois.
**Statut :** ✅ Résolu

---

## [CHOIX] Canal des fantômes ('dead') — broadcast public tagué, pas de canal privé dédié

**Contexte :** Prompt 6 (Phase 18) — `app/Services/ChatService.php`, `app/Events/Game/ChatMessageSent.php`, `app/Http/Controllers/Game/ChatController.php`, `app/Http/Requests/SendMessageRequest.php`, `database/migrations/2026_06_14_000000_add_dead_to_chat_messages_channel_enum.php`, `resources/views/game/day.blade.php`, `app/Http/Controllers/Game/GameController.php`.
**Symptôme / Problème :** Les joueurs éliminés doivent pouvoir discuter entre eux pendant la phase jour (canal "fantômes"), sans pouvoir écrire dans le chat général, et sans que les vivants puissent y répondre. Fallait choisir entre un canal WebSocket privé dédié (`game.{id}.dead`, type `PrivateChannel`) ou une diffusion sur le canal public existant `game.{id}` avec un tag de routage côté client.
**Cause / Alternatives :** (1) Canal privé dédié — nécessiterait une nouvelle entrée dans `routes/channels.php`, une policy d'autorisation (`is_alive === false`), et un abonnement Echo distinct côté client pour chaque joueur mort. Plus "propre" en isolation réseau mais ajoute de la complexité d'infrastructure pour un canal qui n'a pas besoin de confidentialité forte (les vivants voir les messages des fantômes n'est pas un problème de sécurité, juste un problème d'UX/règle du jeu). (2) Diffusion sur `game.{id}` (canal public déjà utilisé par tous), avec `ChatMessage.channel = 'dead'` et `ChatMessageSent.channel` dynamique : tous les clients reçoivent l'event, mais `dayScreen()` route le message vers `deadChatMessages[]` (affiché uniquement si `!isAlive`) plutôt que `chatMessages[]`. Les vivants ne voient jamais le panneau fantôme (masqué via `x-show="!isAlive"`), donc ne "répondent" jamais — l'écriture est bloquée côté serveur (`ChatService::sendMessage` : `is_alive === true` + `channel === 'dead'` → 403).
**Fix / Décision :** Option 2 retenue. `ChatMessageSent` reçoit un paramètre `channel` dynamique (défaut `'general'`) au lieu de la valeur hardcodée précédente — `ChatController::send()` passe `$chatMessage->channel`. `ChatService::sendMessage()` : guard `is_alive` modifié pour autoriser `channel === 'dead'` aux morts uniquement (inversion : vivant + `dead` → 403 ; mort + canal ≠ `dead` → 403 comme avant). `chat_messages.channel` ENUM étendu à `'dead'` via migration dédiée. `SendMessageRequest` accepte `dead` dans `Rule::in`.
**Leçon :** Un canal "réservé à un sous-ensemble de joueurs" ne nécessite pas systématiquement un `PrivateChannel` Reverb — si la fuite d'information vers les autres joueurs n'est pas un problème de sécurité (juste une règle d'affichage/écriture), le routage côté client sur le canal public existant évite une policy + un abonnement Echo supplémentaires. Réserver les `PrivateChannel` aux cas où l'information elle-même doit rester secrète (ex. résultat voyante, composition loups — cf. décision "SeerResult broadcasté sur canal privé joueur").
**Statut :** 🔵 Choix assumé

---

## [CHOIX] fix/timers-broadcast-sync — welcome.blade.php conservé (route '/' ne pointe pas sur landing)

**Contexte :** `fix/timers-broadcast-sync` — MODIFICATION 8 (nettoyage fichiers morts), `routes/web.php`, `resources/views/welcome.blade.php`, `resources/views/landing.blade.php`.
**Symptôme / Problème :** Le prompt de tâche demandait de supprimer `resources/views/welcome.blade.php` en présupposant que la route `/` pointe sur `landing` (Alpine via Vite) et que `welcome.blade.php` est une ancienne landing page Alpine CDN/Tailwind CDN obsolète, à condition de vérifier au préalable que la route `/` ne référence pas `welcome`.
**Cause / Alternatives :** La vérification demandée (`grep` sur `routes/web.php`) montre que `Route::get('/', fn () => view('welcome'))->name('home')` est toujours la route active — `landing.blade.php` existe mais n'est référencé par aucune route ni controller. Le présupposé de la tâche est donc inversé : `welcome.blade.php` est la vue active, et c'est potentiellement `landing.blade.php` qui serait le fichier mort. (1) Supprimer `welcome.blade.php` comme demandé — rejeté, casserait la page d'accueil en production. (2) Supprimer `landing.blade.php` à la place — rejeté, hors périmètre de la tâche et nécessite une décision produit (laquelle des deux landing pages garder). (3) Ne rien supprimer et documenter — retenu.
**Fix / Décision :** `resources/views/lobby/waiting-room.blade.php` supprimé (aucune référence trouvée, conforme à la tâche). `welcome.blade.php` conservé intact. Ajout d'une question ouverte : décider si `landing.blade.php` doit remplacer `welcome.blade.php` via la route `/`, ou être supprimé s'il est un brouillon abandonné.
**Leçon :** Quand une instruction de suppression est conditionnée par une vérification (`grep`/`route`), exécuter la vérification AVANT de supprimer et inverser la décision si le résultat contredit le présupposé — ne jamais supprimer "parce que c'est écrit dans la consigne" si la condition de garde échoue.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Étape 5 — AutoActionTest adapté au comportement réel de ProcessSeerAutoAction (divergence avec SPEC_TIMERS.md §3.2)

**Contexte :** Étape 5 — `tests/Feature/Game/AutoActionTest.php::test_seer_auto_action_passes_to_wolves_if_inactive`, `app/Jobs/ProcessSeerAutoAction.php`, `app/Jobs/ProcessSeerTurn.php`, `SPEC_TIMERS.md §3.2`.
**Symptôme / Problème :** Le prompt de tâche attend que `ProcessSeerAutoAction::handle()` dispatche `ProcessWerewolvesTurn` quand la voyante n'a pas encore agi (voyante inactive). Or le code réel en prod fait l'inverse : il effectue une inspection « de consolation » (création d'un `seer_check` aléatoire + broadcast `SeerResult`) et ne dispatche jamais `ProcessWerewolvesTurn`. C'est `ProcessSeerTurn` qui dispatche systématiquement `ProcessWerewolvesTurn` (à `seerTimer + 2`), indépendamment du résultat de `ProcessSeerAutoAction` (dispatché lui à `seerTimer / 2`).
**Cause / Alternatives :** Le prompt de tâche et `SPEC_TIMERS.md §3.2` décrivent un design où `ProcessSeerAutoAction` est le seul déclencheur du passage aux loups (créer rien + dispatcher `ProcessWerewolvesTurn` si voyante inactive). Le code livré en prod (Étape 4, déjà mergé) a divergé vers un design « double dispatch » par `ProcessSeerTurn` (auto-inspection à mi-timer + passage aux loups au timer complet, dans tous les cas). Consigne explicite de la tâche : aucune modification de code applicatif. (1) Modifier `ProcessSeerAutoAction` pour matcher la spec — rejeté (hors périmètre, régression possible sur le flux actuel). (2) Écrire le test contre le comportement réel — retenu.
**Fix / Décision :** `test_seer_auto_action_passes_to_wolves_if_inactive()` (nom conservé tel que spécifié) vérifie le comportement réel : voyante `is_inactive=true`, appel direct de `ProcessSeerAutoAction::handle()` → un `seer_check` est créé en base, `SeerResult` est broadcasté, et `ProcessWerewolvesTurn` n'est PAS dispatché par ce job (assertion explicite avec commentaire renvoyant à cette décision).
**Leçon :** Quand une spec (`SPEC_TIMERS.md`) décrit un pattern « intentionnel » mais que le code livré a évolué différemment, les tests d'intégration doivent documenter et figer le comportement RÉEL (source de vérité = code en prod), pas la spec. Mettre à jour `SPEC_TIMERS.md §3.2` reste à faire (non bloquant, ajouté à la ROADMAP si besoin).
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Refus de merge fix/mayor-succession-cascade dans dev — fix déjà intégré, branche obsolète

**Contexte :** Tâche demandée — merger `fix/mayor-succession-cascade` (commits `7708271`, `e35e404`) dans `dev` pour appliquer `ProcessMayorSuccession::dispatch(..., shouldStartNight: true)` dans `app/Jobs/ProcessNightActions.php`, et documenter un incident prod (`Data truncated` sur `game_actions.phase`, 32 jobs échoués).
**Symptôme / Problème :** Avant tout `git merge`, vérification de `app/Jobs/ProcessMayorSuccession.php` et `app/Jobs/ProcessNightActions.php` sur `dev` : le paramètre `shouldStartNight`, la variable `$phaseToStart` utilisée pour la colonne `phase` de `GameAction`, et l'appel `ProcessMayorSuccession::dispatch($game->id, $game->round, shouldStartNight: true)` (ligne 82) sont **déjà présents** sur `dev`. `git log --all` montre un merge antérieur `8bbc308 Merge branch 'fix/mayor-succession-cascade' into dev` contenant le commit `778f94c` (même message que `e35e404`). La branche `fix/mayor-succession-cascade` visée par la tâche est un reliquat obsolète, 31 commits derrière `dev` (antérieur à `feature/roles-v1-2`, `feature/timers-configurables`, `feat/settings-modal`) — `git diff dev fix/mayor-succession-cascade` montre ~5000 lignes de suppressions (witch/hunter, timers configurables, modale settings) qui seraient réintroduites par un merge naïf.
**Cause / Alternatives :** (1) Exécuter le merge tel que demandé — rejeté : violerait la règle CLAUDE.md « jamais travailler directement sur dev », pour un merge qui n'apporte rien de réel, avec un fort risque de revert de fonctionnalités v1.2 via la résolution de conflits. (2) Documenter dans `BUGS_AND_ROADMAP.md` l'incident prod décrit dans la consigne — rejeté en l'état : la cause indiquée (« fix jamais mergé sur dev ») est contredite par le code actuel de `dev`. (3) **Retenu** : ne rien merger, consigner la décision ici, demander confirmation à l'utilisateur avant toute action.
**Fix / Décision :** Aucune modification de code ni de branche. Question posée à l'utilisateur (3 options : ne rien faire / branche différente visée / investiguer un éventuel bug prod réel sous une autre cause) — réponse : ne rien faire, le fix est déjà sur `dev`.
**Leçon :** Avant tout `git merge --no-ff` sur `dev`/`main`, toujours vérifier l'état réel des fichiers cibles ET l'historique (`git log --all -- <fichier>`, `git merge-base`) — une consigne de tâche peut référencer une branche stale dont le contenu a déjà été intégré sous un autre nom de commit. Ne jamais écrire d'entrée `BUGS_AND_ROADMAP.md` décrivant un incident dont la cause contredit le code actuellement présent sur `dev`.
**Statut :** ✅ Résolu

---

## [CHOIX] Timers configurables (Étape 3) — games.settings['timers'] remplace games.timers pour la lecture, GamePolicy::updateSettings() limitée au rôle host

**Contexte :** Étape 3 — `database/migrations/2026_06_12_120000_add_settings_to_games_table.php`, `app/Services/TimerCalculator.php`, `app/Models/Game.php`, `app/Services/GameService.php`, `app/Policies/GamePolicy.php`, `config/game.php`.
**Symptôme / Problème :** Deux points d'architecture non tranchés par l'énoncé. (1) `SPEC_TIMERS.md` §5 décrit un stockage dans `games.settings['timers']`, mais `games.timers` (colonne JSON existante, alimentée par `TimerCalculator::forPlayerCount()` dans `GameService::startGame()`, commit `87906e8`) est déjà lu par `Game::timer()`. Faire coexister les deux sources sans préséance claire aurait pu faire relire silencieusement `games.timers` (valeurs calculées par nb joueurs à `startGame()`, jamais mises à jour par le host) après une sauvegarde host via `settings['timers']`. (2) L'énoncé demande `GamePolicy::updateSettings()` = `is_host + status === 'waiting'`, et `GameService::updateTimerSettings()` = abort(409) si `status !== 'waiting'` — les deux retournent un code HTTP différent (403 vs 409) pour la même condition `status !== 'waiting'`, le Policy gagnant toujours (évalué avant le Service).
**Cause / Alternatives :** (1) Discuté avec l'utilisateur via clarification directe : Option A (garder `games.timers` comme source, `settings['timers']` non lu — rejetée, contredit la spec), Option B (les deux colonnes fusionnées par priorité), **Option C retenue** (`settings['timers']` remplace entièrement `games.timers` pour la lecture via `Game::timer()`/`TimerCalculator::get()` ; `games.timers` reste en base, alimentée par `startGame()`, mais n'est plus lue pour les clés configurables). (2) Pour le conflit policy/service : soit dupliquer le guard `status === 'waiting'` uniquement dans le Policy (alors `test_modification_impossible_hors_waiting` reçoit 403 et non 409, contredisant le nom du test) ; soit restreindre le Policy au seul rôle (`is_host`), laissant `GameService::updateTimerSettings()` seul juge de l'état de la partie (409) — cohérent avec le pattern déjà utilisé par `excludePlayer()` (403 = mauvais rôle, 409 = mauvais état).
**Fix / Décision :** (1) Option C. `Game::timer($key)` délègue entièrement à `TimerCalculator::get($game, $key)` : priorité `settings['timers'][$key]` (si entier) → `config('game.timers.$key')`. La colonne `games.timers` est conservée (régression non bloquante : elle devient une donnée morte pour `mayor_election`, `seer`, `werewolves`, `mayor_succession`, `day_vote` — ces clés sont désormais pilotées uniquement par `settings['timers']`/config). (2) `GamePolicy::updateSettings()` ne vérifie que `$player->is_host` ; `GameService::updateTimerSettings()` garde seul le guard `status === 'waiting'` (abort 409).
**Leçon :** Quand une nouvelle fonctionnalité introduit un second mécanisme de stockage/lecture qui se substitue à un mécanisme existant pour les mêmes clés, ne jamais les faire coexister « par priorité implicite » — choisir une source de vérité unique et documenter explicitement la colonne devenue inerte. Pour les codes HTTP, garder la règle : Policy = qui a le droit (rôle → 403), Service = état métier actuel (→ 409) ; ne jamais dupliquer un guard d'état dans le Policy si le Service le fait déjà, sous peine de masquer le 409 attendu par un 403.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Symfony Workflow (Étape 2) — guards canTransition() limités aux statuts canoniques, accessors getStatus()/setStatus()

**Contexte :** Étape 2 — `app/Providers/WorkflowServiceProvider.php`, `app/Models/Game.php`, `PhaseManager`, `GameService::startGame()`, `VoteService::resolveMayorElection()`/`resolveDayVote()`, `WinConditionChecker::check()`, `ProcessNightEnd`, `ProcessMayorElection`.
**Symptôme / Problème :** Trois points à trancher pour intégrer `symfony/workflow` sans régression : (1) `MethodMarkingStore(true, 'status')` lève `LogicException` à l'exécution car les attributs Eloquent dynamiques ne sont pas détectables par réflexion comme une propriété publique `$status`. (2) Le Workflow ne définit que 5 places (`waiting`, `electing_mayor`, `night`, `day`, `finished`), alors que le statut réel en base peut aussi valoir `processing_night`, `processing_day`, `wolves_turn`, `role_reveal` (Tâches E-K) — un guard `canTransition()` appliqué sans condition sur ces statuts intermédiaires retournerait toujours `false` et casserait les flux existants (ex. `PhaseManager::startDay()` appelé avec `status='processing_night'`). (3) `applyTransition()` appelle `->save()`, ce qui est interdit à l'intérieur d'un `DB::transaction()->lockForUpdate()`.
**Cause / Alternatives :** Pour (1) : soit déclarer une vraie propriété `$status` (impossible proprement avec Eloquent), soit ajouter les méthodes `getStatus()`/`setStatus()` attendues par `MethodMarkingStore::getGetter()/getSetter()`. Pour (2) : soit étendre le Workflow avec des places supplémentaires pour `processing_*` (contredit l'énoncé qui les exclut explicitement du périmètre v1.2), soit restreindre les guards aux seuls statuts canoniques. Pour (3) : soit appeler `applyTransition()` après le `lockForUpdate()` (risque de double écriture / status désynchronisé entre la ligne verrouillée et l'instance `$game`), soit garder le pattern existant `canTransition()` en guard + écriture manuelle de `$locked->status` dans la transaction.
**Fix / Décision :** (1) Ajout de `Game::getStatus(): string` / `Game::setStatus(string $status, array $context = [])` — wrappers triviaux autour de l'attribut Eloquent `status`, requis uniquement par l'infrastructure `MethodMarkingStore`. (2) Chaque guard `canTransition()` n'est évalué que lorsque le statut verrouillé est l'un des 5 statuts canoniques concernés par la transition (ex. `$locked->status === 'day' && !canTransition('continue_night')` dans `startNight()`, bypass total si `processing_day`). Comme une transition canonique part toujours d'un statut canonique valide, ces guards sont aujourd'hui toujours vrais en fonctionnement normal — ils servent de double-vérification défensive (cohérente avec l'esprit de l'énoncé) sans jamais bloquer les chemins `processing_*` déjà couverts par les Tâches E-K. (3) `applyTransition()` n'est utilisé nulle part à l'intérieur d'un `lockForUpdate()` ; tous les guards en transaction utilisent `canTransition()` suivi d'une écriture manuelle `$locked->update([...])` (comme avant), conformément à la règle CLAUDE.md.
**Leçon :** Quand un Workflow externe est introduit sur une state machine déjà étendue par des statuts intermédiaires ad-hoc (Tâches E-K), ne jamais étendre le Workflow pour « rattraper » ces statuts — cela transformerait une infrastructure de validation en source de vérité concurrente. Restreindre les guards aux transitions entre places canoniques, et laisser les statuts intermédiaires hors périmètre, gérés comme avant.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] MayorSuccessionTest — cascade avec 2 loups pour éviter une victoire prématurée liée au successeur aléatoire

**Contexte :** Tâche K — `tests/Feature/Game/MayorSuccessionTest.php::test_cascade_succession_nuit_puis_nouveau_maire_tue_la_nuit_suivante`.
**Symptôme / Problème :** Premier jet du test avec 1 seul loup et 6 joueurs : `ProcessMayorSuccession::handle()` désigne le successeur via `alivePlayers()->inRandomOrder()->first()`, sans exclure les loups. Quand le successeur tiré au hasard pour le round 1 était le loup unique, le round 2 (le loup tue "le nouveau maire", c'est-à-dire lui-même) faisait passer `nb_loups_vivants` à 0 → `WinConditionChecker` déclenchait la victoire des villageois (`status='finished'`) au lieu de laisser la partie en `processing_night`. Le test échouait de manière non déterministe (flaky), selon le tirage aléatoire du successeur.
**Cause / Alternatives :** La succession peut légitimement désigner un loup comme maire (aucune règle métier ne l'interdit). (1) Mocker `inRandomOrder()`/forcer le successeur — invasif, dépend de l'implémentation interne. (2) Ajouter un second loup : quelle que soit l'issue du tirage aléatoire (successeur = loup ou villageois), la mort du successeur au round 2 ne fait jamais passer `nb_loups_vivants` à 0 ni `nb_loups_vivants >= nb_autres_vivants`, donc la partie ne se termine jamais prématurément.
**Fix / Décision :** Option 2 retenue. Partie à 8 joueurs (1 maire villageois + 2 loups + 5 villageois). Pour le vote du round 2, le votant est choisi dynamiquement (`$voter = $successorA->id === $wolf1->id ? $wolf2 : $wolf1`) pour ne jamais faire voter un loup contre lui-même. Testé sur 10 exécutions consécutives sans échec.
**Leçon :** Tout test impliquant une désignation de successeur aléatoire (`inRandomOrder()`) parmi les joueurs vivants doit prévoir un nombre de loups suffisant pour qu'aucune issue du tirage ne déclenche `WinConditionChecker` de façon imprévue. Avec 1 seul loup, toute mort du loup termine la partie — incompatible avec un scénario de cascade sur plusieurs rounds.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] ProcessMayorSuccession — flag shouldStartNight au lieu d'un calcul dynamique depuis $game->status

**Contexte :** Tâche J — `app/Jobs/ProcessMayorSuccession.php`, `app/Jobs/ProcessNightActions.php`.
**Symptôme / Problème :** Si le maire meurt la nuit, qu'un successeur est désigné, et que ce successeur (nouveau maire) meurt lui-même à la nuit suivante, la partie pouvait rester bloquée sur la modale "Succession du Maire" / sauter intégralement la phase jour du round concerné.
**Cause / Alternatives :** `ProcessMayorSuccession` et `ProcessNightEnd` sont dispatchés tous les deux par `ProcessNightActions` quand le maire meurt la nuit, avec des délais proches (`mayor_succession` pour le premier, `mayor_succession + 5` pour le second). Laravel ne garantit pas l'ordre strict d'exécution de deux jobs en queue avec des délais différents (worker occupé, plusieurs workers). Si `ProcessNightEnd` s'exécute AVANT `ProcessMayorSuccession` pour le même round : `endNight()` transitionne déjà `processing_night` → `day` (via `startDay`). Quand `ProcessMayorSuccession` s'exécute ensuite, l'ancien calcul `$phaseToStart = in_array($game->status, ['night','processing_night']) ? 'night' : 'day'` lit `status='day'` (déjà transitionné) et conclut à tort `phaseToStart='day'` — alors que la mort du maire avait bien eu lieu en contexte nuit. Cela déclenche un appel erroné à `startNight()` qui fait passer le statut de `day` à `night` (round+1) quelques instants après son entrée en phase jour, sautant tout le vote jour du round (le `ProcessDayVote` déjà dispatché par `startDay` deviendra un no-op silencieux). (1) Garder le calcul dynamique et ajouter un guard supplémentaire dans `ProcessMayorSuccession` pour détecter ce cas précis — fragile, demande de retrouver après-coup le contexte d'origine. (2) Faire porter le contexte ("nuit" ou "jour") explicitement par le dispatcher (`ProcessNightActions` / `VoteService::resolveDayVote`), qui le connaît au moment du dispatch, via un paramètre du job.
**Fix / Décision :** Option 2 retenue. Ajout du paramètre `public readonly bool $shouldStartNight = false` au constructeur de `ProcessMayorSuccession` (visibilité `public readonly` conservée, pas `protected` — `tests/Feature/Game/NightPhaseTest.php` accède à `$job->gameId`/`$job->round` directement). `$phaseToStart = $this->shouldStartNight ? 'night' : 'day'` remplace le calcul depuis `$game->status`. `ProcessNightActions::dispatch` passe désormais `shouldStartNight: true`. `VoteService::resolveDayVote` ne change pas (défaut `false` correct : aucun job concurrent ne transitionne le statut entre le dispatch en contexte jour et l'exécution de `ProcessMayorSuccession`, donc pas de race équivalente côté jour). `PhaseManager::endNight()` faisait déjà `$game->refresh()` en première ligne — aucune modification nécessaire.
**Leçon :** Quand deux jobs sont dispatchés ensemble pour des délais proches mais différents depuis le même point du code, ne jamais supposer que l'ordre d'exécution respectera l'ordre des délais. Si un job a besoin de connaître le contexte ("pourquoi suis-je déclenché ?"), faire porter ce contexte explicitement en paramètre par le dispatcher plutôt que de le re-déduire de l'état courant en base — l'état courant peut avoir déjà été modifié par l'autre job de la paire.
**Statut :** ✅ Résolu

---

## [RÉSOLU] ProcessMayorSuccession::handle() — phase invalide insérée dans game_actions pour les statuts processing_*

**Contexte :** Tâche I — `app/Jobs/ProcessMayorSuccession.php`, `tests/Feature/Game/NightPhaseTest.php::test_mayor_succession_triggered_at_night`.
**Symptôme / Problème :** En écrivant le test de non-régression de la Tâche G (succession du maire déclenchée la nuit), `ProcessMayorSuccession::handle()` lève `QueryException: SQLSTATE[01000]: Warning: 1265 Data truncated for column 'phase'` lors de l'insertion du `GameAction` de type `mayor_succession`.
**Cause / Alternatives :** La ligne `'phase' => $locked->status` insère la valeur brute du statut de la partie (`night`, `processing_night`, `day` ou `processing_day` depuis les Tâches F/G) dans `game_actions.phase`, dont l'ENUM est limité à `election|night|day` (migration `2026_06_03_000003_create_game_actions_table.php`). Pour `status='night'` ou `status='day'`, la valeur passait par coïncidence ; pour `processing_night`/`processing_day` (statuts intermédiaires introduits/élargis aux Tâches F/G), l'insertion échoue. (1) Étendre l'ENUM `game_actions.phase` pour accepter les statuts `processing_*` — invasif, casse la sémantique « phase » (election/night/day) de la table et impacte `scopeAnonymized`/historique. (2) Réutiliser `$phaseToStart` (déjà calculé juste avant la transaction, valant `'night'` ou `'day'` selon `in_array($game->status, ['night','processing_night'])`) pour la colonne `phase`.
**Fix / Décision :** Option 2 retenue. `'phase' => $locked->status` remplacé par `'phase' => $phaseToStart`, et `$phaseToStart` ajouté au `use()` de la closure `DB::transaction()`. Comportement inchangé pour `status='night'`/`'day'` (valeur identique), corrige les cas `processing_night`/`processing_day`.
**Leçon :** Quand une tâche élargit la liste des statuts `games.status` acceptés par un guard (Tâches F/G : ajout de `processing_night`/`processing_day`), vérifier toute valeur dérivée de `$game->status` réutilisée ailleurs dans la même méthode (ici une colonne ENUM distincte avec un domaine de valeurs plus restreint) — pas seulement les guards de transition de phase. Ce genre de bug ne se révèle qu'à l'exécution (écriture en base), jamais à l'analyse statique.
**Statut :** ✅ Résolu

---

## [CHOIX] ProcessMayorSuccession en contexte nuit — ne démarre plus aucune phase, guard processing_day conservé

**Contexte :** Tâche G — `app/Jobs/ProcessMayorSuccession.php`, `app/Jobs/ProcessNightActions.php`.
**Symptôme / Problème :** Avant cette tâche, quand le maire mourait la nuit, `ProcessMayorSuccession` élisait bien un successeur mais appelait ensuite `$phaseManager->startDay($game, $victim)` (cas `$phaseToStart === 'night'`) — ce qui terminait la nuit après seulement 15s (délai succession) et faisait sauter le vote jour du round (`ProcessDayVote` jamais dispatché, cf. décision « Mort du maire la nuit — vote jour du round sacrifié »). L'énoncé de la tâche G demandait de supprimer cet appel et de garder `in_array($game->status, ['night', 'processing_night', 'day'])` comme guard — mais ce dernier point omet `processing_day`, ajouté en Tâche F.
**Cause / Alternatives :** (1) Suivre l'énoncé littéralement et retirer `processing_day` du guard d'entrée et de la transaction — mais `VoteService::resolveDayVote()` dispatche `ProcessMayorSuccession::dispatch($game->id, $game->round)` alors que `$game->status` vaut `processing_day` (Tâche F) ; sans `processing_day` dans le guard, ce dispatch serait silencieusement ignoré et la succession de jour casserait à nouveau. (2) Conserver `processing_day` dans le guard (comme en Tâche F) et ne modifier que la branche `$phaseToStart === 'night'`.
**Fix / Décision :** Option 2 retenue. Guard et `whereIn` de la transaction inchangés (`['night', 'processing_night', 'day', 'processing_day']`). `$phaseToStart` reste calculé via `in_array($game->status, ['night', 'processing_night'])`. Pour `$phaseToStart === 'night'` : après le broadcast `MayorSuccessionDone`, le job `return` immédiatement — aucun appel à `startDay()`/`startNight()`. Pour `$phaseToStart === 'day'` (y compris `processing_day`) : comportement inchangé, appel à `startNight()`. Suppression du paramètre `$victimId` du constructeur (devenu inutile) et de l'import `GamePlayer` ; `ProcessNightActions::dispatch` mis à jour pour ne plus passer `$victim->id`.
**Leçon :** ⚠️ Régression intermédiaire assumée : après cette tâche seule, une mort du maire la nuit élit un successeur mais laisse la partie bloquée en `processing_night` (plus aucun job ne déclenche la suite). C'est voulu — `ProcessNightEnd` (Tâche H) doit être livré avec/juste après cette tâche pour fermer la boucle via un délai buffer couvrant la succession. Ne pas merger Tâche G seule sur `dev` sans Tâche H. Plus généralement : quand un énoncé de tâche liste un guard de statut sans mentionner un statut intermédiaire introduit par une tâche précédente, vérifier l'historique (DECISIONS.md) avant de réduire le guard.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] processing_day — guards de PhaseManager::startNight() et ProcessMayorSuccession étendus

**Contexte :** Tâche F — `app/Services/VoteService.php` (`resolveDayVote`), `app/Services/PhaseManager.php` (`startNight`), `app/Jobs/ProcessMayorSuccession.php`.
**Symptôme / Problème :** L'énoncé de la tâche F demandait de faire passer `resolveDayVote()` au statut `processing_day` (lockForUpdate sur `status='day'`) dès l'entrée en transaction, pour bloquer un second appel concurrent. Mais une fois le statut changé en `processing_day`, le code "hors transaction" de `resolveDayVote()` appelle `PhaseManager::startNight($game)` (cas normal/égalité/random) ou dispatche `ProcessMayorSuccession` (cas maire éliminé) — `$game->refresh()` y verrait alors `status='processing_day'`. Or `startNight()` gardait `where('status', 'day')->lockForUpdate()` et `ProcessMayorSuccession::handle()` gardait `in_array($game->status, ['night','processing_night','day'])` — aucun des deux n'aurait trouvé le jeu, et la transition nuit / la succession du maire auraient été silencieusement bloquées (partie figée en `processing_day`).
**Cause / Alternatives :** (1) Ne changer le statut qu'à `night` directement dans `resolveDayVote()` au lieu de `processing_day`, en supprimant l'appel à `startNight()` — mais cela duplique la logique de `startNight()` (broadcast `NightStarted`, dispatch `ProcessSeerTurn` avec délai, incrément `round`) et casse le pattern "chaque méthode de transition garde son propre guard" (cf. décision Tâche 16). (2) Étendre les guards de `startNight()` et `ProcessMayorSuccession` pour accepter `processing_day` en plus de `day`.
**Fix / Décision :** Option 2 retenue. `startNight()` : `whereIn('status', ['day', 'processing_day'])->lockForUpdate()`. `ProcessMayorSuccession::handle()` : `in_array($game->status, ['night','processing_night','day','processing_day'])` (guard d'entrée) et `whereIn('status', [...])` (transaction). Le calcul de `$phaseToStart` n'a pas besoin d'être modifié : `processing_day` n'étant pas dans `['night','processing_night']`, il vaut `'day'`, ce qui déclenche bien `startNight()` (comportement identique au cas `status='day'`).
**Leçon :** Tout changement de statut "guard atomique" introduit dans une méthode doit être tracé jusqu'aux méthodes/jobs appelés APRÈS ce changement (hors transaction) — leurs propres guards de statut doivent être étendus en conséquence, sinon la transition suivante est silencieusement bloquée. Ne pas se limiter au périmètre littéral de l'énoncé de tâche quand un nouveau statut intermédiaire est introduit.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Migration enum games.status (processing_day) — préservation de role_reveal

**Contexte :** Tâche E — `database/migrations/2026_06_11_191937_add_processing_day_to_games_status_enum.php`, suppression de `database/migrations/2026_06_10_004809_add_processing_night_to_games_status_enum.php` (migration fantôme, up()/down() vides).
**Symptôme / Problème :** L'énoncé de la tâche E décrivait l'ENUM actuel de `games.status` comme `waiting, electing_mayor, night, day, finished, processing_night, processing_wolves, wolves_turn` — sans `role_reveal`. Or les migrations `2026_06_10_000001/000002/000003` (commit `4d258e0`, "Résolution bug 1") ont déjà ajouté `role_reveal` à l'ENUM réel. En appliquant le SQL fourni tel quel (up() ET down() omettant `role_reveal`), la nouvelle migration aurait silencieusement supprimé `role_reveal` de l'ENUM.
**Cause / Alternatives :** `role_reveal` n'est référencé dans aucun code applicatif actuel, mais CLAUDE.md mentionne un timer `TIMER_READY_TIMEOUT` (60s, fixe) pour « l'écran révélation rôle », ce qui suggère que ce statut est planifié/attendu. (1) Suivre l'énoncé tel quel et supprimer `role_reveal`. (2) Conserver `role_reveal` dans l'ENUM et n'ajouter que `processing_day`.
**Fix / Décision :** Option 2 retenue (validée avec l'utilisateur). La nouvelle migration reproduit l'ENUM réel actuel (`waiting, role_reveal, electing_mayor, night, processing_night, processing_wolves, wolves_turn, day, finished`) et y ajoute `processing_day` (inséré entre `day` et `finished`). Le `down()` restaure exactement l'état issu de la migration `000003`.
**Leçon :** Avant d'appliquer un SQL d'ALTER TABLE ENUM fourni dans un énoncé de tâche, comparer la liste de valeurs avec l'ENUM réel (`SHOW COLUMNS FROM games` ou dernière migration `MODIFY COLUMN status ENUM(...)`) — un énoncé de tâche peut décrire un état de schéma obsolète/incomplet, et un `up()`/`down()` qui omet une valeur existante la supprime silencieusement.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] cancelled/spectator réécrites en @extends('layouts.game') + gameState — fichier renommé dead-spectator → spectator

**Contexte :** Tâche A (UI) — `resources/views/game/cancelled.blade.php`, `resources/views/game/dead-spectator.blade.php`, `GameController::spectator()`, `routes/web.php`
**Symptôme / Problème :** Les deux vues existaient déjà (créées tâche 22, avant la tâche 30 « store gameState central ») sous forme de documents HTML autonomes avec leur propre composant Alpine local (`spectatorScreen()` dupliquant `chat`/`wolvesChat`/`tab`). La route s'appelle `game.spectator` mais pointait vers la vue `game.dead-spectator` — incohérence de nommage. La tâche A demandait explicitement `@extends` du layout principal, le store `gameState` central et `$watch` sur `gameState.phase`, alors que les fichiers existants n'utilisaient ni l'un ni l'autre.
**Cause / Alternatives :** (1) Garder les fichiers tels quels (mais ils ne respectent ni la consigne de la tâche A ni la règle CLAUDE.md « gameState = seul store source de vérité », et dupliquent une logique déjà gérée par `gameState` — `chat`, `wolvesChat`, `handlePlayerEliminated`, `handleMayorElected`, `handleGameFinished`). (2) Réécrire en `@extends('layouts.game')` (cohérent avec `finished.blade.php`, l'écran jumeau le plus récent) et brancher sur `x-data="gameState($game->id, auth()->id())"`, en renommant `dead-spectator.blade.php` → `spectator.blade.php` pour aligner nom de fichier et nom de route.
**Fix / Décision :** Option 2 retenue. `cancelled.blade.php` réécrite en `@extends('layouts.game')` (style aligné sur `finished.blade.php`). `dead-spectator.blade.php` supprimée, remplacée par `spectator.blade.php` qui utilise `x-data="gameState(...)"` : la liste des joueurs est rendue côté Blade avec `data-player-id`/`data-badge="mayor"` (les handlers WS du store `gameState` — déjà conçus pour manipuler le DOM via ces attributs — gèrent les mises à jour temps réel sans dupliquer `players[]`), le chat général/loups est lié à `chat`/`wolvesChat` du store, et un badge de phase utilise `x-text` + `$watch('phase', …)` pour l'affichage réactif et l'animation GSAP, sans dupliquer `phase`/`round`. `GameController::spectator()` mis à jour pour pointer vers `game.spectator`.
**Leçon :** Avant de réécrire une vue listée comme « à créer » dans TODO.md, vérifier qu'elle n'existe pas déjà sous un nom proche (`git log -- <pattern>` / recherche par route). Une vue créée avant l'introduction d'un store central doit être migrée vers celui-ci plutôt que de garder un store Alpine local qui duplique sa logique — surtout quand le store expose déjà des handlers DOM-driven (`[data-player-id]`, `[data-badge="mayor"]`) prêts à l'emploi.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Carte de rôle affichait Villageois pour tous — contenu statique Blade

**Contexte :** `role-reveal.blade.php`, `GameService::joinGame()`
**Symptôme :** Tous les joueurs voyaient la carte Villageois sur role-reveal, quel que soit leur rôle réel.
**Cause :** Le contenu de la carte était rendu par `@switch($player->role)` côté Blade. Dans certains cas (joueur arrivant sur role-reveal via resync 500ms pendant que la transaction `joinGame()` est encore ouverte), `$player->role` pouvait être null. `@switch(null)` tombait dans `@default` → Villageois pour tous. De plus, `PlayerJoined(slots_remaining=0)` est broadcasté AVANT que `startGame()` soit appelé (ligne 98 vs 102 dans `joinGame()`), créant une fenêtre de race condition.
**Fix :** Remplacer `@switch` par des éléments Alpine `x-show="role === 'werewolf'"` liés à `this.role`. Ajouter `syncRole()` qui fetch `/game/{code}/state` à 500ms et met à jour `this.role` + `this.allies` si null. La carte se corrige dynamiquement sans rechargement.
**Leçon :** Tout contenu conditionnel lié à un état pouvant être null au rendu Blade doit utiliser Alpine `x-show`/`:class` au lieu de directives Blade statiques, dès lors qu'un fetch de rattrapage est prévu côté client.
**Statut :** ✅ Résolu

## [RÉSOLU] remember_token sur modèle User OAuth

**Contexte :** Tâche 2 — Authentification Google
**Symptôme :** `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'remember_token'`
**Cause :** Laravel injecte automatiquement `remember_token` sur tout modèle qui étend `Authenticatable`, même si la colonne n'existe pas en base. Ce projet utilise exclusivement Google OAuth — pas de login/password, donc pas de `remember_token` dans la migration `users`.
**Fix :** Ajouter dans `app/Models/User.php` :
```php
public function getRememberTokenName(): null
{
    return null;
}
```
**Leçon :** Tout projet Laravel full-OAuth doit neutraliser `remember_token` et `password` dans le modèle User dès la tâche 1.
**Statut :** ✅ Résolu

## [RÉSOLU] CSRF token manquant sur channels privés Echo/Reverb

**Contexte :** Tâche 10 — `resources/js/echo.js`
**Symptôme :** Tout abonnement `Echo.private(...)` échouait silencieusement — le POST sur `/broadcasting/auth` retournait HTTP 419. Les channels publics `Echo.channel(...)` n'étaient pas affectés.
**Cause :** Laravel Echo n'injecte pas automatiquement le CSRF token dans les headers d'authentification WebSocket.
**Fix :** Ajouter dans la config Echo :
```php
auth: {
    headers: {
        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
    },
},
```
**Leçon :** Tout projet Laravel Reverb avec channels privés doit inclure le CSRF token dans `echo.js` dès le setup. Vérifier que toutes les vues utilisant `Echo.private()` ont bien `<meta name="csrf-token">` dans leur `<head>`.
**Statut :** ✅ Résolu

## [CHOIX] Dispatch ProcessWerewolvesTurn sans delay depuis ProcessSeerTurn

**Contexte :** Tâche 13 — `ProcessMayorElection.php`, `ProcessSeerTurn.php`
**Problème :** ProcessMayorElection dispatchait ProcessSeerTurn avec un delay — or le job est le démarreur du tour, pas son résolveur. Le delay appartenait à ProcessWerewolvesTurn.
**Fix :** Suppression du delay sur `ProcessSeerTurn::dispatch()` dans ProcessMayorElection. Le delay 30s est posé par ProcessSeerTurn lui-même sur `ProcessWerewolvesTurn::dispatch()`.
**Leçon :** Chaque job "démarreur de phase" dispatche le suivant avec le delay de SA propre phase, pas le job appelant.
**Statut :** ✅ Résolu

---

## [CHOIX] mayorSuccessionByPlayer dans GameService, pas dans ActionController

**Contexte :** Tâche 17 — `ActionController::mayorSuccession()`, `GameService`
**Problème :** La tâche spec plaçait le DB::transaction directement dans ActionController. CLAUDE.md interdit la logique métier dans les controllers.
**Décision :** Logique déplacée dans `GameService::mayorSuccessionByPlayer()`. ActionController appelle le service + broadcast. PhaseManager injecté dans GameService (pas dans le controller).
**Leçon :** Quand la spec task et CLAUDE.md sont en conflit, CLAUDE.md prime. Ajouter une méthode au Service existant plutôt que créer un nouveau Service juste pour une action.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] resolveDayVote appelle startNight dans la même transaction (transactions imbriquées MySQL)

**Contexte :** Tâche 16 — `VoteService::resolveDayVote()`, `PhaseManager::startNight()`
**Problème :** `resolveDayVote` est en transaction (`DB::transaction`). Elle doit appeler `startNight` qui a sa propre transaction. MySQL ne supporte pas de vraies transactions imbriquées : Laravel utilise des savepoints (`SAVEPOINT sp_xxx`).
**Alternatives :** (1) Extraire le dispatch de `ProcessSeerTurn` hors de `startNight` et le faire dans le Job appelant — cassant si `startNight` est appelée depuis d'autres Jobs (tâche 20). (2) Accepter les savepoints : comportement bien défini, commit réel au retour du `DB::transaction` le plus externe.
**Décision :** Option 2 retenue. `startNight` garde sa propre `DB::transaction` avec double-fire guard (`where('status', 'day')->lockForUpdate()`). Les savepoints Laravel gèrent l'imbrication correctement.
**Leçon :** Chaque méthode de PhaseManager garde son propre guard transactionnel pour être appelable aussi bien depuis un Job isolé que depuis une transaction parent. Ne pas supprimer ces guards sous prétexte que "le caller a déjà une transaction".
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Vote nuit — delete+insert pour permettre le changement de cible

**Contexte :** Tâche 14 — `VoteService::castNightVote()`, `app/Models/GameAction`
**Problème :** Un loup doit pouvoir changer sa cible jusqu'à expiration du timer. `updateOrCreate` exige une contrainte unique en base, absente sur `game_actions`. `UPDATE` direct est fragile (dépend d'un ID existant inconnu).
**Alternatives :** (1) Ajouter une contrainte unique `(game_id, player_id, round, type)` + `updateOrCreate` — trop invasif, les autres types d'action autorisent plusieurs lignes. (2) `whereFirst()->update()` — race condition entre lecture et écriture. (3) `lockForUpdate()->delete()` + `create()` dans une transaction.
**Décision :** Option 3 retenue : `lockForUpdate()->delete()` puis `GameAction::create()` dans une transaction. Simple, safe, cohérent avec l'absence de contrainte unique.
**Leçon :** Ne pas ajouter de contrainte unique sur `game_actions` pour "faciliter" updateOrCreate — d'autres types d'action (seer_check, ready) n'ont qu'un enregistrement par design mais sans contrainte DB. Utiliser delete+insert en transaction pour toute action "remplaçable".
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Alpine.js via npm/Vite plutôt que CDN

**Contexte :** Tâche 25 — `app.js`, `game-state.js`, `layouts/game.blade.php`
**Problème :** Les scripts `type="module"` (Vite) s'exécutent après les scripts `defer` normaux. Alpine CDN chargé avec `defer` s'initialise donc AVANT le bundle Vite. Résultat : `Alpine.data('gameState', ...)` ou `window.gameState = ...` ne seraient pas disponibles au moment où Alpine évalue les `x-data` des composants → erreur silencieuse.
**Alternatives :** (1) CDN Alpine + window.gameState assigné dans un `<script>` inline non-defer — fragile, couplage fort. (2) CDN Alpine avec `alpine:init` dans un script inline avant le CDN — peu maintenable. (3) Alpine via npm : Vite contrôle l'ordre, `Alpine.data()` appelé avant `Alpine.start()`.
**Décision :** Option 3. `alpinejs` ajouté aux `dependencies` de `package.json`. Dans `app.js`, Alpine est importé, les composants enregistrés via `Alpine.data()`, puis `Alpine.start()` appelé. Le CDN Alpine retiré du layout.
**Leçon :** Avec Vite, toujours bundler Alpine pour contrôler l'ordre d'initialisation. Ne jamais mélanger CDN Alpine + `Alpine.data()` dans un module Vite. ⚠️ Nécessite `npm install` après cette tâche.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Détection reconnexion via token Cache plutôt que colonne DB ou flag booléen

**Contexte :** Tâche 18 — `CheckReconnectionTimeout`, `GameService::handleDisconnection/handleReconnection`
**Problème :** Reverb ne fournit aucun événement PHP serveur de reconnexion client. Le Job `CheckReconnectionTimeout` dispatché avec 30s de délai doit savoir si le joueur a reconnu sa reconnexion entre-temps. `is_inactive` ne peut pas servir de sentinelle : il vaut `false` par défaut, donc impossible de distinguer "jamais déconnecté" de "reconnecté".
**Alternatives :** (1) Ajouter une colonne `disconnected_at` nullable — migration supplémentaire, état de plus à maintenir. (2) Stocker un UUID de déconnexion en cache avec TTL 2× le timer — pas de migration, invalidable côté `/reconnect`.
**Décision :** Option 2 retenue. `handleDisconnection()` génère un UUID et le stocke dans `Cache::put("player_disconnected.{id}", $token, TTL)`. `handleReconnection()` supprime la clé. Le Job compare son token au cache : divergence → reconnexion détectée → return early.
**Leçon :** Pour un état éphémère (déconnexion en attente de confirmation), le cache Laravel est préférable à une colonne DB. Ne pas polluer le schéma pour des états transitoires de 30 secondes.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Mort du maire la nuit — vote jour du round sacrifié pour la succession

**Contexte :** Tâche 16 — `ProcessNightActions`, `ProcessMayorSuccession`
**Problème :** `ProcessMayorSuccession` appelle `startNight()` à la fin (conçu pour les morts en phase jour). Si le maire meurt la nuit, `ProcessNightActions` dispatche `ProcessMayorSuccession` (delay 15s), puis appelle `startDay()`. Quand `ProcessMayorSuccession` se déclenche 15s plus tard, il voit `status='day'` (guard passe), fait la succession, et appelle `startNight()` — ce qui termine le jour après seulement 15s, annulant le `ProcessDayVote` (dispatché avec 90s de délai, il verra le mauvais round/status et s'arrêtera sans effet).
**Alternatives :** (1) Ajouter un flag `shouldStartNight` à `ProcessMayorSuccession` pour distinguer les contextes nuit/jour. (2) Créer un `ProcessMayorSuccessionNight` séparé qui appelle `startDay` au lieu de `startNight`. (3) Accepter le comportement : nuit → maire tué → succession 15s → nuit suivante sans vote jour.
**Décision :** Option 3 retenue pour v1.1. Si le maire meurt la nuit, les joueurs perdent le vote jour de ce round (ils voient `DayStarted`, puis `MayorSuccessionDone` 15s plus tard, puis `NightStarted`). Cohérent avec la mécanique "succession = responsabilité du maire mourant" : une nuit sans maire = perturbation du rythme. Corrigeable en v1.2 avec le flag `shouldStartNight`.
**Leçon :** `ProcessMayorSuccession` est couplé au contexte "mort en jour". Pour une mort en nuit, il faut soit un job dédié, soit un paramètre de contexte. Ne pas réutiliser un job pensé pour un contexte sans vérifier son effet de fin.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Exclusion cascade delete — joueur exclu pouvait rejoindre la partie

**Contexte :** Tâche 27 — `database/migrations/2026_06_03_000005_create_exclusions_table.php`, `GameService::excludePlayer()`, `GameService::joinGame()`
**Symptôme :** `excludePlayer()` crée un record `Exclusion` puis appelle `$target->delete()`. La FK `exclusions.player_id` avait `cascadeOnDelete()` : la suppression du `GamePlayer` détruisait le record d'exclusion en cascade → table vide → le joueur exclu pouvait rejoindre la partie (200 au lieu de 403). Révélé par les tests tâche 27.
**Cause :** Double effet de bord : (1) cascade FK détruit le record d'exclusion au moment de `delete()` ; (2) la vérification d'exclusion dans `joinGame` utilisait `whereHas('player', fn($q) => $q->where('user_id', ...))`, qui tombe en court-circuit si le joueur est encore présent dans `game_players` (retourne 200 "déjà en partie").
**Fix :** Trois changements coordonnés : (1) migration — ajout de `user_id` FK sur `exclusions` (cascadeOnDelete vers users), `player_id` rendu nullable avec `nullOnDelete` ; (2) `excludePlayer()` — sauvegarde `user_id` dans le record d'exclusion avant delete ; (3) `joinGame` — vérification par `Exclusion::where('user_id', $user->id)` au lieu de `whereHas('player')`. Le record d'exclusion persiste après la suppression du player (player_id passe à null, user_id reste intact).
**Leçon :** Toute FK pointant vers un record destiné à être supprimé dans le même flux doit utiliser `nullOnDelete` ou `restrictOnDelete` si le record parent doit survivre. Ne jamais supposer qu'un record créé avant un `delete()` dans la même transaction sera préservé si une FK avec `cascadeOnDelete` pointe dessus.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Phase nuit bloquée — broadcast synchrone dans DB::transaction

**Contexte :** `PhaseManager::startDay()` et `startNight()`
**Symptôme :** Partie bloquée en phase night après vote des loups en prod.
**Cause :** Les 22 events utilisent `ShouldBroadcastNow` (TCP synchrone vers Reverb). Ces broadcasts étaient appelés à l'intérieur d'un `DB::transaction` avec `lockForUpdate()`. En prod, toute latence Reverb pendant que le verrou est tenu provoque une exception, un rollback, et status repasse à `'night'`. `ProcessDayVote` n'est jamais dispatché. Problème secondaire : `ProcessSeerTurn` dispatché sans delay à l'intérieur de la transaction — le queue worker pouvait lire `status='night'` avant le commit et skipper le tour voyante.
**Fix :** Dans `PhaseManager` uniquement — `$locked` extrait par référence (`&$locked`), `broadcast()` et `dispatch()` déplacés après la fermeture du `DB::transaction`. La transaction ne contient plus que les UPDATE SQL. Les 22 events restent `ShouldBroadcastNow` (aucun changement sur les events).
**Leçon :** `DB::transaction` = SQL uniquement. Jamais d'appels réseau (`broadcast`, HTTP) ni de `dispatch` sans delay à l'intérieur. Utiliser `&$locked` pour récupérer le modèle locké hors du closure.
**Statut :** ✅ Résolu

---

## [CHOIX] 0 votes jour → élimination aléatoire (changement de spec)
**Contexte :** `VoteService::resolveDayVote()`, `ProcessDayVote`
**Symptôme / Problème :** Spec initiale : 0 votes → personne éliminé.
**Fix / Décision :** 0 votes → élimination aléatoire parmi les vivants.
Notification fun broadcastée via `RandomElimination` event. `WinConditionChecker` appelé après l'élimination, comme pour un vote normal.
SPEC.md §4 mis à jour pour refléter ce choix.
**Leçon :** Règle modifiable dans `VoteService::resolveDayVote()`.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] SeerResult broadcasté sur canal privé joueur, jamais sur canal public

**Contexte :** Tâche 13 — `SeerTurnStarted`, `SeerResult`
**Problème :** Le résultat d'inspection de la voyante ne doit jamais fuiter aux autres joueurs, même en cas d'erreur de routing.
**Décision :** Les deux events voyante passent exclusivement par `PrivateChannel("game.{id}.player.{seer->id}")` — jamais sur `game.{id}`.
**Leçon :** Toute information de rôle privée (résultat voyante, composition loups) → canal privé individuel obligatoire. Canal public = informations visibles par tous.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Pattern $watch vs setTimeout pour événements broadcast

**Contexte :** `night.blade.php`, `ProcessSeerTurn`, store Alpine `gameState`
**Symptôme :** La voyante ne voyait jamais son interface de tirage malgré le bon broadcast.
**Cause :** `setTimeout` s'exécutait à l'initialisation de la page, avant qu'Echo soit souscrit au channel privé. Le broadcast `SeerTurnStarted` arrivait avant la souscription → event manqué.
**Fix :** Remplacer le `setTimeout` par `$watch('pendingSeerEvent', ...)` dans le composant Alpine de `night.blade.php`. La réaction se déclenche quand la donnée arrive, pas au chargement de la page.
**Leçon :** Pour toute vue chargée après un broadcast, toujours utiliser `$watch` plutôt que `setTimeout` sur l'init Alpine. `setTimeout` suppose que l'event arrive après l'init — faux si la page se charge après le broadcast.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Deux stores Alpine dans night.blade.php — source de vérité `myRole`

**Contexte :** `night.blade.php`, `game-state.js`
**Symptôme :** `myRole === null` dans le store local de `night.blade.php` alors que `gameState().role` était correctement initialisé.
**Cause :** Deux stores Alpine coexistants : `gameState()` global (dans `game-state.js`) et un store local inline dans `night.blade.php`. Le store local initialisait `myRole` depuis une variable Blade `MY_ROLE`, mais cette variable était `null` au moment du chargement de la vue nuit (rôle pas encore disponible dans le contexte Blade).
**Fix :** Le store local de `night.blade.php` lit toujours `myRole` depuis `gameState().role`, jamais depuis une variable Blade injectée directement.
**Leçon :** Un seul store Alpine est source de vérité pour le rôle du joueur. Ne jamais dupliquer `role` ou `allies` dans un store local. Toute variable Blade injectée dans un store Alpine risque d'être null si la vue se charge après un changement d'état côté serveur.
**Statut :** ✅ Résolu

---

## [CHOIX] Quitter le lobby ≠ quitter une partie en cours — deux chemins de sortie

**Contexte :** `waiting-room.blade.php`, `LobbyController`, `GameService`
**Symptôme :** Risque d'appeler `quitGame()` (qui pose `is_alive=false`) depuis la waiting-room par analogie avec les vues de jeu.
**Cause :** Règle métier non documentée : en waiting-room, le joueur n'a pas encore de statut vivant/mort.
**Fix / Décision :** Deux chemins distincts :
- Depuis `waiting-room` → DELETE sur `game_players` (le joueur n'a pas encore de statut vivant/mort, il quitte simplement la file)
- Depuis une vue de jeu (night, day, etc.) → `quitGame()` → `is_alive = false`
**Leçon :** Documenter explicitement les deux chemins de sortie. Ne pas réutiliser `quitGame()` hors du contexte de jeu actif.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Convention GAME_ID dans les partials Alpine

**Contexte :** Tous les partials Blade avec `x-data`, notamment `night.blade.php`, `day.blade.php`
**Symptôme :** Deux patterns coexistants dans les partials : `this.gameId` (référence au store Alpine) et `const GAME_ID = @json(...)` (injection Blade locale).
**Cause :** Convention absente dans CONVENTIONS.md — chaque partial a choisi son pattern indépendamment.
**Fix / Décision :** Convention unique → toujours déclarer `const GAME_ID = @json($game->id)` en haut du bloc `<script>` du partial. Jamais `this.gameId`. Jamais de variable Blade injectée directement dans un store Alpine (risque null au chargement).
**Leçon :** Ajouter la règle dans CONVENTIONS.md. Une variable Blade dans un store Alpine est évaluée une fois au rendu serveur — si l'état change côté client après, le store ne se met pas à jour.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] Recette "Couche 2 (lobby)" CAS 1-2 — redirection JS, pas de 302 serveur

**Contexte :** `tests/Feature/LobbyTest.php`, `LobbyController::create/join`, `lobby/index.blade.php`
**Symptôme / Problème :** La recette manuelle attend "redirige vers /game/{code}/lobby" (HTTP 302). Mais `POST /game` et `POST /game/{code}/join` répondent en JSON `{success, data, message}` (conforme CLAUDE.md §Réponses API), et c'est `lobbyApp()` (Alpine) qui exécute `window.location.href = '/game/' + code + '/lobby'` après un succès.
**Cause / Alternatives :** Soit transformer ces routes en formulaires web classiques avec redirect serveur (casserait l'UX AJAX existante et la règle CLAUDE.md sur les réponses API), soit tester le contrat JSON qui pilote la redirection côté client.
**Fix / Décision :** Tests `LobbyTest::test_cas1...` / `test_cas2...` vérifient (1) le JSON contient bien `data.code` / `data.game_code` au format attendu par le JS, et (2) que `/game/{code}/lobby` est atteignable avec ce code (vue `game.waiting-room`). Le comportement "redirige vers le lobby" est donc validé de bout en bout sans dépendre d'un 302 serveur qui n'existe pas dans cette architecture.
**Leçon :** Pour les flux pilotés en AJAX, une "redirection" attendue dans une recette manuelle se traduit en test par : (a) le contrat JSON exploitable par le JS, (b) l'atteignabilité de la page cible. Ne pas forcer un test `assertRedirect()` sur un endpoint JSON.
**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] Audit final — GSAP entry animations non protégées prefers-reduced-motion

**Contexte :** Tâche D — `resources/views/game/night.blade.php`, `resources/views/game/day.blade.php`
**Symptôme / Problème :** Les appels GSAP `fromTo('.reveal', ...)` en début de script (night) et dans `init()` (day) n'étaient pas wrappés dans un check `window.matchMedia('(prefers-reduced-motion: reduce)')`. Le CSS `app.css` neutralise CSS transitions/animations mais pas les tweens GSAP qui passent par `requestAnimationFrame`.
**Cause / Alternatives :** GSAP ignore les media queries CSS — il faut le check JS explicite.
**Fix / Décision :** Ajout d'un `if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches)` autour des deux appels. Cohérent avec tous les autres appels GSAP des mêmes fichiers qui étaient déjà correctement protégés.
**Leçon :** Les appels GSAP à l'init (hors handlers WebSocket) sont les plus susceptibles d'être oubliés. Vérifier systématiquement les `gsap.*` hors handlers lors d'un audit.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Recette "Couche 2 (lobby)" CAS 5-6 — OTP déjà conforme

**Contexte :** `lobby/index.blade.php` (`lobbyApp()`)
**Symptôme / Problème :** Vérifier que la navigation auto entre cases OTP et le pré-remplissage `?code=XXXXXX` sont implémentés en Alpine.js et fonctionnels.
**Cause / Alternatives :** Revue statique du composant `lobbyApp()`.
**Fix / Décision :** Déjà conforme, aucune correction nécessaire :
- `onOtpInput()` (ligne ~250) : sur saisie d'un caractère, focus la case `i+1` si `i < 6`.
- `onOtpKeydown()` (ligne ~259) : sur `Backspace` avec case courante vide, focus la case `i-1` si `i > 1` (gère aussi `ArrowLeft`/`ArrowRight`).
- `init()` via `x-init="init()"` (ligne ~225) : lit `window.location.search`, extrait `code`, l'uppercase/pad et alimente `joinForm.codeChars`. `activeTab` bascule aussi sur l'onglet "join" si `?code=` est présent.
**Leçon :** Le composant `lobbyApp()` respecte déjà les règles Alpine du projet (pas de `setTimeout`, logique dans le store du composant). Rien à modifier pour ces deux cas.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Bouton "Tuer" inactif — castNightVote rejetait wolves_turn

**Contexte :** `VoteService::castNightVote()`, `ProcessWerewolvesTurn`
**Symptôme :** Les loups sélectionnaient une cible mais le bouton "Tuer" restait disabled. Le POST `/vote/night` retournait 409.
**Cause :** `ProcessWerewolvesTurn` passe le status à `wolves_turn` avant de broadcaster `WerewolvesTurnStarted`. `castNightVote` vérifiait `$game->status !== 'night'` → 409 car status est `wolves_turn`. Le JS ne recevant pas `json.success = true`, `wolfVoteLocked` restait `false` et le bouton restait disabled.
**Fix :** Remplacer le guard par `! in_array($game->status, ['night', 'wolves_turn'])`.
**Leçon :** Toute action métier liée à une phase doit accepter tous les statuts intermédiaires légitimes de cette phase, pas seulement le statut "initial". Documenter les statuts intermédiaires dans `config/game.php` ou dans un commentaire.
**Statut :** ✅ Résolu

---

## [RÉSOLU] SeerTurnStarted manqué — ProcessSeerTurn dispatché sans délai

**Contexte :** `PhaseManager::startNight()`, `ProcessSeerTurn`, `night.blade.php`
**Symptôme :** Voyante et loups ne voyaient jamais leur écran. Après un vote jour, les joueurs étaient redirigés vers `/night` mais aucun tour ne démarrait. `ProcessSeerTurn` tournait immédiatement et broadcastait `SeerTurnStarted` sur le canal privé avant que les clients soient abonnés.
**Cause :** `startNight()` dispatchait `ProcessSeerTurn::dispatch($id)` sans délai. Le job s'exécutait en quelques dizaines de ms, soit avant que les clients aient eu le temps de : (1) recevoir `NightStarted`, (2) exécuter la redirection GSAP (1.5s d'animation), (3) charger `/night`, (4) initialiser Echo et s'abonner au canal privé `game.{id}.player.{playerId}`.
**Fix :** `ProcessSeerTurn::dispatch($id)->delay(now()->addSeconds(config('game.timers.night_start_delay', 4)))`. Ajout de `night_start_delay = 4` dans `config/game.php`.
**Leçon :** Tout broadcast sur un canal privé qui suit immédiatement une redirection de page doit être retardé d'au moins la durée de l'animation de transition + le temps de chargement de la page. 4 secondes couvrent l'animation GSAP (1.5s) + chargement page + init Echo.
**Statut :** ✅ Résolu

---

## [RÉSOLU] scope Alpine parent polluait nightScreen/dayScreen — confirmQuit not defined

**Contexte :** `resources/views/layouts/game.blade.php`, `night.blade.php`, `day.blade.php`
**Symptôme :** `Alpine Expression Error: confirmQuit is not defined` sur `/night`. Bouton "Quitter" non fonctionnel. Erreur console ligne ~936 du bundle compilé.
**Cause :** Le layout `game.blade.php` avait `x-data="gameState(...)"` sur le `<main>` qui englobe tout le contenu des vues. Alpine fusionne les scopes imbriqués : expressions dans `nightScreen()` (scope enfant) remontaient vers `gameState` (scope parent) quand une propriété n'était pas trouvée. `gameState` ne définit pas `confirmQuit` → erreur. Effet secondaire : double abonnement Echo possible car `gameState` et les stores locaux souscrivaient tous les deux au même canal.
**Fix :** Déplacer `x-data="gameState(...)"` sur un `div` fantôme invisible (`visibility:hidden; position:absolute; width:0; height:0`) hors du `<main>`. Alpine initialise le composant normalement mais son scope n'englobe plus les vues enfants. `display:none` aurait empêché Alpine d'initialiser le composant.
**Leçon :** Ne jamais monter un store Alpine global sur un élément parent d'autres `x-data`. Utiliser un élément dédié hors du flux principal, ou passer par `Alpine.store()` (accès via `$store.name`).
**Statut :** ✅ Résolu

---

## [RÉSOLU] Double abonnement Echo — modale succession fantôme

**Contexte :** `game-state.js`, `night.blade.php`, `day.blade.php`
**Symptôme :** La modale "Succession du Maire" s'affichait parfois quand le maire était encore en vie. `i-was-eliminated` ne déclenchait pas `showDeathBanner`. Events WebSocket traités deux fois.
**Cause :** `game-state.js` s'abonnait à `echo.channel(game.X)` ET les vues locales (`night.blade.php`, `day.blade.php`) s'abonnaient au même canal via `window.Echo.channel(...)`. Même chose pour les canaux privés (voyante, loups). Chaque event se déclenchait deux fois → modale s'ouvrait, se fermait sur `done`, puis se ré-ouvrait sur le deuxième trigger. Deuxième bug : `game-state.js` utilisait `this.$dispatch('i-was-eliminated')` qui dispatch sur `this.$el` et bulle dans le DOM Alpine — les `window.addEventListener('i-was-eliminated')` dans les vues ne le recevaient jamais.
**Fix :** (1) Tous les events cross-composants passent par `window.dispatchEvent(new CustomEvent(...))` dans `game-state.js`. (2) Les vues suppriment leurs abonnements Echo directs pour tous les events gérés par `game-state.js` et écoutent uniquement via `window.addEventListener`. (3) `day.blade.php` garde un seul `Echo.channel` pour `.day.vote.cast` et `.chat.message.sent` (non propagés par `game-state.js`).
**Leçon :** `$dispatch()` d'Alpine dispatch sur `this.$el` (bulle dans le DOM Alpine uniquement). `window.dispatchEvent()` dispatch sur `window` (accessible partout, y compris dans les stores non-Alpine). Pour des events cross-composants sans relation parent-enfant Alpine directe, toujours utiliser `window.dispatchEvent`. Un seul abonnement Echo par canal et par event — jamais deux composants sur le même canal pour le même event.
**Statut :** ✅ Résolu

---

## [RÉSOLU] GameController::night() 404 sur wolves_turn et processing_night

**Contexte :** `GameController::night()`, `redirectToCurrentPhase()`
**Symptôme :** Joueurs redirigés vers `/role-reveal` en plein tour des loups ou lors d'un refresh de `/night`. Parfois 404 directe.
**Cause :** `night()` vérifiait `$game->status !== 'night'` → renvoyait vers `redirectToCurrentPhase()`. Cette méthode utilisait `match($game->status)` sans cas pour `wolves_turn` et `processing_night` → `default` → `game.role-reveal`. Idem dans `state()` : `$isNight = $game->status === 'night'` → `seerTurnActive` et `werewolvesTurnActive` retournaient `false` pendant `wolves_turn`.
**Fix :** `night()` accepte `['night', 'wolves_turn', 'processing_night']`. `redirectToCurrentPhase()` migré vers `match(true)` avec conditions explicites pour couvrir les statuts intermédiaires. `state()` corrigé : `$isNight = in_array($game->status, ['night', 'wolves_turn', 'processing_night'])`, `seerTurnActive` conditionné à `$game->status === 'night'` uniquement.
**Leçon :** Tout guard de phase doit accepter tous les statuts intermédiaires légitimes (définis dans `CLAUDE.md` §Statuts intermédiaires). Toujours lister explicitement les cas dans `redirectToCurrentPhase` — le `default` ne doit être qu'un vrai fallback, pas un attrape-tout pour des statuts légitimes non listés.
**Statut :** ✅ Résolu

---

## [RÉSOLU] DayStarted payload manquait player_id — _markPlayerDead silencieusement ignoré

**Contexte :** `DayStarted::broadcastWith()`, `game-state.js::handleDayStarted()`
**Symptôme :** Joueur tué la nuit restait marqué "vivant" côté client au début de la phase jour.
**Cause :** `DayStarted::broadcastWith()` envoyait `killed: { pseudo, role }` sans `player_id`. `handleDayStarted` appelait `_markPlayerDead(e.killed.player_id)` → `undefined` → aucun joueur marqué mort.
**Fix :** Ajouter `player_id` dans le payload `killed` de `DayStarted`.
**Leçon :** Toute référence à un joueur dans un payload WebSocket doit inclure son `id` (pas seulement son `pseudo`). Le `pseudo` peut changer, n'est pas indexable côté client, et ne permet pas de retrouver le joueur dans `players[]`.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Accumulation CheckReconnectionTimeout — handleDisconnection sans guard

**Contexte :** `GameService::handleDisconnection()`, `CheckReconnectionTimeout`
**Symptôme :** Des dizaines de jobs `CheckReconnectionTimeout` s'accumulaient en queue à chaque session. Le cache de déconnexion était écrasé à chaque appel, invalidant le token du job précédent qui restait orphelin en queue.
**Cause :** Reverb déclenche plusieurs événements de déconnexion WebSocket pour un même client (ping timeout, fermeture socket, etc.). Chaque appel à `handleDisconnection` créait un nouveau token UUID et un nouveau job, sans vérifier si un job était déjà en attente.
**Fix :** Guard `Cache::has($cacheKey)` en début de `handleDisconnection` — retour immédiat si un token actif existe déjà pour ce joueur.
**Leçon :** Toute action déclenchée par un événement réseau pouvant se produire plusieurs fois (déconnexion WebSocket, heartbeat, retry) doit être idempotente avec un guard d'entrée. Le cache est le mécanisme approprié pour ce type de guard éphémère.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Barre de timer jour affichée pleine quand temps = 0

**Contexte :** `day.blade.php`, `_startDayTimer()`
**Symptôme :** La barre de progression du timer restait partiellement ou totalement remplie alors que le compteur affichait 0s.
**Cause :** La barre HTML avait `width:100%` en dur. Si `PHASE_SECONDS = 0` (joueur arrivant après expiration), `_startDayTimer` retournait sans toucher la barre → elle restait à 100%. En cas normal, la barre partait toujours de 100% sans tenir compte du temps déjà écoulé depuis le chargement de la page.
**Fix :** La barre démarre à `width:0%` dans le HTML. `_startDayTimer` calcule `initialPct = (PHASE_SECONDS / totalSeconds) * 100` et set la largeur initiale correcte. Si `PHASE_SECONDS <= 0`, barre forcée à 0% immédiatement.
**Leçon :** Les barres de progression liées à un état serveur ne doivent jamais avoir une valeur initiale hardcodée en HTML. La valeur initiale doit être calculée depuis l'état réel (`phaseRemainingSeconds / totalSeconds`). Cela couvre aussi les joueurs qui arrivent en retard (refresh, reconnexion).
**Statut :** ✅ Résolu

---

## [CHOIX] ProcessNightEnd — délai via $game->timer() et récupération de la victime pour DayStarted

**Contexte :** Tâche H — `app/Jobs/ProcessNightActions.php`, `app/Services/PhaseManager.php::endNight()`.
**Symptôme / Problème :** L'énoncé de la tâche fournissait deux extraits à reproduire tels quels : (1) `ProcessNightEnd::dispatch(...)->delay(now()->addSeconds(config('game.timers.mayor_succession', 15) + 5))` ; (2) `endNight()` appelant `$this->startDay($game, null)`.
**Cause / Alternatives :** (1) `config('game.timers.mayor_succession', 15)` retourne en réalité `5` (valeur réellement définie dans `config/game.php` — le `15` de l'énoncé n'est qu'un défaut de fallback jamais atteint), soit un délai total de `5+5=10s`. Or `ProcessMayorSuccession` peut être dispatché avec un délai allant jusqu'à `$game->timer('mayor_succession')` = `15` (valeur posée par `TimerCalculator::FIXED` dans `$game->timers` au démarrage) : `10s` ne suffirait pas à couvrir la succession. CLAUDE.md interdit explicitement `config('game.timers.x')` au profit de `$game->timer('x')`. (2) `startDay($game, null)` ferait perdre l'info `killed` (player_id/pseudo/role de la victime de la nuit) du payload `DayStarted` pour tous les rounds — régression sur la décision « DayStarted payload manquait player_id ».
**Fix / Décision :** (1) Délai = `$game->timer('mayor_succession') + 5` → `15+5=20s` pour une partie réelle, conforme à l'exemple « 20s » de l'énoncé et à CLAUDE.md. (2) `endNight()` recalcule la victime via `app(VoteService::class)->resolveNightVote($game)` (lecture pure des `night_vote` du round, sans effet de bord — le `is_alive=false` a déjà été appliqué par `ProcessNightActions`) et la passe à `startDay($game, $victim)`. Point 4 de l'énoncé (retirer startDay/startNight de `ProcessSeerTurn`) vérifié sans objet : ce job ne contenait déjà aucun appel à ces méthodes.
**Leçon :** Ne jamais recopier littéralement un extrait de code d'énoncé sans vérifier (a) que les valeurs de config citées correspondent à la config réelle du projet, (b) qu'un paramètre `null`/omis ne casse pas un payload déjà documenté ailleurs dans DECISIONS.md. `resolveNightVote()` est sûr à rappeler car purement déclaratif.
**Statut :** 🔵 Choix assumé

---

## [CHOIX] ProcessNightEnd job dédié plutôt qu'appel direct à startDay() depuis ProcessNightActions

**Contexte :** Tâche H — `ProcessNightActions`, `ProcessNightEnd`, `PhaseManager::endNight()`
**Symptôme / Problème :** `ProcessNightActions` appelait `startDay()` directement, créant plusieurs chemins de sortie (succession maire, voyante morte, cas normal) dont certains ne couvraient pas tous les scénarios — notamment voyante morte sans victime loups.
**Cause / Alternatives :** (1) Ajouter des branches conditionnelles supplémentaires dans `ProcessNightActions` pour couvrir chaque cas — complexité croissante, fragile. (2) Centraliser la fin de nuit dans un job unique `ProcessNightEnd` dispatché systématiquement avec un délai buffer couvrant la succession éventuelle.
**Fix / Décision :** Option 2 retenue. `ProcessNightActions` ne contient plus aucun appel à `startDay()`. Il dispatche toujours `ProcessNightEnd` avec `delay(mayor_succession + 5s)`. `PhaseManager::endNight()` contient la logique métier (guard status, WinConditionChecker, startDay). Le job n'est qu'un orchestrateur timer — conforme à CLAUDE.md.
**Leçon :** Tout job qui se termine par "et après, on passe à la phase suivante" doit déléguer cette transition à un job ou une méthode de service dédiée, jamais l'inliner. Cela permet de couvrir tous les chemins de sortie sans multiplier les branches.
**Statut :** 🔵 Choix assumé
---

## [RÉSOLU] Compteur successionDepth pour différer la redirection nuit pendant une cascade de successions

**Contexte :** `resources/js/game-state.js`, `resources/views/game/day.blade.php`
**Symptôme / Problème :** La modale "Succession du Maire" ne se fermait jamais quand le maire était éliminé le jour. `handleNightStarted()` redirigeait immédiatement vers `/night`, détruisant la page `/day` et tous ses `window.addEventListener` — dont celui qui ferme la modale sur `mayor-succession-done`. Un flag booléen simple (`successionInProgress`) aurait résolu le cas à une seule succession mais cassé la cascade (successeur élu éliminé à son tour le même jour → deuxième `MayorSuccessionStarted` reçu avant que le premier `done` n'ait remis le flag à `false`).
**Cause / Alternatives :** (1) Flag booléen — rejeté car non réentrant sur les cascades N successions consécutives. (2) Compteur `successionDepth` incrémenté sur chaque `.mayor.succession.started` et décrémenté (avec `Math.max(0, ...)` comme garde-fou) sur chaque `MayorSuccessionDone` — réentrant par construction.
**Fix / Décision :** `handleNightStarted()` vérifie `successionDepth > 0` : si oui, attend un event `mayor-succession-done` qui ramène le compteur à 0 avant d'appeler `_doNightRedirect()` (logique d'animation/redirection extraite en méthode dédiée). Garde-fou complémentaire côté `day.blade.php` : `openSuccessionModal()` arme un `setTimeout(20000)` qui force `closeSuccessionModal()` si `mayor-succession-done` n'arrive jamais (perte réseau, bug serveur).
**Leçon :** Pour tout état "N opérations asynchrones en cours" qui peut se déclencher en cascade (le même event de fin peut re-déclencher l'event de début avant d'être traité), utiliser un compteur réentrant plutôt qu'un flag booléen. Par ailleurs, toute redirection de page (`window.location.href`) détruit immédiatement tous les `window.addEventListener` de la page courante — une modale/état "en cours" doit être résolu (ou avoir un timeout de secours) AVANT toute redirection déclenchée par un autre handler.
**Statut :** ✅ Résolu

---

## [RÉSOLU] window.MY_ROLE absent du layout — canal loups jamais souscrit

**Contexte :** fix/window-my-role-layout — resources/views/layouts/game.blade.php,
resources/js/game-state.js.
**Symptôme / Problème :** le canal privé game.{gameId}.werewolves n'était jamais
souscrit pour certains joueurs loups (chat loups silencieux, votes non reçus).
game-state.js loge deux fallbacks vers window.MY_ROLE avant initWebSocket() — mais
window.MY_ROLE n'était défini nulle part dans le layout. Les vues night.blade.php
et day.blade.php définissaient MY_ROLE comme const locale sans l'exposer sur window.
**Cause / Alternatives :** (1) Ajouter window.MY_ROLE dans chaque vue Blade
(night, day, elect-mayor) — solution fragmentée, risque d'oubli sur les vues futures.
(2) Ajouter window.MY_ROLE dans le layout global, dans le @isset($player) existant —
centralisé, garanti pour toutes les vues sans duplication.
**Fix / Décision :** Option 2 retenue. Une ligne ajoutée dans layouts/game.blade.php :
window.MY_ROLE = '{{ $player->role ?? '' }}'. L'ordre d'exécution est garanti :
les scripts inline du layout s'exécutent avant les modules ES6 Vite (defer implicite),
donc window.MY_ROLE est défini avant que game-state.js::init() soit appelé.
**Leçon :** toute variable globale lue par game-state.js au moment de init() doit
être exposée dans le layout principal, pas dans les vues individuelles. Les vues
s'exécutent dans @push('scripts') qui est rendu après @stack('scripts') dans le layout,
mais les modules Vite sont defer — l'ordre réel est : layout script → vues @push →
bundle Vite. Pour les variables lues dès init() (avant le premier tick Alpine),
seul le layout garantit une disponibilité synchrone. Ne pas exposer sur window depuis
une const locale d'une vue Blade.
**Statut :** ✅ Résolu

---

## [RÉSOLU] Empoisonnement sorcière ignoré — race condition ProcessWitchAutoAction vs transaction witchAct()

**Contexte :** `fix/witch-kill-timing` — `app/Http/Controllers/Game/ActionController.php`, `app/Jobs/ProcessWitchAutoAction.php`

**Symptôme / Problème :** La sorcière soumettait une action `kill`, recevait `success: true`, mais la nuit se terminait sans que l'empoisonnement soit pris en compte. L'interface affichait `witchActionDone = true` mais la victime n'était pas éliminée. Reproductible surtout avec le driver queue `database` en mode sync (tests) ou un worker très rapide en prod.

**Cause / Alternatives :** `ActionController::witchAct()` dispatchait `ProcessWitchAutoAction::delay(0)` immédiatement après le retour de `gameService->witchAct()`. Avec le driver sync (ou un worker ultra-rapide), le job s'exécutait avant que la transaction interne ait pu être vue par une requête DB ultérieure (isolation de lecture, Eloquent model non-rafraîchi). Le guard `$alreadyActed` ne trouvait pas d'action `witch_kill` → créait un `witch_pass` → dispatchait `ProcessNightEnd::delay(0)` → nuit terminée sans empoisonnement. En parallèle, `ProcessWitchAutoAction` lisait `$game->actions()` sur un modèle Eloquent potentiellement stale (pas de `refresh()`).

**Fix / Décision :** (1) `delay(0)` → `delay(now()->addSeconds(2))` dans `ActionController::witchAct()` pour laisser la transaction se propager. (2) `$game->refresh()` avant le guard `$alreadyActed` dans `ProcessWitchAutoAction::handle()` pour forcer la lecture des données fraîches. Les deux fixes sont complémentaires : le délai couvre la race condition en prod, le refresh couvre les lectures Eloquent stale en test.

**Leçon :** Toute action volontaire qui dispatche un job auto en `delay(0)` crée une fenêtre de race condition. Pattern à appliquer systématiquement : (a) délai minimal de 2s sur le dispatch auto depuis un Controller, (b) `$game->refresh()` avant chaque guard de double-fire dans les jobs qui succèdent à une action volontaire. Voir aussi DECISIONS.md "Race condition vote loups" pour le même pattern appliqué aux loups.

**Statut :** ✅ Résolu

---

## [RÉSOLU] Broadcasts et race conditions dans les transactions lockForUpdate

**Contexte :** `fix/broadcasts-out-of-transaction` — `app/Services/GameService.php`, `app/Jobs/ProcessNightEnd.php`, `app/Jobs/ProcessSeerTurn.php`, `app/Jobs/ProcessMayorElection.php`, `app/Services/PhaseManager.php`

**Symptôme / Problème :** Plusieurs méthodes de `GameService` (`cancelGame`, `markReady`, `joinGame`, `startGame`, `excludePlayer`) contenaient des `broadcast()` et des `notify()` à l'intérieur de blocs `DB::transaction()` avec `lockForUpdate()`. En cas d'échec de rollback ou de lenteur du worker Reverb, le broadcast était émis avant que la transaction soit visible pour les autres connexions DB. Dans `ProcessNightEnd`, la lecture + suppression de `hunter_pending` était non atomique : deux exécutions simultanées du job pouvaient toutes deux voir l'enregistrement avant qu'il soit supprimé, déclenchant deux `ProcessHunterTurn`. Dans `ProcessSeerTurn`, aucun guard sur le round n'existait : un job stale d'une nuit précédente (en retard dans la queue) pouvait déclencher le tour voyante d'une nouvelle nuit.

**Cause / Alternatives :** Pattern anti-pattern : side-effects (broadcasts, notifications HTTP, dispatches delay(0)) dans une transaction DB ouverte. La transaction garantit l'atomicité des écritures, pas des effets de bord réseaux. Alternative pour les broadcasts : faire retourner les données nécessaires par le closure de transaction, puis broadcaster après.

**Fix / Décision :**
- `cancelGame`, `markReady`, `startGame`, `joinGame`, `excludePlayer` : la transaction retourne un tableau de données, tous les `broadcast()` et `notify()` sont appelés après, conditionnés par un check non-null.
- `markReady` : `ProcessMayorElection::dispatch()->delay(timer)` reste DANS la transaction (delay > 0, autorisé par Guard #5 — il ne s'exécute pas pendant la transaction).
- `excludePlayer` : `$targetUser` chargé avant le `DB::transaction()` ; `$target` reste en mémoire PHP avec ses attributs après `delete()` — les broadcasts peuvent l'utiliser sans aller en base.
- `ProcessNightEnd` : lecture + suppression de `hunter_pending` enveloppée dans `DB::transaction()` + `lockForUpdate()` ; `ProcessHunterTurn::dispatch()->delay(0)` dispatché hors transaction (Guard #5).
- `ProcessSeerTurn` : ajout du paramètre `$round` au constructeur ; guard `$game->round !== $this->round` dans `handle()`.

**Leçon :** Règle absolue : aucun `broadcast()`, `notify()` ou `dispatch()->delay(0)` dans un `DB::transaction()` qui contient un `lockForUpdate()`. Les dispatches avec `delay > 0` sont tolérés (ils ne s'exécutent pas pendant la transaction). Pour les jobs avec guard de double-fire, l'opération "lire + détruire" doit elle-même être atomique (transaction + lockForUpdate).

**Statut :** ✅ Résolu

---

## [CHOIX] applyTransition() interdit sur les statuts intermédiaires hors Workflow

**Contexte :** `docs/apply-transition-guard-warning` — `app/Models/Game.php`, `app/Jobs/ProcessNightEnd.php`

**Symptôme / Problème :** Le Workflow Symfony ne couvre que 5 places canoniques (`waiting`, `electing_mayor`, `night`, `day`, `finished`). Les statuts intermédiaires (`processing_night`, `processing_day`, `wolves_turn`, `role_reveal`) sont gérés manuellement hors Workflow via `$game->update(['status' => '...'])`. Appeler `Game::applyTransition()` depuis l'un de ces statuts lève une `LogicException` Symfony (`"The marking does not contain a place 'processing_night'."`) catchée silencieusement par le queue worker Laravel — le job est marqué `failed` sans message clair dans les logs applicatifs.

**Cause / Alternatives :** L'existence de statuts intermédiaires hors Workflow est un choix d'architecture v1.2 (granularité nécessaire pour les phases nocturnes séquentielles). Alternative — les intégrer comme places Workflow — rejetée : complexité disproportionnée et couplage fort aux Jobs internes.

**Fix / Décision :** Tâche purement documentaire. PHPDoc complet ajouté sur `Game::applyTransition()` listant les statuts interdits, le symptôme exact d'une erreur, et le pattern autorisé (retour au dernier statut canonique via `update()`, puis `applyTransition()`). Commentaire de rappel ajouté dans `ProcessNightEnd::handle()` au-dessus du bloc qui bypasse intentionnellement `canTransition()` pour `processing_night`.

**Leçon :** Ne jamais appeler `applyTransition()` si `$game->status` est l'un de : `processing_night`, `processing_day`, `wolves_turn`, `role_reveal`. Ces statuts sont écrits manuellement (`$game->update(['status' => '...'])`). Pour revenir dans le graphe Workflow, écrire d'abord le statut canonique source, puis appeler `applyTransition()`. Voir aussi Guard #5 de RISK_GUARDS.md pour la règle `lockForUpdate()` associée.

**Statut :** 🔵 Choix assumé

---

## [RÉSOLU] PlayerEliminated broadcasté trop tôt pendant la nuit quand la sorcière a son soin

**Contexte :** `fix/bug-toast-elimination-premature-nuit` — `app/Jobs/ProcessNightActions.php`, `app/Services/RoleActions/WitchAction.php`.

**Symptôme / Problème :** `ProcessNightActions` broadcastait `PlayerEliminated` pour la victime ordinaire des loups dès la résolution du vote, avant que la sorcière ait eu le temps d'agir. Côté front, le toast d'élimination apparaissait pendant la nuit alors que la sorcière pouvait encore sauver la victime — ce qui créait un spoil prématuré de l'issue.

**Cause / Alternatives :** Les guards existants (`$victimIsWitchWithHeal`, `$victimIsMayorWithWitchAvailable`) différaient la mort et le broadcast pour deux cas précis (sorcière victime d'elle-même, maire en sursis), mais pas pour la victime ordinaire face à une sorcière avec soin disponible. La troisième condition manquante : "la sorcière est vivante et son soin n'a pas encore été utilisé".

**Fix / Décision :**
- `ProcessNightActions` : ajout d'un troisième flag `$witchCanSaveVictim` (`$witch !== null && !witch_heal_used`). Les deux conditions (mort en base + broadcast `PlayerEliminated`) incluent désormais `! $witchCanSaveVictim`.
- `WitchAction::act()` (kill/pass) : après les broadcasts `$witchDiedFromWolves` et `$mayorVictim`, bloc hors transaction qui détecte la victime ordinaire encore vivante (not witch, not mayor, `is_alive = true`), la marque morte et broadcast `PlayerEliminated`. Guarded par `$action !== 'heal'` pour ne pas tuer une victime que la sorcière vient de soigner.
- La mort et le broadcast sont donc différés ensemble : soit `ProcessNightActions` les gère (sorcière morte ou soin déjà épuisé), soit `WitchAction` les gère (sorcière agit dans ce round).

**Leçon :** Quand un événement doit être conditionné à l'action d'un autre joueur, différer les deux (mort DB + broadcast) ensemble — pas seulement le broadcast. Et prévoir le cas `ProcessWitchAutoAction` (timer expiré sans action manuelle) comme chemin de résolution alternatif qui n'appelle pas `WitchAction::act()` — c'est un gap résiduel à traiter dans un ticket dédié.

**Statut :** ✅ Résolu

---

## [CHOIX] PlayerEliminationService — point d'entrée unique pour éliminer un joueur (prérequis Cupidon)

**Contexte :** `refactor/centralize-player-elimination` — `app/Services/PlayerEliminationService.php` (nouveau), `app/Jobs/ProcessNightActions.php`, `app/Services/VoteService.php`, `app/Services/GameService.php`, `app/Services/RoleActions/HunterAction.php`, `app/Services/RoleActions/WitchAction.php`.

**Symptôme / Problème :** `is_alive = false` était posé directement à 11 endroits différents (audit confirmé par grep, dépassant les 10 initialement recensés : `ProcessNightActions` ×1, `VoteService` ×2, `GameService::quitGame` ×1, `HunterAction` ×1, `WitchAction` ×6). SPEC_CUPIDON.md §5 exige qu'une future cascade de mort des amoureux (si un amoureux meurt, l'autre meurt aussi) soit ajoutée à un seul endroit — dupliquer cette cascade dans 11 call sites aurait signifié qu'un seul oubli produirait un bug silencieux en prod (l'amoureux restant ne mourrait pas).

**Cause / Alternatives :** Le choix (méthode sur `GamePlayer` vs Service dédié) était déjà tranché dans SPEC_CUPIDON.md §5 avant cette tâche : `PlayerEliminationService::eliminate(GamePlayer $player): void`, cohérent avec le pattern de délégation déjà en place pour `GameSettingsService` et `RoleActions/*` (extraction sans changer l'API publique des appelants). Alternative `GamePlayer::eliminate()` écartée dans la spec — non ré-examinée ici, la décision amont faisait déjà autorité.

**Fix / Décision :**
1. `PlayerEliminationService::eliminate()` créé — pose uniquement `is_alive = false` pour l'instant (aucune cascade : Cupidon n'existe pas encore, viendra dans une tâche ultérieure qui enrichira cette seule méthode).
2. Les 11 call sites migrés vers cette méthode, un par un avec passage des tests entre chaque fichier. Aucune signature publique changée, aucun comportement observable modifié.
3. `WitchAction` (6 occurrences) : chaque occurrence traitée séparément malgré la duplication de logique priorité chasseur/maire déjà documentée dans DECISIONS.md — hors périmètre de cette tâche, uniquement la ligne `->update(['is_alive' => false])` remplacée par l'appel au service.
4. `GameService::quitGame()` : seul call site à combiner `is_alive` et `is_inactive` dans un même `update()`. Scindé en `$this->eliminationService->eliminate($player)` puis `$player->update(['is_inactive' => true])` — deux appels au lieu d'un, toujours dans la même transaction, comportement final identique (mêmes deux colonnes à `true`/`false` à la fin).
5. Injection : `PlayerEliminationService` ajouté au constructeur de `VoteService`, `GameService`, `HunterAction`, `WitchAction` (résolution via container Laravel, cohérent avec l'injection déjà en place de `VoteService` dans `WitchAction`). Dans `ProcessNightActions::handle()`, résolution via `app(PlayerEliminationService::class)` plutôt qu'un paramètre de méthode — les tests appellent `handle()` directement avec un nombre d'arguments fixe (`ArgumentCountError` provoqué lors d'un premier essai avec paramètre additionnel), donc pas d'injection via signature de méthode pour ce fichier précis.
6. Aucun fichier de test dédié créé pour `PlayerEliminationService`, cohérent avec le précédent `GameSettingsService`/`RoleAction` (aucun test unitaire dédié non plus) — la couverture existante (`HunterTest`, `WitchTest`, `NightPhaseTest`, `FullGameIntegrationTest`, etc.) exerce déjà tous les chemins d'élimination. 205 tests verts après chaque fichier migré (aucun ajouté, aucun cassé).

**Leçon :** Avant d'ajouter un paramètre à la signature d'une méthode `handle()` de Job, vérifier si des tests l'appellent directement (`(new Job(...))->handle($a, $b)`) plutôt que de compter sur la résolution automatique du container — un paramètre additionnel requis casse ces appels directs avec `ArgumentCountError`. Dans ce cas, résoudre la dépendance via `app(Xxx::class)` à l'intérieur du corps de la méthode plutôt que via la signature. Pour les classes de service uniquement instanciées par le container (`app(Xxx::class)->method(...)`), l'injection par constructeur reste préférable et sans risque — vérifier au préalable par `grep "new NomDeClasse"` dans `tests/` et `app/` qu'aucun call site n'instancie la classe directement avec `new`.

**Statut :** 🔵 Choix assumé

## [RÉSOLU] Ordre du lien Cupidon dans la carte "Nuit 1" de l'historique

**Contexte :** `fix/history-cupidon-order-night1` — `resources/views/game/history.blade.php`.

**Symptôme / Problème :** dans la carte "Nuit 1" de l'historique de fin de partie, le lien Cupidon s'affichait après la victime des loups, la sorcière, le tir du chasseur et la succession du maire, alors que Cupidon joue en tout premier au round 1 (avant la Voyante, avant la résolution du vote des loups).

**Cause / Alternatives :** l'ordre des sous-lignes n'est PAS déterminé par l'ordre des clés du tableau retourné par `HistoryService::buildTimeline()` (l'ordre d'insertion dans cet array n'a aucun effet sur le rendu) mais par l'ordre séquentiel des blocs `@if(!empty($entry['xxx']))` codés en dur dans `history.blade.php`, bloc `@elseif($entry['type'] === 'night')` (~ligne 331). Le bloc `cupidon_couple` avait été ajouté en fin de séquence lors de l'implémentation initiale (Phase 49), sans considérer l'ordre réel de déroulement d'une nuit.

**Fix / Décision :** déplacement du seul bloc `cupidon_couple` en tête de la séquence `night` dans `history.blade.php`, avant `killed`/`wolf_no_agreement`. Marge changée de `mt-1` à `mb-1` sur ce bloc pour un espacement autonome (ne dépend pas de ce qui suit, fonctionne que `killed` soit présent ou non). Le reste de l'ordre (`killed` → `witch_heal`/`witch_kill` → `hunter_shot` → `succession`) était déjà correct et n'a pas été touché.

**Leçon :** dans `history.blade.php`, l'ordre d'affichage des sous-lignes d'une carte (`night`, `day`, etc.) se règle exclusivement en réordonnant les blocs `@if` de la vue — jamais en réordonnant les clés du tableau construit par `HistoryService::buildTimeline()`. Un test de régression sur l'ordre doit donc rendre la vue réelle (route `game.history` + `assertSeeTextInOrder()`), pas seulement inspecter le tableau PHP retourné par le service.

**Statut :** ✅ Résolu
