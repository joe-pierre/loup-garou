# TASK_PROMPTS_REMAINING.md — Tâches v1.2

> Tâches v1.1 (1→40 + bugfixes E→K) terminées et taggées `v1.1.1`.
> Ce fichier contient les Étapes v1.2 **ainsi que les correctifs UX (Phase 17)**
> à exécuter avant l'Étape 4.
>
> **Règle absolue :** coller un prompt à la fois dans Claude Code.
> Respecter impérativement l'ordre des sections — chaque étape s'appuie sur la précédente.

---

## ORDRE D'EXÉCUTION

```
Phase 17 — Correctifs UX (Prompts A → E)
    ↓ chaque prompt = une branche + merge sur dev
Étape 4 — Rôles v1.2 (Sorcière, Chasseur)
    ↓ merge sur dev + validation manuelle
Étape 5 — Tests d'intégration v1.2
    ↓ merge sur dev + php artisan test 100% vert → tag v1.2.0
```

---

## PHASE 17 — Correctifs UX pré-Étape 4

> Ces cinq correctifs doivent tous être mergés sur `dev` **avant** de commencer
> l'Étape 4. Ils corrigent des régressions UX et comportements incorrects qui
> masqueraient des bugs pendant les tests des nouveaux rôles.
>
> **Ordre obligatoire A → E** : les Prompts B et E touchent tous deux
> `waiting-room.blade.php`. Merger B avant de lancer E.

---

### Prompt A — Timer GSAP désynchronisé en arrière-plan

```
AVANT DE COMMENCER :
git checkout -b fix/timer-gsap-sync

Fichier concerné : resources/views/game/day.blade.php — fonction _startDayTimer()

Problème : la barre de progression GSAP se désynchronise du compteur Alpine
quand la page est mise en arrière-plan (throttling navigateur). GSAP met en
pause son tween mais setInterval continue. Résultat : le compteur affiche 0s
mais la barre est encore partiellement remplie.

Cause : gsap.to(el, { width: '0%', duration: PHASE_SECONDS }) tourne en
parallèle du setInterval — les deux sont indépendants.

Fix : supprimer le tween GSAP continu sur la largeur. Synchroniser la largeur
de la barre avec le setInterval. Conserver GSAP uniquement pour les changements
de couleur (or → orange → rouge).

Remplacer _startDayTimer() par :

_startDayTimer() {
    const el = document.getElementById('day-vote-timer');
    const totalSeconds = {{ $game->timer('day_vote') }};

    if (PHASE_SECONDS <= 0) {
        if (el) el.style.width = '0%';
        this.dayTimerSeconds = 0;
        return;
    }

    this.dayTimerSeconds = PHASE_SECONDS;
    const initialPct = Math.min(100, Math.round((PHASE_SECONDS / totalSeconds) * 100));
    if (el) el.style.width = initialPct + '%';

    const iv = setInterval(() => {
        this.dayTimerSeconds = Math.max(0, this.dayTimerSeconds - 1);
        const pct = Math.round((this.dayTimerSeconds / totalSeconds) * 100);
        if (el) el.style.width = pct + '%';

        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            if (this.dayTimerSeconds <= 5 && el) {
                gsap.to(el, { backgroundColor: '#ef4444', duration: 0.3, overwrite: true });
            } else if (this.dayTimerSeconds <= 10 && el) {
                gsap.to(el, { backgroundColor: '#f97316', duration: 0.3, overwrite: true });
            }
        }

        if (this.dayTimerSeconds <= 0) {
            clearInterval(iv);
            if (el) el.style.width = '0%';
        }
    }, 1000);
},

Supprimer l'appel gsap.to(el, { width: '0%', duration: PHASE_SECONDS, ease: 'none' })
qui existait juste après l'initialisation de la largeur.
Ne pas toucher au reste du fichier.

Mettre à jour BUGS_AND_ROADMAP.md (section BUGS CORRIGÉS).
Mettre à jour TODO.md : cocher [x] le Prompt A en Phase 17.
Rien à ajouter dans DECISIONS.md (fix simple, cause documentée).

git add -A && git commit -m "fix(timer): synchronise barre GSAP avec setInterval pour éviter désync navigateur"
```

---

### Prompt B — Anonymat du pseudo host pour les autres joueurs

```
AVANT DE COMMENCER :
git checkout -b fix/host-anonymity

Fichiers concernés :
- config/game.php
- resources/views/game/waiting-room.blade.php (vue active via LobbyController@waitingRoom)

Prérequis : fix/timer-gsap-sync mergé sur dev.

───────────────────────────────────────────────
MODIFICATION 1 — config/game.php
───────────────────────────────────────────────

Dans le tableau 'timers', modifier uniquement ces deux valeurs :

- night_start_delay : valeur actuelle → 8
- mayor_reveal      : valeur actuelle → 8

Ces deux timers sont fixes (non configurables par le host).
Ne pas toucher aux autres valeurs ni à la structure du fichier.

───────────────────────────────────────────────
MODIFICATION 2 — waiting-room.blade.php
───────────────────────────────────────────────

La liste des joueurs est rendue via x-for="p in players".
Dans le template x-for, modifier l'affichage du pseudo selon cette règle :

- Si p.is_host ET p.id !== currentPlayerId
  → afficher le texte "Hôte" à la place de p.pseudo
    <span class="...">Hôte</span>

- Si p.is_host ET p.id === currentPlayerId
  → afficher p.pseudo normalement + badge "(Hôte)" après le pseudo
    <span x-text="p.pseudo"></span>
    <span class="text-xs italic" style="color:#c9a84c;">(Hôte)</span>

- Sinon
  → afficher p.pseudo normalement (comportement inchangé)

Le badge "Host" existant côté droit (x-show="p.is_host") reste en place —
seul l'affichage du pseudo dans le span principal change.

Ne pas modifier LobbyController, GameService, ni les events WebSocket.
Le pseudo reste broadcasté normalement — seul l'affichage change dans cette vue.

Mettre à jour BUGS_AND_ROADMAP.md (section BUGS CORRIGÉS).
Mettre à jour TODO.md : cocher [x] le Prompt B en Phase 17.
Rien à ajouter dans DECISIONS.md.

git add -A && git commit -m "fix(ux): masquer pseudo du host pour les autres joueurs en waiting-room"
```

---

### Prompt C — Chat en processing_day + rôles révélés des joueurs morts

```
AVANT DE COMMENCER :
git checkout -b fix/day-improvements

Fichiers concernés :
- app/Services/ChatService.php
- resources/views/game/day.blade.php

Prérequis : fix/host-anonymity mergé sur dev.

───────────────────────────────────────────────
MODIFICATION 1 — ChatService.php
───────────────────────────────────────────────

Le canal general n'accepte pas le statut processing_day.
Les joueurs ne peuvent donc pas chatter pendant la résolution du vote.

Localiser la ligne :

    if (! in_array($game->status, ['electing_mayor', 'day'])) {

La remplacer par :

    if (! in_array($game->status, ['electing_mayor', 'day', 'processing_day'])) {

───────────────────────────────────────────────
MODIFICATION 2 — day.blade.php : rôle révélé des joueurs morts
───────────────────────────────────────────────

Dans la liste x-for="p in players", ajouter sous le pseudo des joueurs morts :

    <span
        x-show="!p.is_alive && p.revealed_role_label"
        class="text-xs italic"
        style="color:rgba(232,224,208,0.4);"
        x-text="p.revealed_role_label"
    ></span>

Dans le bloc @php qui construit $playersJson (PLAYERS_DATA), ajouter
ces deux clés à chaque joueur :

    'revealed_role'       => $p->is_alive ? null : $p->role,
    'revealed_role_label' => $p->is_alive ? null : match($p->role) {
        'werewolf' => 'Loup-Garou',
        'seer'     => 'Voyante',
        'witch'    => 'Sorcière',
        'hunter'   => 'Chasseur',
        default    => 'Villageois',
    },

Dans le handler window.addEventListener('player-eliminated', (e) => { ... }),
après p.is_alive = false, ajouter :

    const roleLabels = {
        werewolf: 'Loup-Garou',
        seer:     'Voyante',
        witch:    'Sorcière',
        hunter:   'Chasseur',
        villager: 'Villageois',
    };
    p.revealed_role       = e.detail?.role ?? null;
    p.revealed_role_label = roleLabels[e.detail?.role] ?? '';

Dans init(), trier les joueurs pour mettre les vivants en premier :

    this.players = [...PLAYERS_DATA].sort((a, b) => b.is_alive - a.is_alive);

Ne pas trier dynamiquement après chaque élimination — le tri initial suffit
pour éviter le réordonnancement visuel brutal.

Mettre à jour BUGS_AND_ROADMAP.md (section BUGS CORRIGÉS).
Mettre à jour TODO.md : cocher [x] le Prompt C en Phase 17.
Rien à ajouter dans DECISIONS.md.

git add -A && git commit -m "fix(day): chat en processing_day + rôle révélé des joueurs morts"
```

---

### Prompt D — Votes loups : afficher qui vote pour qui

```
AVANT DE COMMENCER :
git checkout -b fix/wolves-vote-visibility

Fichiers concernés :
- app/Services/VoteService.php — méthode privée getNightVoteState()
- resources/views/game/night.blade.php — section "Votes de la meute"

Prérequis : fix/day-improvements mergé sur dev.

───────────────────────────────────────────────
MODIFICATION 1 — VoteService::getNightVoteState()
───────────────────────────────────────────────

Remplacer la méthode entière par :

    private function getNightVoteState(Game $game): array
    {
        $aliveWolves = $game->alivePlayers()
            ->whereIn('role', ['werewolf', 'white_wolf'])
            ->get();

        $votes = GameAction::where('game_id', $game->id)
            ->where('type', 'night_vote')
            ->where('round', $game->round)
            ->get()
            ->keyBy('player_id');

        $targetIds = $votes->pluck('target_player_id')->filter()->unique();
        $targets   = GamePlayer::whereIn('id', $targetIds)
            ->pluck('pseudo', 'id');

        return $aliveWolves->map(fn (GamePlayer $w) => [
            'player_id'        => $w->id,
            'pseudo'           => $w->pseudo,
            'has_voted'        => $votes->has($w->id),
            'target_player_id' => $votes->get($w->id)?->target_player_id,
            'target_pseudo'    => $votes->has($w->id)
                ? ($targets[$votes[$w->id]->target_player_id] ?? null)
                : null,
        ])->values()->toArray();
    }

Ne pas modifier la signature. Ne pas modifier les autres méthodes.

───────────────────────────────────────────────
MODIFICATION 2 — night.blade.php : section "Votes de la meute"
───────────────────────────────────────────────

Localiser le bloc x-show="wolfVoteState.length > 0" contenant
x-for="wolf in wolfVoteState".

Remplacer le contenu du template x-for par :

    <template x-for="wolf in wolfVoteState" :key="wolf.player_id">
        <div class="flex items-center gap-2 text-xs py-1"
             style="color:rgba(232,224,208,0.85);">
            <span class="font-semibold"
                  style="color:#ff8888;"
                  x-text="wolf.pseudo"></span>
            <span style="color:rgba(232,224,208,0.25);">→</span>
            <span x-show="wolf.has_voted && wolf.target_pseudo"
                  style="color:#ff4444;font-weight:600;"
                  x-text="wolf.target_pseudo"></span>
            <span x-show="!wolf.has_voted"
                  class="italic"
                  style="color:rgba(232,224,208,0.3);">
                n'a pas encore voté
            </span>
        </div>
    </template>

Vérifier (sans modifier) que le handler :

    window.addEventListener('wolves-vote-cast', (e) => { ... })

dans nightScreen.init() assigne bien :

    this.wolfVoteState = e.detail.wolves ?? []

WerewolvesVoteCast reste sur PrivateChannel game.{id}.werewolves —
ne pas modifier le canal ni l'event.

Mettre à jour BUGS_AND_ROADMAP.md (section BUGS CORRIGÉS).
Mettre à jour TODO.md : cocher [x] le Prompt D en Phase 17.
Rien à ajouter dans DECISIONS.md.

git add -A && git commit -m "feat(night): afficher qui vote pour qui dans le canal loups"
```

---

### Prompt E — Config host déplacée en modale + suppression bloc dupliqué

```
AVANT DE COMMENCER :
git checkout -b feat/settings-modal

Fichier concerné : resources/views/game/waiting-room.blade.php

Prérequis : fix/wolves-vote-visibility mergé sur dev.
Ce prompt suppose que fix/host-anonymity est mergé (les stores timerSettings()
et roleSettings() existent déjà dans la waiting-room).

───────────────────────────────────────────────
MODIFICATION 1 — Supprimer les panneaux inline
───────────────────────────────────────────────

Supprimer du flux principal de la page :
- Le div #wr-timers (panneau timers inline)
- Le div #wr-roles (panneau rôles inline)
- Le bloc "Exclure un joueur" dupliqué (celui des lignes ~134, avant la liste
  des joueurs) — conserver uniquement celui positionné après la liste (~185)

───────────────────────────────────────────────
MODIFICATION 2 — Bouton ⚙️ Paramètres (host uniquement)
───────────────────────────────────────────────

Ajouter, après la barre de progression et visible uniquement pour le host :

    <button
        x-show="isHost"
        @click="showSettingsModal = true"
        class="text-xs px-4 py-2 rounded-lg transition-opacity hover:opacity-75"
        style="background-color:rgba(201,168,76,0.12);
               border:1px solid rgba(201,168,76,0.3);
               color:#c9a84c;">
        ⚙️ Paramètres de la partie
    </button>

───────────────────────────────────────────────
MODIFICATION 3 — Propriété Alpine showSettingsModal
───────────────────────────────────────────────

Ajouter dans le store waitingRoom() :

    showSettingsModal: false,

───────────────────────────────────────────────
MODIFICATION 4 — Modale avec deux onglets
───────────────────────────────────────────────

Créer la modale avec cette structure :

    <!-- Overlay -->
    <div x-show="showSettingsModal"
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center"
         style="background:rgba(0,0,0,0.75);"
         @click.self="showSettingsModal = false">

        <!-- Panneau -->
        <div class="relative w-full max-w-lg mx-4 rounded-xl p-6"
             style="background:#111827;
                    border:1px solid rgba(201,168,76,0.35);">

            <!-- Bouton fermer -->
            <button @click="showSettingsModal = false"
                    class="absolute top-4 right-4 text-lg"
                    style="color:rgba(232,224,208,0.5);">
                ✕
            </button>

            <!-- Titre -->
            <h2 class="font-cinzel text-lg mb-4"
                style="color:#c9a84c;">
                Paramètres de la partie
            </h2>

            <!-- Onglets -->
            <div class="flex gap-2 mb-6">
                <button @click="settingsTab = 'timers'"
                        :class="settingsTab === 'timers'
                            ? 'tab-btn tab-btn--active'
                            : 'tab-btn'">
                    Timers
                </button>
                <button @click="settingsTab = 'roles'"
                        :class="settingsTab === 'roles'
                            ? 'tab-btn tab-btn--active'
                            : 'tab-btn'">
                    Rôles
                </button>
            </div>

            <!-- Contenu Timers -->
            <div x-show="settingsTab === 'timers'"
                 x-data="timerSettings()">
                <!-- Déplacer ici exactement le HTML intérieur du div #wr-timers -->
            </div>

            <!-- Contenu Rôles -->
            <div x-show="settingsTab === 'roles'"
                 x-data="roleSettings()">
                <!-- Déplacer ici exactement le HTML intérieur du div #wr-roles -->
            </div>

        </div>
    </div>

Ajouter la propriété settingsTab: 'timers' dans le store waitingRoom().

L'overlay se ferme au clic extérieur (@click.self déjà présent sur l'overlay).
Les stores Alpine timerSettings() et roleSettings() restent inchangés —
déplacer uniquement leur HTML, pas leur logique JS.

Ne pas modifier les endpoints ni les services.

Mettre à jour BUGS_AND_ROADMAP.md :
- Section BUGS CORRIGÉS : suppression bloc "Exclure un joueur" dupliqué
- Section ROADMAP : cocher/supprimer l'item "waiting-room.blade.php : bloc
  Exclure un joueur dupliqué"

Mettre à jour TODO.md : cocher [x] le Prompt E en Phase 17.
Rien à ajouter dans DECISIONS.md.

git add -A && git commit -m "feat(ux): config host (timers + rôles) déplacée en modale, bloc dupliqué supprimé"
```

---

## ÉTAPE 4 — Rôles v1.2 : Sorcière et Chasseur

```
AVANT DE COMMENCER :
git checkout -b feature/roles-v1-2

Prérequis : tous les Prompts A → E de la Phase 17 sont mergés sur dev.

Contexte :
- Lis SPEC.md §3 (enum game_players.role), §4 (règles métier), §8 (extensibilité).
- Lis SPEC_TIMERS.md en entier — pattern endpoint volontaire / job auto.
- Lis SPEC_TRANSITIONS.md §4 (messages privés rôles actifs).
- Lis RISK_GUARDS.md en entier — guards obligatoires #2, #3, #4, #5, #6.
- Lis DECISIONS.md — entrées mentionnant RoleDistributor, isVillagerSide(),
  processeurs de nuit.
- Lis CODE_SNAPSHOT.md pour cibler les fichiers concernés.
- Les Étapes 2 et 3 sont mergées sur dev.

Objectif : ajouter Sorcière et Chasseur.
Ne pas implémenter Loup Blanc, Cupidon, Petite Fille (v1.3+).
```

### Ordre nocturne v1.2

> ⚠️ Différent de v1.1 — la Sorcière agit après les Loups pour connaître la victime.

1. Voyante → `/seer/done` OU `ProcessSeerAutoAction`
2. Loups → résolution anticipée OU `ProcessNightAutoAction`
3. Sorcière → `/witch/act` OU `ProcessWitchAutoAction`

### Éléments à créer

**Backend**

- Migration : ajout `witch` et `hunter` à l'enum `game_players.role`
- `GamePlayer::isVillagerSide()` mis à jour
- `RoleDistributor` : lire `settings['roles']` (witch, hunter optionnels)
- `ProcessWitchTurn`, `ProcessWitchAutoAction`
- `ProcessHunterTurn`, `ProcessHunterAutoAction`
- `POST /game/{id}/witch/act`
- `POST /game/{id}/hunter/shoot`
- `POST /game/{id}/settings/roles`
- Events : `WitchTurnStarted`, `WitchActed`, `HunterTurnStarted`, `HunterShot`

**Frontend**

- `night.blade.php` — sections Sorcière et Chasseur conditionnelles
- `waiting-room.blade.php` — panneau composition rôles dans la modale ⚙️ (onglet Rôles)

### Règles métier Sorcière

- 1 potion de soin + 1 potion de mort, une fois chacune par partie
- État des potions dans `game_players.settings['witch_heal_used']` / `['witch_kill_used']`
- Pas d'auto-sauvetage si elle est la victime
- Pas de double action la même nuit

### Règles métier Chasseur

- Agit uniquement à sa mort (nuit ou jour), timer 15s
- Si inactif → pas d'élimination supplémentaire
- Son tir déclenche `WinConditionChecker` après élimination

### Guards obligatoires à vérifier avant d'écrire chaque fichier

| Guard          | Fichiers concernés                              |
|----------------|-------------------------------------------------|
| `RISK_GUARD #2`| `ProcessHunterTurn`, `ProcessNightEnd`, `VoteService::resolveDayVote()` |
| `RISK_GUARD #3`| `ProcessWitchTurn`, `ProcessWitchAutoAction`    |
| `RISK_GUARD #4`| `ProcessNightActions`, `ProcessWitchTurn`       |
| `RISK_GUARD #5`| Toute méthode `PhaseManager` modifiée           |
| `RISK_GUARD #6`| `ProcessWitchTurn::handle()`                    |

### Tests à créer

- `tests/Feature/Game/WitchTest.php`
- `tests/Feature/Game/HunterTest.php`

Voir `SPEC_TIMERS.md §8` pour les noms de tests.

```bash
git add -A && git commit -m "feat(roles): add Witch and Hunter for v1.2"
```

---

## ÉTAPE 5 — Tests d'intégration v1.2

```
AVANT DE COMMENCER :
git checkout -b feature/tests-v1-2

Prérequis :
- Tous les Prompts A → E de la Phase 17 sont mergés sur dev.
- Les Étapes 2, 3 et 4 sont mergées sur dev.
- Lancer php artisan test avant de commencer — tous les tests existants passent.

Contexte :
- Lis SPEC_TIMERS.md §8 (notes Claude Code — tests auto_action).
- Lis SPEC_TRANSITIONS.md §10 (tests à écrire).
- Lis RISK_GUARDS.md (tableau "Tests obligatoires par guard").

Objectif : couverture complète v1.2. Aucune modification de code applicatif.
```

### Fichiers à créer

**1. `tests/Feature/Game/AutoActionTest.php`**

Voyante :

- `test_seer_auto_action_skipped_if_already_acted()`
- `test_seer_auto_action_passes_to_wolves_if_inactive()`
- `test_seer_done_endpoint_dispatches_wolves_immediately()`
- `test_seer_done_rejected_if_already_acted()`
- `test_seer_done_rejected_if_self_target()`

Sorcière :

- `test_witch_auto_action_skipped_if_already_acted()`
- `test_witch_auto_action_passes_if_inactive()`

Chasseur :

- `test_hunter_auto_action_skipped_if_already_shot()`
- `test_hunter_auto_action_no_elimination_if_inactive()`

**2. `tests/Feature/Game/PhaseAnnouncementTest.php`**

- `test_night_fall_broadcasted_on_start_night()`
- `test_day_break_broadcasted_on_end_night()`
- `test_seer_turn_not_broadcasted_publicly()`
- `test_phase_announcement_uses_public_channel()`
- `test_public_phase_duration_is_constant()`

**3. `tests/Feature/Game/ReconnectionTest.php`**

- `test_state_endpoint_retourne_phase_courante()`
- `test_state_traduit_wolves_turn_en_night()`
- `test_state_traduit_processing_day_en_day()`

**4. `tests/Feature/Game/RoleSettingsTest.php`**

- `test_host_peut_activer_sorciere()`
- `test_host_peut_activer_chasseur()`
- `test_role_distributor_inclut_sorciere_si_configuree()`
- `test_role_distributor_remplit_villageois_automatiquement()`
- `test_deux_sorcieres_impossibles()`
- `test_villageois_residuels_toujours_positifs()`

**5. Guards obligatoires (RISK_GUARDS.md)**

- `test_timer_fallback_si_settings_null()`
- `test_chasseur_tire_apres_resolution_complete_de_nuit()`
- `test_chasseur_ne_tire_pas_avant_day_started()`
- `test_sorciere_auto_action_sans_victime_ne_bloque_pas()`
- `test_witch_turn_skipped_si_egalite_loups()`
- `test_witch_turn_dispatche_par_night_actions_uniquement()`
- `test_apply_transition_hors_transaction_uniquement()`
- `test_witch_turn_non_double_dispatche_meme_round()`

### Exécution

```bash
php artisan test --filter=AutoActionTest
php artisan test --filter=PhaseAnnouncementTest
php artisan test --filter=ReconnectionTest
php artisan test --filter=RoleSettingsTest
php artisan test   # suite complète — 100% vert avant le tag
```

### Commit et tag final

```bash
git add -A && git commit -m "test: integration tests for v1.2"

git checkout dev
git merge feat/settings-modal --no-ff
git merge feature/roles-v1-2 --no-ff
git merge feature/tests-v1-2 --no-ff
git tag v1.2.0
git push origin v1.2.0
```
