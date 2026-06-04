@props([
    'player',
    'size'            => 'md',
    'currentPlayerId' => null,
])

@php
    // Génère une couleur HSL déterministe depuis le pseudo (fond sombre)
    $hash = 0;
    foreach (str_split($player->pseudo) as $char) {
        $hash = ord($char) + (($hash << 5) - $hash);
    }
    $hue    = abs($hash) % 360;
    $bgColor = "hsl({$hue}, 45%, 22%)";
    $border  = "hsl({$hue}, 55%, 40%)";
    $text    = "hsl({$hue}, 70%, 75%)";

    $sizeMap = [
        'sm' => 'w-7 h-7 text-xs',
        'md' => 'w-9 h-9 text-sm',
        'lg' => 'w-12 h-12 text-base',
    ];
    $sizeClass = $sizeMap[$size] ?? $sizeMap['md'];
    $isMe      = $currentPlayerId && $player->id === (int) $currentPlayerId;
@endphp

<div class="relative inline-flex flex-col items-center gap-0.5">
    {{-- Avatar --}}
    <div
        class="{{ $sizeClass }} rounded-full flex items-center justify-center font-medieval font-bold flex-shrink-0
               {{ $player->is_alive ? '' : 'opacity-40 grayscale' }}"
        style="background-color:{{ $bgColor }}; border:1.5px solid {{ $border }}; color:{{ $text }};"
        aria-label="{{ $player->pseudo }}{{ !$player->is_alive ? ' (mort)' : '' }}{{ $player->is_mayor ? ' — Maire' : '' }}"
    >
        {{ strtoupper(substr($player->pseudo, 0, 1)) }}
    </div>

    {{-- Badges superposés --}}
    @if($player->is_mayor)
        <span class="absolute -top-1.5 -right-1.5 text-xs leading-none" aria-label="Maire">👑</span>
    @elseif(!$player->is_alive)
        <span class="absolute -top-1.5 -right-1.5 text-xs leading-none" aria-label="Éliminé">💀</span>
    @endif

    {{-- Badge "Toi" --}}
    @if($isMe)
        <span
            class="text-[0.6rem] px-1 rounded leading-tight font-medieval"
            style="background-color:rgba(201,168,76,0.2); color:#c9a84c;"
            aria-label="C'est vous"
        >Toi</span>
    @endif
</div>
