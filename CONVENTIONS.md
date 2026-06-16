# CONVENTIONS

## Langue
- Code (variables, méthodes, classes) : anglais
- Messages d'erreur API : français
- Commentaires : français

## Nommage
- Events WebSocket : PascalCase, suffixe implicite (ex: GameStarted, DayVoteCast)
- Jobs : verbe + nom (ex: ProcessNightActions, CheckReconnectionTimeout)
- Services : nom + Service (ex: VoteService, PhaseManager = exception acceptée)
- Channels : snake_case (ex: game.{gameId}.werewolves)

## Responses API
Toujours retourner ce format :
{
  "success": true|false,
  "data": {},      // si success
  "message": ""   // si erreur
}
Code HTTP : 200/201 succès, 422 validation, 403 interdit, 409 conflit métier

## Validation
- Utiliser des FormRequest dédiés (ex: VoteMayorRequest, SendChatMessageRequest)
- Jamais valider dans le Controller directement

## Broadcasting
- Toujours implémenter broadcastOn() et broadcastAs()
- broadcastAs() retourne le nom de l'event en camelCase (ex: 'day.vote.cast')
- Les events privés étendent PrivateEvent, publics étendent Event

## Tests
- Un fichier de test par Service (ex: VoteServiceTest)
- Factories pour tous les modèles
- Tester les cas limites : égalité votes, joueur inactif, timer expiré

## Sécurité
- Toujours utiliser Gate::authorize() ou $this->authorize() avant une action
- Ne jamais exposer le rôle d'un joueur dans une réponse publique
- Toujours vérifier game_id + player_id cohérents (anti-spoofing)

## Partials Alpine : injection des variables Blade

Pour tout partial Blade contenant un bloc `<script>` avec de la logique Alpine :

```javascript
// ✅ Correct — toujours en haut du <script> du partial
const GAME_ID = @json($game->id);

// ❌ Interdit
this.gameId          // référence au store Alpine parent — fragile
@json($game->id)     // injecté directement dans un store Alpine x-data={...}
```

**Règle :** `const GAME_ID = @json($game->id)` est la seule façon d'accéder à l'ID de partie dans un partial.

**Raison :** Une variable Blade injectée dans un `x-data` est évaluée une seule fois au rendu serveur. Si la variable est `null` au moment du rendu (ex: vue chargée après un changement d'état), le store Alpine l'aura `null` indéfiniment, sans possibilité de correction côté client.

## Guard obligatoire dans init() Alpine

Tout composant Alpine qui enregistre des listeners via `window.addEventListener()` dans son `init()` DOIT commencer par :

```js
if (this._initialized) return;
this._initialized = true;
```

**Raison :** Alpine peut déclencher `init()` plusieurs fois. Sans ce guard, chaque event `window` sera capturé autant de fois que `init()` a été appelé.

### Extension : scripts inline `@push('scripts')`

Si un bloc `@push('scripts')` enregistre des `window.addEventListener()` (ou tout listener susceptible de s'accumuler), l'envelopper dans une IIFE avec un flag global :

```js
if (!window._nomVueInitialized) {
    window._nomVueInitialized = true;
    window.addEventListener('mon-event', handler);
}
```

**Note :** `document.addEventListener('DOMContentLoaded')` et `document.addEventListener('alpine:init')` sont des événements à déclenchement unique — ils ne nécessitent pas de guard.

### Cas `gameState()`

`gameState()` est protégé par `_wsInitialized` dans `initWebSocket()`. Les vues qui utilisent `x-data="gameState(...)"` directement n'ont pas besoin d'un guard supplémentaire pour les abonnements WebSocket.

Les vues sans `window.addEventListener()` locaux doivent documenter ce constat avec un commentaire HTML :

```html
{{-- Aucun window.addEventListener local : le guard _initialized est géré par gameState() dans game-state.js --}}
```