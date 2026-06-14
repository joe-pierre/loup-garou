# RISK_GUARDS.md — Protections anti-bugs obligatoires

> Fichier de contexte pour Claude Code.
> Lire intégralement avant toute implémentation des Étapes 3, 4 et 5.
> Ces protections sont des contraintes d'architecture obligatoires —
> pas des corrections à effectuer après apparition des bugs.

---

## RÈGLE GÉNÉRALE

Avant d'écrire chaque fichier PHP ou JS :
1. Relire la section correspondante dans ce document.
2. Vérifier que le code n'introduit aucun des patterns dangereux listés ci-dessous.
3. Ajouter les protections avant même qu'un bug ne se produise.
4. Si un cas n'est pas clair, demander une clarification avant d'implémenter.

---

## GUARD #1 — Initialisation de `game.settings['timers']` (Étape 3)

### Symptôme sans protection
```
ErrorException: Undefined index 'timers'
```
Quand un host n'a jamais sauvegardé de timers et que `$game->settings` est null.

### Pattern obligatoire dans TimerCalculator::get()
```php
$settings = $game->settings['timers'] ?? [];  // ✅ jamais $game->settings['timers'] directement
```

### Pattern obligatoire dans GameService::updateTimerSettings()
```php
$currentSettings = $game->settings ?? [];           // ✅ null-safe
$currentSettings['timers'] = $validatedTimers;
$game->update(['settings' => $currentSettings]);
// ❌ Interdit : $game->update(['settings->timers' => ...])
// ❌ Interdit : $game->settings['timers'] = ... sans initialiser settings
```

### Pattern obligatoire dans waiting-room.blade.php
```javascript
// ✅ Toujours un fallback sur la valeur par défaut config
timers: {
    seer: @json($game->settings['timers']['seer'] ?? 30),
    // ...
}
// ❌ Interdit : @json($game->settings['timers']['seer']) sans fallback
```

---

## GUARD #2 — Tir du Chasseur au bon moment (Étape 4)

### Symptôme sans protection
Le Chasseur meurt la nuit mais ne tire jamais, ou tire avant la résolution
complète de la nuit (avant `DayStarted`), ce qui casse l'ordre des events.

### Mécanisme obligatoire : cache intermédiaire

Dans `GameService::killPlayer()` (ou équivalent) :
```php
if ($player->isHunter() && $game->status !== 'finished') {
    Cache::put(
        "hunter_must_shoot_{$game->id}",
        $player->id,
        now()->addMinutes(10)
    );
}
// ❌ Interdit : ProcessHunterTurn::dispatch() directement depuis killPlayer()
//    car la séquence nocturne n'est pas encore terminée
```

Dans `ProcessNightEnd::handle()`, avant d'appeler `endNight()` :
```php
$hunterId = Cache::pull("hunter_must_shoot_{$game->id}");

if ($hunterId) {
    ProcessHunterTurn::dispatch($game->id, $game->round, $hunterId)->delay(0);
    return; // ProcessHunterTurn appellera startDay() après le tir ou le timeout
}

$phaseManager->endNight($game); // chemin normal sans chasseur
```

### Cas mort le jour (dans VoteService::resolveDayVote())
```php
// Après $eliminated->update(['is_alive' => false])
if ($eliminated->isHunter()) {
    ProcessHunterTurn::dispatch($game->id, $game->round, $eliminated->id)->delay(0);
    return; // ProcessHunterTurn gère la suite (startNight ou fin de partie)
}
// Continuer la résolution normale sinon
```

### Dans ProcessHunterTurn::handle() — toujours appeler la transition suivante
```php
// Que le chasseur ait tiré ou non (timeout), toujours :
if ($game->status === 'night' || in_array($game->status, ['processing_night'])) {
    app(PhaseManager::class)->endNight($game);
} else {
    app(PhaseManager::class)->startNight($game); // contexte jour → passer à la nuit
}
// ❌ Interdit : return sans déclencher la transition suivante
```

---

## GUARD #3 — Sorcière sans victime (égalité vote loups) (révisé)

Comportement révisé (fix/witch-turn-no-victim) :
- Pas de victime ET deux potions épuisées → ProcessNightEnd immédiat (skip)
- Pas de victime ET poison encore disponible → WitchTurnStarted avec victim:null,
  heal_available:false, kill_available:true
- Pas de victime ET soin disponible mais poison épuisé → skip (idem deux épuisées)

Pattern obligatoire dans ProcessWitchTurn::handle() :
```php
$witchSettings = $witch->settings ?? [];
$healUsed      = $witchSettings['witch_heal_used'] ?? false;
$killUsed      = $witchSettings['witch_kill_used'] ?? false;

if (! $victim && $healUsed && $killUsed) {
    ProcessNightEnd::dispatch(...)->delay(0);
    return;
}

$healAvailable = ! $healUsed && $victim !== null && $victim->id !== $witch->id;
$killAvailable = ! $killUsed;

if (! $victim && ! $killAvailable) {
    ProcessNightEnd::dispatch(...)->delay(0);
    return;
}
```

---

## GUARD #4 — Ordre d'exécution Sorcière après Loups (Étape 4)

### Symptôme sans protection
La Sorcière reçoit `WitchTurnStarted` avant que le vote des loups soit résolu,
ou voit des events destinés aux loups.

### Règle d'or : qui dispatche ProcessWitchTurn ?

```
✅ ProcessNightActions::handle()     — après résolution du vote loups + is_alive=false
✅ ProcessWerewolvesAutoAction::handle() — si timer loups expire sans vote unanime
❌ ProcessWerewolvesTurn::handle()   — JAMAIS (le vote n'est pas encore résolu)
❌ ProcessSeerAutoAction::handle()   — JAMAIS (mauvais moment dans la séquence)
```

### Ordre nocturne v1.2 — séquence exacte des dispatches

```
ProcessSeerTurn
  → ProcessSeerAutoAction (delay = timer seer)
     [Voyante agit via /seer/done OU ProcessSeerAutoAction expire]
  → ProcessWerewolvesTurn (delay = 0)
     → ProcessWerewolvesAutoAction (delay = timer werewolves)
        [Loups votent via /vote/night OU ProcessWerewolvesAutoAction expire]
  → ProcessNightActions (résolution vote loups, is_alive=false)
     → ProcessWitchTurn (delay = 0) — SI sorcière vivante
        → ProcessWitchAutoAction (delay = timer witch)
           [Sorcière agit via /witch/act OU ProcessWitchAutoAction expire]
     → ProcessNightEnd (delay = mayor_succession + 5s) — toujours dispatché
```

**`ProcessNightActions` est le seul point d'entrée de `ProcessWitchTurn`.**

### Dans ProcessNightActions::handle(), après résolution
```php
$witch = $game->players()
    ->where('role', 'witch')
    ->where('is_alive', true)
    ->first();

if ($witch) {
    ProcessWitchTurn::dispatch($game->id, $game->round)->delay(0);
} else {
    // Pas de sorcière — ProcessNightEnd gère la suite via son délai buffer
}

// ProcessNightEnd est toujours dispatché, quelle que soit la présence de la sorcière
ProcessNightEnd::dispatch($game->id, $game->round)
    ->delay(now()->addSeconds($game->timer('mayor_succession') + 5));
```

---

## GUARD #5 — applyTransition() dans lockForUpdate() (Étapes 2, 3, 4)

### Symptôme sans protection
```
LogicException: The workflow cannot apply transition "start_night"
```
ou deadlock MySQL si `applyTransition()` tente un `save()` pendant qu'un
verrou exclusif est tenu sur la même ligne.

### Règle absolue
```php
// ✅ DANS lockForUpdate() : canTransition() uniquement (lecture)
DB::transaction(function () use ($game, &$locked) {
    $locked = Game::where('id', $game->id)
        ->lockForUpdate()
        ->first();

    if (!$locked->canTransition($transitionName)) {
        \Log::warning("Transition refusée : {$transitionName} depuis {$locked->status}");
        return;
    }

    $locked->status = $newStatus; // ✅ écriture manuelle
    $locked->save();
});

// ✅ HORS lockForUpdate() : applyTransition() si aucun verrou actif
$game->applyTransition($transitionName);

// ❌ INTERDIT dans tous les cas :
DB::transaction(function () use ($game) {
    $locked = Game::lockForUpdate()->find($game->id);
    $locked->applyTransition('start_night'); // deadlock probable
});
```

### Vérification rapide
Si tu vois `applyTransition()` à l'intérieur d'un closure `DB::transaction()`
qui contient aussi un `lockForUpdate()` → c'est un bug. Toujours.

---

## GUARD #6 — Double dispatch de ProcessWitchTurn (Étape 4)

### Symptôme sans protection
La sorcière voit son interface deux fois, ou deux résolutions de tour sorcière
se produisent dans le même round.

### Pattern obligatoire dans ProcessWitchTurn::handle()
```php
// Guard identique au pattern ProcessSeerAutoAction
$existingAction = $game->actions()
    ->where('round', $this->round)
    ->whereIn('type', ['witch_heal', 'witch_kill', 'witch_pass'])
    ->exists();

if ($existingAction) {
    return; // déjà agi ce round
}

// Vérifier aussi que la sorcière est vivante
$witch = $game->players()
    ->where('id', $this->witchId)
    ->where('is_alive', true)
    ->first();

if (!$witch) {
    return;
}
```

---

## TABLEAU DE VÉRIFICATION PAR ÉTAPE

| Étape | Guards à vérifier avant de commencer |
|-------|--------------------------------------|
| 3     | Guard #1 (settings null-safe)         |
| 4     | Guards #2, #3, #4, #5, #6            |
| 5     | Tous — les tests doivent couvrir chaque guard |

---

## TESTS OBLIGATOIRES PAR GUARD

Ces tests doivent exister à la fin de l'Étape 5 :

| Guard | Test |
|-------|------|
| #1 | `test_timer_fallback_si_settings_null()` |
| #2 | `test_chasseur_tire_apres_resolution_complete_de_nuit()` |
| #2 | `test_chasseur_ne_tire_pas_avant_day_started()` |
| #3 | `test_sorciere_auto_action_sans_victime_ne_bloque_pas()` |
| #3 | `test_witch_turn_avec_poison_disponible_si_egalite_loups()` |
| #3 | `test_witch_turn_skipped_si_egalite_loups_et_potions_epuisees()` |
| #4 | `test_witch_turn_dispatche_par_night_actions_uniquement()` |
| #5 | `test_apply_transition_hors_transaction_uniquement()` |
| #6 | `test_witch_turn_non_double_dispatche_meme_round()` |