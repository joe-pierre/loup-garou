import './bootstrap';

import Alpine from 'alpinejs';
import { gameState  } from './game-state';
import { timerState } from './timer-state';
import { playerAvatarColor } from './player-avatar';
import { registerPush } from './push-notifications';

// Exposer les utilitaires avant Alpine.start() pour que les scripts @push('scripts') y aient accès
window.playerAvatarColor = playerAvatarColor;

// Enregistrer les composants avant Alpine.start()
Alpine.data('gameState',  (gameId, userId) => gameState(gameId, userId));
Alpine.data('timerState', (seconds)        => timerState(seconds));

// Exposer Alpine sur window (accès depuis les vues Blade via x-data inline)
window.Alpine = Alpine;

Alpine.start();

if (document.querySelector('meta[name="vapid-public-key"]')?.content) {
    registerPush();
}
