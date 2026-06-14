# Prompt 1 — Bug doublons chat (tous les chats)

AVANT DE COMMENCER :
git checkout -b fix/chat-double-messages

Fichiers concernés :
- resources/views/game/day.blade.php
- resources/views/game/night.blade.php

Problème : les messages de chat apparaissent en doublon car deux abonnements
coexistent pour le même event :
1. game-state.js abonne window.Echo.channel(`game.${gameId}`) et pousse dans
   this.chat via _handleChatMessage()
2. day.blade.php abonne aussi window.Echo.channel(`game.${GAME_ID}`) pour
   .chat.message.sent et pousse dans this.chatMessages[] directement

Idem pour le canal loups dans night.blade.php :
game-state.js abonne le canal werewolves et dispatch window 'werewolf-chat-message',
mais night.blade.php s'abonne AUSSI au même canal via Echo.

───────────────────────────────────────────────
MODIFICATION 1 — day.blade.php
───────────────────────────────────────────────

Dans la fonction init() de dayScreen(), localiser le bloc :

    window.Echo.channel(`game.${GAME_ID}`)
        .listen('.day.vote.cast',    (data) => { ... })
        .listen('.chat.message.sent', (data) => {
            if (data.channel === 'general') {
                this.chatMessages.push(data);
                this.$nextTick(() => { ... });
            }
        });

Supprimer UNIQUEMENT le listener '.chat.message.sent' et son handler.
Garder le listener '.day.vote.cast' intact.

Résultat attendu :

    window.Echo.channel(`game.${GAME_ID}`)
        .listen('.day.vote.cast', (data) => { this._updateVoteBars(data.votes ?? []); });

Puis ajouter un window.addEventListener pour recevoir les messages chat
dispatchés par game-state.js :

    window.addEventListener('chat-message', (e) => {
        if (e.detail?.channel === 'general') {
            this.chatMessages.push(e.detail);
            this.$nextTick(() => {
                const el = this.$refs.chatMessages;
                if (el) el.scrollTop = el.scrollHeight;
            });
        }
    });

───────────────────────────────────────────────
MODIFICATION 2 — game-state.js : dispatcher l'event chat sur window
───────────────────────────────────────────────

Dans _handleChatMessage(e), après this.chat.push(e), ajouter :

    window.dispatchEvent(new CustomEvent('chat-message', { detail: e }));

───────────────────────────────────────────────
MODIFICATION 3 — night.blade.php : supprimer le double abonnement loups
───────────────────────────────────────────────

Dans init() de nightScreen(), localiser le bloc où window.addEventListener
'werewolf-chat-message' pousse dans this.wolfMessages. Ce listener est correct
car il reçoit depuis game-state.js via window.dispatchEvent.

Vérifier qu'il n'y a PAS d'abonnement Echo direct du type :
    window.Echo.private(`game.${GAME_ID}.werewolves`).listen('.werewolf.chat.message', ...)
dans nightScreen() — si un tel abonnement existe, le supprimer.
(game-state.js gère déjà ce canal et dispatch sur window.)

Mettre à jour BUGS_AND_ROADMAP.md (section BUGS CORRIGÉS).
Rien à ajouter dans DECISIONS.md (bug simple).

git add -A && git commit -m "fix(chat): supprimer double abonnement Echo qui causait les messages en doublon"

----

# Prompt 2 — Rôles manquants dans history, player-list, notifications et home

AVANT DE COMMENCER :
git checkout -b fix/roles-completeness

Fichiers concernés :
- resources/views/game/history.blade.php
- resources/views/components/player-list.blade.php
- app/Notifications/RoleAssignedNotification.php
- app/Notifications/PlayerEliminatedDayNotification.php
- resources/views/home.blade.php

Objectif : ajouter sorcière (witch) et chasseur (hunter) partout où les rôles
sont affichés ou mentionnés, et corriger l'icône villageois dans home.blade.php.

───────────────────────────────────────────────
MODIFICATION 1 — history.blade.php
───────────────────────────────────────────────

Localiser le bloc @php en haut qui définit $roleLabel et $roleClass.

Remplacer :
    $roleLabel = fn(?string $r) => match($r) {
        'villager' => '🏘 Villageois',
        'werewolf' => '🐺 Loup-Garou',
        'seer'     => '🔮 Voyante',
        default    => $r ?? '?',
    };
    $roleClass = fn(?string $r) => match($r) {
        'villager' => 'role-badge-villager',
        'werewolf' => 'role-badge-werewolf',
        'seer'     => 'role-badge-seer',
        default    => 'role-badge-villager',
    };

Par :
    $roleLabel = fn(?string $r) => match($r) {
        'villager' => '🧑‍🌾 Villageois',
        'werewolf' => '🐺 Loup-Garou',
        'seer'     => '🔮 Voyante',
        'witch'    => '🧙‍♀️ Sorcière',
        'hunter'   => '🏹 Chasseur',
        default    => $r ?? '?',
    };
    $roleClass = fn(?string $r) => match($r) {
        'villager' => 'role-badge-villager',
        'werewolf' => 'role-badge-werewolf',
        'seer'     => 'role-badge-seer',
        'witch'    => 'role-badge-witch',
        'hunter'   => 'role-badge-hunter',
        default    => 'role-badge-villager',
    };

Dans la section <style> de history.blade.php, ajouter après .role-badge-seer :
    .role-badge-witch  { background-color: rgba(52,147,211,0.2); color: #3493d3; }
    .role-badge-hunter { background-color: rgba(247,191,36,0.2);  color: #fbbf24; }

Vérifier aussi le bloc @php plus bas qui définit $roleColor et $roleLabel2
(utilisé dans le tableau de fin) et y ajouter :
    'witch'  => '#3493d3',  // pour $roleColor
    'hunter' => '#fbbf24',  // pour $roleColor
    'witch'  => 'Sorcière', // pour $roleLabel2
    'hunter' => 'Chasseur', // pour $roleLabel2

───────────────────────────────────────────────
MODIFICATION 2 — player-list.blade.php
───────────────────────────────────────────────

Localiser le bloc @php qui définit $roleLabel, $roleBg, $roleColor.

Remplacer entièrement par :
    $roleLabel = fn(?string $r) => match($r) {
        'villager' => '🧑‍🌾 Villageois',
        'werewolf' => '🐺 Loup-Garou',
        'seer'     => '🔮 Voyante',
        'witch'    => '🧙‍♀️ Sorcière',
        'hunter'   => '🏹 Chasseur',
        default    => ($r ?? '?'),
    };
    $roleBg = fn(?string $r) => match($r) {
        'werewolf' => 'rgba(139,0,0,0.25)',
        'seer'     => 'rgba(124,58,237,0.2)',
        'witch'    => 'rgba(52,147,211,0.2)',
        'hunter'   => 'rgba(247,191,36,0.2)',
        default    => 'rgba(232,224,208,0.08)',
    };
    $roleColor = fn(?string $r) => match($r) {
        'werewolf' => '#f87171',
        'seer'     => '#a78bfa',
        'witch'    => '#3493d3',
        'hunter'   => '#fbbf24',
        default    => 'rgba(232,224,208,0.7)',
    };

───────────────────────────────────────────────
MODIFICATION 3 — RoleAssignedNotification.php
───────────────────────────────────────────────

Remplacer la constante ROLE_LABELS par :
    private const ROLE_LABELS = [
        'villager' => '🧑‍🌾 Villageois',
        'werewolf' => '🐺 Loup-Garou',
        'seer'     => '🔮 Voyante',
        'witch'    => '🧙‍♀️ Sorcière',
        'hunter'   => '🏹 Chasseur',
    ];

───────────────────────────────────────────────
MODIFICATION 4 — PlayerEliminatedDayNotification.php
───────────────────────────────────────────────

Remplacer la constante ROLE_LABELS par :
    private const ROLE_LABELS = [
        'villager' => 'Villageois',
        'werewolf' => 'Loup-Garou',
        'seer'     => 'Voyante',
        'witch'    => 'Sorcière',
        'hunter'   => 'Chasseur',
    ];

───────────────────────────────────────────────
MODIFICATION 5 — home.blade.php : icône villageois + ajout sorcière/chasseur
───────────────────────────────────────────────

Dans la section "Les Rôles" (4 flip-cards), apporter ces changements :

1. Dans la flip-card "Villageois", remplacer l'emoji hache 🪓 par 🧑‍🌾 dans
   les deux faces (front et back) de la carte.

2. Après la flip-card "Maire", ajouter deux nouvelles flip-cards :

<!-- Sorcière -->
<div class="role-card flip-card">
  <div class="flip-inner">
    <div class="flip-face" style="border: 1px solid rgba(52,147,211,0.5)">
      <div class="text-6xl mb-4">🧙‍♀️</div>
      <h3 class="font-title text-2xl" style="color: #3493d3">Sorcière</h3>
    </div>
    <div class="flip-back flip-face" style="border: 1px solid rgba(52,147,211,0.5)">
      <h3 class="font-title text-xl mb-3" style="color: #3493d3">Sorcière</h3>
      <p class="text-sm opacity-85">Elle dispose d'une potion de soin et d'une potion de poison, à utiliser une seule fois chacune dans la partie.</p>
    </div>
  </div>
</div>

<!-- Chasseur -->
<div class="role-card flip-card">
  <div class="flip-inner">
    <div class="flip-face" style="border: 1px solid rgba(247,191,36,0.5)">
      <div class="text-6xl mb-4">🏹</div>
      <h3 class="font-title text-2xl" style="color: #fbbf24">Chasseur</h3>
    </div>
    <div class="flip-back flip-face" style="border: 1px solid rgba(247,191,36,0.5)">
      <h3 class="font-title text-xl mb-3" style="color: #fbbf24">Chasseur</h3>
      <p class="text-sm opacity-85">Quand il est éliminé, il peut emporter un joueur de son choix dans la mort. Un seul tir, mais décisif.</p>
    </div>
  </div>
</div>

La grille passe de 4 à 6 cartes : adapter la classe grid de
"grid-cols-2 lg:grid-cols-4" en "grid-cols-2 lg:grid-cols-3".

Rien à ajouter dans DECISIONS.md (corrections simples).
Mettre à jour BUGS_AND_ROADMAP.md si pertinent.

git add -A && git commit -m "fix(roles): ajouter sorcière et chasseur partout où les rôles sont affichés"

----

# Prompt 3 — Élection du maire : carte personnelle avec rôle

AVANT DE COMMENCER :
git checkout -b feat/mayor-election-role-card

Fichier concerné : resources/views/game/mayor-election.blade.php

Objectif : dans la grille des candidats, la carte du joueur courant
(currentPlayerId) doit afficher son rôle (icône + nom + couleur) à la place
de la simple initiale, pour qu'il se reconnaisse clairement.

───────────────────────────────────────────────
MODIFICATION 1 — Injecter les données de rôle côté Blade
───────────────────────────────────────────────

Dans le bloc @php en haut de la vue (ou en ajoutant un bloc @php),
définir les données de rôle du joueur courant :

@php
    $myRoleConfig = match($player->role) {
        'werewolf' => ['icon' => '🐺', 'label' => 'Loup-Garou',  'color' => '#f87171'],
        'seer'     => ['icon' => '🔮', 'label' => 'Voyante',     'color' => '#a78bfa'],
        'witch'    => ['icon' => '🧙‍♀️', 'label' => 'Sorcière',   'color' => '#3493d3'],
        'hunter'   => ['icon' => '🏹', 'label' => 'Chasseur',    'color' => '#fbbf24'],
        default    => ['icon' => '🧑‍🌾', 'label' => 'Villageois', 'color' => '#e8e0d0'],
    };
@endphp

───────────────────────────────────────────────
MODIFICATION 2 — Modifier le template x-for des candidate-card
───────────────────────────────────────────────

Dans le template x-for des candidats, localiser le bloc qui affiche l'avatar
(la div avec la lettre initiale) et le bloc pseudo en dessous.

Remplacer l'avatar actuel (initiale en lettre) par un affichage conditionnel :
- Si c'est le joueur courant (candidate.id === currentPlayerId) :
  afficher l'icône du rôle (grande, ~2rem) + fond coloré discret
- Sinon : garder l'initiale actuelle

Concrètement, remplacer le div.avatar par :

<div class="avatar"
     :style="candidate.id === currentPlayerId
         ? 'background-color: rgba({{ implode(',', sscanf($myRoleConfig['color'], '#%02x%02x%02x')) }}, 0.15); border: 1px solid {{ $myRoleConfig['color'] }}55; font-size: 1.5rem;'
         : `background-color: ${avatarColor}; color: #0a0f1e; border: none; font-weight: 700;`">
    <template x-if="candidate.id === currentPlayerId">
        <span>{{ $myRoleConfig['icon'] }}</span>
    </template>
    <template x-if="candidate.id !== currentPlayerId">
        <span x-text="candidate.pseudo.charAt(0).toUpperCase()"></span>
    </template>
</div>

Note : la couleur d'avatar pour soi-même est déjà définie via $myRoleConfig,
pas besoin de avatarColor dans ce cas.

───────────────────────────────────────────────
MODIFICATION 3 — Remplacer "toi" par le nom du rôle sous le pseudo
───────────────────────────────────────────────

Localiser le bloc :
    @if ($candidate->id === $player->id)
        <p class="text-xs mt-0.5" style="color: rgba(201,168,76,0.55); ...">toi</p>
    @endif

Remplacer par :
    @if ($candidate->id === $player->id)
        <p class="text-xs mt-0.5 font-semibold" style="color: {{ $myRoleConfig['color'] }};">
            {{ $myRoleConfig['icon'] }} {{ $myRoleConfig['label'] }} · MOI
        </p>
    @endif

───────────────────────────────────────────────
MODIFICATION 4 — Augmenter le délai d'affichage du maire élu
───────────────────────────────────────────────

Dans la fonction init() de mayorElection(), localiser le listener .mayor.elected :

    .listen('.mayor.elected', (data) => {
        this.electedMayor = data.pseudo;
        this.wasRandom    = data.was_random;
        this.showResult   = true;
        setTimeout(() => { window.location.href = `/game/${this.gameCode}/night`; }, 2000);
    })

Remplacer le délai de 2000ms par 4000ms :
    setTimeout(() => { window.location.href = `/game/${this.gameCode}/night`; }, 4000);

Rien à ajouter dans DECISIONS.md.
Rien à modifier dans BUGS_AND_ROADMAP.md.

git add -A && git commit -m "feat(mayor-election): afficher le rôle sur la carte personnelle + délai résultat 4s"

----

# Prompt 4 — Modale succession : flou + fermeture avec délai

AVANT DE COMMENCER :
git checkout -b fix/succession-modal-ux

Fichiers concernés :
- resources/views/game/day.blade.php
- resources/views/game/night.blade.php

Problème 1 : le backdrop-filter blur(4px) sur la modale succession cause
un rendu visuellement flou/dégradé sur certains navigateurs.
Problème 2 : la modale se ferme instantanément à la réception de
mayor-succession-done, sans laisser le temps de lire le résultat.

───────────────────────────────────────────────
MODIFICATION 1 — Retirer le backdrop-filter des deux modales succession
───────────────────────────────────────────────

Dans day.blade.php ET night.blade.php, localiser le div de la modale succession :

    style="background-color: rgba(10,15,30,0.65); backdrop-filter: blur(4px);"

Remplacer par :

    style="background-color: rgba(10,15,30,0.82);"

L'opacité légèrement augmentée compense visuellement l'absence du blur.

───────────────────────────────────────────────
MODIFICATION 2 — day.blade.php : fermeture différée de la modale
───────────────────────────────────────────────

Dans la fonction closeSuccessionModal() de dayScreen(), ne pas fermer
immédiatement mais après un délai de 2500ms :

    closeSuccessionModal() {
        // Délai avant fermeture pour laisser le temps de voir le résultat
        setTimeout(() => {
            this.successionOpen = false;
        }, 2500);
        if (this._successionTimer) {
            clearTimeout(this._successionTimer);
            this._successionTimer = null;
        }
    },

Attention : le garde-fou 20s dans openSuccessionModal() doit appeler
closeSuccessionModal() directement (le délai de 2.5s s'ajoutera).
Vérifier que le guard-fou reste cohérent.

───────────────────────────────────────────────
MODIFICATION 3 — night.blade.php : fermeture différée de la modale
───────────────────────────────────────────────

Même logique. Dans closeSuccessionModal() de nightScreen() :

    closeSuccessionModal() {
        setTimeout(() => {
            this.successionOpen = false;
        }, 2500);
    },

───────────────────────────────────────────────
MODIFICATION 4 — Ajouter le pseudo du nouveau maire dans la modale
───────────────────────────────────────────────

Actuellement la modale affiche seulement "Le maire X est mort" + spinner.
Quand mayor-succession-done est reçu (successionDepth décrémenté), la modale
devrait afficher le nom du successeur avant de se fermer.

Dans dayScreen(), ajouter une propriété :
    newMayorPseudo: '',

Dans le handler window.addEventListener('mayor-succession-done', (e) => { ... }) :
    // avant closeSuccessionModal()
    this.newMayorPseudo = e.detail?.new_mayor_pseudo ?? '';

Dans le template HTML de la modale (day.blade.php), après le bloc spinner,
ajouter :
    <p x-show="newMayorPseudo"
       class="text-sm mt-4 font-medieval font-semibold"
       style="color:#c9a84c;"
       x-text="'👑 ' + newMayorPseudo + ' est le nouveau Maire'">
    </p>

Faire la même chose dans nightScreen() et night.blade.php.

Rien à ajouter dans DECISIONS.md.
Mettre à jour BUGS_AND_ROADMAP.md (section BUGS CORRIGÉS pour le flou).

git add -A && git commit -m "fix(succession-modal): supprimer backdrop-filter, fermeture différée 2.5s, afficher nouveau maire"

----


# Prompt 5 — Chat UX : input plus grand, bouton plus visible

AVANT DE COMMENCER :
git checkout -b feat/chat-ux-improvements

Fichiers concernés :
- resources/views/game/day.blade.php
- resources/views/game/night.blade.php

───────────────────────────────────────────────
MODIFICATION 1 — day.blade.php : améliorer l'UX du chat général
───────────────────────────────────────────────

Localiser la zone d'input du chat général (div avec l'input et le bouton ✉).

Remplacer l'input actuel (type="text") par un textarea de 2 lignes
pour plus d'espace de saisie, et rendre le bouton "Envoyer" explicite :

Remplacer le bloc flex items-center (input + bouton) par :

<div class="flex flex-col gap-2 p-2" style="border-top:1px solid rgba(201,168,76,0.08);">
    <textarea
        x-model="chatInput"
        :disabled="!isAlive || chatSending"
        @keydown.enter.prevent="if (!$event.shiftKey) sendChat()"
        maxlength="200"
        rows="2"
        :placeholder="isAlive ? 'Votre message... (Entrée pour envoyer)' : 'Tu es mort, silence...'"
        class="w-full resize-none bg-transparent px-3 py-2 text-sm outline-none disabled:opacity-40 rounded-lg"
        style="color:#e8e0d0; border:1px solid rgba(201,168,76,0.15);
               background-color:rgba(255,255,255,0.03);"
    ></textarea>
    <div class="flex items-center justify-between">
        <span class="text-xs char-counter"
              :class="chatInput.length >= 195 ? 'cc-danger' : chatInput.length >= 180 ? 'cc-warn' : ''"
              x-show="chatInput.length >= 150"
              x-text="(200 - chatInput.length) + ' restants'"></span>
        <button
            @click="sendChat()"
            :disabled="!chatInput.trim() || !isAlive || chatSending"
            class="px-4 py-1.5 rounded-lg text-xs font-medieval font-semibold
                   disabled:opacity-30 transition-all hover:opacity-80"
            style="background-color:#c9a84c; color:#0a0f1e; min-width:80px;"
        >
            <span x-show="!chatSending">Envoyer ✉</span>
            <span x-show="chatSending">…</span>
        </button>
    </div>
</div>

───────────────────────────────────────────────
MODIFICATION 2 — night.blade.php : améliorer l'UX du chat loups
───────────────────────────────────────────────

Localiser la zone d'input du chat loups (wolf-chat-input + bouton ✉).

Même transformation : remplacer input par textarea 2 lignes + bouton explicite.
Style adapté aux couleurs loups (rouge sombre) :

<div class="flex flex-col gap-2 p-2" style="border-top:1px solid rgba(139,0,0,0.2);">
    <textarea
        x-model="wolfChatInput"
        :disabled="!isAlive || wolfChatSending"
        @keydown.enter.prevent="if (!$event.shiftKey) sendWolfChat()"
        maxlength="200"
        rows="2"
        placeholder="Message aux loups... (Entrée pour envoyer)"
        class="w-full resize-none px-3 py-2 text-xs outline-none disabled:opacity-40 rounded-lg wolf-chat-input"
        style="background:#1a0505; border:1px solid rgba(255,68,68,0.25); color:#e8e0d0;"
    ></textarea>
    <div class="flex justify-end">
        <button
            @click="sendWolfChat()"
            :disabled="!wolfChatInput.trim() || !isAlive || wolfChatSending"
            class="px-4 py-1.5 rounded-lg text-xs font-medieval font-semibold
                   disabled:opacity-30 transition-all hover:opacity-80"
            style="background-color:#8b0000; color:#fca5a5; border:1px solid rgba(255,68,68,0.3); min-width:80px;"
        >
            <span x-show="!wolfChatSending">Envoyer ✉</span>
            <span x-show="wolfChatSending">…</span>
        </button>
    </div>
</div>

Rien à ajouter dans DECISIONS.md ni BUGS_AND_ROADMAP.md.

git add -A && git commit -m "feat(chat): textarea 2 lignes + bouton envoyer visible dans tous les chats"

----

# Prompt 6 — Phase jour : afficher les morts + canal des fantômes

AVANT DE COMMENCER :
git checkout -b feat/dead-chat-day

Fichiers concernés :
- app/Services/ChatService.php
- app/Events/Game/ChatMessageSent.php
- routes/web.php (vérifier si déjà couvert par la route /chat existante)
- resources/views/game/day.blade.php

Contexte : pendant la phase jour, les joueurs morts doivent :
- Voir le chat des vivants en LECTURE SEULE
- Avoir leur propre canal "fantômes" (dead) pour discuter entre eux
- Être affichés dans la liste des joueurs, grisés avec leur rôle révélé
- Ne PAS pouvoir écrire dans le chat des vivants

───────────────────────────────────────────────
MODIFICATION 1 — ChatService.php : nouveau canal 'dead'
───────────────────────────────────────────────

Dans sendMessage(), après le guard is_alive, ajouter la gestion du canal dead :

    if ($channel === 'dead') {
        if ($player->is_alive) {
            abort(403, 'Seuls les joueurs éliminés peuvent écrire dans le canal des fantômes.');
        }
        if (! in_array($game->status, ['day', 'processing_day'])) {
            abort(409, 'Le chat des fantômes n\'est disponible que pendant la phase jour.');
        }
        // Créer le message et broadcaster sur canal public (tout le monde le voit)
        // mais tagué 'dead' pour que les vivants ne puissent PAS y répondre
        return ChatMessage::create([
            'game_id'   => $game->id,
            'player_id' => $player->id,
            'message'   => $message,
            'channel'   => 'dead',
            'round'     => $game->round,
            'phase'     => 'day',
        ]);
    }

Modifier le guard is_alive existant pour permettre aux morts d'écrire
sur 'dead' (le guard est placé avant la logique canal) :

    if (! $player->is_alive && $channel !== 'dead') {
        abort(403, 'Les joueurs éliminés ne peuvent pas envoyer de messages.');
    }

───────────────────────────────────────────────
MODIFICATION 2 — ChatController.php : broadcaster le canal dead
───────────────────────────────────────────────

Dans ChatController::send(), après la création du message, le broadcast
conditionnel doit gérer le canal 'dead' :

    if ($chatMessage->channel === 'werewolves') {
        broadcast(new WerewolfChatMessage(...));
    } elseif ($chatMessage->channel === 'dead') {
        // Broadcaster sur le canal public (game.{id}) avec un event tagué 'dead'
        // pour que tous les joueurs (vivants et morts) puissent le recevoir
        broadcast(new ChatMessageSent($player->game, $player, $chatMessage->message, $timestamp));
        // Note : ChatMessageSent broadcastWith() retourne channel: 'general'
        // On doit passer le vrai channel : modifier ChatMessageSent ou créer un event dédié.
        // Solution simple : modifier broadcastWith() pour utiliser $this->channel
    } else {
        broadcast(new ChatMessageSent(...));
    }

ATTENTION : la solution la plus propre est de modifier ChatMessageSent pour
accepter un channel dynamique. Modifier ChatMessageSent.php :

    public function __construct(
        public readonly Game $game,
        public readonly GamePlayer $player,
        public readonly string $message,
        public readonly string $timestamp,
        public readonly string $channel = 'general', // nouveau paramètre
    ) {}

    public function broadcastWith(): array
    {
        return [
            'pseudo'    => $this->player->pseudo,
            'message'   => $this->message,
            'channel'   => $this->channel, // dynamique au lieu de hardcodé 'general'
            'timestamp' => $this->timestamp,
        ];
    }

Dans ChatController, passer le channel :
    broadcast(new ChatMessageSent($player->game, $player, $chatMessage->message, $timestamp, $chatMessage->channel));

───────────────────────────────────────────────
MODIFICATION 3 — SendMessageRequest.php : accepter 'dead'
───────────────────────────────────────────────

Dans rules(), modifier la validation du channel :
    'channel' => ['required', 'string', Rule::in(['general', 'werewolves', 'dead'])],

Dans messages() :
    'channel.in' => 'Canal invalide. Valeurs acceptées : general, werewolves, dead.',

───────────────────────────────────────────────
MODIFICATION 4 — day.blade.php : affichage + chat fantômes
───────────────────────────────────────────────

A) PLAYERS_DATA : s'assurer que tous les joueurs (vivants ET morts) sont inclus.
   Actuellement la vue reçoit $players = $game->alivePlayers()->get().
   Modifier GameController::day() pour passer tous les joueurs :
   $allPlayers = $game->players()->get();
   et l'exposer dans la vue comme $players (la liste inclut morts et vivants).
   Le tri "vivants d'abord" dans init() est déjà en place.

B) Dans le template x-for des joueurs, le style "mort" est déjà partiellement
   géré (v-dead, grayscale). Vérifier que les joueurs morts ont bien :
   - opacity réduite + texte barré sur le pseudo
   - badge rôle révélé visible (déjà implémenté dans PLAYERS_DATA)
   - cursor: not-allowed si !p.is_alive

C) Ajouter un second panneau de chat "Fantômes" sous le chat principal,
   visible uniquement pour !isAlive :

   <!-- Chat fantômes (morts uniquement) -->
   <div x-show="!isAlive" x-cloak class="mt-6">
       <p class="font-medieval text-sm tracking-widest mb-2" style="color:rgba(139,0,0,0.7);">
           💀 CANAL DES FANTÔMES
       </p>
       <div class="rounded-xl overflow-hidden" style="border:1px solid rgba(139,0,0,0.3);background:#0d0505;">
           <div class="p-3 overflow-y-auto flex flex-col gap-2 h-32 sm:h-48"
                x-ref="deadChatMessages"
                role="log" aria-label="Messages des fantômes" aria-live="polite">
               <template x-for="(msg, i) in deadChatMessages" :key="i">
                   <div class="text-xs flex flex-col"
                        style="background:#1a0808;border:1px solid rgba(139,0,0,0.2);border-radius:0.5rem;padding:0.35rem 0.6rem;"
                        :class="msg.pseudo === MY_PSEUDO ? 'ml-auto' : ''">
                       <span class="font-semibold mb-0.5" style="color:rgba(139,0,0,0.8);" x-text="msg.pseudo"></span>
                       <span style="color:rgba(232,224,208,0.6);" x-text="msg.message"></span>
                   </div>
               </template>
               <p x-show="deadChatMessages.length === 0"
                  class="text-xs italic text-center m-auto"
                  style="color:rgba(232,224,208,0.2);">
                   Les fantômes gardent le silence...
               </p>
           </div>
           <!-- Input fantôme -->
           <div class="flex flex-col gap-2 p-2" style="border-top:1px solid rgba(139,0,0,0.2);">
               <textarea
                   x-model="deadChatInput"
                   :disabled="deadChatSending"
                   @keydown.enter.prevent="if (!$event.shiftKey) sendDeadChat()"
                   maxlength="200"
                   rows="2"
                   placeholder="Parle avec les autres fantômes..."
                   class="w-full resize-none px-3 py-2 text-xs outline-none rounded-lg"
                   style="background:rgba(139,0,0,0.08); border:1px solid rgba(139,0,0,0.25); color:#e8e0d0;"
               ></textarea>
               <div class="flex justify-end">
                   <button
                       @click="sendDeadChat()"
                       :disabled="!deadChatInput.trim() || deadChatSending"
                       class="px-4 py-1.5 rounded-lg text-xs font-medieval font-semibold disabled:opacity-30"
                       style="background-color:rgba(139,0,0,0.5);color:#fca5a5;border:1px solid rgba(139,0,0,0.4);">
                       <span x-show="!deadChatSending">Envoyer</span>
                       <span x-show="deadChatSending">…</span>
                   </button>
               </div>
           </div>
       </div>
   </div>

D) Dans dayScreen(), ajouter les propriétés et méthodes nécessaires :

   deadChatMessages: [],
   deadChatInput:    '',
   deadChatSending:  false,

   // Dans le listener 'chat-message' (via window.addEventListener),
   // router vers deadChatMessages si channel === 'dead' :
   window.addEventListener('chat-message', (e) => {
       if (e.detail?.channel === 'general') {
           this.chatMessages.push(e.detail);
           ...scroll...
       } else if (e.detail?.channel === 'dead') {
           this.deadChatMessages.push(e.detail);
           this.$nextTick(() => {
               const el = this.$refs.deadChatMessages;
               if (el) el.scrollTop = el.scrollHeight;
           });
       }
   });

   // Méthode sendDeadChat :
   async sendDeadChat() {
       const msg = this.deadChatInput.trim();
       if (!msg || this.deadChatSending) return;
       this.deadChatInput   = '';
       this.deadChatSending = true;
       try {
           await fetch(`/game/${GAME_ID}/chat`, {
               method: 'POST',
               headers: {
                   'Content-Type': 'application/json',
                   'Accept': 'application/json',
                   'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
               },
               body: JSON.stringify({ message: msg, channel: 'dead' }),
           });
       } catch { }
       finally { this.deadChatSending = false; }
   },

E) NOTE IMPORTANTE sur GameController::day() :
   Remplacer $players = $game->alivePlayers()->get() par
   $players = $game->players()->orderBy('is_alive', 'desc')->get()
   pour que la liste inclut les morts (ils sont déjà filtrés visuellement
   côté client, mais il faut les avoir dans PLAYERS_DATA).

Mettre à jour BUGS_AND_ROADMAP.md si pertinent.
Ajouter dans DECISIONS.md : choix canal 'dead' broadcast public tagué,
pas de canal privé dédié (tous voient les messages fantômes, vivants en
lecture seule via le routage client).

git add -A && git commit -m "feat(day): canal des fantômes pour joueurs éliminés + affichage de tous les joueurs"

----



----

# 



----