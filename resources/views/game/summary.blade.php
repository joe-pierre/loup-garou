@extends('layouts.game')

@section('title', 'Loup-Garou Undu — Résumé')

@push('styles')
<style>
    body {
        font-family: 'EB Garamond', serif;
        background: radial-gradient(ellipse at 50% 20%, #0a0f1e 0%, #030712 75%);
        color: #e8e0d0; min-height: 100vh;
    }
    .summary-card {
        background-color: #0d1426;
        border: 1px solid rgba(201,168,76,0.25);
        border-radius: 1.25rem;
        padding: 2rem 1.5rem;
        opacity: 0;
    }
    .summary-title {
        font-family: 'Cinzel', serif;
        font-size: clamp(1.25rem, 4vw, 1.75rem);
        color: #c9a84c;
        letter-spacing: 0.05em;
        margin-bottom: 1.25rem;
    }
    .detail-row {
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.65rem 0;
        border-bottom: 1px solid rgba(201,168,76,0.08);
    }
    .detail-row:last-child { border-bottom: none; }
    .detail-icon { font-size: 1.4rem; width: 2rem; text-align: center; flex-shrink: 0; }
    .detail-label { color: rgba(232,224,208,0.55); font-size: 0.9rem; min-width: 9rem; }
    .detail-value { color: #e8e0d0; font-size: 0.95rem; }
    .role-werewolf { color: #f87171; }
    .role-seer     { color: #a78bfa; }
    .role-witch    { color: #3493d3; }
    .role-hunter   { color: #fbbf24; }
    .role-villager { color: #e8e0d0; }
    .progress-bar-bg {
        background: rgba(201,168,76,0.12);
        border-radius: 4px; overflow: hidden; height: 4px;
    }
    .progress-bar {
        height: 100%; background: #c9a84c;
        border-radius: 4px;
        transition: width 1s linear;
    }
    .btn-results {
        background-color: #c9a84c; color: #0a0f1e;
        padding: 0.7rem 2rem;
        border-radius: 0.75rem;
        font-family: 'Cinzel', serif;
        font-weight: 600; font-size: 0.9rem;
        transition: background-color .2s, transform .2s;
        cursor: pointer; border: none;
    }
    .btn-results:hover { background-color: #e0c068; transform: translateY(-2px); }
</style>
@endpush

@section('header-phase')
    <span class="text-xs font-medieval tracking-widest" style="color: rgba(201,168,76,0.7);">
        📜 RÉSUMÉ DE LA PARTIE
    </span>
@endsection

@section('content')
{{-- Aucun window.addEventListener local : le guard _initialized est géré par gameState() dans game-state.js --}}
<div class="max-w-lg mx-auto px-4 py-10"
     x-data="summaryScreen()"
     x-init="init()">

    {{-- ══════════ CARD RÉSUMÉ ══════════ --}}
    <div class="summary-card" id="summary-card">

        <template x-if="lastAction && lastAction.phase === 'night'">
            <div>
                <p class="summary-title">🌙 Ce qui s'est passé cette nuit</p>

                <div class="detail-row">
                    <span class="detail-icon">💀</span>
                    <span class="detail-label">Victime</span>
                    <span class="detail-value"
                          x-text="lastAction.killed
                            ? lastAction.killed.pseudo + ' (' + roleLabel(lastAction.killed.role) + ')'
                            : 'Égalité — aucune victime'">
                    </span>
                </div>

                <div class="detail-row">
                    <span class="detail-icon">🧙</span>
                    <span class="detail-label">Sorcière</span>
                    <span class="detail-value"
                          x-text="lastAction.witch_acted ? 'A utilisé une potion' : 'N\'a pas agi'">
                    </span>
                </div>

                <div class="detail-row">
                    <span class="detail-icon">🏹</span>
                    <span class="detail-label">Chasseur</span>
                    <span class="detail-value"
                          x-text="lastAction.hunter_fired ? 'A tiré avant de mourir' : 'N\'a pas tiré'">
                    </span>
                </div>
            </div>
        </template>

        <template x-if="lastAction && lastAction.phase === 'day'">
            <div>
                <p class="summary-title">☀️ Le verdict du village</p>

                <div class="detail-row">
                    <span class="detail-icon">⚖️</span>
                    <span class="detail-label">Éliminé</span>
                    <span class="detail-value"
                          :class="lastAction.eliminated ? 'role-' + lastAction.eliminated.role : ''"
                          x-text="lastAction.eliminated
                            ? lastAction.eliminated.pseudo + ' (' + roleLabel(lastAction.eliminated.role) + ')'
                            : 'Aucune élimination'">
                    </span>
                </div>

                <div class="detail-row" x-show="lastAction.vote_count > 0">
                    <span class="detail-icon">🗳️</span>
                    <span class="detail-label">Voix reçues</span>
                    <span class="detail-value" x-text="lastAction.vote_count + ' voix'"></span>
                </div>
            </div>
        </template>

        <template x-if="!lastAction">
            <div>
                <p class="summary-title">📜 Fin de partie</p>
                <p style="color:rgba(232,224,208,0.55); font-size:0.9rem;">
                    La partie vient de se terminer.
                </p>
            </div>
        </template>

        {{-- ══════════ COMPTE À REBOURS ══════════ --}}
        <div class="mt-6">
            <div class="progress-bar-bg mb-3">
                <div class="progress-bar" :style="'width:' + (countdown / 8 * 100) + '%'"></div>
            </div>
            <div class="flex items-center justify-between gap-4 flex-wrap">
                <p style="color:rgba(232,224,208,0.45); font-size:0.8rem;">
                    Redirection dans <span x-text="countdown"></span>s…
                </p>
                <button class="btn-results" @click="goToResults()">
                    Voir les résultats →
                </button>
            </div>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
if (!window._summaryScreenInitialized) {
    window._summaryScreenInitialized = true;

    function summaryScreen() {
        return {
            lastAction: null,
            countdown:  8,
            _interval:  null,

            init() {
                try {
                    const raw = sessionStorage.getItem('last_action');
                    this.lastAction = raw ? JSON.parse(raw) : null;
                } catch {
                    this.lastAction = null;
                }

                document.addEventListener('DOMContentLoaded', () => {
                    gsap.to('#summary-card', { opacity: 1, y: 0, duration: 0.7, ease: 'power2.out', from: { y: 30 } });
                });
                gsap.from('#summary-card', { opacity: 0, y: 30, duration: 0.7, ease: 'power2.out' });

                this._interval = setInterval(() => {
                    this.countdown--;
                    if (this.countdown <= 0) {
                        clearInterval(this._interval);
                        this.goToResults();
                    }
                }, 1000);
            },

            goToResults() {
                clearInterval(this._interval);
                sessionStorage.removeItem('last_action');
                window.location.href = '/game/{{ $game->code }}/finished';
            },

            roleLabel(role) {
                const labels = {
                    werewolf: 'Loup-Garou',
                    villager: 'Villageois',
                    seer:     'Voyante',
                    witch:    'Sorcière',
                    hunter:   'Chasseur',
                };
                return labels[role] ?? role ?? '?';
            },
        };
    }

    if (typeof Alpine !== 'undefined') {
        Alpine.data('summaryScreen', summaryScreen);
    } else {
        document.addEventListener('alpine:init', () => Alpine.data('summaryScreen', summaryScreen));
    }
}
</script>
@endpush
