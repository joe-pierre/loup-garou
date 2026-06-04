@props([
    'phase',
    'round',
    'player' => null,
])

@php
    $phases = [
        'electing_mayor' => ['icon' => '👑', 'label' => 'Élection du Maire', 'color' => '#c9a84c'],
        'night'          => ['icon' => '🌙', 'label' => 'Phase Nuit',         'color' => '#7c3aed'],
        'day'            => ['icon' => '☀️', 'label' => 'Phase Jour',         'color' => '#c9a84c'],
        'finished'       => ['icon' => '🏁', 'label' => 'Partie terminée',    'color' => '#9ca3af'],
        'waiting'        => ['icon' => '⏳', 'label' => 'En attente',         'color' => '#c9a84c'],
    ];
    $info = $phases[$phase] ?? ['icon' => '?', 'label' => $phase, 'color' => '#e8e0d0'];

    $roleLabel = fn(?string $r) => match($r) {
        'villager' => '🏘 Villageois',
        'werewolf' => '🐺 Loup-Garou',
        'seer'     => '🔮 Voyante',
        default    => null,
    };
    $roleBg = fn(?string $r) => match($r) {
        'werewolf' => 'rgba(139,0,0,0.25)',
        'seer'     => 'rgba(124,58,237,0.2)',
        default    => 'rgba(201,168,76,0.1)',
    };
    $roleColor = fn(?string $r) => match($r) {
        'werewolf' => '#f87171',
        'seer'     => '#a78bfa',
        default    => '#c9a84c',
    };
@endphp

<div
    class="flex items-center justify-between px-4 py-3 rounded-xl mb-4"
    style="background-color:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06);"
    role="region"
    aria-label="Informations de phase"
>
    <div class="flex items-center gap-3">
        <span class="text-2xl" aria-hidden="true">{{ $info['icon'] }}</span>
        <div>
            <p class="font-medieval font-semibold text-sm" style="color:{{ $info['color'] }};">
                {{ $info['label'] }}
            </p>
            @if($round > 0)
            <p class="text-xs" style="color:rgba(232,224,208,0.45);">Round {{ $round }}</p>
            @endif
        </div>
    </div>

    @if($player && $roleLabel($player->role))
    <span
        class="text-xs px-2 py-0.5 rounded-full font-medieval"
        style="background-color:{{ $roleBg($player->role) }}; color:{{ $roleColor($player->role) }};"
        aria-label="Votre rôle : {{ $roleLabel($player->role) }}"
    >
        {{ $roleLabel($player->role) }}
    </span>
    @endif
</div>
