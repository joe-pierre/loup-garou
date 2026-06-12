# SPEC_TRANSITIONS.md — Système d’annonces et transitions de phases

Fichier de contexte pour Claude Code. Lire intégralement avant toute modification des transitions ou de l’UI des phases.

---

## 1. PRINCIPE GÉNÉRAL

Les transitions entre phases (nuit → jour, jour → nuit, élection, succession, etc.) sont matérialisées par des **annonces visuelles (overlay plein écran)** qui informent les joueurs du déroulement sans révéler d’informations sensibles.

Deux types d’annonces :

| Type     | Canal                         | Destinataires | Contenu |
|----------|------------------------------|--------------|--------|
| Publique | game.{gameId}               | Tous les joueurs (vivants + spectateurs) | Message neutre, durée fixe, ne mentionne jamais un rôle spécifique |
| Privée active | game.{gameId}.player.{playerId} | Joueur dont c’est le tour | Message personnalisé + timer individuel |

**Règle anti-fuite :** la durée d’une annonce publique est fixe et identique quelle que soit l’action réelle ou l’inaction du rôle actif.

---

## 2. OPTION C HYBRIDE (VALIDÉE)

Chaque transition utilise un overlay plein écran avec une durée fixe, puis retour à l’état du jeu.

### Comportement

- Broadcast `PhaseAnnouncement` sur `game.{gameId}` (public)
- Le client stocke dans `announcements[]`
- Affichage séquentiel des overlays
- UI bloquée pendant l’overlay
- Fin → refresh `/state`

### Cas particulier (rôle actif)

- Public affiche uniquement transition neutre
- Puis passage immédiat à l’écran privé
- Aucun overlay intermédiaire pour rôle actif

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
        public int $gameId,
        public string $type,
        public string $messagePublic,
        public int $durationMs
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


## 3.2 Tableau des durées fixes (publiques)

| Type d’annonce      | Durée (ms) | Contexte |
|--------------------|------------|----------|
| night_fall         | 3000       | “Le village s’endort…” (début de nuit) |
| seer_turn          | 2000       | “La voyante ouvre les yeux…” (canal privé seulement – pas de public pour ce message !) |
| werewolves_turn    | 2000       | “Les loups se réveillent…” (canal privé seulement) |
| day_break          | 3000       | “L’aube approche…” → “Le jour se lève” |
| mayor_election     | 4000       | “Élection du Maire…” |
| mayor_succession   | 3000       | “Le Maire est mort. Succession…” |

**Important :** Les annonces `seer_turn` et `werewolves_turn` ne sont jamais diffusées sur le canal public.  
À la place, le canal public reçoit un `night_fall` au début de la nuit, puis un `day_break` à la fin. Pendant l’intervalle, l’affichage public reste sur un écran “nuit” neutre (sans indiquer quelle action est en cours).

---

## 4. MESSAGES PUBLICS (SPECTATEURS)

Liste exhaustive des messages diffusés sur `game.{gameId}` (canal public), avec leur moment de déclenchement.

| Moment | Type | Message |
|--------|------|--------|
| Début de nuit (après vote jour) | night_fall | “Le village s’endort…” |
| Fin de nuit (avant jour) | day_break | “L’aube approche…” |
| Élection maire | mayor_election | “Élection du Maire. Que la sagesse guide vos votes !” |
| Succession maire | mayor_succession | “Le Maire a succombé. Un nouveau va prendre sa place.” |
| Victoire village | game_finished | “Le village a triomphé !” |
| Victoire loups | game_finished | “Les loups ont dévoré le village !” |
| Annulation | game_cancelled | “Partie annulée (trop d’inactifs).” |

Aucun autre message public n’est émis pendant la nuit.

---

## 5. MESSAGES PRIVÉS (RÔLES ACTIFS)

Ces messages sont envoyés uniquement sur le canal privé du joueur concerné.  
Ils peuvent être plus longs et contenir des informations spécifiques au rôle.

| Rôle | Type | Message (exemple) | Canal |
|------|------|------------------|------|
| Voyante | seer_turn | “C’est à ton tour, Voyante. Désigne un joueur à inspecter.” | game.{id}.player.{playerId} |
| Loup | werewolves_turn | “Réveillez-vous, loups. Choisissez votre proie.” | game.{id}.werewolves |
| Sorcière (v1.2) | witch_turn | “Sorcière, veux-tu sauver ou tuer quelqu’un ?” | game.{id}.player.{playerId} |
| Chasseur (v1.2) | hunter_turn | “Chasseur, avant de mourir, emporte quelqu’un avec toi.” | game.{id}.player.{playerId} |

**Durée privée :** chaque rôle actif dispose de son propre timer (voir SPEC_TIMERS.md).  
L’overlay public n’est pas affiché pendant ces actions — seul l’écran de jeu du rôle apparaît.

---

## 6. FILE D’ANNONCES ALPINE.JS

### 6.1 Store gameState – propriétés et méthodes ajoutées

```javascript
// game-state.js
{
    announcements: [],          // file d’objets { type, message, durationMs }
    isAnnouncing: false,        // un overlay est en cours d’affichage

    addAnnouncement(type, message, durationMs) {
        this.announcements.push({ type, message, durationMs });
        this.consumeAnnouncements();
    },

    consumeAnnouncements() {
        if (this.isAnnouncing) return;
        if (this.announcements.length === 0) return;

        this.isAnnouncing = true;
        const next = this.announcements.shift();

        // Déclencher l’affichage de l’overlay (via un composant dédié)
        this.$dispatch('show-announcement', next);
        // L’overlay émet un événement 'announcement-done' après durationMs
    },

    onAnnouncementDone() {
        this.isAnnouncing = false;
        this.consumeAnnouncements(); // passe à la suivante
    }
}
```


### 6.2 Composant overlay

```HTML
<!-- resources/views/components/announcement-overlay.blade.php -->
<div x-data="announcementOverlay()" x-show="active" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center bg-black/90 backdrop-blur-sm">
    
    <div class="text-center text-[#e8e0d0] px-6">
        <p x-text="message" class="text-2xl md:text-4xl font-cinzel animate-pulse"></p>
    </div>
</div>

<script>
function announcementOverlay() {
    return {
        active: false,
        message: '',
        timer: null,

        init() {
            this.$watch('$store.gameState.isAnnouncing', (val) => {
                if (!val) {
                    this.active = false;
                }
            });

            window.addEventListener('show-announcement', (e) => {
                const { message, durationMs } = e.detail;

                this.message = message;
                this.active = true;

                if (this.timer) {
                    clearTimeout(this.timer);
                }

                this.timer = setTimeout(() => {
                    this.active = false;
                    window.dispatchEvent(new CustomEvent('announcement-done'));
                }, durationMs);
            });
        }
    };
}
</script>
```

### 6.3 Intégration dans le layout
```HTML
<!-- layouts/game.blade.php -->
<body>
    <div x-data="gameState()" x-init="init()" class="relative">

        <!-- Overlay global (bloque l’UI pendant transitions) -->
        <x-announcement-overlay />

        <!-- Zone principale du jeu -->
        <main class="relative z-10">
            @yield('content')
        </main>

    </div>
</body>
```

7\. COMPORTEMENT À LA RECONNEXION
---------------------------------

Lorsqu’un joueur se reconnecte via :

Plain `   GET /game/{code}/state   `

### Le serveur retourne uniquement :

*   phase actuelle (night, day, voting, etc.)
    
*   round actuel
    
*   rôle du joueur (my\_role)
    
*   statut vivant/mort (is\_alive)
    
*   temps restant (phase\_remaining\_seconds)
    

### Côté client

*   ❌ aucune tentative de replay des annonces passées
    
*   ❌ aucun stockage d’historique visuel
    
*   ✅ simple synchronisation de l’état courant
    

### Notification de reconnexion

Un toast s’affiche :

*   “Reconnecté — nuit en cours, round X”
    
*   “Reconnecté — jour en cours, round X”
    

### Règle importante

Les overlays sont **éphémères et non persistés**.Le système ne doit jamais reconstruire une séquence passée.

8\. EXEMPLES DE FLUX COMPLETS
-----------------------------

### 8.1 Nuit avec voyante vivante

1.  Fin du jour → PhaseManager::startNight()
    
2.  Broadcast public :
    
    *   PhaseAnnouncement(night\_fall, 3000ms)
        
3.  UI publique :
    
    *   overlay “Le village s’endort…”
        
4.  Après 3000ms :
    
    *   SeerTurnStarted (privé voyante)
        
5.  Voyante agit (ou timeout auto)
    
6.  Dispatch :
    
    *   WerewolvesTurnStarted
        
7.  Loups agissent
    
8.  Fin de nuit :
    
    *   ProcessNightEnd
        
9.  Broadcast public :
    
    *   PhaseAnnouncement(day\_break, 3000ms)
        
10.  Après 3000ms :
    

*   DayStarted
    

### 8.2 Nuit sans voyante

1.  startNight() détecte absence de voyante
    
2.  Broadcast :
    
    *   night\_fall (3000ms)
        
3.  Après 3000ms :
    
    *   passage direct aux loups (WerewolvesTurnStarted)
        
4.  Aucun écran spécifique voyante
    
5.  Fin identique :
    
    *   day\_break
        

### 8.3 Annulation partie

1.  GameService::checkInactivity() détecte inactivité critique
    
2.  Broadcast public :
    
    *   game\_cancelled (5000ms)
        
3.  UI :
    
    *   overlay “Partie annulée”
        
4.  Après 5000ms :
    
    *   redirection /game/{code}/cancelled
        

9\. RÈGLES DE SÉCURITÉ ANTI-FUITE
---------------------------------

### Interdictions strictes sur canal public :

*   aucun rôle nommé (voyante, loup, etc.)
    
*   aucun timing dépendant de l’action réelle
    
*   aucune indication “personne n’a agi”
    
*   aucun état interne du backend
    
*   aucun nombre de joueurs par rôle
    

### Principe fondamental

> Le canal public doit rester **identique dans toutes les parties**, indépendamment :

*   des rôles présents
    
*   des décisions prises
    
*   de la vitesse des joueurs
    

10\. TESTS À ÉCRIRE (v1.2)
--------------------------

TestDescriptiontest\_night\_fall\_announcement\_broadcasted\_publiclybroadcast correct night\_falltest\_seer\_turn\_not\_broadcasted\_publiclyjamais de seer\_turn publictest\_overlay\_queue\_executes\_in\_orderFIFO strict sans overlaptest\_reconnection\_no\_replay\_of\_announcementsaucun replay après reloadtest\_public\_phase\_duration\_is\_constantdurée identique peu importe le gameplay réel
