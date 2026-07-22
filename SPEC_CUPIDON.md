# SPEC_CUPIDON.md — Rôle Cupidon (v1.3)

Statut : validé, prêt pour découpage en tâches d'implémentation.
Ne remplace pas `SPEC.md` — vient en complément, section dédiée à un seul rôle.

---

## 1. Règles de jeu (décisions validées)

- Cupidon agit **une seule fois, uniquement au round 1**, avant tout le monde
  (avant la Voyante). Absent des rounds suivants.
- Cupidon **peut se choisir lui-même** comme un des deux amoureux.
- Si les deux amoureux sont les deux derniers survivants (même camp ou camps
  opposés, y compris loup+loup), **victoire spéciale du camp "amoureux"** —
  prioritaire sur les conditions de victoire loups/village classiques.
- **Confidentialité totale** : le reste de la table ne sait jamais qui sont
  les amoureux. Seuls les deux concernés apprennent l'identité de l'autre.
- **Timeout sans choix** (fin du timer, aucune action volontaire) → **aucun
  couple n'est formé**, pas de tirage aléatoire. La partie continue sans
  camp amoureux pour ce round. Décision assumée : contrairement au Chasseur
  (tir aléatoire au timeout), imposer un couple au hasard a des conséquences
  trop lourdes (camp de victoire entier) pour être laissé au hasard.

## 2. Modèle de données

### `game_players` — nouvelle colonne

```php
$table->foreignId('lover_player_id')->nullable()
    ->constrained('game_players')->nullOnDelete();
```

Posée symétriquement sur les deux joueurs une fois Cupidon a choisi
(`playerA->lover_player_id = playerB->id` ET `playerB->lover_player_id = playerA->id`).
La logique de jeu (cascade de mort, condition de victoire) lit **toujours**
cette colonne, jamais l'historique `game_actions` — même principe que
`is_mayor`/`is_alive`, jamais recalculés depuis un log.

### `game_actions` — historique uniquement

Deux lignes créées pour la traçabilité (`GET /game/{code}/history`), type
`cupidon_link`, round 1, phase `night`, `player_id` = Cupidon, `target_player_id`
= chaque amoureux sur sa propre ligne. **Jamais lues par la logique de jeu.**

### Enums à étendre

- `ActionType` : `+ cupidon_link`
- `PlayerRole` : `+ cupidon`
- `WinnerTeam` : `+ lovers`

## 3. Séquence nocturne — intégration

```
startNight() :
  si round === 1 :
    ProcessCupidonTurn (delay night_start_delay)
      → broadcast CupidonTurnStarted (canal privé cupidon)
      → ProcessCupidonAutoAction (delay = cupidonTimer)
           → si pas d'action volontaire : rien, aucun couple. Puis ProcessSeerTurn.
      → si action volontaire (CupidonAction::link) : pose lover_player_id
           symétrique, notifie les 2 amoureux en privé, puis ProcessSeerTurn.
  si round > 1 :
    ProcessSeerTurn directement (Cupidon absent, comme aujourd'hui)
```

`PhaseGuard::canCupidonLink()` : `$game->round === 1 && $game->status === 'night'`.

Timer proposé : `TIMER_CUPIDON = 30s`, configurable par le host comme les
autres timers v1.2 (fallback `config/game.php`).

## 4. CupidonAction — contrat

```php
class CupidonAction extends RoleAction
{
    public function link(GamePlayer $cupidon, int $target1Id, int $target2Id): void
}
```

Guards, dans l'ordre :
1. Rôle : `$cupidon->role !== 'cupidon'` → 403
2. Phase : `PhaseGuard::canCupidonLink($game)` → 409
3. Anti-double-action : `$this->guardNotAlreadyActed($game, $cupidon->id, ['cupidon_link'])` → 409
4. Validation cibles : `target1Id !== target2Id` (on ne peut pas coupler quelqu'un avec lui-même) → 422. `target1Id` ou `target2Id` **peut** valoir `$cupidon->id` (auto-sélection autorisée).
5. Les deux cibles doivent être des joueurs vivants de la partie → 404/422

Transaction : pose `lover_player_id` sur les deux `GamePlayer`, crée les 2
`GameAction` d'historique, broadcast des 2 events privés (1 seul si Cupidon
s'est choisi lui-même).

## 5. Mort en cascade — centralisation de l'élimination

**Constat :** `is_alive = false` est aujourd'hui posé à 4 endroits différents
(résolution vote loups, `WitchAction`, `VoteService::resolveDayVote`, cible du
Chasseur). Dupliquer la logique de cascade amoureux dans ces 4 endroits
reproduirait exactement le problème déjà corrigé pour le guard anti-double-action
(tâche 5 du refactoring) — avec un risque plus grave : une cascade oubliée à
un seul endroit = bug silencieux en prod (l'amoureux restant ne meurt pas).

**Décision validée :** créer un point d'entrée unique pour éliminer un joueur.

```php
// App\Services\PlayerEliminationService
public function eliminate(GamePlayer $player): void
{
    // pose is_alive = false
    // si $player->lover_player_id existe et que l'amoureux est encore vivant :
    //   appel récursif eliminate() sur l'amoureux (cascade)
}
```

Les 4 call sites existants migrent vers `app(PlayerEliminationService::class)->eliminate($player)`
au lieu de `$player->update(['is_alive' => false])` en direct. **Ceci est un
prérequis à traiter avant l'implémentation de Cupidon**, sur sa propre branche
(voir tâche 6 du refactoring, à ajouter à `REFACTOR_PROMPTS.md`).

## 6. Condition de victoire

Dans `WinConditionChecker::check()`, **avant** le calcul loups/village :

```
si nb_vivants === 2 ET les 2 vivants sont mutuellement lover_player_id l'un de l'autre :
    winner_team = 'lovers' → fin de partie, court-circuite tout le reste

sinon : calcul loups/village inchangé (nb_loups_vivants >= nb_autres_vivants, etc.)
```

Fonctionne même si les deux amoureux sont loup+loup ou loup+villageois — le
camp amoureux prime sur tout dès qu'il ne reste plus que les deux amoureux
en vie, quel que soit leur camp d'origine.

## 7. Frontend

Écran/modale Cupidon sur le modèle exact de `HunterTurnStarted` (canal privé,
liste des joueurs vivants, timer visuel). Un seul écran suffit — sélection
de 2 cibles au lieu d'1 (le joueur clique 2 avatars au lieu d'1, POST unique
avec les 2 ids).

Chaque amoureux voit, une fois le lien posé, un toast/écran personnel
« Tu es amoureux de [pseudo] » — jamais affiché aux autres joueurs.

## 8. Découpage en tâches d'implémentation (à venir)

1. Migration `lover_player_id` + extension des enums
2. `PlayerEliminationService` + migration des 4 call sites (prérequis)
3. `CupidonAction extends RoleAction` + `ProcessCupidonTurn`/`ProcessCupidonAutoAction`
4. Intégration dans `PhaseManager::startNight()` (condition round === 1)
5. `WinConditionChecker` — priorité camp amoureux
6. Frontend (modale + events privés + écran amoureux)
7. Tests d'intégration `CupidonTest.php` (couvrant : auto-sélection, timeout
   sans couple, cascade de mort, victoire amoureux loup+villageois)
