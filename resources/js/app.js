import './bootstrap';

import Alpine from 'alpinejs';
import { gameState  } from './game-state';
import { timerState } from './timer-state';

// Enregistrer les composants avant Alpine.start()
Alpine.data('gameState',  (gameId, userId) => gameState(gameId, userId));
Alpine.data('timerState', (seconds)        => timerState(seconds));

// Exposer Alpine sur window (accès depuis les vues Blade via x-data inline)
window.Alpine = Alpine;

Alpine.start();
