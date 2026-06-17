@props(['role', 'withEmoji' => false])

@php
    $labels = $withEmoji ? config('game_ui.role_labels_emoji') : config('game_ui.role_labels');
    $label = $labels[$role] ?? $role;
@endphp

<span {{ $attributes }}>{{ $label }}</span>
