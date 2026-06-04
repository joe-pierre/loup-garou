/**
 * game-state.js — Gestion déconnexion / reconnexion en temps réel
 *
 * Usage dans une vue Blade :
 *   import { initGameState } from './game-state.js';
 *   initGameState({ gameId: {{ $game->id }}, gameCode: '{{ $game->code }}', playerId: {{ $player->id }} });
 */

/**
 * @param {{ gameId: number, gameCode: string, playerId: number }} config
 */
export function initGameState({ gameId, gameCode, playerId }) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // --- Presence channel : détection leaving() ---
    window.Echo.join(`game.${gameId}.presence`)
        .here(members => {
            // Membres déjà connectés à l'arrivée — pas d'action nécessaire
        })
        .joining(member => {
            // Autre joueur vient de rejoindre/se reconnecter
        })
        .leaving(member => {
            if (member.id !== playerId) {
                showToast(`${member.pseudo} s'est déconnecté`, 'info');
            }
        });

    // --- Écoute des événements de déconnexion/reconnexion ---
    window.Echo.channel(`game.${gameId}`)
        .listen('.player.disconnected', data => {
            showToast(`${data.pseudo} se reconnecte…`, 'info');
            if (data.pseudo === getCurrentPseudo()) {
                showReconnectingOverlay();
            }
        })
        .listen('.player.inactive', data => {
            showToast(`${data.pseudo} est inactif`, 'warning');
            if (data.pseudo === getCurrentPseudo()) {
                hideReconnectingOverlay();
            }
        })
        .listen('.player.reconnected', data => {
            showToast(`${data.pseudo} est de retour`, 'success');
            if (data.pseudo === getCurrentPseudo()) {
                hideReconnectingOverlay();
            }
        })
        .listen('.game.finished', data => {
            if (data.winner_team === null) {
                showToast('Partie annulée — trop de joueurs inactifs', 'error');
            }
        });

    // --- beforeunload : POST best-effort vers /disconnect ---
    window.addEventListener('beforeunload', () => {
        const url = `/game/${gameId}/disconnect`;
        // sendBeacon est synchrone et ne nécessite pas de réponse
        const formData = new FormData();
        formData.append('_token', csrfToken);
        navigator.sendBeacon(url, formData);
    });

    // --- Reconnexion automatique à l'arrivée sur la page si marqué inactif ---
    fetch(`/game/${gameCode}/reconnect`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/json',
        },
    }).catch(() => {});
}

// --- Helpers UI ---

function getCurrentPseudo() {
    return document.querySelector('[data-player-pseudo]')?.dataset?.playerPseudo ?? '';
}

function showToast(message, type = 'info') {
    const colors = {
        info:    'bg-[#111827] border-[#c9a84c] text-[#e8e0d0]',
        warning: 'bg-[#111827] border-[#f97316] text-[#f97316]',
        success: 'bg-[#111827] border-[#16a34a] text-[#16a34a]',
        error:   'bg-[#111827] border-[#8b0000] text-[#e8e0d0]',
    };

    const toast = document.createElement('div');
    toast.className = `fixed bottom-6 right-6 z-50 px-5 py-3 rounded border font-[EB_Garamond] text-sm shadow-lg transition-opacity duration-500 ${colors[type] ?? colors.info}`;
    toast.textContent = message;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 500);
    }, 3500);
}

function showReconnectingOverlay() {
    if (document.getElementById('reconnecting-overlay')) return;

    const overlay = document.createElement('div');
    overlay.id = 'reconnecting-overlay';
    overlay.className = 'fixed inset-0 z-40 bg-[#030712]/80 flex items-center justify-center pointer-events-none';
    overlay.innerHTML = `
        <div class="text-center">
            <svg class="animate-spin h-10 w-10 text-[#c9a84c] mx-auto mb-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <p class="text-[#c9a84c] font-[Cinzel] text-lg">Reconnexion en cours…</p>
        </div>
    `;
    document.body.appendChild(overlay);
}

function hideReconnectingOverlay() {
    document.getElementById('reconnecting-overlay')?.remove();
}
