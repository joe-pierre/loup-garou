@props([
    'seconds',
    'color' => 'gold',
])

@php
    $colorHex = match($color) {
        'red'    => '#8b0000',
        'purple' => '#7c3aed',
        default  => '#c9a84c',
    };
@endphp

<div
    x-data="{
        total:    {{ (int) $seconds }},
        seconds:  {{ (int) $seconds }},
        interval: null,

        init() {
            const fill        = this.$el.querySelector('.timer-bar-fill');
            const noMotion    = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (!noMotion && typeof gsap !== 'undefined' && fill) {
                gsap.to(fill, {
                    width:    '0%',
                    duration: this.total,
                    ease:     'none',
                });
            }

            this.interval = setInterval(() => {
                this.seconds = Math.max(0, this.seconds - 1);
                if (this.seconds <= 0) {
                    clearInterval(this.interval);
                    this.$dispatch('timer-expired');
                }
            }, 1000);
        },

        get barColor() {
            if (this.seconds <= 5)  return '#ef4444';
            if (this.seconds <= 10) return '#f97316';
            return '{{ $colorHex }}';
        },
    }"
    x-init="init()"
    class="w-full"
    role="timer"
    :aria-label="'Temps restant : ' + seconds + ' secondes'"
>
    {{-- Chiffre + label --}}
    <div class="flex items-center justify-between text-xs mb-1.5">
        <span class="font-body" style="color:rgba(232,224,208,0.5);">Temps restant</span>
        <span
            class="font-medieval font-semibold tabular-nums"
            x-text="seconds + 's'"
            :style="'color:' + barColor"
        ></span>
    </div>

    {{-- Barre de progression --}}
    <div
        class="h-1 rounded-full overflow-hidden"
        style="background-color:rgba(255,255,255,0.08);"
        aria-hidden="true"
    >
        <div
            class="timer-bar-fill h-full rounded-full transition-colors duration-300"
            :style="'width:100%; background-color:' + barColor"
        ></div>
    </div>
</div>
