# SPEC_TRANSITIONS.md — Système d'annonces et transitions de phases

Fichier de contexte pour Claude Code. Lire intégralement avant toute modification des transitions ou de l'UI des phases.

---

## 1. PRINCIPE GÉNÉRAL

Les transitions entre phases (nuit → jour, jour → nuit, élection, succession, etc.) sont matérialisées par des **annonces visuelles (overlay plein écran)** qui informent les joueurs du déroulement sans révéler d'informations sensibles.

Deux types d'annonces :

| Type           | Canal                               | Destinataires                          | Contenu                                                          |
|----------------|-------------------------------------|----------------------------------------|------------------------------------------------------------------|
| Publique       | `game.{gameId}`                     | Tous les joueurs (vivants + spectateurs) | Message neutre, durée fixe, ne mentionne jamais un rôle spécifique |
| Privée active  | `game.{gameId}.player.{playerId}`   | Joueur dont c'est le tour              | Message personnalisé + timer individuel                          |

**Règle anti-fuite :** la durée d'une annonce publique est fixe et identique quelle que soit l'action réelle ou l'inaction du rôle actif.

---

## 2. OPTION C HYBRIDE (VALIDÉE)

Chaque transition utilise un overlay plein écran avec une durée fixe, puis retour à l'état du jeu.

### Comportement

- Broadcast `PhaseAnnouncement` sur `game.{gameId}` (public)
- Le client stocke dans `announcements[]`
- Affichage séquentiel des overlays (FIFO strict, sans chevauchement)
- UI bloquée pendant l'overlay (`pointer-events: none` sur la zone de jeu)
- Fin de l'overlay → interface de la phase courante prend le relais

### Cas particulier (rôle actif)

- Le canal public affiche uniquement la transition neutre (`night_fall`, `day_break`)
- Après fermeture de l'overlay public, le rôle actif reçoit ses events privés (`SeerTurnStarted`, etc.)
- Aucun overlay intermédiaire entre deux actions nocturnes côté public

---

## 3. NOUVEL EVENT PhaseAnnouncement

### 3.1 Définition

```php
<?php

namespace App\Events\Game;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PhaseAnnouncement implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int    $gameId,
        public string $type,
        public string $messagePublic,
        public int    $durationMs
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("game.{$this->gameId}")];
    }

    public function broadcastAs(): string
    {
        return 'phase.announcement';
    }
}
```

### 3.2 Tableau des durées fixes (publiques)

| Type               | Durée (ms) | Message public                                        | Moment de déclenchement              |
|--------------------|------------|-------------------------------------------------------|--------------------------------------|
| `night_fall`       | 3000       | "Le village s'endort…"                               | `PhaseManager::startNight()`         |
| `day_break`        | 3000       | "L'aube approche…"                                   | `PhaseManager::endNight()`           |
| `mayor_election`   | 4000       | "Élection du Maire. Que la sagesse guide vos votes !" | `PhaseManager::startMayorElection()` |
| `mayor_succession` | 3000       | "Le Maire a succombé. Un nouveau va prendre sa place."| `ProcessMayorSuccession::handle()`   |
| `game_finished`    | 4000       | "Le village a triomphé !" / "Les loups ont dévoré le village !" | `WinConditionChecker`     |
| `game_cancelled`   | 5000       | "Partie annulée (trop d'inactifs)."                  | `GameService::checkInactivity()`     |

**Important :** les types `seer_turn` et `werewolves_turn` n'existent **jamais** sur le canal public. Pendant l'intervalle entre `night_fall` et `day_break`, l'affichage public reste sur un écran "nuit" neutre sans aucune annonce.

---

## 4. MESSAGES PRIVÉS (RÔLES ACTIFS)

Ces messages sont envoyés uniquement sur le canal privé du joueur concerné, après la fermeture de l'overlay public.

| Rôle            | Type              | Message                                                          | Canal                          |
|-----------------|-------------------|------------------------------------------------------------------|--------------------------------|
| Voyante         | `seer_turn`       | "C'est à ton tour, Voyante. Désigne un joueur à inspecter."    | `game.{id}.player.{playerId}` |
| Loup            | `werewolves_turn` | "Réveillez-vous, loups. Choisissez votre proie."               | `game.{id}.werewolves`        |
| Sorcière (v1.2) | `witch_turn`      | "Sorcière, veux-tu sauver ou tuer quelqu'un ?"                 | `game.{id}.player.{playerId}` |
| Chasseur (v1.2) | `hunter_turn`     | "Chasseur, avant de mourir, emporte quelqu'un avec toi."       | `game.{id}.player.{playerId}` |

**Règle :** un message privé n'est jamais envoyé si le joueur ciblé est mort ou si le rôle est absent de la composition.

---

## 5. FILE D'ANNONCES ALPINE.JS

### 5.1 Propriétés et méthodes ajoutées au store gameState

```javascript
// game-state.js — à ajouter dans l'objet retourné par gameState()
{
    announcements: [],    // file FIFO d'objets { type, message, durationMs }
    isAnnouncing: false,  // true pendant l'affichage d'un overlay

    // Appelé par le listener Echo sur 'phase.announcement'
    addAnnouncement(type, message, durationMs) {
        this.announcements.push({ type, message, durationMs });
        this.consumeAnnouncements();
    },

    consumeAnnouncements() {
        if (this.isAnnouncing) return;
        if (this.announcements.length === 0) return;

        this.isAnnouncing = true;
        const next = this.announcements.shift();

        // window.dispatchEvent (pas $dispatch) pour atteindre le composant overlay
        window.dispatchEvent(new CustomEvent('show-announcement', { detail: next }));
        // Le composant overlay appellera window.dispatchEvent('announcement-done')
        // après durationMs, ce qui déclenche onAnnouncementDone()
    },

    onAnnouncementDone() {
        this.isAnnouncing = false;
        // Appliquer les events de phase mis en attente pendant l'overlay
        if (this._pendingNightStarted)  { this._applyNightStarted(this._pendingNightStarted);  this._pendingNightStarted  = null; }
        if (this._pendingDayStarted)    { this._applyDayStarted(this._pendingDayStarted);      this._pendingDayStarted    = null; }
        if (this._pendingMayorElected)  { this._applyMayorElected(this._pendingMayorElected);  this._pendingMayorElected  = null; }
        this.consumeAnnouncements(); // passe à l'annonce suivante si file non vide
    }
}
```

### 5.2 Events de phase à différer pendant un overlay

Certains events modifient l'interface de façon incompatible avec un overlay actif.
Ils sont mis en attente dans une propriété `_pendingX` et appliqués dans `onAnnouncementDone()`.

| Event WebSocket   | Comportement si `isAnnouncing = true`                    |
|-------------------|----------------------------------------------------------|
| `NightStarted`    | → `this._pendingNightStarted = e` ; return              |
| `DayStarted`      | → `this._pendingDayStarted = e` ; return                |
| `MayorElected`    | → `this._pendingMayorElected = e` ; return              |

| Event WebSocket      | Comportement si `isAnnouncing = true`                 |
|----------------------|-------------------------------------------------------|
| `GameFinished`       | Passe **immédiatement** (urgent, prime sur tout)      |
| `PlayerDisconnected` | Passe **immédiatement**                               |
| `PlayerInactive`     | Passe **immédiatement**                               |
| `ChatMessageSent`    | Passe **immédiatement** (chat non bloqué)             |
| `WerewolvesVoteCast` | Passe **immédiatement** (mise à jour interne)         |
| `DayVoteCast`        | Passe **immédiatement** (mise à jour interne)         |

**Règle de tri :** on diffère uniquement les events qui déclenchent une transition d'écran ou un changement de phase visible. Les events de mise à jour d'état interne passent toujours immédiatement.

### 5.3 Abonnement Echo à ajouter dans game-state.js

```javascript
// Dans la méthode d'init du store, après les abonnements existants
window.Echo.channel(`game.${this.gameId}`)
    .listen('.phase.announcement', (e) => {
        this.addAnnouncement(e.type, e.messagePublic, e.durationMs);
    });

// Écoute du signal de fin d'overlay depuis le composant
window.addEventListener('announcement-done', () => {
    this.onAnnouncementDone();
});
```

**⚠️ Ordre d'abonnement :** l'abonnement à `.phase.announcement` doit être déclaré **avant** les listeners `NightStarted` et `DayStarted`, pour que la file soit alimentée avant que ces events tentent d'être différés.

### 5.4 Composant overlay

```html
<!-- resources/views/components/announcement-overlay.blade.php -->
<div
    x-data="announcementOverlay()"
    x-show="active"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/90 backdrop-blur-sm"
    style="pointer-events: all;"
>
    <div class="text-center text-[#e8e0d0] px-6">
        <p x-text="message" class="text-2xl md:text-4xl font-cinzel tracking-widest"></p>
    </div>
</div>

<script>
function announcementOverlay() {
    return {
        active: false,
        message: '',
        _timer: null,

        init() {
            window.addEventListener('show-announcement', (e) => {
                const { message, durationMs } = e.detail;
                this.message = message;
                this.active = true;

                if (this._timer) clearTimeout(this._timer);

                if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    gsap.fromTo('.announcement-text',
                        { opacity: 0, y: 20 },
                        { opacity: 1, y: 0, duration: 0.5, ease: 'power2.out' }
                    );
                }

                this._timer = setTimeout(() => {
                    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        gsap.to('.announcement-text', {
                            opacity: 0, duration: 0.4, ease: 'power2.in',
                            onComplete: () => {
                                this.active = false;
                                window.dispatchEvent(new CustomEvent('announcement-done'));
                            }
                        });
                    } else {
                        this.active = false;
                        window.dispatchEvent(new CustomEvent('announcement-done'));
                    }
                }, durationMs);
            });
        }
    };
}
</script>
```

### 5.5 Intégration dans le layout

```html
<!-- layouts/game.blade.php -->
<body>
    <!-- Store gameState monté sur div fantôme (hors <main>, règle DECISIONS.md) -->
    <div x-data="gameState({{ $game->id }}, {{ auth()->id() }})"
         style="visibility:hidden;position:absolute;width:0;height:0;"></div>

    <!-- Overlay d'annonce — au-dessus de tout -->
    <x-announcement-overlay />

    <main class="relative z-10">
        @yield('content')
    </main>
</body>
```

---

## 6. COMPORTEMENT À LA RECONNEXION

### Principe

Un joueur qui se reconnecte ne voit **jamais** les annonces manquées.
Il reçoit directement l'état courant via `GET /game/{code}/state`.

### Séquence côté client

```javascript
// Dans game-state.js, à la reconnexion (handleReconnection ou syncState)
this.announcements = [];        // vider la file
this.isAnnouncing = false;      // reset du flag
this._pendingNightStarted  = null;
this._pendingDayStarted    = null;
this._pendingMayorElected  = null;
// Appliquer l'état courant directement depuis /state
// Afficher le toast de reconnexion
```

### Messages du toast de reconnexion

| `status` retourné par `/state`           | Message toast                                    |
|------------------------------------------|--------------------------------------------------|
| `electing_mayor`                         | "Reconnecté — Élection du Maire, round {{N}}"   |
| `night` / `wolves_turn` / `processing_night` | "Reconnecté — Nuit en cours, round {{N}}"   |
| `day` / `processing_day`                 | "Reconnecté — Jour en cours, round {{N}}"       |
| `finished`                               | "Reconnecté — La partie est terminée."          |

Les statuts intermédiaires (`wolves_turn`, `processing_night`, `processing_day`) sont traduits en leur phase parente. Ne jamais exposer les statuts techniques aux joueurs.

**Les overlays sont éphémères et non persistés.** Le système ne doit jamais reconstruire une séquence d'annonces passée.

---

## 7. DISPATCH CÔTÉ SERVEUR

### Où et quand broadcaster PhaseAnnouncement

`PhaseAnnouncement` est broadcasté par `PhaseManager` (et `WinConditionChecker` pour la fin de partie), **après** la transaction DB et **avant** l'event de phase principal — dans la même séquence que les autres broadcasts post-transaction existants.

```
PhaseManager::startNight()        → broadcast(PhaseAnnouncement night_fall)    → broadcast(NightStarted)
PhaseManager::endNight()          → broadcast(PhaseAnnouncement day_break)     → broadcast(DayStarted)
PhaseManager::startMayorElection()→ broadcast(PhaseAnnouncement mayor_election)→ broadcast(MayorElectionStarted)
ProcessMayorSuccession::handle()  → broadcast(PhaseAnnouncement mayor_succession) → broadcast(MayorSuccessionDone)
WinConditionChecker::check()      → broadcast(PhaseAnnouncement game_finished)  → broadcast(GameFinished)
GameService::checkInactivity()    → broadcast(PhaseAnnouncement game_cancelled) → broadcast(GameFinished)
```

Le serveur ne connaît pas la durée d'affichage côté client — les events sont broadcastés immédiatement à la suite. C'est le client qui gère l'ordre via la file `announcements[]` et le mécanisme de différement.

---

## 8. EXEMPLES DE FLUX COMPLETS

### 8.1 Nuit avec voyante vivante

1. Fin du jour → `PhaseManager::startNight()`
2. Broadcast public : `PhaseAnnouncement(night_fall, 3000ms)`
3. Broadcast public : `NightStarted { round, timer }` → mis en `_pendingNightStarted` si overlay actif
4. Client : overlay "Le village s'endort…" pendant 3000ms
5. Fin overlay → `onAnnouncementDone()` → applique `_pendingNightStarted`
6. [délai `night_start_delay` = 4s] → `SeerTurnStarted` (privé voyante)
7. Voyante agit (POST `/seer/done`) OU `ProcessSeerAutoAction` (timer)
8. `WerewolvesTurnStarted` (privé loups)
9. Loups votent → `ProcessNightActions`
10. `PhaseManager::endNight()` → broadcast public : `PhaseAnnouncement(day_break, 3000ms)`
11. Broadcast public : `DayStarted` → mis en `_pendingDayStarted` si overlay actif
12. Client : overlay "L'aube approche…" pendant 3000ms
13. Fin overlay → applique `_pendingDayStarted` → interface jour

### 8.2 Nuit sans voyante (morte ou absente)

1. `startNight()` → `PhaseAnnouncement(night_fall, 3000ms)` + `NightStarted`
2. `ProcessSeerTurn` détecte voyante absente → dispatche `ProcessSeerAutoAction` sans délai
3. `ProcessSeerAutoAction` : Guard 2 → pas de seer_check, voyante morte → return immédiat
4. `ProcessWerewolvesTurn` dispatché directement
5. Suite identique à 8.1 à partir de l'étape 8

### 8.3 Annulation de partie

1. `GameService::checkInactivity()` détecte > 50% inactifs
2. Broadcast public : `PhaseAnnouncement(game_cancelled, 5000ms)`
3. Client : overlay "Partie annulée (trop d'inactifs)." pendant 5000ms
4. Après 5000ms : redirection vers `/game/{code}/cancelled`

---

## 9. RÈGLES DE SÉCURITÉ ANTI-FUITE

### Interdictions strictes sur le canal public

- Aucun rôle nommé (voyante, loup, sorcière…)
- Aucun timing dépendant de l'action réelle ou de l'inaction
- Aucune indication "personne n'a agi" ou "le joueur a passé son tour"
- Aucun état interne du backend
- Aucun nombre de joueurs par rôle

### Principe fondamental

> Le canal public doit rester **identique dans toutes les parties**, indépendamment des rôles présents, des décisions prises et de la vitesse des joueurs.

---

## 10. TESTS À ÉCRIRE (v1.2 — Étape 5)

| Test                                         | Description                                              |
|----------------------------------------------|----------------------------------------------------------|
| `test_night_fall_broadcasted_on_start_night` | `startNight()` broadcaste `night_fall` avant `NightStarted` |
| `test_seer_turn_not_broadcasted_publicly`    | Jamais de `PhaseAnnouncement` de type `seer_turn` sur canal public |
| `test_overlay_queue_executes_in_order`       | FIFO strict : 2 annonces en file → affichage séquentiel sans chevauchement |
| `test_reconnection_clears_announcement_queue`| Reconnexion → `announcements[]` vidé, pas de replay     |
| `test_public_phase_duration_is_constant`     | Durée `night_fall` identique qu'une voyante ait agi ou non |

---

## 11. NOTES D'IMPLÉMENTATION POUR CLAUDE CODE

1. **`PhaseAnnouncement` est toujours sur le canal public** — jamais sur un canal privé. Les informations sensibles passent uniquement par les events privés existants (`SeerResult`, `WerewolvesTurnStarted`, etc.).

2. **Ne jamais bloquer le serveur** en attendant la fin d'une annonce. Le serveur broadcaste et continue. C'est le client qui synchronise l'affichage.

3. **`isAnnouncing` est une propriété publique du store** — elle est nécessaire pour que le composant overlay puisse réagir via `$watch`. Ne pas la renommer en propriété privée.

4. **`pointer-events: all` sur l'overlay** — l'UI sous-jacente est bloquée pendant l'affichage. C'est intentionnel : empêche les clics accidentels pendant une transition.

5. **prefers-reduced-motion** : si actif, l'overlay apparaît et disparaît instantanément sans animation GSAP. La durée `durationMs` s'écoule quand même — le message est affiché pendant toute la durée.

6. **`window.dispatchEvent` pour tous les events cross-composants** (règle DECISIONS.md "Double abonnement Echo"). Ne jamais utiliser `$dispatch()` pour communiquer entre `gameState` et `announcementOverlay`.