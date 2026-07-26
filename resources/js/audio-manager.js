/**
 * AudioManager — musiques d'ambiance par phase de jeu.
 *
 * Deux éléments <audio> permutés en crossfade (jamais plus d'un seul actif
 * à pleine lecture). Chaque page (navigation serveur complète, pas de SPA)
 * appelle window.AudioManager.crossfadeTo('nom_piste') au chargement pour
 * annoncer la piste correspondant à sa propre phase — voir SPEC pièce jointe
 * "Musiques d'ambiance par phase de jeu" pour le mapping phase → son.
 *
 * Autoplay : le volume ne pilote jamais l'autorisation navigateur — seul
 * `el.muted` le fait. Un lecteur `muted = true` est autorisé à démarrer sans
 * geste utilisateur ; un lecteur non muet sans geste préalable verra sa
 * promesse `play()` rejetée, auto-réarmée sur le prochain clic/tap (jamais
 * de blocage/plantage sur ce rejet — contrainte non-négociable de la spec).
 */

const TRACKS = {
    site_music:   '/audio/site_music.ogg',
    waiting_room: '/audio/waiting_room.ogg',
    day_music:    '/audio/day_music.ogg',
    night_music:  '/audio/night_music.ogg',
    winner:       '/audio/winner.ogg',
};

const MUTE_STORAGE_KEY = 'loupGarouAudioMuted';
const CROSSFADE_MS     = 1200;

function readStoredMute() {
    try {
        return localStorage.getItem(MUTE_STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function writeStoredMute(muted) {
    try {
        localStorage.setItem(MUTE_STORAGE_KEY, muted ? '1' : '0');
    } catch {
        // localStorage indisponible (navigation privée stricte) — mute non persisté,
        // le reste de la session fonctionne quand même.
    }
}

function fadeVolume(el, from, to, durationMs, onDone) {
    if (el._fadeRaf) cancelAnimationFrame(el._fadeRaf);
    const start = performance.now();
    const step = (now) => {
        const t = Math.min(1, (now - start) / durationMs);
        el.volume = from + (to - from) * t;
        if (t < 1) {
            el._fadeRaf = requestAnimationFrame(step);
        } else {
            el._fadeRaf = null;
            if (onDone) onDone();
        }
    };
    el._fadeRaf = requestAnimationFrame(step);
}

export function createAudioManager() {
    function makePlayer() {
        const el = new Audio();
        el.loop    = true;
        el.preload = 'auto';
        el.volume  = 0;
        return el;
    }

    const players    = [makePlayer(), makePlayer()];
    let activeIndex   = 0;
    let currentTrack  = null;
    let muted         = readStoredMute();
    let retryArmed    = false;

    // Autoplay refusé (pas encore de geste utilisateur valide) : on retente à la
    // prochaine interaction (clic, tap ou touche clavier — jamais mousemove, que
    // les navigateurs n'autorisent pas comme geste d'activation pour l'autoplay).
    // Chaque écouteur se détache lui-même après un seul déclenchement ; le flag
    // `fired` évite un double déclenchement quand plusieurs de ces events se
    // produisent pour un même geste (ex. touchstart puis click sur mobile).
    function armRetry(trackName) {
        if (retryArmed) return;
        retryArmed = true;
        let fired = false;
        const retry = () => {
            if (fired) return;
            fired      = true;
            retryArmed = false;
            crossfadeTo(trackName, { force: true });
        };
        document.addEventListener('click',      retry, { capture: true, once: true });
        document.addEventListener('touchstart', retry, { capture: true, once: true });
        document.addEventListener('keydown',    retry, { capture: true, once: true });
    }

    function crossfadeTo(trackName, { force = false } = {}) {
        const src = TRACKS[trackName];
        if (!src) return;
        if (currentTrack === trackName && !force) return;
        currentTrack = trackName;

        const outgoingIndex = activeIndex;
        const incomingIndex = 1 - activeIndex;
        const outgoing       = players[outgoingIndex];
        const incoming       = players[incomingIndex];

        if (incoming.dataset.track !== trackName) {
            incoming.src            = src;
            incoming.dataset.track  = trackName;
            incoming.currentTime    = 0;
        }
        incoming.muted  = muted;
        incoming.volume = 0;

        const playPromise = incoming.play();
        if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.then(() => {
                // Ne bascule l'index actif qu'après confirmation réelle de lecture —
                // sinon un play() rejeté laisserait l'ancienne piste orpheline.
                activeIndex = incomingIndex;
                fadeVolume(incoming, 0, 1, CROSSFADE_MS);
                if (outgoing !== incoming && !outgoing.paused) {
                    fadeVolume(outgoing, outgoing.volume, 0, CROSSFADE_MS, () => outgoing.pause());
                }
            }).catch(() => {
                armRetry(trackName);
            });
        }
    }

    function fadeOutCurrent() {
        const el = players[activeIndex];
        if (el && !el.paused) {
            fadeVolume(el, el.volume, 0, CROSSFADE_MS, () => el.pause());
        }
    }

    function setMuted(value) {
        muted = !!value;
        writeStoredMute(muted);
        const el = players[activeIndex];
        if (!el) return;
        el.muted = muted;
        if (!muted && el.paused && currentTrack) {
            const p = el.play();
            if (p && p.catch) p.catch(() => armRetry(currentTrack));
        }
    }

    function toggleMute() {
        setMuted(!muted);
        return muted;
    }

    function isMuted() {
        return muted;
    }

    // Reprise après retour au premier plan : certains navigateurs (Safari iOS
    // notamment) suspendent la lecture <audio> en arrière-plan sans le signaler
    // autrement qu'un `paused === true` constaté au retour — on ne suppose jamais
    // que l'audio a continué, on relance simplement si besoin.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') return;
        if (!currentTrack) return;
        const el = players[activeIndex];
        if (el && el.paused) {
            el.muted = muted;
            const p = el.play();
            if (p && p.catch) p.catch(() => armRetry(currentTrack));
        }
    });

    return {
        crossfadeTo,
        fadeOutCurrent,
        setMuted,
        toggleMute,
        isMuted,
        get currentTrack() { return currentTrack; },
    };
}

// Composant Alpine partagé pour le bouton toggle mute (enregistré dans app.js
// via Alpine.data('muteToggle', muteToggleComponent) — pages sans app.js
// bundlé, ex. home.blade.php, utilisent leur propre bouton vanilla JS).
export function muteToggleComponent() {
    return {
        muted:       true,
        showTooltip: true,
        _hideTimer:  null,

        init() {
            this.muted = window.AudioManager ? window.AudioManager.isMuted() : true;
            // Affiché quelques secondes au chargement pour rendre le statut
            // explicite sans avoir à cliquer, puis se referme comme un tooltip normal.
            this._hideTimer = setTimeout(() => { this.showTooltip = false; }, 3000);
        },
        toggle() {
            this.muted = window.AudioManager ? window.AudioManager.toggleMute() : this.muted;
            this._flashTooltip();
        },
        _flashTooltip() {
            this.showTooltip = true;
            clearTimeout(this._hideTimer);
            this._hideTimer = setTimeout(() => { this.showTooltip = false; }, 1500);
        },
    };
}

if (typeof window !== 'undefined') {
    window.AudioManager = window.AudioManager || createAudioManager();
}
