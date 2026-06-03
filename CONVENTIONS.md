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