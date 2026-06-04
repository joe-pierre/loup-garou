@props([
    'players',
    'showVotes'       => false,
    'showRoles'       => false,
    'votes'           => [],      // [player_id => vote_count]
    'maxVotes'        => 1,       // pour la largeur relative des barres
    'currentPlayerId' => null,
])

@php
    $roleLabel = fn(?string $r) => match($r) {
        'villager' => '🏘 Villageois',
        'werewolf' => '🐺 Loup-Garou',
        'seer'     => '🔮 Voyante',
        default    => ($r ?? '?'),
    };
    $roleBg = fn(?string $r) => match($r) {
        'werewolf' => 'rgba(139,0,0,0.25)',
        'seer'     => 'rgba(124,58,237,0.2)',
        default    => 'rgba(232,224,208,0.08)',
    };
    $roleColor = fn(?string $r) => match($r) {
        'werewolf' => '#f87171',
        'seer'     => '#a78bfa',
        default    => 'rgba(232,224,208,0.7)',
    };
    $computedMax = $showVotes && count($votes) > 0 ? max(array_values($votes)) : 1;
    $computedMax = max($computedMax, 1);
@endphp

<ul class="flex flex-col gap-1.5" role="list" aria-label="Liste des joueurs">
    @foreach($players as $player)
    @php
        $voteCount = $votes[$player->id] ?? 0;
        $barWidth  = $showVotes ? round(($voteCount / $computedMax) * 100) : 0;
    @endphp
    <li
        class="flex items-center gap-3 px-3 py-2 rounded-lg"
        style="background-color:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06);"
    >
        <x-player-avatar :player="$player" size="sm" :currentPlayerId="$currentPlayerId" />

        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-1.5 flex-wrap">
                <span
                    class="text-sm font-medium truncate"
                    style="color:{{ $player->is_alive ? '#e8e0d0' : 'rgba(232,224,208,0.35)' }};"
                >{{ $player->pseudo }}</span>

                @if($showRoles && $player->role)
                    <span
                        class="text-xs px-1.5 py-0.5 rounded-full font-medieval leading-tight"
                        style="background-color:{{ $roleBg($player->role) }}; color:{{ $roleColor($player->role) }};"
                    >{{ $roleLabel($player->role) }}</span>
                @endif
            </div>

            @if($showVotes)
            <div class="mt-1 h-1 rounded-full overflow-hidden" style="background-color:rgba(255,255,255,0.06);" aria-hidden="true">
                <div
                    class="h-full rounded-full transition-all duration-500"
                    style="width:{{ $barWidth }}%; background-color:rgba(249,115,22,0.7);"
                ></div>
            </div>
            @if($voteCount > 0)
            <span class="text-xs" style="color:rgba(249,115,22,0.8);">{{ $voteCount }} vote{{ $voteCount > 1 ? 's' : '' }}</span>
            @endif
            @endif
        </div>

        {{-- Indicateurs vivant/mort --}}
        <span class="text-sm flex-shrink-0" aria-hidden="true">{{ $player->is_alive ? '✅' : '❌' }}</span>
    </li>
    @endforeach
</ul>
