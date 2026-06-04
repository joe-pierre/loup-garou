/**
 * timerState(seconds) — Composant Alpine pour les timers de phase.
 *
 * Usage dans Blade :
 *   <div x-data="timerState(30)" x-init="start()">
 *     <span x-text="remaining + 's'"></span>
 *   </div>
 */
export function timerState(seconds) {
    return {
        total:      seconds,
        remaining:  seconds,
        percentage: 100,
        _interval:  null,
        _barEl:     null,
        _onExpired: null,

        get colorClass() {
            if (this.remaining <= 5)  return 'text-red-500';
            if (this.remaining <= 10) return 'text-orange-400';
            return 'text-gold';
        },

        get colorHex() {
            if (this.remaining <= 5)  return '#ef4444';
            if (this.remaining <= 10) return '#f97316';
            return '#c9a84c';
        },

        start() {
            this.stop();

            this._barEl = this.$el?.querySelector?.('.timer-bar-fill') ?? null;
            const noMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (!noMotion && typeof gsap !== 'undefined' && this._barEl) {
                gsap.killTweensOf(this._barEl);
                gsap.to(this._barEl, {
                    width:    '0%',
                    duration: this.remaining,
                    ease:     'none',
                });
            }

            this._interval = setInterval(() => {
                this.remaining  = Math.max(0, this.remaining - 1);
                this.percentage = (this.remaining / this.total) * 100;

                if (this.remaining <= 0) {
                    this.stop();
                    if (typeof this._onExpired === 'function') {
                        this._onExpired();
                    }
                    this.$dispatch?.('timer-expired');
                }
            }, 1000);
        },

        stop() {
            if (this._interval) {
                clearInterval(this._interval);
                this._interval = null;
            }
            if (this._barEl && typeof gsap !== 'undefined') {
                gsap.killTweensOf(this._barEl);
            }
        },

        reset(newSeconds) {
            this.stop();
            this.total      = newSeconds ?? this.total;
            this.remaining  = this.total;
            this.percentage = 100;
            if (this._barEl) {
                this._barEl.style.width = '100%';
            }
        },

        onExpired(callback) {
            this._onExpired = callback;
            return this;
        },
    };
}
