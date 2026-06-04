@props([
    'role',
    'revealed' => false,
])

@php
    $roles = [
        'villager' => [
            'label'       => 'Villageois',
            'emoji'       => '🏘',
            'color'       => '#e8e0d0',
            'description' => 'Un citoyen ordinaire. Votez avec sagesse pour éliminer les loups du village.',
        ],
        'werewolf' => [
            'label'       => 'Loup-Garou',
            'emoji'       => '🐺',
            'color'       => '#f87171',
            'description' => 'Chaque nuit, dévorez un villageois. Restez discret le jour pour survivre.',
        ],
        'seer'     => [
            'label'       => 'Voyante',
            'emoji'       => '🔮',
            'color'       => '#a78bfa',
            'description' => 'Chaque nuit, inspectez un joueur et découvrez son vrai rôle.',
        ],
    ];
    $info = $roles[$role] ?? ['label' => $role, 'emoji' => '?', 'color' => '#e8e0d0', 'description' => ''];
@endphp

<div
    x-data="{
        revealed: {{ $revealed ? 'true' : 'false' }},
        flip() {
            if (this.revealed) return;
            const noMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (noMotion || typeof gsap === 'undefined') {
                this.revealed = true;
                return;
            }
            const card = this.$refs.card;
            gsap.to(card, {
                rotateY: 90, duration: 0.4, ease: 'power2.in',
                onComplete: () => {
                    this.revealed = true;
                    gsap.fromTo(card, { rotateY: -90 }, { rotateY: 0, duration: 0.4, ease: 'power2.out' });
                }
            });
        }
    }"
    style="perspective: 1200px;"
    class="inline-block"
>
    <div
        x-ref="card"
        class="relative w-44 h-64 cursor-pointer select-none"
        style="transform-style: preserve-3d;"
        @click="flip()"
        :aria-label="revealed ? 'Rôle : {{ $info['label'] }}' : 'Carte rôle (cliquer pour révéler)'"
        role="button"
        tabindex="0"
        @keydown.enter="flip()"
        @keydown.space.prevent="flip()"
    >
        {{-- DOS (visible avant révélation) --}}
        <div
            x-show="!revealed"
            class="absolute inset-0 rounded-2xl flex flex-col items-center justify-center gap-4 p-4
                   animate-float"
            style="background: linear-gradient(135deg,#111827 0%,#1a2340 100%);
                   border: 2px solid rgba(201,168,76,0.35);
                   box-shadow: 0 0 20px rgba(201,168,76,0.08);"
        >
            {{-- Motif SVG médiéval --}}
            <svg class="absolute inset-0 w-full h-full opacity-5 pointer-events-none" aria-hidden="true">
                <pattern id="rp-{{ $role }}" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse">
                    <path d="M10 2 L18 10 L10 18 L2 10 Z" fill="none" stroke="#c9a84c" stroke-width="0.8"/>
                </pattern>
                <rect width="100%" height="100%" fill="url(#rp-{{ $role }})"/>
            </svg>

            <span
                class="font-medieval font-bold relative z-10"
                style="font-size:4.5rem; color:rgba(201,168,76,0.25); line-height:1;"
                aria-hidden="true"
            >?</span>
            <span class="font-medieval text-xs tracking-widest relative z-10" style="color:rgba(201,168,76,0.4);">
                LOUP-GAROU
            </span>
        </div>

        {{-- FACE (visible après révélation) --}}
        <div
            x-show="revealed"
            x-cloak
            class="absolute inset-0 rounded-2xl flex flex-col items-center justify-center gap-3 p-5 text-center"
            style="background: linear-gradient(160deg,#111827 0%,#0d1420 100%);
                   border: 2px solid {{ $info['color'] }}55;
                   box-shadow: 0 0 20px {{ $info['color'] }}20;"
        >
            <span class="text-4xl" aria-hidden="true">{{ $info['emoji'] }}</span>
            <div>
                <p class="font-medieval font-bold text-base mb-1" style="color:{{ $info['color'] }};">
                    {{ $info['label'] }}
                </p>
                <p class="text-xs leading-snug" style="color:rgba(232,224,208,0.6);">
                    {{ $info['description'] }}
                </p>
            </div>
        </div>
    </div>
</div>
