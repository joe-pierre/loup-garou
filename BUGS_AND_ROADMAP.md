# BUGS CORRIGÉS

### [x] 2026-07-25 — Écran Voyante (seer_result) se referme sur un mauvais repère temporel

- **Symptôme :** entre la fin du tour de la Voyante et le début réel du tour des Loups, un délai anormal de ~10-14s était observé (audit dédié, partie réelle à 6 joueurs) — l'écran `seer_result` de la Voyante repassait à `village_sleeping` bien avant que les Loups ne démarrent réellement côté serveur.
- **Cause :** `night.blade.php` (listener `seer-result`) utilisait un `setTimeout(5000)` fixe, indexé sur le mauvais repère (déclenché dès réception de `SeerResult`, qui peut arriver à mi-timer via l'inspection de consolation), au lieu du vrai passage aux Loups (`seerTimer + 2s` côté serveur).
- **Fix :** suppression du `setTimeout(5000)` — la Voyante reste sur `seer_result` jusqu'au dismiss volontaire (bouton "J'ai compris" déjà existant) ou jusqu'à `DayStarted`. Commentaire stale de `ProcessSeerTurn.php` ("+5s") corrigé en "+2s" (valeur réelle du code). Voir DECISIONS.md "Écran Voyante (seer_result) se referme sur un mauvais repère temporel" pour le détail complet (pourquoi `NightResyncService` et `WerewolvesTurnStarted` n'étaient pas des options viables).

---

### [x] 2026-07-25 — Nombre de voix non affiché en temps réel pendant le vote de jour

- **Symptôme :** contrairement à l'élection du Maire, `day.blade.php` n'affichait jamais le nombre de voix ni la barre de progression à côté du pseudo d'un joueur pendant le vote de jour, bien que le mécanisme d'affichage (`#vbar-{id}`, libellé "X votes") soit déjà en place dans le template — aucune erreur JS visible, l'affichage restait juste silencieusement vide.
- **Cause :** `_updateVoteBars()` lisait `v.player_id`/`v.vote_weight`/`v.vote_count`, des noms de champs qui n'existent pas dans le payload réel de `DayVoteCast::broadcastWith()` (`target_player_id`/`total_weight`) — défaut de nommage introduit lors du passage du vote de jour en public, jamais corrigé côté consommation JS. `MayorVoteCast` (qui fonctionne) utilise `target_player_id`/`vote_count` — un nom de champ de poids différent pour un concept équivalent, les deux events ne sont pas interchangeables.
- **Fix :** `_updateVoteBars()` (`day.blade.php`) corrigée pour lire `v.target_player_id`/`v.total_weight`. `_handleMayorVoteCast` (`game-state.js`) non touchée, déjà correcte. Trouvé au passage : `game-state.js::_buildVoteMap()` (partagée par `.werewolves.vote.cast` et `.day.vote.cast`) a le même défaut de nommage (et lit même `e.votes` pour l'event loups, qui broadcaste en réalité sous `e.wolves`) — non corrigé ici car son résultat (`this.votes`/`this.wolvesVotes`) n'est lu par aucune vue Blade actuellement (day.blade.php et night.blade.php consomment directement le détail brut de l'event), donc sans impact visible ; voir ROADMAP.

---

### [x] 2026-07-25 — Chasseur mort de chagrin (cascade amoureux) ne tirait jamais

- **Symptôme :** quand l'amoureux (lien Cupidon) d'une victime meurt par cascade de chagrin, s'il s'agit du Chasseur, il ne tire jamais avant de mourir — contrairement à tous les autres chemins de mort du Chasseur (loups, poison Sorcière, vote village).
- **Cause :** `PlayerEliminationService::eliminate()` ne vérifiait `isHunter()` que via les 4 call sites existants sur leur propre victime directe ; l'amoureux cascadé meurt entièrement à l'intérieur du service, hors de leur portée — aucun d'eux ne le voit jamais.
- **Fix :** vérification `isHunter()` + création du `GameAction hunter_pending` ajoutée directement dans la branche cascade de `PlayerEliminationService::eliminate()`, avec round/phase déterminés via `$lover->game->isNightPhase()`. Voir DECISIONS.md "Chasseur mort de chagrin (cascade amoureux) ne tirait jamais" pour le détail complet.

---

### [x] 2026-07-24 — Écran de fin de partie et historique affichaient "Loups"/"Annulée" pour une victoire des Amoureux

- **Symptôme :** même après correction des deux bugs backend (SFICZ8, RIQPAZ), une victoire des amoureux correctement persistée (`winner_team = 'lovers'`) aurait quand même affiché "Les Loups ont gagné !" sur l'écran de fin et "🏁 Annulée" dans l'historique.
- **Cause :** `finished.blade.php` pilotait tout son thème via un booléen `$isVillage = winner_team === 'villagers'` (tout le reste retombait sur le thème Loups) ; `history.blade.php` et `HistoryService::buildTimeline()` avaient un `match()` à 2 branches (`villagers`/`werewolves`) avec un `default` affichant "Annulée" — `'lovers'` y tombait, alors que ce `default` n'est censé s'appliquer qu'à une vraie annulation (`winner_team === null`).
- **Fix :** `finished.blade.php` généralisé en thème à 3 branches (rose `#f472b6`, déjà standardisé pour Cupidon, pour les amoureux) ; branches `lovers` ajoutées dans `history.blade.php` et `HistoryService`. Écrans admin (`admin/games/show.blade.php`, `admin/users/show.blade.php`) corrigés par cohérence. Voir DECISIONS.md "Écran de fin de partie et historique affichaient 'Loups'/'Annulée'..." pour le détail complet.

---

### [x] 2026-07-24 — Victoire des Amoureux annulée à tort par une course entre `cancelGame()` et une résolution de vote/nuit en cours

- **Symptôme :** partie RIQPAZ — couple Cupidon formé au round 1, un vote de jour élimine le dernier autre joueur (les deux amoureux restent seuls, vivants et actifs), la partie aurait dû se terminer immédiatement en "Victoire des Amoureux" mais affiche "Partie annulée".
- **Cause :** `GameService::cancelGame()` avait un garde (`whereNotIn('status', ['finished', 'waiting'])`) bien plus permissif que le pré-check de `CheckReconnectionTimeout::handle()` qui le déclenche (`in_array($game->status, ['night', 'day', 'electing_mayor'])`, excluant les statuts intermédiaires `'processing_day'`/`'processing_night'`) — TOCTOU classique, jamais revalidé atomiquement au moment de l'écriture. `cancelGame()` pouvait donc annuler une partie dont une résolution de vote/nuit était encore en cours, avant que `WinConditionChecker::check()` n'ait la moindre chance de détecter la victoire.
- **Fix :** garde de `cancelGame()` resserré à `whereIn('status', ['night', 'day', 'electing_mayor'])`, symétrique au pré-check du Job, revalidé sous le même `lockForUpdate()`. Voir DECISIONS.md "Victoire des Amoureux annulée à tort par une course avec cancelGame()..." pour le détail complet (cause racine confirmée, remplace la mention "non confirmé" de l'entrée précédente).

---

### [x] 2026-07-24 — Victoire des Amoureux non déclenchée après une résolution différée de la Sorcière

- **Symptôme :** partie SFICZ8 — couple Cupidon formé au round 1, dernier autre joueur (Chasseur) tué en Nuit 3, la partie aurait dû se terminer immédiatement en "Victoire des Amoureux" mais a continué jusqu'à un "Jour 3" incohérent, avec `/history` affichant "Annulée" et l'écran de fin affichant "Les Loups ont gagné !".
- **Cause :** `WitchAction.php` n'appelait jamais `WinConditionChecker::check()` après une élimination différée (sorcière elle-même, maire en sursis, victime ordinaire) ; `ProcessHunterTurn` ne vérifiait la victoire qu'en repli, jamais avant de donner la main au Chasseur. Voir aussi le bug ci-dessus (cause racine de l'anomalie "Annulée vs Loups ont gagné").
- **Fix :** `check()` ajouté dans `WitchAction::act()`/`finalizeTimedOutVictim()` et déplacé en tête de `ProcessHunterTurn::handle()`. Voir DECISIONS.md "Victoire des Amoureux non déclenchée quand une résolution différée de la Sorcière fait tomber l'effectif à 2 (partie SFICZ8)" pour le détail complet.

---

### [x] 2026-07-23 — Vote loup perdu si refresh/reconnexion pendant une sous-phase de nuit

- **Symptôme :** un loup choisissait sa cible, la page restait figée, et après rechargement il se retrouvait sur l'écran générique d'attente au lieu de l'écran loups — vote jamais enregistré, personne tué cette nuit-là. Le même défaut touchait voyante, sorcière, chasseur et cupidon.
- **Cause :** `nightPhase` (client, `night.blade.php`) ne progresse que via des events Echo one-shot, jamais persistés côté serveur au-delà de `games.status` — aucun moyen de rattraper la sous-phase active après un refresh ou une coupure réseau.
- **Fix :** nouvelle colonne `games.night_sub_phase` (vérité persistée, posée par chaque `ProcessXTurn` au moment du broadcast) + `NightResyncService` exposé via `GET /state` (`night_action`) + rattrapage câblé au chargement de page et à la reconnexion Echo. Voir DECISIONS.md "Resynchronisation des sous-phases de nuit" pour le détail complet.

---

### [x] 2026-07-23 — Avatar de la salle d'attente laissait deviner le pseudo des autres joueurs

- **Symptôme :** le fix du 2026-06-13 masquait le pseudo de l'hôte dans la salle d'attente, mais l'avatar de tous les joueurs (y compris l'hôte) affichait toujours l'initiale du vrai pseudo (`p.pseudo.charAt(0).toUpperCase()`), rendant le pseudo facilement devinable.
- **Cause :** le masquage de pseudo n'avait été appliqué qu'au libellé texte, pas à l'avatar, et ne concernait que l'hôte — pas les autres joueurs.
- **Fix :** généralisation à tous les joueurs dans `waiting-room.blade.php` : avatar affiche l'initiale réelle uniquement si `p.id === currentPlayerId` (sinon `'?'`) ; libellé affiche le vrai pseudo uniquement pour soi-même, `'Hôte'` pour l'hôte vu par les autres, `'Joueur N'` (index + 1) pour les autres joueurs. La modale d'exclusion (host uniquement) continue d'afficher les vrais pseudos/avatars, nécessaire pour identifier qui exclure.

---

### [x] 2026-07-23 — Ordre des sous-lignes de la carte "Nuit 1" incorrect dans l'historique (history.blade.php)

- **Symptôme :** dans la carte "Nuit 1" de l'historique de fin de partie, le lien Cupidon s'affichait en dernier (après victime des loups, sorcière, tir du chasseur, succession du maire) alors que Cupidon joue en tout premier lors du round 1, avant même la résolution du vote des loups.
- **Cause :** l'ordre d'affichage des sous-lignes d'une carte nuit est déterminé par l'ordre séquentiel des blocs `@if` dans `history.blade.php` (bloc `@elseif($entry['type'] === 'night')`, ~ligne 331) — pas par l'ordre des clés du tableau retourné par `HistoryService::buildTimeline()`, qui n'a aucun effet sur le rendu. Le bloc `cupidon_couple` avait été ajouté en fin de séquence lors de l'implémentation initiale, sans tenir compte de l'ordre réel de déroulement d'une nuit.
- **Fix :** déplacement du bloc `cupidon_couple` en tête de la séquence `night` (avant `killed`/`wolf_no_agreement`), avec `mb-1` (au lieu de `mt-1`) pour un espacement autonome ne dépendant pas du bloc suivant — le reste de l'ordre (`killed` → `witch_heal`/`witch_kill` → `hunter_shot` → `succession`) était déjà correct et n'a pas été touché. Test `test_night1_affiche_le_lien_cupidon_avant_les_autres_evenements` ajouté dans `GameHistoryServiceTest`, vérifiant l'ordre réel du rendu HTML via `assertSeeTextInOrder()` sur la route `game.history` (un test sur le tableau PHP de `buildTimeline()` n'aurait pas suffi : l'ordre d'affichage vient de la vue, pas de la structure de données).

---

### [x] 2026-07-23 — Couple formé par Cupidon absent de l'historique de fin de partie (GameController::history() + HistoryService)

- **Symptôme :** l'écran d'historique (`/history`, accessible uniquement une fois la partie `finished`) n'affichait jamais le couple formé par Cupidon au round 1, alors que tous les rôles y sont déjà révélés.
- **Cause :** `cupidon_link` n'était ni dans le `whereIn()` des types d'actions chargées par `GameController::history()`, ni géré par `HistoryService::buildTimeline()` — omission lors de l'ajout de Cupidon (v1.3), la timeline n'avait jamais été auditée pour ce rôle.
- **Fix :** `cupidon_link` chargé via une requête dédiée sans `anonymized()` (même pattern que `mayor_vote`, les deux identités doivent rester lisibles) et mergé aux autres actions dans `GameController::history()`. `HistoryService::buildTimeline()` regroupe les 2 `GameAction cupidon_link` du round 1 (une par amoureux) en une paire `cupidon_couple` sur l'entrée `night` du round 1. `history.blade.php` affiche cette paire avec l'icône/couleur Cupidon déjà standardisées (💘, `#f472b6`). Rien n'est affiché si Cupidon n'a pas agi (timeout ou rôle non distribué). Tests ajoutés dans `GameHistoryServiceTest` (couple présent round 1, absence sans régression).

---

### [x] 2026-07-23 — Witch/Hunter/Cupidon retombaient sur "Villageois" dans le message de victime de nuit (day.blade.php) + Cupidon absent de /state

- **Symptôme :** le message "C'était un [rôle]" affiché sous la bannière de victime de nuit dans `day.blade.php` (~ligne 101) affichait "Villageois" pour une victime Sorcière, Chasseur ou Cupidon — fausse information en jeu. Par ailleurs, `GameController::state()` (`revealed_role_label`, endpoint `/state`) ne couvrait pas `cupidon` (witch/hunter déjà présents).
- **Cause :** le `match($nightVictim->role)` de `day.blade.php:101` ne couvrait que `werewolf`/`seer` avec `default => 'Villageois'`, introduit dans le commit initial des vues de jeu (`7239050`), avant même l'existence de Sorcière/Chasseur (v1.2) — bug préexistant, pas une régression Cupidon. Les deux autres emplacements de `day.blade.php` (`$playersJson` ligne ~470 et `roleLabels` du listener `player-eliminated` ligne ~662) couvraient déjà les 6 rôles, confirmant l'oubli isolé de cette seule ligne. `GameController::state()` avait été mis à jour pour witch/hunter (v1.2) mais jamais ré-audité lors de l'ajout de Cupidon.
- **Fix :** ajout de `witch`, `hunter`, `cupidon` au `match()` de `day.blade.php:101`. Ajout de `'cupidon' => 'Cupidon'` au `match()` de `GameController::state()` (label court, cohérent avec les entrées sœurs `werewolf`/`seer`/`witch`/`hunter` du même tableau — pas la phrase longue "Cupidon — Innocent." de `night.blade.php`, qui sert un contexte d'affichage différent). Test `ReconnectionTest::test_state_endpoint_retourne_la_liste_des_joueurs` étendu pour couvrir witch/hunter/cupidon (jusqu'ici seul werewolf était testé).

---

### [x] 2026-07-23 — Cupidon absent du résultat d'inspection Voyante (night.blade.php)

- **Symptôme :** la Voyante inspectant un joueur Cupidon voyait "❓" et "Rôle inconnu." au lieu de l'icône/label dédiés, dans l'écran `seer_result` de `night.blade.php`.
- **Cause :** `roleEmoji(role)`/`roleLabel(role)` (introduites par le fix "Voyante affichait Innocent pour Chasseur et Sorcière" du 2026-06-23, avant l'existence de Cupidon) n'avaient jamais été ré-auditées lors de l'ajout de Cupidon aux écrans de rôle (Phase 46).
- **Fix :** `cupidon: '💘'` et `cupidon: 'Cupidon — Innocent.'` ajoutés aux deux objets. Voir DECISIONS.md "Cupidon absent du résultat d'inspection Voyante" pour le détail complet et l'audit des emplacements similaires.

---

### [x] 2026-07-23 — Cupidon absent des rôles configurables côté host (GameSettingsService + waiting-room)

- **Symptôme :** malgré RoleDistributor/config/enums déjà en place depuis les Étapes 1→4 de Cupidon, `POST /game/{id}/settings/roles` avec `{cupidon: 1}` était rejeté (422 "n'est pas configurable"), et la modale ⚙️ Paramètres de la waiting-room n'affichait aucune option Cupidon — deux listes codées en dur limitées à `witch`/`hunter`.
- **Cause :** `GameSettingsService::validateRoleSettings()` (whitelist de clés acceptées) et `roleSettings()` dans `waiting-room.blade.php` (objets `roles`/`labels` du store Alpine) n'avaient jamais été étendus à `cupidon` lors des étapes précédentes — chacune scopée à sa propre couche (schéma, distribution, intégration nuit, win condition, frontend jeu) sans jamais toucher la configuration host.
- **Fix :** `cupidon` ajouté à la whitelist des deux boucles de `validateRoleSettings()`, au store `roleSettings()` (`roles.cupidon`, `labels.cupidon = 'Cupidon'`). Docblock de `GameService::validateRoleSettings()` (délégation) mis à jour en cohérence. Test `test_host_peut_activer_cupidon()` ajouté dans `RoleSettingsTest`. Audit grep `witch.*hunter`/`hunter.*witch` sur `app/` et `resources/` : aucune 3e liste de rôles configurables oubliée (RoleDistributor et GamePolicy déjà corrects). Suite à une demande explicite de l'utilisateur, `config('game.roles.cupidon')` est ensuite passé de `0` à `1` (activé par défaut, parité witch/hunter) — voir DECISIONS.md "Cupidon activé par défaut" pour le détail (annule le choix "désactivé par défaut" de la Phase 40).

---

### [x] 2026-07-23 — Docblock obsolète dans CupidonTurnStarted.php

- **Symptôme :** le docblock de `app/Events/Game/CupidonTurnStarted.php` affirmait encore "Non branché dans PhaseManager à ce stade", laissant croire que le tour de Cupidon était inatteignable depuis le flux de jeu réel.
- **Cause :** commentaire écrit à l'Étape 3 (Cupidon isolé, non branché), jamais mis à jour lors de l'Étape 4 qui a effectivement branché `ProcessCupidonTurn` dans `PhaseManager::startNight()` (Phase 40 du TODO).
- **Fix :** commentaire corrigé pour refléter l'état réel : "Branché dans PhaseManager::startNight() (round === 1, Cupidon distribué)".

---

### [x] 2026-07-22 — Partie bloquée en night (broadcasts MayorElected/NightStarted non protégés dans ProcessMayorElection)

- **Symptôme :** lors d'un incident réseau/Reverb, une exception sur `broadcast(new MayorElected(...))` ou `broadcast(new NightStarted(...))` dans `ProcessMayorElection::handle()` empêchait `ProcessSeerTurn::dispatch()` de s'exécuter — la partie restait bloquée en `status='night'` sans rien pour piloter la suite.
- **Cause :** même cause racine que le bug PlayerJoined ci-dessous (events `ShouldBroadcastNow` synchrones, non protégés par `try/catch`), simplement non traitée pour ce job lors du premier fix.
- **Fix :** `try/catch (\Throwable)` + `Log::warning()` autour de chacun des deux broadcasts dans `ProcessMayorElection::handle()`. Voir DECISIONS.md "Partie bloquée en night — broadcasts MayorElected/NightStarted non protégés dans ProcessMayorElection" pour le détail complet.

---

### [x] 2026-07-22 — Partie bloquée en waiting + 404 role-reveal (broadcast PlayerJoined non protégé + resync() sur mauvais critère)

- **Symptôme :** lors d'un incident réseau/Reverb au remplissage du dernier slot, la partie restait bloquée en `status='waiting'` (le `GamePlayer` était bien créé en base). Côté client, `resync()` redirigeait quand même vers `/role-reveal` dès que `slots_remaining === 0`, menant à un état incohérent (404).
- **Cause :** `broadcast(new PlayerJoined(...))` dans `GameService::joinGame()` (event `ShouldBroadcastNow`, synchrone) n'était pas protégé — une exception à cet endroit empêchait l'appel à `startGame()` juste après. `resync()` dérivait sa redirection de `slots_remaining === 0` en plus de `status !== 'waiting'`, alors que seul `status` reflète l'avancement réel de la partie.
- **Fix :** `try/catch (\Throwable)` + `Log::warning()` autour du broadcast `PlayerJoined` dans `joinGame()` — l'exécution continue vers `startGame()` quel que soit le résultat du broadcast. Suppression de la branche `slots_remaining === 0` dans `resync()` ; seule `status !== 'waiting'` déclenche la redirection. Voir DECISIONS.md "Partie bloquée en waiting + 404 role-reveal" pour le détail complet.

---

### [x] 2026-07-22 — Chasseur Maire tué de nuit : succession déclenchée avant le tir (ProcessNightActions + WitchAction)

- **Symptôme :** un Chasseur-Maire tué de nuit (loups ou poison sorcière) voyait la succession du maire partir immédiatement au lieu d'attendre son tour de tir. Sur le chemin "maire en sursis" de `WitchAction` (sorcière ayant choisi de ne pas sauver le maire visé par les loups), le Chasseur ne tirait même jamais — `hunter_pending` n'était pas créé du tout sur ce chemin.
- **Cause :** `ProcessNightActions::handle()` et `WitchAction::act()` (3 emplacements) déclenchaient `MayorSuccessionStarted`/`ProcessMayorSuccession::dispatch()` sans condition sur `isHunter()`, contrairement au fix déjà en place côté jour (`VoteService::resolveDayVote()`).
- **Fix :** priorité tir > succession rendue mutuellement exclusive dans les 4 sites concernés, `hunter_pending` créé sur le chemin "maire en sursis" de `WitchAction` qui en était dépourvu. Voir DECISIONS.md "Chasseur Maire tué de nuit — succession déclenchée avant le tir" pour le détail complet.

---

### [x] 2026-07-22 — Modale succession maire masquant le panel de tir du Chasseur

- **Symptôme :** un joueur cumulant Chasseur et Maire, mort de nuit (loups ou poison sorcière), ne voyait jamais l'option "éliminer quelqu'un" de son tour de tir — masqué par la modale "Succession du Maire" restée ouverte par-dessus. Diagnostiqué sur `game_id=249`, round 2, joueur `Maba diakhouba`.
- **Cause :** `successionOpen` s'ouvre immédiatement à la mort du maire (`mayor-succession-started`) alors que `hunter-turn-started` arrive bien plus tard (délai `witch_timer + mayor_succession_timer + 5s`) sans jamais fermer `successionOpen` — les deux vues partagent le même `z-index` plein écran.
- **Fix :** ajout de `this.successionOpen = false;` en première instruction des listeners `hunter-turn-started` dans `night.blade.php` (`nightScreen()`) et `day.blade.php` (`dayScreen()`).

---

### [x] 2026-07-14 — Warning dépréciation @dataProvider doc-comment (PhaseGuardTest)

- **Symptôme :** `php artisan test` affichait 3 warnings de dépréciation PHPUnit sur `tests/Unit/Services/PhaseGuardTest.php` — les annotations `@dataProvider` en doc-comment seront supprimées en PHPUnit 12.
- **Cause :** les 3 méthodes de test utilisant un data provider déclaraient celui-ci via `/** @dataProvider nomDeLaMethode */` au lieu de l'attribut PHP natif.
- **Fix :** remplacement des 3 annotations doc-comment par `#[DataProvider('nomDeLaMethode')]` et ajout de `use PHPUnit\Framework\Attributes\DataProvider;` en haut du fichier.

---

### [x] 2026-06-24 — Relation `targetPlayer` inexistante dans `PhaseManager::endNight()`

- **Symptôme :** `ProcessNightEnd` échouait en boucle avec `Call to undefined relationship [targetPlayer]` — partie 140 bloquée en `processing_night` depuis 23:30.
- **Cause :** `endNight()` utilisait `->with('targetPlayer')` et `$killAction->targetPlayer` alors que la relation dans `GameAction` est définie sous le nom `target` (FK `target_player_id`).
- **Fix :** remplacement des trois occurrences de `targetPlayer` par `target` dans `PhaseManager::endNight()`.

---

### [x] 2026-06-24 — Fausse déconnexion loup entre pages (race condition + retry reconnect)

- **Symptôme :** un joueur loup pouvait être marqué `is_inactive = true` et `is_alive = false` par `CheckReconnectionTimeout` alors qu'il était physiquement connecté et en train de jouer (toast "Undu est de retour !" cyclique observé en prod, partie 139 : loup `is_inactive=1` sans aucun `night_vote`).
- **Cause :** deux problèmes combinés — (1) `_navigateTo()` appelait `window.location.href` de façon synchrone après `sessionStorage.setItem`, laissant `beforeunload` lire `null` sur mobile et envoyer `/disconnect` ; (2) `_reconnect()` avalait silencieusement les erreurs réseau sans retry, laissant le token cache non invalidé et `CheckReconnectionTimeout` marquer le joueur mort 30s plus tard.
- **Fix :** `_navigateTo()` encapsule `window.location.href` dans `setTimeout(..., 0)` pour garantir le flush sessionStorage avant `beforeunload`. `_reconnect()` remplace `.catch(() => {})` par un retry exponentiel (`attempt(3)` avec 2s entre chaque tentative). Timer `reconnection` passé de 30s à 45s dans `config/game.php` (valeur par défaut et limites min/max) pour absorber les retards réseau mobile.

---

### [x] 2026-06-24 — Cartes joueurs éliminés quasi illisibles (vue jour)

- **Symptôme :** les cartes des joueurs morts affichaient `opacity: 0.3` + `filter: grayscale(100%)` sur fond `#111827`, rendant le pseudo barré et le rôle révélé pratiquement illisibles, surtout sur mobile.
- **Cause :** le style `.v-dead` combinait opacité globale très faible et filtre niveaux de gris sur toute la carte — approche trop agressive pour un fond sombre. L'animation GSAP à l'élimination reproduisait le même résultat (`opacity: 0.3`, `grayscale(100%)`).
- **Fix :** `.v-dead` remplacé par un fond rouge teinté (`rgba(139,0,0,0.10)`) et une bordure rouge subtile, sans toucher à `opacity` ni `filter` de la carte globale. Opacité de l'avatar réduite à `0.45` isolément. Contraste pseudo et rôle révélé amélioré (`0.4` → `0.6`). Animation GSAP mise à jour pour transitionner vers le fond rouge teinté avec `prefers-reduced-motion` géré.

---

### [x] 2026-06-24 — Chats inaccessibles sur mobile en phase jour (overlay)

- **Symptôme :** sur mobile, le chat général et le chat des fantômes s'affichaient sous la liste des joueurs dans le flux normal du document, obligeant le joueur à scroller vers le bas pendant le vote — rendant les deux zones de chat pratiquement inutilisables en phase jour.
- **Cause :** absence d'overlay mobile : les deux sections de chat étaient positionnées dans le flux HTML, sans `fixed` ni toggle accessible depuis la barre de navigation fixe.
- **Fix :** ajout d'une barre de navigation footer (`@section('footer-nav')`) avec boutons dédiés 💬 et 💀 ; les deux wrappers de chat passent en `fixed bottom-14 z-50` sur mobile quand leur toggle est actif (`chatVisible`/`deadChatVisible`), restent dans le flux sur desktop (`md:static`). Composant `footerDayNav()` léger séparé de `dayScreen()` (scopes Alpine distincts), synchronisé via `window.dispatchEvent('day-chat-state')`. `z-index` de `announcement-overlay` monté de `z-50` à `z-60` pour rester par-dessus les chats overlay.

---

### [x] 2026-06-24 — Rejets de guard silencieux dans ProcessNightActions et ProcessWerewolvesTurn

- **Symptôme :** quand `ProcessNightActions` ou `ProcessWerewolvesTurn` était rejeté par leur guard (statut ou round incorrect), le job retournait sans laisser aucune trace — la nuit se terminait sans victime sans aucune entrée dans les logs. Observé sur la partie SXAKHE (game_id 138, nuit 5) : le loup a voté, aucun effet visible, aucun log.
- **Cause :** les deux jobs avaient un `if (! $game) { return; }` silencieux après la transaction `lockForUpdate()`. Un rejet légitime (job stale) et une vraie anomalie étaient indiscernables sans log.
- **Fix :** remplacement du `return` silencieux par `Log::warning(...)` avec `game_id` et `round` dans les deux jobs. Les rejets légitimes (inter-rounds) et les rejets anormaux sont désormais visibles dans les logs Laravel.

---

### [x] 2026-06-24 — Vote nuit bloqué avec un seul loup (stale job ProcessWerewolvesTurn)

- **Symptôme :** quand un seul loup restant votait, l'interface confirmait le vote mais aucune victime n'était désignée — la nuit se terminait silencieusement sans élimination.
- **Cause :** `ProcessWerewolvesTurn` n'avait pas de paramètre `$round` dans son constructeur, rendant impossible tout guard anti-stale-job inter-rounds. Le job fallback timer (dispatché au départ du tour) et le job immédiat (dispatché par le vote du loup ou par `seerCheck()`) entraient en race condition : le premier à passer la transaction changeait le statut en `processing_night`, le second était rejeté par le guard `whereIn('status', ['night', 'wolves_turn'])` de `ProcessNightActions` — silencieusement, sans victime.
- **Fix :** ajout du paramètre `public readonly int $round` au constructeur de `ProcessWerewolvesTurn` et ajout de `->where('round', $this->round)` dans le `lockForUpdate()`. Mise à jour de tous les appelants : `ProcessSeerTurn` (2 dispatches), `ActionController::seerCheck()` (1 dispatch). `ProcessSeerAutoAction` ne dispatche pas ce job — non modifié.

---

### [x] 2026-06-24 — Nom Google émis dans les payloads broadcast publics

- **Symptôme :** `users.name` (nom Google OAuth) était inclus dans `PlayerEliminated`, `HunterShot` et `RandomElimination`, tous émis sur le canal public `game.{gameId}`, exposant ainsi l'identité réelle de chaque joueur à tous les participants.
- **Cause :** Les trois événements construisaient un affichage "Jean aka Pseudo" côté client — nécessitant le nom Google dans le payload. Cette décision initiale ignorait la contrainte vie privée.
- **Fix :** Suppression de `google_name` et `target_google_name` des trois `broadcastWith()`. Toast dans `handlePlayerEliminated()` réduit à `e.pseudo` uniquement. `users.name` reste en base pour un futur dashboard admin.

---

### [x] 2026-06-24 — Toast "Reconnecté !" affiché sur toute connexion WS initiale

- **Symptôme :** le toast "Reconnecté !" apparaissait à chaque chargement de page (nuit→jour, jour→nuit…) alors que le joueur n'avait jamais été déconnecté.
- **Cause :** `conn.bind('connected', ...)` dans `initWebSocket()` se déclenchait à toute connexion Echo/Reverb, y compris la connexion initiale au chargement — pas uniquement après une vraie coupure réseau.
- **Fix :** flag `_wsEverConnected` posé à `true` au premier `connected` sans afficher de toast ; les connexions suivantes (vraies reconnexions) affichent le toast, sous réserve du guard `sessionStorage.__internalNavigation` déjà utilisé dans les autres handlers.

---

### [x] 2026-06-24 — Chasseur Maire : succession déclenchée avant le tir

- **Symptôme :** quand le Chasseur était aussi Maire et qu'il était éliminé (jour ou nuit), la succession du Maire était déclenchée immédiatement sans attendre que le Chasseur effectue (ou renonce à) son tir.
- **Cause :** dans `VoteService::resolveDayVote()`, le bloc `if ($eliminated->is_mayor)` avait la priorité absolue sur `elseif ($hunterPending = ...)` — si `is_mayor === true`, la branche chasseur était inaccessible. Idem potentiellement dans `ProcessNightEnd` (absence de `$isMayor` passé à `ProcessHunterTurn`).
- **Fix :** inversion de la priorité dans `resolveDayVote()` (hunter_pending d'abord, is_mayor en fallback). `$isMayor` capturé et transmis à `ProcessHunterTurn` (jour et nuit via `ProcessNightEnd`). `ProcessHunterTurn` passe `$isMayor` à `ProcessHunterAutoAction`. `ProcessHunterAutoAction` : si `$isMayor=true`, déclenche `MayorSuccessionStarted` + `ProcessMayorSuccession` après le tir ou le renoncement (guard `hunter_shot` en DB pour éviter une double succession quand `ActionController` l'a déjà déclenché). `ActionController::hunterShoot()` : lit `$isMayor` avant le tir et déclenche la succession si vrai.

---

### [x] 2026-06-24 — Messages sorcière non différenciés au matin

- **Symptôme :** tous les joueurs recevaient un toast générique "La sorcière a agi cette nuit." au matin, sans distinction selon leur rôle (sorcière elle-même, joueur sauvé, joueur empoisonné, autres).
- **Cause :** `DayStarted` ne transportait pas `witch_player_id`, `poisoned_player_id` ni `poisoned_player_pseudo` — `_applyDayStarted()` ne pouvait pas identifier qui était qui. Le toast `witch_kill` dans `handlePlayerEliminated()` était non visible car la page `/night` est détruite par la redirection GSAP avant que le composant toast puisse rendre.
- **Fix :** `DayStarted` enrichi de trois champs. `PhaseManager::endNight()` calcule `$witchPlayerId`, `$poisonedPlayerId` et `$poisonedPlayerPseudo` depuis les `game_actions` du round. `game-state.js::_applyDayStarted()` : logique différenciée via sessionStorage (sorcière, sauvé, empoisonné) et toast direct pour les autres. Bloc `witch_kill` supprimé de `handlePlayerEliminated()` (jamais visible côté client, remplacé par sessionStorage sur /day).

---

### [x] 2026-06-24 — Historique : détail des votes maire manquant (qui a voté pour qui)

- **Symptôme :** l'entrée "Élection du Maire" dans l'historique de fin de partie affichait uniquement les totaux par candidat (`vote_totals`), sans indiquer qui avait voté pour qui.
- **Cause :** `GameController::history()` appliquait `->anonymized()` sur toutes les actions y compris `mayor_vote` — ce scope supprime `player_id`, rendant impossible la construction d'un détail individuel par vote. Depuis le Bug 5, les votes maire sont publics, mais l'anonymisation n'avait pas été ajustée côté historique.
- **Fix :** séparation de la query dans `history()` : `mayor_vote` chargé sans `anonymized()`, autres types avec `anonymized()`. `HistoryService::buildTimeline()` : construction de `vote_details` (liste de `{ voter_pseudo, target_pseudo }`) depuis `$mayorVotes`. `history.blade.php` : affichage sous les totaux, style atténué (opacity 0.35).

---

### [x] 2026-06-23 — @show-toast.window ignoré par Alpine v3 (event name avec tiret)

- **Symptôme :** `window.dispatchEvent(new CustomEvent('show-toast', ...))` ne déclenchait pas `add()` dans `toast.blade.php`, malgré le binding déclaratif `@show-toast.window="add($event.detail)"` présent sur le `<div>`.
- **Cause :** Alpine v3 ne convertit pas correctement les event names contenant des tirets (`show-toast`) en listeners `window.addEventListener` via la syntaxe `@event.window` dans certains contextes de rendu Blade (composant chargé en tant que `<x-toast />`). Le binding est syntaxiquement valide mais le listener n'est jamais enregistré.
- **Fix :** Suppression de `@show-toast.window="add($event.detail)"` du `<div>`. Ajout de `window.addEventListener('show-toast', (e) => this.add(e.detail))` dans `init()` après `window.__toastReady = true` — enregistrement impératif, fiable dans tous les contextes.

---

### [x] 2026-06-23 — Votes maire non affichés en temps réel

- **Symptôme :** pendant l'élection du Maire, les joueurs voyaient uniquement les totaux mis à jour — aucune indication de qui venait de voter pour qui.
- **Cause :** `MayorVoteCast` n'exposait que les totaux agrégés (`votes[]`), sans `voter_pseudo` ni `target_pseudo`. Changement de spec assumé : les votes maire sont désormais publics (auteur visible), contrairement aux votes jour qui restent anonymes.
- **Fix :** `MayorVoteCast` : ajout de `voterPseudo` et `targetPseudo` dans le constructeur et `broadcastWith()`. `VoteController::mayor()` : `$targetPseudo` extrait des totaux retournés par `castMayorVote()` (sans requête DB supplémentaire). `game-state.js::_handleMayorVoteCast()` : toast "👑 X a voté pour Y" (ou "pour lui-même"). Test `test_mayor_vote_cast_payload_ne_contient_pas_player_id` mis à jour pour vérifier la présence de `voter_pseudo`/`target_pseudo` et l'absence de `player_id`.

---

### [x] 2026-06-23 — WinConditionChecker orderBy sur colonne updated_at inexistante

- **Symptôme :** `SQLSTATE[42S22]: Unknown column 'updated_at' in 'ORDER BY'` sur `game_players` lors du calcul de la dernière action (nuit ou jour). Tests `DayPhaseTest > égalité vote jour` et `NightPhaseTest > mayor succession not triggered if victory occurs simultaneously` en échec.
- **Cause :** `WinConditionChecker::buildLastAction()` utilisait `->latest('updated_at')` sur `game_players`, mais le modèle `GamePlayer` a `$timestamps = false` et la migration ne définit pas de colonne `updated_at`.
- **Fix :** remplacement de `->latest('updated_at')` par `->latest('id')` dans `WinConditionChecker.php` ligne ~90. L'`id` auto-increment est un proxy fiable pour l'ordre d'insertion.

---

### [x] 2026-06-23 — Voyante affichait "Innocent" pour Chasseur et Sorcière

- **Symptôme :** quand la Voyante inspectait un Chasseur ou une Sorcière, le résultat affichait "🧑‍🌾 C'est un Innocent. Tu peux lui faire confiance." au lieu du rôle exact.
- **Cause :** `night.blade.php` utilisait un booléen binaire `isWerewolf` pour déterminer l'emoji et le texte affiché — aucune distinction entre les rôles non-loup.
- **Fix :** ajout de `roleEmoji(role)` et `roleLabel(role)` dans `nightScreen()` avec un switch sur les 5 rôles possibles (`werewolf`, `villager`, `seer`, `witch`, `hunter`). Le champ `role` était déjà exposé par `SeerResult::broadcastWith()` et stocké dans `seerResult.role` — aucune modification backend.

---

### [x] 2026-06-23 — Progress bar absente dans role-reveal et mayor-election

- **Symptôme :** aucune barre de progression visible sur les écrans role-reveal et mayor-election.
- **Cause :** conflit Alpine.js / GSAP — le binding `:style` avec une chaîne appelle `el.style.cssText = value` en interne, ce qui écrase l'animation `width` de GSAP à chaque mise à jour réactive (chaque seconde).
- **Fix :** suppression du binding `:style` Alpine sur les éléments de remplissage ; GSAP gère `width` (animation) et `backgroundColor` (transitions de couleur). Barre violet `#reveal-timer-bar` ajoutée dans role-reveal pour `mayor_reveal` ; barre or `#election-timer-fill` corrigée dans mayor-election pour `mayor_election`. Guard `prefers-reduced-motion` ajouté dans les deux vues.

---

### [x] 2026-06-23 — Succession maire non déclenchée si le maire est empoisonné par la sorcière

- **Symptôme :** si la sorcière utilisait son poison sur le maire, aucune succession n'était déclenchée — le maire mourait sans désigner de successeur.
- **Cause :** `WitchAction::kill()` ne vérifiait pas si la victime était le maire (`is_mayor`) avant de la marquer morte ; aucun dispatch de `ProcessMayorSuccession` sur ce chemin.
- **Fix :** `WitchAction::kill()` stocke `$mayorVictim = $target` si `$target->is_mayor`, ce qui déclenche broadcast `MayorSuccessionStarted` + dispatch `ProcessMayorSuccession` hors transaction après la mort de la victime.

---

### [x] 2026-06-23 — Élimination aléatoire absente de l'historique (persistance manquante)

- **Symptôme :** quand 0 votes jour déclenchaient une élimination aléatoire, l'historique indiquait `result: 'no_votes'` avec `eliminated: null` — la victime n'était pas identifiée.
- **Cause :** `VoteService::resolveDayVote()` éliminait le joueur mais ne créait pas de `GameAction random_elimination` ; `GameController::history()` ne l'incluait pas dans son `whereIn` ; `HistoryService::buildTimeline()` n'avait aucune source de données pour ce cas.
- **Fix :** création d'un `GameAction random_elimination` dans la branche 0-votes de `resolveDayVote()` ; `random_elimination` ajouté au `whereIn` dans `history()` ; `buildTimeline()` lit l'action et peuple `$dayEntry['eliminated']`.

---

### [x] 2026-06-23 — ENUM MySQL manquant pour `random_elimination` dans `game_actions.type`

- **Symptôme :** insertion d'une `GameAction` de type `random_elimination` échouait avec `SQLSTATE[01000]: Data truncated for column 'type'` — la valeur n'était pas dans l'ENUM MySQL.
- **Cause :** la migration `2026_06_19_000000_add_night_resolve_to_game_actions_type_enum.php` (dernière migration modifiant cet ENUM) ne listait pas `random_elimination`, qui n'existait pas encore à ce stade (créé dans `fix/add-random-elimination-history`).
- **Fix :** migration `2026_06_23_000000_add_random_elimination_to_game_actions_type_enum.php` ajoutant `random_elimination` à l'ENUM complet des 14 valeurs.

---

### [x] 2026-06-23 — Couronne absente sur le nouveau maire après une succession (day.blade.php)

- **Symptôme :** après une succession du maire, la couronne 👑 n'apparaissait pas sur la carte du nouveau maire dans la liste des joueurs en phase jour.
- **Cause :** `day.blade.php` ne mettait pas à jour le flag `is_mayor` dans sa liste locale `this.players` lors des events `mayor-elected` et `mayor-succession-done`.
- **Fix :** ajout de `window.addEventListener('mayor-elected', ...)` et `window.addEventListener('mayor-succession-done', ...)` dans `dayScreen()` — les deux events remappent `is_mayor` sur le bon joueur.

---

### [x] 2026-06-22 — Succession maire déclenchée avant que la sorcière ait pu agir

- **Symptôme :** si les loups tuaient le maire et que la sorcière avait sa potion de soin, `ProcessNightActions` dispatchait `MayorSuccessionStarted` avant le tour de la sorcière — la succession partait même si la sorcière sauvait ensuite le maire.
- **Cause :** le bloc maire dans `ProcessNightActions` n'avait pas de guard symétrique au guard sorcière (`$victimIsWitchWithHeal`).
- **Fix :** `$witch` résolu avant le bloc victime ; flag `$victimIsMayorWithWitchAvailable` (maire + sorcière avec soin) ; mort + succession différées. `WitchAction` : dans `kill` et `pass`, marque le maire mort et déclenche la succession hors transaction.

---

### [x] 2026-06-22 — Élimination aléatoire absente de l'historique côté client

- **Symptôme :** quand `VoteService::resolveDayVote()` éliminait un joueur par tirage au sort (0 votes), aucun toast et aucune entrée dans la liste des joueurs éliminés n'apparaissaient.
- **Cause :** `RandomElimination::broadcastWith()` n'exposait pas `role` ni `google_name` ; aucun listener `.random.elimination` dans `game-state.js`.
- **Fix :** `broadcastWith()` enrichi (`role`, `google_name`) ; listener `.random.elimination` ajouté dans `initWebSocket()` — réutilise `handlePlayerEliminated` + dispatche `CustomEvent('random-elimination')`.

---

### [x] 2026-06-22 — Sorcière ne pouvait pas se sauver elle-même

- **Symptôme :** quand les loups ciblaient la sorcière, elle était marquée morte avant de voir son panel, et l'action `heal` retournait 403 si elle tentait de s'auto-sauver.
- **Cause :** `ProcessNightActions` marquait toutes les victimes mortes immédiatement ; `WitchAction` avait un guard `$victim->id === $witch->id → abort(403)`.
- **Fix :** `ProcessNightActions` détecte si la victime est la sorcière avec soin disponible (`victimIsWitchWithHeal`) et reporte la mort ; `WitchAction` supprime le guard, marque la sorcière morte dans les actions `pass` et `kill` si elle était la victime des loups, et broadcast `PlayerEliminated` hors transaction.

---

### [x] 2026-06-22 — Toast empoisonnement générique n'indiquait pas la cible

- **Symptôme :** le toast "🧙 La sorcière a agi cette nuit." ne révélait pas l'identité de la cible du poison.
- **Cause :** `WitchActedPublic` avait un payload intentionnellement vide.
- **Fix :** `WitchActedPublic` accepte un `?string $targetPseudo` (fourni uniquement pour `kill`) ; le listener JS affiche "La sorcière a empoisonné [pseudo]" si présent.

---

### [x] 2026-06-22 — Toasts d'élimination affichaient seulement le pseudo sans nom Google

- **Symptôme :** les toasts "💀 Pseudo a été éliminé — Rôle" ne montraient pas le vrai nom du joueur.
- **Cause :** `PlayerEliminated` ne transmettait pas le champ `google_name` ; `HunterShot` ne transmettait pas `target_google_name`.
- **Fix :** les deux events ajoutent `user?->name` dans leur payload ; le JS construit "Jean aka Pseudo était le Rôle" / "Le Chasseur a tué Jean aka Pseudo".

---

### [x] 2026-06-20 — playerAvatarColor() dupliquée dans day.blade.php et waiting-room.blade.php

- **Symptôme :** la même fonction utilitaire JS était définie en double dans deux vues Blade avec un commentaire TODO pointant vers `config/game_ui.php`.
- **Cause :** la centralisation était reportée — les vues ne pouvant pas être des modules ES6, aucun import commun n'était en place.
- **Fix :** extraction dans `resources/js/player-avatar.js` (export nommé avec palette fallback), exposition sur `window.playerAvatarColor` dans `app.js` avant `Alpine.start()`, suppression des deux définitions locales et leurs commentaires TODO.

---

### [x] 2026-07-22 — playerAvatarColor() encore dupliquée en PHP dans mayor-election.blade.php

- **Symptôme :** le fix du 2026-06-20 n'avait couvert que `day.blade.php` et `waiting-room.blade.php` (usage Alpine `:style`). `mayor-election.blade.php` recalculait la couleur côté serveur avec sa propre copie du tableau `$avatarColors` dans un bloc `@php`, avec le même commentaire TODO resté non résolu.
- **Cause :** la vue calcule l'avatar dans une boucle `@foreach` PHP plutôt que via `x-for` Alpine, donc le pattern `:style="playerAvatarColor(p.id)"` des deux autres vues n'était pas directement copiable.
- **Fix :** remplacement du bloc `@php` local par un attribut conditionnel — `style` PHP statique pour l'avatar du joueur courant (couleur de rôle, logique inchangée), `:style` Alpine appelant `playerAvatarColor({{ $candidate->id }})` (fonction globale exposée par `app.js`) pour les autres candidats. Suppression du tableau `$avatarColors` dupliqué et du commentaire TODO. Équivalence vérifiée palette PHP vs JS pour les index 0–11 (sortie identique) + `php artisan test` 202/202 verts.

---

### [x] 2026-06-20 — Double abonnement Echo sur mayor-election.blade.php et role-reveal.blade.php

- **Symptôme :** `mayor-election.blade.php` et `role-reveal.blade.php` ouvraient un second `window.Echo.channel()` sur `game.{gameId}` en parallèle du store `game-state.js`, provoquant un double traitement de chaque event Reverb — dont une double logique de redirection concurrente vers `/night` (un `setTimeout` brut depuis la vue, une animation GSAP coordonnée depuis `game-state.js`).
- **Cause :** `_handleMayorVoteCast`, `handleMayorElected` et `_handleMayorElectionStarted` dans `game-state.js` ne dispatchaient pas de `CustomEvent` window — les vues ne pouvaient pas s'y brancher et s'abonnaient directement à Echo. `.player.ready` n'était pas géré du tout dans `game-state.js`.
- **Fix :** ajout de `window.dispatchEvent(new CustomEvent(...))` dans les trois handlers, ajout de `.listen('.player.ready', e => this._handlePlayerReady(e))` sur le canal public, suppression des blocs `window.Echo.channel()` dans les deux vues, remplacement par `window.addEventListener('mayor-vote-cast' | 'mayor-elected' | 'mayor-election-started' | 'player-ready', ...)`. Guard `_initialized` ajouté dans les deux `init()`. Redirection vers `/night` sur `.night.started` supprimée de `mayor-election.blade.php` sans remplacement — déjà gérée globalement par `game-state.js::handleNightStarted`.

---

### [x] 2026-06-20 — /disconnect et /reconnect non throttlés (spam d'écritures DB)

- **Symptôme :** les endpoints `/quit`, `/disconnect` et `/reconnect` étaient hors de tout groupe `throttle`, permettant un spam sans limite — notamment sur `/reconnect` qui déclenche des écritures DB et broadcasts `PlayerReconnected`.
- **Cause :** les trois routes étaient déclarées dans le groupe `auth` mais en dehors du groupe `throttle:60,1` réservé aux actions de gameplay.
- **Fix :** déplacement des trois routes dans un nouveau sous-groupe `throttle:30,1` dédié dans `routes/web.php`, sans modifier aucun nom de route ni comportement applicatif.

---

### [x] 2026-06-20 — DayVoteRequest ne validait pas que la cible est vivante et dans la partie

- **Symptôme :** voter pour un joueur mort ou appartenant à une autre partie retournait une 404/500 depuis `firstOrFail()` dans `VoteService::castDayVote()` au lieu d'un 422 propre avec message d'erreur de validation.
- **Cause :** `DayVoteRequest` ne validait que `required|integer` — aucune vérification d'existence en base, de game_id, ni de is_alive. La validation complète était reportée au Service.
- **Fix :** ajout de `Rule::exists('game_players', 'id')->where('game_id', ...)->where('is_alive', true)` et `Rule::notIn($selfId)` dans `DayVoteRequest::rules()`, avec `messages()` en français. Même pattern que `HunterShootRequest` et `SeerCheckRequest`. `MayorSuccessionRequest::authorize()` renforcé pour vérifier que le demandeur est bien le maire éliminé (is_mayor && !is_alive).

---

### [x] 2026-06-20 — hunter_pending créé hors transaction dans witchAct('kill')

- **Symptôme :** si le process PHP crashait entre le commit de la transaction `witch_kill` et le `GameAction::create('hunter_pending')` hors transaction, le chasseur empoisonné par la sorcière perdait silencieusement son tour de tir — aucune trace en base, aucune erreur visible.
- **Cause :** la création du `GameAction hunter_pending` était positionnée après la fermeture du `DB::transaction()` contenant `witch_kill`, hors de toute protection atomique.
- **Fix :** création de `hunter_pending` déplacée à l'intérieur du `DB::transaction()`, dans la branche `elseif ($action === 'kill')`, juste après la création de `witch_kill` — les deux GameAction sont désormais atomiques.

---

### [x] 2026-06-19 — Victime loups et sorcière potentiellement différentes (égalité vote)

- **Symptôme :** En cas d'égalité parfaite entre deux cibles du vote nocturne, `ProcessNightActions` et `ProcessWitchTurn` appelaient chacun `resolveNightVote()` indépendamment — deux tirages aléatoires pouvaient désigner deux victimes différentes.
- **Cause :** Deux appels à une méthode non-déterministe (`inRandomOrder()`) sans persistance du résultat intermédiaire.
- **Fix :** `ProcessNightActions` persiste la victime résolue dans un `GameAction night_resolve` (pattern `hunter_pending`). `ProcessWitchTurn` et `witchAct(heal)` lisent ce `night_resolve` via `VoteService::resolveNightVoteFromAction()`.

---

### [x] 2026-06-19 — Guard sans lock dans ProcessWitchAutoAction

- **Symptôme :** double `witch_pass` possible en théorie si deux exécutions concurrentes du job passaient simultanément le guard anti-doublon.
- **Cause :** la vérification `exists()` sur les actions sorcières était effectuée hors transaction, sans `lockForUpdate()`.
- **Fix :** guard + création enveloppés dans `DB::transaction()` avec `lockForUpdate()` ; `ProcessNightEnd::dispatch()->delay(0)` reste hors transaction (RISK_GUARDS Guard #5).

---

### [x] 2026-06-19 — Broadcasts dans transactions lockForUpdate + race conditions

- **Symptôme :** (1) Broadcasts (`PlayerJoined`, `GameStarted`, `PlayerExcluded`, `GameFinished`, `PlayerReady`, `MayorElectionStarted`) émis à l'intérieur de `DB::transaction()` + `lockForUpdate()` dans `GameService`, violant la règle de non side-effect en transaction. (2) `hunter_pending` lu + supprimé sans verrou dans `ProcessNightEnd` — deux jobs concurrents pouvaient déclencher deux `ProcessHunterTurn`. (3) `ProcessSeerTurn` sans guard sur le round — un job stale d'une nuit précédente pouvait s'exécuter sur la nuit suivante.
- **Cause :** Manque d'isolation des side-effects réseau (WebSocket, notifications) hors des transactions DB ; lecture non atomique de `hunter_pending` ; absence du paramètre `$round` dans `ProcessSeerTurn`.
- **Fix :** (1) Les 5 méthodes concernées de `GameService` retournent maintenant un tableau de données depuis la transaction, et broadcastent après. (2) `ProcessNightEnd` lit et supprime `hunter_pending` dans une transaction + `lockForUpdate()` atomique ; dispatche `ProcessHunterTurn::delay(0)` après. (3) `ProcessSeerTurn` reçoit un second paramètre `$round` et vérifie `$game->round !== $this->round` en entrée de `handle()`.

---

### [x] 2026-06-17 — Bandeau "Tu as été éliminé" absent au rechargement de page

- **Symptôme :** un joueur mort qui recharge `/day` ou `/night` ne voit pas le bandeau d'élimination.
- **Cause :** `showDeathBanner` initialisé depuis `sessionStorage`, vidé lors de la navigation précédente. `MY_IS_ALIVE` est `false` côté Blade mais `init()` ne le vérifiait pas directement.
- **Fix :** ajout de `if (!MY_IS_ALIVE) { this.showDeathBanner = true; }` au début de `init()` dans `day.blade.php` et `night.blade.php`.

---

### [x] 2026-06-17 — Chat loups absent dès la nuit 2 (gsap.from conflit x-transition dans _checkWolfChatAuto)

- **Symptôme :** chat loups visible nuit 1, absent nuit 2 et suivantes, même manuellement.
- **Cause :** `gsap.from(this.$refs.wolfChatPanel, ...)` dans `_checkWolfChatAuto()` conflictuait avec `x-transition` du div — le panneau s'affichait comme un espace vide. Le `clearInterval` avant recréation de `_wolfTimerInterval` était déjà en place et ne causait pas de régression.
- **Fix :** suppression du bloc `gsap.from()` dans `_checkWolfChatAuto()` ; `x-transition` sur le div gère seul l'animation d'entrée.

---

### [x] 2026-06-17 — Chat loups auto-ouverture basée sur timer résiduel au lieu du temps écoulé

- **Symptôme :** le chat loups s'ouvrait trop tôt (dès que `wolfTimerSeconds <= 75`) au lieu d'attendre 10s après le début du tour.
- **Cause :** `_checkWolfChatAuto()` comparait le temps restant au lieu du temps écoulé depuis le début du tour ; l'ouverture dépendait donc de la valeur de `WOLVES_TIMER` et non d'une durée fixe de 10s.
- **Fix :** remplacement de `wolfTimerSeconds <= 75` par `elapsed >= 10` (avec `elapsed = WOLVES_TIMER - wolfTimerSeconds`). Ajout de la garde `wolfTimerSeconds > 15` pour éviter le chevauchement avec l'auto-fermeture quand le timer est court.

---

### [x] 2026-06-17 — Canal loups jamais souscrit si myRole null au moment de initWebSocket

- **Symptôme :** les messages envoyés par les loups n'étaient jamais reçus par les autres loups (canal `game.{id}.werewolves` non souscrit côté client).
- **Cause :** `initWebSocket()` évaluait `this.isWerewolf` (getter sur `this.myRole`) pour décider de souscrire au canal loups. Si `_loadState()` échouait silencieusement, `this.myRole` restait `null`, `isWerewolf` valait `false`, et le guard `_wsInitialized` bloquait toute nouvelle tentative de souscription.
- **Fix :** au début de `initWebSocket()`, fallback `if (!this.myRole) { this.myRole = window.MY_ROLE ?? null; }`. Au moment de la décision de souscription, calcul d'`effectiveRole = this.myRole ?? window.MY_ROLE ?? null` et `isWolfEffective` pour remplacer le getter `isWerewolf`.

---

### [x] 2026-06-17 — Sorcière et chasseur jamais distribués sans settings hôte explicites

- **Symptôme :** dans une partie créée sans ouvrir la modale paramètres, sorcière et chasseur n'étaient jamais inclus dans la distribution, quelles que soient les valeurs de `config/game.php`.
- **Cause :** `RoleDistributor::getRoleConfig()` ne lisait `config('game.roles.witch/hunter')` comme fallback que pour `seer` et `werewolf`. Pour witch/hunter, le code ne lisait que `$overrides['witch']` — si `$game->settings` est `null` (aucun paramètre hôte), `$overrides = []` et la condition `($overrides['witch'] ?? 0) > 0` est toujours false.
- **Fix :** `$witchAmount = $overrides['witch'] ?? config('game.roles.witch', 0)` — même pattern que seer/werewolf, override prioritaire sinon fallback config.

---

### [x] 2026-06-17 — Chat loups muet pendant wolves_turn (PhaseGuard trop restrictif)

- **Symptôme :** un loup envoyait un message pendant son tour (phase nuit), l'input se vidait mais le message n'apparaissait jamais dans le fil des autres loups. Le message était bien enregistré en base.
- **Cause :** `PhaseGuard::canChatWolves()` ne retournait `true` que pour `status === 'night'`. Or `ProcessWerewolvesTurn` transite le statut de `night` vers `wolves_turn` avant d'émettre `WerewolvesTurnStarted`. Pendant tout le tour des loups, le statut est `wolves_turn` — `canChatWolves` retournait `false` → abort 409. Le client (`sendWolfChat`) ne vérifiait pas le code HTTP de la réponse fetch : l'input était déjà vidé avant l'appel, rendant l'échec invisible.
- **Fix :** `PhaseGuard::canChatWolves()` étendu à `in_array($game->status, ['night', 'wolves_turn'])`. Tests ajoutés : `test_message_loup_broadcasté_pendant_wolves_turn` (ChatTest) et `test_can_chat_wolves_retourne_true_pour_night_et_wolves_turn` / `test_can_chat_wolves_retourne_false_hors_phase_loups` (PhaseGuardTest).

---

### [x] 2026-06-17 — Logique métier dupliquée dans VoteController (_checkAll*)

- **Symptôme :** `VoteController` contenait trois méthodes privées (`_checkAllMayorVotesCast`, `_checkAllDayVotesCast`, `_checkAllNightVotesCast`) contenant des requêtes DB et des dispatches de jobs — violation de la règle CLAUDE.md "Controllers : valider request + appeler Service, rien d'autre". Les méthodes day et night étaient redondantes avec `VoteService::castDayVote()` / `castNightVote()` qui gèrent déjà ce cas.
- **Cause :** extraction partielle lors des tâches précédentes : la logique de résolution anticipée (tous votants détectés) avait été déplacée dans VoteService pour night et day, mais pas pour mayor. La méthode Controller n'avait jamais été nettoyée.
- **Fix :** logique "tous joueurs vivants ont voté → dispatch ProcessMayorElection" déplacée dans `VoteService::castMayorVote()` (même pattern que `castDayVote()`/`castNightVote()`). Trois méthodes privées et trois imports inutiles (`ProcessDayVote`, `ProcessMayorElection`, `ProcessNightActions`, `GameAction`) supprimés de VoteController.

---

### [x] 2026-06-16 — Cache volatile pour hunter_pending remplacé par GameAction

- **Symptôme :** le chasseur perdait silencieusement son pouvoir si Redis redémarrait entre ProcessNightActions et ProcessNightEnd.
- **Cause :** mécanisme reposant sur `Cache::put("hunter_must_shoot_...")` volatile au lieu de persistance DB.
- **Fix :** remplacement de `Cache::put/pull` par `GameAction` de type `hunter_pending` dans ProcessNightActions, ProcessNightEnd, GameService::witchAct() et VoteService::resolveDayVote(). L'action est supprimée après consommation.

---

### [x] 2026-06-16 — Double toast lors de la succession du maire

- **Symptôme :** à chaque `MayorSuccessionDone`, deux toasts quasi identiques s'affichaient : "👑 X est élu Maire" (issu de l'appel interne à `handleMayorElected()`) puis "👑 X est le nouveau Maire".
- **Cause :** `handleMayorSuccessionDone()` appelait `handleMayorElected()` pour réutiliser la mise à jour DOM, entraînant l'émission du premier toast par `handleMayorElected()`.
- **Fix :** extraction de la logique de mise à jour DOM (players array + badges couronne) dans une méthode privée `_updateMayorBadges(playerId)`. `handleMayorElected()` et `handleMayorSuccessionDone()` appellent chacun directement `_updateMayorBadges()` — seul le toast propre à chaque cas est émis.

---

### [x] 2026-06-16 — Guards double action sans index sur player_id et type

- **Symptôme :** les guards de double action (seer_check, wolves_vote, witch_act, hunter_shot…) scannaient toutes les lignes du round sans index sur `player_id` et `type`.
- **Cause :** l'index existant `(game_id, round, phase)` ne couvrait pas les colonnes `player_id` et `type` utilisées dans les requêtes de guard exécutées dans des transactions `lockForUpdate`.
- **Fix :** ajout d'index composite `idx_game_actions_player_type_round` sur `(game_id, player_id, type, round)`.

---

### [x] 2026-06-16 — Endpoints d'action sans rate limiting (saturation queue)

- **Symptôme :** un joueur malveillant pouvait envoyer des centaines de requêtes par seconde sur les endpoints de vote, de rôle et de chat, saturant la queue Laravel avec des transactions DB et des broadcasts WebSocket.
- **Cause :** aucun middleware `throttle` sur les 8 routes d'action POST dans `routes/web.php`.
- **Fix :** sous-groupe `Route::middleware('throttle:60,1')` regroupant les 8 endpoints concernés (vote/mayor, vote/day, vote/night, seer/check, witch/act, hunter/shoot, mayor/succession, chat). Les routes GET et les routes de navigation restent sans throttle additionnel.

---

### [x] 2026-06-16 — Appels config('game.timers.*') résiduels hors TimerCalculator

- **Symptôme :** si un host configurait un timer (ex. `mayor_succession=20s`), certains chemins (VoteService, ProcessNightActions, GameService, WaitForReadyPlayers, ProcessMayorElection, PhaseManager) utilisaient toujours la valeur `config()` hardcodée au lieu de la valeur personnalisée.
- **Cause :** 9 occurrences de `config('game.timers.*')` subsistaient en dehors de `TimerCalculator.php`, en violation de la règle CLAUDE.md.
- **Fix :** remplacement par `$game->timer('...')` / `$locked->timer('...')` / `$player->game->timer('...')` dans tous les fichiers concernés. Seul `config('game.timers.limits')` dans `validateTimerSettings()` conservé (accès au tableau de validation, pas une valeur de timer).

---

### [x] 2026-06-16 — redirectToCurrentPhase() ignorait processing_day et processing_wolves

- **Symptôme :** un joueur rafraîchissant la page pendant la résolution d'un vote (statuts `processing_day` ou `processing_wolves`) était redirigé vers `game.role-reveal` au lieu de `game.day` / `game.night`.
- **Cause :** le `match` de `redirectToCurrentPhase()` ne couvrait pas `processing_day` et `processing_wolves` ; ils tombaient dans le `default`.
- **Fix :** regroupement de `['day', 'processing_day']` → `game.day` et `['night', 'wolves_turn', 'processing_night', 'processing_wolves']` → `game.night`.

---

### [x] 2026-06-16 — Succession maire durait 5s au lieu de 15s (hosts sans timers custom)

- **Symptôme :** succession du maire durait 5s au lieu de 15s pour les hosts sans timers personnalisés.
- **Cause :** `config/game.php` avait `mayor_succession = 5` au lieu de 15 ; `TimerCalculator::FIXED` définissait bien 15 mais n'était plus la source de lecture depuis l'Étape 3 — le fallback `config()` s'appliquait donc avec la mauvaise valeur.
- **Fix :** `config/game.php → mayor_succession = 15`.

---

### [x] 2026-06-15 — Nuit entière skippée quand les loups sont en égalité

- **Symptôme :** quand les loups ne désignaient aucune victime (égalité),
  les tours voyante, loups et sorcière étaient silencieusement skippés.
  La partie passait directement au jour suivant.
- **Cause :** ProcessWitchTurn dispatchait ProcessNightEnd::delay(0) dans
  ses guards "skip silencieux" (potions épuisées, ou pas de victime + pas
  de poison disponible). Ce ProcessNightEnd anticipé s'exécutait
  immédiatement, voyait status='processing_night', passait le guard et
  appelait endNight() avant même que les timers voyante et loups (8s +
  seer_timer + wolves_timer) ne soient écoulés.
- **Fix :** suppression des dispatches ProcessNightEnd::delay(0) dans les
  guards de skip de ProcessWitchTurn. Ces guards font maintenant un simple
  return. Le ProcessNightEnd dispatché par ProcessNightActions (avec un
  délai calculé dynamiquement = witch_timer + mayor_succession + 5s) gère
  la fin de nuit dans tous les cas.
- **Fix associé :** le délai du ProcessNightEnd dispatché par ProcessNightActions
  était fixe (mayor_succession + 5s ≈ 20s) et ne couvrait pas witch_timer
  (jusqu'à 60s configurable). Remplacé par witch_timer + mayor_succession + 5s.
- **Fichiers modifiés :** app/Jobs/ProcessWitchTurn.php,
  app/Jobs/ProcessNightActions.php, tests/Feature/Game/WitchTest.php.

---

### [x] 2026-06-14 — Toasts dispatchés tôt dans le cycle de vie Alpine perdus

- **Symptôme :** certains toasts (ex. déclenchés très tôt après le chargement de la page) n'apparaissaient jamais.
- **Cause :** `_dispatchToast()` dispatche `show-toast` sur `window` avant que le composant `toast.blade.php` ait fini son `init()` Alpine — personne n'écoute encore l'event, il est perdu.
- **Fix :** buffer global `window.__toastBuffer` rempli par `_dispatchToast()` tant que `window.__toastReady` n'est pas vrai ; `toast.blade.php::init()` consomme ce buffer et passe `window.__toastReady = true`. Retry via `requestAnimationFrame` côté `_dispatchToast()`.

---

### [x] 2026-06-14 — Canal loups jamais souscrit (myRole non renseigné avant initWebSocket)

- **Symptôme :** le chat loups et les events du canal `game.{gameId}.werewolves`
  (votes de la meute, démarrage du tour loups) ne s'affichaient jamais pour
  certains loups.
- **Cause :** `isWerewolf` (getter sur `this.myRole`) pouvait valoir `false` au
  moment de `initWebSocket()` si `_loadState()` échouait silencieusement et que
  `window.MY_ROLE` n'était pas encore disponible au moment de l'appel.
- **Fix :** ajout d'un fallback `if (!this.myRole) this.myRole = window.MY_ROLE ?? null;`
  dans `init()`, juste avant `this.initWebSocket()`, garantissant que `myRole`
  est renseigné avant l'évaluation de `isWerewolf`.

---

### [x] 2026-06-14 — Sorcière ne pouvait pas utiliser son poison si égalité loups

- **Symptôme :** les nuits où les loups étaient en égalité (pas de victime),
  la sorcière ne voyait aucune interface et ne pouvait pas utiliser son poison.
  ProcessNightEnd s'exécutait directement.
- **Cause :** Guard #3 dans ProcessWitchTurn skippait le tour entier si
  resolveNightVote() retournait null, sans vérifier si des potions étaient
  encore disponibles.
- **Fix :** le tour n'est skippé que si les deux potions sont épuisées,
  ou si la seule potion disponible est le soin (inutilisable sans victime).
  WitchTurnStarted accepte victim:null et affiche un message adapté côté client.

---

### [x] 2026-06-14 — Chasseur ne pouvait pas exercer son pouvoir si éliminé le jour

- **Symptôme :** quand le chasseur était éliminé par vote jour, aucune interface
  ne s'affichait — ProcessHunterAutoAction s'exécutait sans que le joueur ait pu tirer.
- **Cause :** day.blade.php n'écoutait pas l'event hunter-turn-started dispatché
  par game-state.js sur window. Seul night.blade.php gérait cet event.
- **Fix :** ajout d'une modale chasseur dans day.blade.php déclenchée par
  window.addEventListener('hunter-turn-started'), avec liste des cibles vivantes,
  timer visuel et appel POST /hunter/shoot.

---

### [x] 2026-06-14 — Éliminations silencieuses pour les autres joueurs

- **Symptôme :** quand un joueur était éliminé, les autres joueurs ne voyaient
  aucune annonce visuelle — seule la liste des joueurs se mettait à jour silencieusement.
- **Cause :** handlePlayerEliminated() dans game-state.js ne dispatchait pas de toast.
- **Fix :** ajout d'un _dispatchToast() dans handlePlayerEliminated() avec le pseudo
  et le rôle du joueur éliminé, visible par tous les joueurs connectés.

---

### [x] 2026-06-14 — Timers UX trop courts (résultat maire, modale succession)

- **Symptôme :** le résultat de l'élection du maire disparaissait après 4s,
  la modale succession se fermait après 2.5s — trop rapide pour être lu confortablement.
- **Cause :** valeurs setTimeout codées en dur trop basses.
- **Fix :** résultat maire → 6000ms, fermeture modale succession → 5000ms.

---

### [x] 2026-06-14 — Messages de chat en doublon : cause racine (double abonnement Echo même canal)

- **Symptôme :** chaque message envoyé dans le chat général (jour) apparaissait deux fois.
- **Cause :** day.blade.php appelait window.Echo.channel(`game.${GAME_ID}`) pour écouter
  .day.vote.cast, en plus de game-state.js qui s'abonnait déjà au même canal. Laravel Echo
  réutilise la souscription Pusher/Reverb existante et déclenche tous les listeners deux fois,
  y compris .chat.message.sent. Un premier fix (branche fix/chat-double-messages) avait supprimé
  un listener .chat.message.sent dupliqué mais n'avait pas identifié ce second abonnement Echo.
- **Fix :** suppression de window.Echo.channel() dans day.blade.php. day-vote-cast est désormais
  dispatché sur window par game-state.js (_handleDayVoteCast) et écouté via
  window.addEventListener dans day.blade.php — cohérent avec le pattern chat-message,
  player-eliminated, etc.

---

### [x] 2026-06-14 — Fix doublons chat cause racine : vérification finale (aucune régression)

- **Symptôme :** N/A — vérification de non-régression du fix précédent.
- **Cause :** N/A.
- **Fix :** Vérifié que day.blade.php n'a aucun appel window.Echo.channel()/window.Echo.private(),
  écoute bien .day.vote.cast via window.addEventListener('day-vote-cast', ...) et les messages
  chat via window.addEventListener('chat-message', ...). Vérifié que game-state.js dispatche
  bien window.dispatchEvent(new CustomEvent('day-vote-cast', { detail: e })) dans
  _handleDayVoteCast() et window.dispatchEvent(new CustomEvent('chat-message', { detail: e }))
  dans _handleChatMessage(). Code conforme, aucune modification nécessaire.

---

### [x] 2026-06-14 — backdrop-filter blur sur la modale succession rendu flou/dégradé

- **Symptôme :** la modale "Succession du Maire" s'affichait avec un rendu visuellement flou/dégradé sur certains navigateurs.
- **Cause :** `backdrop-filter: blur(4px)` sur l'overlay de la modale succession dans `day.blade.php` et `night.blade.php`.
- **Fix :** suppression du `backdrop-filter`, opacité de l'overlay augmentée de 0.65 à 0.82 pour compenser visuellement.

---

### [x] 2026-06-14 — Messages de chat en doublon (canal général et canal loups)

- **Symptôme :** chaque message envoyé dans le chat général (jour) ou le canal loups (nuit) apparaissait deux fois.
- **Cause :** `day.blade.php` s'abonnait en plus à `.chat.message.sent` sur `game.${GAME_ID}` alors que `game-state.js` traite déjà cet event via `_handleChatMessage()`.
- **Fix :** suppression du listener Echo direct dans `day.blade.php` ; `_handleChatMessage()` dispatch désormais `window.dispatchEvent('chat-message')`, écouté par `day.blade.php`. Vérifié `night.blade.php` : aucun abonnement Echo dupliqué sur le canal loups, déjà conforme.

---

### [x] 2026-06-14 — welcome.blade.php renommé en home.blade.php, landing.blade.php supprimé

- welcome.blade.php était la vue active (route / → view('welcome')) mais
  landing.blade.php existait en parallèle sans être référencé par aucune route.
- Fix : renommage welcome → home, mise à jour de la route, suppression de landing.

---

### [x] 2026-06-14 — Timers erronés broadcastés aux clients (Events + night.blade.php)

- **Symptôme :** si le host configure des timers personnalisés (ex. seer=15s),
  le client affiche une barre de progression basée sur la valeur par défaut
  (30s) pendant que le vrai timer serveur est 15s — désynchronisation visible.
- **Cause :** MayorElectionStarted, MayorSuccessionStarted, SeerTurnStarted,
  WerewolvesTurnStarted utilisaient config('game.timers.x') directement dans
  broadcastWith() au lieu de $game->timer('x'). night.blade.php accédait aussi
  à $game->settings['timers']['x'] directement.
- **Fix :** remplacement par $game->timer('x') dans les 4 Events et dans
  night.blade.php — conforme à la règle CLAUDE.md et à TimerCalculator.

---

### [x] 2026-06-14 — Guard $alreadyDone bloquait la succession de jour après une succession de nuit au même round

- **Symptôme :** quand le maire mourait la nuit et son successeur était éliminé
  le jour du même round, la partie restait bloquée en processing_day. Aucun
  failed_job, aucune erreur — ProcessMayorSuccession retournait silencieusement null.
- **Cause :** le guard $alreadyDone cherchait mayor_succession par (game_id, round)
  sans distinguer la phase. L'action de nuit bloquait l'action de jour au même round.
- **Fix :** ajout de ->where('phase', $phaseToStart) dans le guard — une succession
  de nuit et une succession de jour au même round sont désormais indépendantes.

---

### [x] 2026-06-14 — Succession maire : is_mayor non retiré à l'ancien maire

- **Symptôme :** après une succession de nuit, l'ancien maire (mort) gardait
  is_mayor = true. Le successeur pouvait ne pas recevoir is_mayor = true.
  Quand le successeur était éliminé le jour, resolveDayVote() ne détectait pas
  qu'il était maire → pas de nouvelle succession → partie bloquée en processing_day.
- **Cause :** ProcessMayorSuccession::handle() n'appelait pas
  $locked->players()->update(['is_mayor' => false]) avant d'assigner le flag
  au successeur.
- **Fix :** reset global is_mayor = false sur tous les joueurs de la partie
  avant chaque assignation de nouveau maire, dans la transaction DB.

---

### [x] 2026-06-13 — Votes de la meute illisibles (cible non affichée)

- **Symptôme :** dans la section "Votes de la meute" du canal loups, seul un ✓/? indiquait si un loup avait voté, sans préciser pour qui.
- **Cause :** `VoteService::getNightVoteState()` ne renvoyait que `has_voted`, sans `target_player_id`/`target_pseudo`.
- **Fix :** `getNightVoteState()` enrichit chaque entrée avec `target_player_id` et `target_pseudo` ; `night.blade.php` affiche "Loup → Cible" ou "n'a pas encore voté".

---

### [x] 2026-06-13 — Chat village indisponible pendant la résolution du vote jour

- **Symptôme :** les joueurs ne pouvaient plus écrire dans le chat général pendant le statut `processing_day` (entre la fin du vote et le démarrage de la nuit/jour suivant).
- **Cause :** `ChatService::sendMessage()` n'autorisait le canal `general` que pour `['electing_mayor', 'day']`.
- **Fix :** ajout de `'processing_day'` à la liste des statuts autorisés pour le canal `general`.

---

### [x] 2026-06-11 — Double-fire ProcessDayVote bloque la phase jour

- **Symptôme :** la partie se bloque en phase jour — la résolution du vote est déclenchée deux fois
- **Cause :** ProcessDayVote dispatché plusieurs fois sans guard atomique ; VoteService::resolveDayVote() lockForUpdate() sur status='day' mais ne changeait jamais ce statut, donc un second appel concurrent retrouvait status='day' et retraitait les votes
- **Fix :** `resolveDayVote()` passe le statut à `processing_day` (lockForUpdate) dès l'entrée en transaction — un second appel ne trouve plus `status='day'` et est ignoré. `PhaseManager::startNight()` et `ProcessMayorSuccession` mis à jour pour accepter `processing_day` en plus de `day` (sinon la transition nuit / la succession du maire ne se déclenchait plus après ce changement)

---

### [x] 2026-06-11 — Succession du maire non déclenchée si mort la nuit

- **Symptôme :** quand le maire meurt la nuit, aucun nouveau maire n'est élu
- **Cause :** ProcessMayorSuccession appelait startDay() au lieu de ne rien faire en contexte nuit (sens inversé documenté dans DECISIONS.md)
- **Fix :** correction de l'inversion startDay/startNight dans ProcessMayorSuccession ; en contexte nuit, le job se termine après l'élection sans déclencher de transition de phase

---

### [x] 2026-06-11 — Nuit sans loups si la voyante est morte

- **Symptôme :** quand la voyante est morte, la nuit ne se termine pas et le jour ne démarre jamais
- **Cause :** ProcessNightActions appelait startDay() directement, ce chemin était court-circuité dans certains contextes (succession maire, voyante absente)
- **Fix :** création de ProcessNightEnd dispatché systématiquement en fin de ProcessNightActions ; PhaseManager::endNight() centralise la logique de fin de nuit

---

### [x] 2026-06-11 — SQL Data truncated sur games.status (ENUM incomplet)

- **Symptôme :** erreur SQL "Data truncated for column status" en production
- **Cause :** processing_day absent de l'ENUM games.status ; migration fantôme 004809 avec up() vide introduisant une fausse sécurité
- **Fix :** suppression de la migration orpheline, nouvelle migration ajoutant processing_day à l'ENUM complet

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

### [x] 2026-06-10 — Bouton "Tuer" inactif côté loups

- **Symptôme :** sélection d'une cible possible mais bouton "Tuer" restait disabled, POST /vote/night retournait 409
- **Cause :** `VoteService::castNightVote` vérifiait `status !== 'night'` mais `ProcessWerewolvesTurn` passe le status à `wolves_turn` avant de broadcaster
- **Fix :** accepter `['night', 'wolves_turn']` dans le guard de `castNightVote`

---

### [x] 2026-06-10 — Tour voyante/loups jamais affiché après vote jour

- **Symptôme :** après un vote jour, joueurs redirigés vers /night mais aucun tour voyante ni loups ne démarrait
- **Cause :** `PhaseManager::startNight()` dispatchait `ProcessSeerTurn` sans délai — le job broadcastait `SeerTurnStarted` avant que les clients soient abonnés au canal privé
- **Fix :** `->delay(now()->addSeconds(config('game.timers.night_start_delay', 4)))` + ajout de `night_start_delay = 4` dans `config/game.php`

---

### [x] 2026-06-10 — confirmQuit not defined / erreur Alpine sur /night

- **Symptôme :** `Alpine Expression Error: confirmQuit is not defined` sur la page nuit, bouton Quitter non fonctionnel
- **Cause :** `x-data="gameState(...)"` sur le `<main>` du layout englobait les vues enfants — Alpine remontait dans le scope parent qui ne définit pas `confirmQuit`
- **Fix :** déplacer `gameState` sur un div fantôme invisible hors du `<main>`

---

### [x] 2026-06-10 — Modale succession affichée sans mort du maire / events doublés

- **Symptôme :** modale "Succession du Maire" s'ouvrait parfois quand le maire était vivant, events WebSocket traités deux fois
- **Cause :** double abonnement Echo sur le même canal dans `game-state.js` et dans les vues locales ; `$dispatch()` Alpine ne reach pas `window.addEventListener`
- **Fix :** supprimer tous les abonnements Echo locaux dans les vues, tout passer par `window.dispatchEvent` dans `game-state.js`

---

### [x] 2026-06-10 — 404 sur /night lors du tour des loups ou d'un refresh

- **Symptôme :** joueurs redirigés vers /role-reveal ou 404 en rafraîchissant /night pendant `wolves_turn` ou `processing_night`
- **Cause :** `GameController::night()` n'acceptait que `status = 'night'`, `redirectToCurrentPhase` tombait dans `default` pour les statuts intermédiaires
- **Fix :** `night()` accepte `['night', 'wolves_turn', 'processing_night']`, `redirectToCurrentPhase` migré vers `match(true)` avec cas explicites

---

### [x] 2026-06-10 — Accumulation de CheckReconnectionTimeout jobs

- **Symptôme :** dizaines de jobs `CheckReconnectionTimeout` en queue, un par événement de déconnexion WebSocket
- **Cause :** `handleDisconnection` sans guard — Reverb peut émettre plusieurs événements de déconnexion pour un même client
- **Fix :** guard `Cache::has($cacheKey)` en entrée de `handleDisconnection`

---

### [x] 2026-06-10 — Barre timer jour pleine quand temps = 0

- **Symptôme :** barre de progression restait remplie alors que le compteur affichait 0s
- **Cause :** `width:100%` hardcodé en HTML, `_startDayTimer` retournait sans toucher la barre si `PHASE_SECONDS = 0`
- **Fix :** barre initialisée à `width:0%`, largeur calculée depuis `PHASE_SECONDS / totalSeconds` au démarrage

---

### [x] 2026-06-09 — GSAP entry non protégé prefers-reduced-motion (night + day)

- **Symptôme :** utilisateurs `prefers-reduced-motion` voyaient quand même les animations d'entrée GSAP
- **Cause :** les appels `gsap.fromTo('.reveal', ...)` à l'init n'étaient pas wrappés dans un check JS matchMedia
- **Fix :** ajout de `if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches)` dans night.blade.php (script global) et day.blade.php (init())

---

### [x] 2026-06-09 — ProcessMayorSuccession bloque si Maire tué la nuit

- **Symptôme :** partie bloquée en phase nuit quand le Maire est la victime des loups
- **Cause :** guard `status = 'day'` empêchait le Job de s'exécuter en phase nuit
- **Fix :** accepter `night` et `day`, déduire la transition depuis `$game->status` avant transaction

---

### [x] 2026-06-12 — Modale succession bloquée si le successeur est tué la nuit suivante
 
- **Symptôme :** quand le maire est éliminé la nuit et qu'un successeur est désigné, si ce successeur est lui-même tué la nuit suivante, la partie restait bloquée sur la modale "Succession du Maire".
- **Cause :** `ProcessMayorSuccession` déduisait le contexte (nuit/jour) depuis `$game->status` au moment de son exécution — mais si `ProcessNightEnd` s'exécutait en premier, le statut était déjà `day` et le job concluait à tort à un contexte jour.
- **Fix :** flag `shouldStartNight` passé explicitement par le dispatcher (`ProcessNightActions` passe `true`, `VoteService::resolveDayVote` laisse le défaut `false`). Le contexte est porté par l'appelant, pas re-déduit depuis la DB.

---

### [x] 2026-06-12 — $this->authorize() indisponible dans les Controllers (trait AuthorizesRequests manquant)

- **Symptôme :** `LobbyController::updateTimers()` (nouvel endpoint Étape 3) plante avec une erreur "Call to undefined method" sur `$this->authorize(...)` ; `GameController::history()` était affecté par le même problème mais sans test couvrant ce chemin, le bug restait latent.
- **Cause :** `app/Http/Controllers/Controller.php` est une classe abstraite vide, sans le trait `Illuminate\Foundation\Auth\Access\AuthorizesRequests` ni extension de `Illuminate\Routing\Controller`.
- **Fix :** ajout de `use Illuminate\Foundation\Auth\Access\AuthorizesRequests;` dans `app/Http/Controllers/Controller.php`, appliqué à tous les controllers via l'héritage existant.

---

### [x] 2026-06-13 — Modale succession maire bloquée si NightStarted précède MayorSuccessionDone

- **Symptôme :** modale "Succession du Maire" ne se fermait jamais quand le maire
  était éliminé le jour — la partie semblait bloquée sur /day. En cascade (successeur
  éliminé à son tour), le bug se reproduisait à chaque succession.
- **Cause :** game-state.js ne trackait pas l'état de succession. handleNightStarted()
  redirigeait immédiatement vers /night en détruisant la page /day et tous ses
  listeners window — dont celui qui ferme la modale sur mayor-succession-done.
  Un flag booléen aurait cassé la cascade (N successions consécutives).
- **Fix :** compteur successionDepth dans le store central (incrémenté à chaque
  MayorSuccessionStarted, décrémenté à chaque MayorSuccessionDone). handleNightStarted()
  attend successionDepth === 0 avant de rediriger. Garde-fou 20s dans
  openSuccessionModal() côté day.blade.php.

---

### [x] 2026-06-13 — Timer GSAP désynchronisé en arrière-plan sur /day

- **Symptôme :** quand l'onglet passe en arrière-plan, le navigateur throttle `setInterval` mais GSAP poursuit son tween — le compteur affiche 0s alors que la barre de progression reste partiellement remplie.
- **Cause :** `gsap.to(el, { width: '0%', duration: PHASE_SECONDS, ease: 'none' })` tournait en parallèle et indépendamment du `setInterval` qui décrémente `dayTimerSeconds`.
- **Fix :** suppression du tween GSAP continu sur `width` ; la largeur de la barre est désormais recalculée à chaque tick du `setInterval` (`pct = dayTimerSeconds / totalSeconds`). GSAP conservé uniquement pour les transitions de couleur (or → orange ≤10s → rouge ≤5s).

---

### [x] 2026-06-13 — Pseudo de l'hôte visible par les autres joueurs en salle d'attente

- **Symptôme :** tous les joueurs voyaient le pseudo réel de l'hôte dans la liste de la salle d'attente, alors qu'il n'a pas de rôle de jeu particulier à cette étape.
- **Cause :** `waiting-room.blade.php` affichait `p.pseudo` sans distinction pour tous les joueurs, y compris l'hôte.
- **Fix :** affichage de "Hôte" à la place du pseudo pour les autres joueurs ; l'hôte continue de voir son propre pseudo, complété d'un badge "(Hôte)". `night_start_delay` et `mayor_reveal` passés de 4s/5s à 8s dans `config/game.php`.

---

### [x] 2026-06-13 — Bloc "Exclure un joueur" dupliqué dans waiting-room.blade.php

- **Symptôme :** le bouton "Exclure un joueur" était affiché deux fois dans la salle d'attente, avant et après la liste des joueurs.
- **Cause :** deux blocs identiques laissés en place lors d'itérations successives sur la vue.
- **Fix :** suppression du bloc dupliqué (avant la liste des joueurs) ; seul celui après la liste est conservé.

---

### [x] 2026-06-15 — Timeline de l'onglet "Déroulé" invisible dans history.blade.php

- **Symptôme :** l'onglet "Déroulé" s'affichait mais tous les `.timeline-item` restaient invisibles (opacity:0), de même que `#players-section`/`.player-row` si l'onglet "Joueurs" n'était pas actif au chargement.
- **Cause :** le `MutationObserver` censé déclencher l'animation GSAP sur `#timeline-section` ne détecte pas toujours les changements de `style` inline posés par Alpine (`x-show`), et `observer.disconnect()` empêchait toute ré-animation lors d'un retour sur l'onglet.
- **Fix :** suppression de l'`opacity:0` initiale sur `.timeline-item`, `.player-row` et `#players-section` (toujours visibles). Suppression du `MutationObserver` et des animations GSAP au chargement ; chaque bouton d'onglet ("Joueurs"/"Déroulé") déclenche désormais `gsap.fromTo(...)` au clic via `$nextTick`, avec garde `prefers-reduced-motion`.

---

### [x] 2026-06-15 — [RÉGRESSIF] Messages WebSocket reçus en double : transport ws+wss simultané (Pusher-js)

- **Symptôme :** tous les events WebSocket (chat, votes, etc.) arrivaient deux fois côté client, malgré les fixes précédents sur les doubles abonnements Echo.
- **Cause :** `enabledTransports: ['ws', 'wss']` dans `resources/js/echo.js` permettait à Pusher-js d'établir deux connexions simultanées (ws ET wss) alors que `forceTLS: true` et un seul port (443) sont configurés — chaque event WebSocket était donc livré une fois par connexion.
- **Fix :** `enabledTransports: ['wss']` — une seule connexion TLS.
- **RÉGRESSION (branche fix/pusher-double-transport) :** ce fix a cassé le temps réel — obligation de recharger la page pour voir l'état du jeu, messages chat ne s'affichant plus. La cause racine des doublons chat n'est PAS le double transport Pusher — `['wss']` seul casse le temps réel car Reverb/Nginx ne répond pas correctement en wss seul dans cette configuration. Revert vers `['ws', 'wss']` (branche revert/wss-only-transport). La vraie cause des doublons reste à identifier.

---

### [x] 2026-06-15 — Messages chat en doublon : double enregistrement window.addEventListener dans init()

- **Symptôme :** 1 message envoyé = 2 affichages pour tous les joueurs sur /day.
  Confirmé en prod avec vrais joueurs. Tous les window.addEventListener enregistrés
  dans dayScreen.init() (chat-message, player-eliminated, hunter-turn-started, etc.)
  étaient déclenchés deux fois.
- **Diagnostic exhaustif :**
  - ❌ Double abonnement Echo dans les vues Blade — écarté (corrigé précédemment)
  - ❌ Double transport Pusher ['ws','wss'] — écarté via `ss -tnp` (une seule connexion
    WebSocket active côté serveur). Test `['wss']` seul en prod : régression critique
    (temps réel cassé, obligation de recharger). `enabledTransports: ['ws', 'wss']`
    obligatoire dans cette config Nginx/Reverb et ne doit jamais être modifié.
  - ✅ Cause confirmée via DevTools prod :
    `getEventListeners(window)['chat-message']?.length === 2`
- **Cause :** Alpine.js appelle `init()` deux fois sur le composant `dayScreen()`.
  Chaque appel enregistre un nouveau `window.addEventListener('chat-message', ...)`
  sans jamais retirer le précédent. Résultat : 2 listeners actifs sur window,
  chaque CustomEvent capturé deux fois, chaque message affiché deux fois.
- **Fix :** guard `_initialized` en tête de `init()` dans `dayScreen()` et `nightScreen()`.
  Guard `_wsInitialized` en tête de `initWebSocket()` dans `game-state.js`.
  ```js
  if (this._initialized) return;
  this._initialized = true;
  ```

---

### [x] 2026-06-15 — window.MY_ROLE absent du layout global (canal loups non souscrit)

- **Symptôme :** certains joueurs loups ne recevaient pas les événements du canal
  game.{id}.werewolves (chat loups silencieux, votes de la meute non affichés,
  tour des loups sans interface), de façon non déterministe selon la vitesse
  de chargement du navigateur.
- **Cause :** game-state.js::init() lit window.MY_ROLE avant initWebSocket() pour
  que isWerewolf soit correct au moment de la souscription Echo. Or window.MY_ROLE
  n'était jamais défini dans layouts/game.blade.php — seules les vues night.blade.php
  et day.blade.php définissaient une const MY_ROLE locale, sans l'exposer sur window.
  Les modules Vite (type="module") étant defer implicite, ils s'exécutent après les
  scripts inline — mais window.MY_ROLE étant absent du layout, la variable restait
  undefined aux deux points de lecture dans init().
- **Fix :** ajout de window.MY_ROLE = '{{ $player->role ?? '' }}' dans le bloc
  @isset($player) du script global de layouts/game.blade.php. Centralisé dans le
  layout → toutes les vues (night, day, elect-mayor, spectator) en bénéficient.
  Le fallback ?? '' garantit qu'un rôle null retourne une chaîne vide, ce qui
  évalue isWerewolf à false (comportement identique à null).

---

### [x] 2026-06-15 — Bannière "Tu as été éliminé" persistante après sauvegarde sorcière

- **Symptôme :** un joueur sauvé par la sorcière voyait en permanence la bannière
  "Tu as été éliminé" sur /day, même en étant vivant. Le même bug pouvait se produire
  lors du rechargement de page si le flag sessionStorage subsistait d'un cycle précédent.
- **Cause :** `sessionStorage.getItem('dead_' + MY_PLAYER_ID)` persistait entre les
  phases et les rechargements. Aucun code ne supprimait ce flag quand un joueur vivant
  chargeait /day ou /night, ni quand `day.started` indiquait qu'il avait été sauvé.
- **Fix :** (1) Nettoyage du flag en tête d'`init()` si `MY_IS_ALIVE` est vrai.
  (2) Écoute de `i-was-saved` (nouveau CustomEvent dispatché par `game-state.js`
  dans `handleDayStarted` quand `saved_player_id === myId`) pour annuler la bannière
  en temps réel. (3) Fallback de 600ms dans `toast.blade.php::remove()` pour garantir
  la suppression même si l'animation GSAP échoue silencieusement.

---

### [x] 2026-06-15 — Toast "La sorcière t'a sauvé" invisible lors de la transition /night → /day

- **Symptôme :** le joueur sauvé par la sorcière ne voyait jamais le toast
  "🧙 La sorcière t'a sauvé cette nuit." malgré que l'event DayStarted soit
  bien reçu avec son `saved_player_id`.
- **Cause :** le toast était dispatché dans `handleDayStarted()` qui déclenche
  immédiatement une animation GSAP + redirection vers `/day`. La page `/night`
  était détruite avant que le composant toast Alpine ait pu rendre le message.
- **Fix :** persistance dans `sessionStorage` avant la redirection, consommation
  via `window.__toastBuffer` dans `dayScreen.init()` après le chargement de
  `/day`. Aucun `setTimeout` — le composant `toast.blade.php` vide le buffer
  dans son propre `init()`, ce qui garantit l'affichage quelle que soit la
  vitesse de la machine.

---

### [x] 2026-06-16 — NightStarted::broadcastWith() utilisait config() au lieu de $game->timer()

- **Symptôme :** le client animait la barre de progression de la nuit sur 30s (valeur par défaut config) même si le host configurait seer=45s — désynchronisation visible.
- **Cause :** `broadcastWith()` appelait `config('game.timers.seer', 30)` au lieu de `$this->game->timer('seer')`, contournant les settings configurés par le host.
- **Fix :** remplacement par `$this->game->timer('seer')` dans `NightStarted::broadcastWith()`.

---

### [x] 2026-06-18 — Empoisonnement sorcière ignoré (race condition ProcessWitchAutoAction)

- **Symptôme :** la sorcière soumettait un `kill`, recevait `success: true`, mais la nuit se terminait sans que la victime soit éliminée.
- **Cause :** `ProcessWitchAutoAction::delay(0)` dispatché depuis le Controller pouvait s'exécuter avant que la transaction `witch_kill` soit visible en DB (driver sync en tests, worker rapide en prod). Le guard `$alreadyActed` trouvait aucune action → créait `witch_pass` → `ProcessNightEnd` terminait la nuit immédiatement.
- **Fix :** `delay(now()->addSeconds(2))` dans `ActionController::witchAct()` + `$game->refresh()` avant le guard dans `ProcessWitchAutoAction::handle()`.

---

### [x] 2026-06-16 — Redirection /night bloquée si MayorSuccessionDone précède le listener

- **Symptôme :** redirection vers /night jamais déclenchée si `MayorSuccessionDone` arrive côté client avant que `handleNightStarted()` pose son listener `mayor-succession-done` (réordonnancement WebSocket possible) — `successionDepth` reste > 0, la partie reste bloquée sur /day indéfiniment.
- **Cause :** aucun timeout de sécurité dans le bloc d'attente de `handleNightStarted()`.
- **Fix :** timeout 25 000 ms dans `handleNightStarted()` qui force `_doNightRedirect()` si `mayor-succession-done` n'arrive jamais.

---

### [x] 2026-06-20 — players[] du store central jamais peuplé (deux sources de vérité parallèles)

- **Symptôme :** `game-state.js` déclarait `players: []` mais `GameController::state()` ne retournait jamais la liste des joueurs — `_loadState()` ne peuplait donc jamais `this.players`. Les vues (`day.blade.php`, `night.blade.php`) maintenaient chacune leur propre copie locale (`this.players = PLAYERS_DATA`, injectée côté Blade) sans jamais lire le store central, créant deux sources de vérité parallèles avec risque de désynchronisation future.
- **Cause :** `GameController::state()` n'incluait pas de clé `players` dans son payload JSON. `_loadState()` n'avait aucun code pour lire `d.players`. Chaque vue bootstrappait sa propre liste depuis Blade.
- **Fix :** (1) `GameController::state()` ajoute `players` au payload : liste de tous les joueurs ordonnée vivants en premier, avec `id/pseudo/is_alive/is_mayor` pour tous, et `revealed_role/revealed_role_label` uniquement pour les morts (règle de non-exposition du rôle des vivants). (2) `game-state.js::_loadState()` ajoute `this.players = d.players ?? []` (fallback `[]` pour compatibilité déploiement progressif). (3) `PLAYERS_DATA` conservé comme amorçage initial légitime dans `day.blade.php` — voir DECISIONS.md. Test ajouté : `test_state_endpoint_retourne_la_liste_des_joueurs` (ReconnectionTest).

---

# ROADMAP (idées / améliorations futures)
 
- [ ] `game-state.js::_buildVoteMap()` (partagée `.werewolves.vote.cast`/`.day.vote.cast`) lit les mêmes noms
      de champs erronés que le bug `_updateVoteBars()` corrigé le 2026-07-25 (`vote_weight`/`vote_count`/
      `player_id` au lieu de `total_weight`/`vote_count`/`target_player_id` selon l'event) et lit `e.votes`
      pour l'event loups qui broadcaste en réalité sous `e.wolves` — sans impact visible aujourd'hui car
      `this.votes`/`this.wolvesVotes` n'est consommé par aucune vue Blade (day.blade.php et night.blade.php
      lisent le détail brut de l'event), mais à corriger si ce store est un jour câblé à un template.
- [ ] Nettoyer les appels `$victim->load('user')` devenus inutiles avant `broadcast(new PlayerEliminated(...))`
      (`ProcessNightActions`, `ActionController`, `WitchAction` ×4) — `google_name` a été retiré de
      `PlayerEliminated::broadcastWith()` par le fix vie privée du 2026-06-24, ces `load('user')` ne
      servent plus qu'à `$victim->user->notify(...)` juste après (relation lazy-loadable de toute façon).
      Trouvé en ajoutant le même `load('user')` par cohérence de pattern dans la cascade amoureux
      (Phase 57, `feat/heartbreak-death-notification`) sans vérifier que le champ existait encore.
- [ ] Délai voyante : réduire de ~8s à ~5s via `$game->timer('seer')` configurable (couvert par Étape 3) —
      statut ambigu (audit documentaire du 2026-07-24) : la configurabilité elle-même est bien livrée
      (Étape 3 terminée, le host peut fixer n'importe quelle valeur), mais la valeur PAR DÉFAUT n'a pas
      été changée (`config/game.php` : `seer` toujours à 30s). Laissé ouvert, pas assez de contexte pour
      trancher si l'idée reste pertinente telle quelle.
- [x] ~~Harmoniser les appels `config('game.timers.mayor_succession', 15)` restants avec `$game->timer()`~~ —
      déjà résolu (Phase 22, Étape 10.2 du TODO.md) : grep `config('game.timers` sur `app/` (2026-07-24)
      ne trouve plus aucun appel hors `TimerCalculator` et `GameSettingsService::validateTimerSettings()`
      (sous-clé `limits`, légitime — validation, pas lecture de valeur). Rien à faire.
- [ ] Rôles v1.4+ : Loup Blanc, Petite Fille — Cupidon retiré de cette liste (audit documentaire du
      2026-07-24) : livré et terminé depuis v1.3 (voir État global, TODO.md), n'a plus sa place parmi
      les rôles "pas encore implémentés".
- [ ] State machine : étendre Symfony Workflow aux statuts intermédiaires (processing_night, wolves_turn) — post-Étape 4 si nécessaire
- [ ] Audit performance post-v1.2 : N+1 queries, temps réponse < 200ms (Laravel Telescope)
- [ ] `phase-header.blade.php` : `$roleLabel`/`$roleBg`/`$roleColor` ne couvrent pas encore witch/hunter et utilisent toujours 🏘 pour villageois (même pattern que `player-list.blade.php`) —
      vérifié toujours réel dans le code (2026-07-24), mais le composant `<x-phase-header>` ne semble
      utilisé nulle part dans `resources/views/` (`grep x-phase-header` ne trouve aucun appelant) : le
      gap n'a donc aucun impact pratique actuellement, à réévaluer si le composant est un jour câblé.
- [ ] Révision timers par défaut config/game.php (day_vote, seer, werewolves)
      et valeurs minimales — prompt séparé après validation prod
- [x] `NightResyncService` ne couvre que le tir du Chasseur en phase NUIT — un maire-chasseur
      éliminé par le vote du JOUR puis un refresh pendant son tir de riposte n'est pas rattrapé
      (gap préexistant, hors périmètre de fix/night-phase-resync, voir DECISIONS.md) —
      **toujours réel** (revérifié 2026-07-24) : `NightResyncService::currentSubPhase()` retourne
      `null` dès l'entrée si `! $game->isNightPhase()`. Marqueur `[x]` car la revérification est
      terminée, le gap lui-même reste ouvert.
- [ ] Étendre players[] du store central à night.blade.php et spectator.blade.php
- [x] ~~`ProcessWitchAutoAction` ne passe pas par `WitchAction::act()` : la victime ordinaire déférée...~~
      — **résolu par la Phase 52** (2026-07-23, voir "BUGS CORRIGÉS" ci-dessus et DECISIONS.md "Victime
      des loups jamais éliminée quand la Sorcière ne clique pas") : `ProcessWitchAutoAction::handle()`
      appelle désormais `WitchAction::finalizeTimedOutVictim()`, qui reproduit exactement la logique de
      finalisation de la branche `pass` (victime ordinaire, sorcière elle-même, maire en sursis).
      Vérifié dans le code actuel (2026-07-24) : le call site est bien en place.
- [ ] `role-reveal.blade.php` (bloc Villageois) et tout autre bloc utilisant une condition en liste blanche d'exclusions (`role !== 'a' && role !== 'b' && ...`) plutôt qu'un `match()`/tableau associatif avec `default` : risque de régression silencieuse à chaque nouveau rôle (v1.4+ Loup Blanc, Petite Fille) — un rôle non exclu explicitement se fait passer pour Villageois sans erreur. Envisager d'inverser en liste blanche positive (`role === 'villager'`) une fois tous les rôles v1.3 stabilisés.
- [x] ~~`day.blade.php` ligne ~101 (message "C'était un [rôle]" pour la victime de la nuit) : ne couvre
      que werewolf/seer, tombe en `default => 'Villageois'` pour witch/hunter/cupidon~~ — **résolu par
      la Phase 48** (2026-07-23, voir "BUGS CORRIGÉS" ci-dessus) : le `match()` couvre désormais les 6
      rôles (`witch`, `hunter`, `cupidon` ajoutés). Vérifié dans le code actuel (2026-07-24).
- [x] ~~`GameController::state()` (`revealed_role_label`, endpoint `/state`) : couvre werewolf/seer/witch/hunter
      mais pas `cupidon`~~ — **résolu par la Phase 48** (2026-07-23) : `'cupidon' => 'Cupidon'` déjà
      présent dans le `match()`. Vérifié dans le code actuel (2026-07-24).
- [x] ~~Rejouer en conditions réelles... course entre WinConditionChecker::check() et cancelGame()~~ —
      cause racine confirmée par test déterministe (garde `cancelGame()` trop permissif face au
      statut `processing_day`/`processing_night`), voir DECISIONS.md "Victoire des Amoureux annulée
      à tort par une course avec cancelGame()..." (partie RIQPAZ, 2026-07-24).
- [ ] `CheckReconnectionTimeout::handle()` (et désormais `GameService::cancelGame()` symétriquement)
      excluent le statut `'wolves_turn'` de leur ensemble de statuts "annulables" — une déconnexion
      massive pendant spécifiquement la phase de vote des loups ne peut jamais déclencher l'annulation
      pour inactivité, contrairement à `'night'`/`'day'`/`'electing_mayor'`. Gap préexistant, non
      introduit par le fix RIQPAZ (qui a resserré `cancelGame()` sur l'ensemble déjà utilisé par le
      Job, sans l'étendre) — à traiter séparément si jugé pertinent (probablement mineur : `wolves_turn`
      est une fenêtre courte).

## Refactoring architectural planifié

### [ ] Refactor — Supprimer night_start_delay et les délais buffers artificiels

**Problème actuel :**

La séquence nocturne repose sur trois délais artificiels qui compensent
une limitation architecturale :

1. night_start_delay = 8s dans config/game.php : délai entre NightStarted
   broadcasté et le premier dispatch (ProcessSeerTurn). Ajouté pour laisser
   le temps aux clients de se rediriger vers /night et de s'abonner au
   canal Echo avant que SeerTurnStarted parte. Sans ce délai, l'event
   est émis dans le vide et la voyante ne voit jamais son tour.

2. Le +2s dans ProcessSeerTurn (delay = seer_timer + 2) : buffer de
   sécurité entre ProcessSeerAutoAction et ProcessWerewolvesTurn pour
   éviter une race condition.

3. mayor_succession + 5s dans ProcessNightActions pour ProcessNightEnd :
   buffer pour couvrir le tour sorcière. Amélioré en witch_timer +
   mayor_succession + 5s par le fix de ce jour, mais reste fragile car
   calculé côté serveur sans confirmation que le client a bien reçu et
   traité WitchTurnStarted.

**Cause racine commune :**

Le backend dispatche des events dans le vide et espère que les délais
en dur suffisent. Il n'existe aucun mécanisme permettant au backend de
savoir si les clients ont bien reçu et traité un event avant de passer
à l'étape suivante.

**Solution cible : pattern "ready acknowledgment"**

Principe : le client signale sa présence au backend après chargement de
la page et abonnement à Echo. Le backend ne commence les tours que quand
tous les joueurs vivants ont signalé leur présence (ou après un timeout
de sécurité).

Implémentation envisagée :

- Ajouter POST /game/{id}/night-ready : appelé par le client dans init()
  de nightScreen() après initWebSocket(), signale que le joueur est
  abonné et prêt.
- Backend stocke les confirmations dans Redis/Cache avec une clé
  "night_ready_{game_id}_{round}_{player_id}".
- ProcessSeerTurn attend que tous les joueurs vivants aient confirmé,
  ou démarre après un timeout (ex: 6s) si certains joueurs ne confirment
  pas (déconnectés, lents).
- Supprimer night_start_delay de config/game.php et de
  PhaseManager::startNight().
- Supprimer le +2s arbitraire dans ProcessSeerTurn.
- Le délai de ProcessNightEnd peut redevenir majority_succession + 5s
  car la sorcière, ayant elle aussi confirmé sa présence, a reçu
  WitchTurnStarted à coup sûr. On peut conserver witch_timer +
  majority_succession + 5s comme garde-fou.

**Ce que ça règle :**
- Suppression complète des race conditions dues à la vitesse de connexion.
- Fin de partie correcte même sur connexion lente (mobile 3G, etc.).
- Plus aucun délai arbitraire à ajuster quand les timers de jeu changent.
- Robustesse face aux futurs rôles actifs (chaque nouveau tour ajoute
  aujourd'hui un +Xs implicite à calibrer manuellement).

**Risques et prérequis :**
- Nécessite que tous les clients appellent /night-ready de façon fiable,
  y compris après un refresh ou une reconnexion en cours de nuit.
- Le timeout de sécurité doit couvrir les cas de reconnexion (actuellement
  30s selon config reconnection). Valeur suggérée : max(night_start_delay,
  reconnection_timeout / 2).
- Tests d'intégration à écrire : test_night_starts_after_all_players_ready,
  test_night_starts_after_timeout_if_player_disconnected.
- Priorité : après stabilisation de v1.2. Ne pas faire avant d'avoir
  tous les rôles stables.

- [ ] `WinConditionCheckerTest` broadcaste réellement (`GameFinished`/`PhaseAnnouncement` non protégés par `try/catch`, aucun `Event::fake()`) et échoue si Reverb n'est pas démarré localement — l'aligner sur le reste de la suite (`Event::fake()`) pour ne plus dépendre d'un service externe pendant `php artisan test` (découvert lors de Cupidon Étape 7, voir DECISIONS.md).
- [ ] `config/game_ui.php` (`role_labels`, `role_labels_emoji`) ne couvre pas `cupidon` (ni `white_wolf`
      pour `role_labels_emoji`). Révérifié 2026-07-24 : la partie "`ROLE_NAMES` JS de `role-reveal.blade.php`"
      de cet item est **obsolète** — `ROLE_NAMES` inclut déjà `cupidon: 'Cupidon'`, corrigé entre-temps.
      En revanche `config('game_ui.role_labels_emoji')` a un impact réel confirmé : consommé par
      `RoleAssignedNotification` et `PlayerEliminatedDayNotification` (notifications push), qui
      retombent sur le nom brut du rôle (`'cupidon'` sans emoji) faute d'entrée dans le tableau — pas
      un simple gap d'affichage Blade, un vrai contenu de notification push incorrect pour Cupidon.
      Les deux composants Blade `role-label.blade.php`/`<x-role-label>` qui lisaient `role_labels`
      semblent inutilisés (aucun appelant trouvé).