@extends('layouts.game')

@section('title', 'Loup-Garou Undu — Spectateur')

@php
    $isWolf = $player->isWerewolf();
@endphp

@push('styles')
<style>
    .game-screen { filter: grayscale(30%); }

    .death-banner {
        background-color: #1a0000;
        border: 1px solid rgba(139,0,0,0.6);
        border-radius: 0.75rem;
        padding: 0.875rem 1.25rem;
        display: flex; align-items: center; gap: 0.75rem;
    }
    .phase-badge {
        background-color: #111827;
        border: 1px solid rgba(201,168,76,0.25);
        border-radius: 0.75rem;
        padding: 0.6rem 1rem;
        text-align: center;
    }
    .player-row {
        background-color: #111827;
        border: 1px solid rgba(255,255,255,0.05);
        border-radius: 0.5rem;
        display: flex; align-items: center; gap: 0.75rem;
        padding: 0.6rem 0.875rem;
    }
    .avatar {
        width: 2rem; height: 2rem; border-radius: 50%;
        background-color: rgba(201,168,76,0.1);
        border: 1px solid rgba(201,168,76,0.25);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Cinzel', serif; font-weight: 600;
        font-size: 0.7rem; color: #c9a84c; flex-shrink: 0;
    }
    .avatar.dead { opacity: 0.35; filter: grayscale(100%); }

    .role-badge-werewolf { background: rgba(139,0,0,0.25); color: #f87171; }
    .role-badge-hidden   { background: rgba(107,114,128,0.15); color: rgba(232,224,208,0.35); }

    .tab-btn {
        font-family: 'Cinzel', serif; font-size: 0.75rem;
        padding: 0.4rem 1.1rem; border-radius: 0.375rem;
        color: rgba(232,224,208,0.45); transition: all 0.2s;
    }
    .tab-btn.active {
        background-color: rgba(201,168,76,0.12);
        color: #c9a84c;
        border: 1px solid rgba(201,168,76,0.3);
    }

    .chat-area {
        background-color: #0d1117;
        border: 1px solid rgba(255,255,255,0.06);
        border-radius: 0.75rem;
        height: 220px; overflow-y: auto;
        padding: 0.75rem;
        display: flex; flex-direction: column; gap: 0.4rem;
    }
    .chat-msg { font-size: 0.875rem; }
    .chat-msg .pseudo { font-weight: 600; color: #c9a84c; margin-right: 0.3rem; }
    .chat-msg.wolf .pseudo { color: #f87171; }

    .readonly-input {
        background-color: rgba(0,0,0,0.3);
        border: 1px solid rgba(139,0,0,0.3);
        border-radius: 0.5rem;
        padding: 0.75rem 1rem;
        font-style: italic;
        color: rgba(232,224,208,0.35);
        font-size: 0.875rem;
        text-align: center;
    }
</style>
@endpush

@section('header-phase')
    <span class="text-xs font-medieval tracking-widest" style="color: rgba(232,224,208,0.4);">💀 SPECTATEUR</span>
@endsection

@section('content')
<div class="game-screen" id="game-screen"
     x-data="gameState({{ $game->id }}, {{ auth()->id() }})"
     data-game-code="{{ $game->code }}"
     data-player-id="{{ $player->id }}"
     x-init="init(); $watch('phase', () => {
         if ($refs.phaseBadge && _motion) {
             gsap.fromTo($refs.phaseBadge, { opacity: 0.3 }, { opacity: 1, duration: 0.4, ease: 'power2.out' });
         }
     })">

    <div class="max-w-xl mx-auto px-4 py-6" x-data="{ tab: 'players' }">

        {{-- ══════════ BANDEAU MORT ══════════ --}}
        <div class="death-banner mb-4">
            <span class="text-2xl">💀</span>
            <div>
                <p class="font-medieval font-semibold text-sm" style="color:#ef4444;">Tu es mort.</p>
                <p class="text-xs italic" style="color:rgba(232,224,208,0.5);">Observe la partie en silence.</p>
            </div>
        </div>

        {{-- ══════════ PHASE COURANTE (réactive via gameState.phase) ══════════ --}}
        <div class="phase-badge mb-5" x-ref="phaseBadge">
            <p class="text-xs font-medieval tracking-widest" style="color:#c9a84c;"
               x-text="(phase === 'night' ? '🌙 NUIT' : phase === 'day' ? '☀️ JOUR' : phase === 'electing_mayor' ? '👑 ÉLECTION DU MAIRE' : phase === 'finished' ? '🏁 PARTIE TERMINÉE' : '…') + ' — Round ' + round"></p>
        </div>

        {{-- ══════════ ONGLETS ══════════ --}}
        <div class="flex gap-2 mb-4">
            <button class="tab-btn" :class="{ active: tab === 'players' }" @click="tab = 'players'">Joueurs</button>
            <button class="tab-btn" :class="{ active: tab === 'chat' }"    @click="tab = 'chat'">Chat 💬</button>
            @if($isWolf)
            <button class="tab-btn" :class="{ active: tab === 'wolves' }"  @click="tab = 'wolves'">Loups 🐺</button>
            @endif
        </div>

        {{-- ══════════ JOUEURS — vivants/morts ══════════ --}}
        <div x-show="tab === 'players'" x-cloak>
            <div class="flex flex-col gap-1.5">
                @foreach($allPlayers as $p)
                @php
                    // Loups morts voient les rôles des loups (vivants ou non)
                    $showRole = $isWolf && $p->isWerewolf();
                @endphp
                <div class="player-row" data-player-id="{{ $p->id }}">
                    <div class="avatar {{ $p->is_alive ? '' : 'dead' }}">
                        {{ strtoupper(substr($p->pseudo, 0, 1)) }}
                    </div>
                    <div class="flex-1 flex items-center gap-1.5">
                        <span class="text-sm" style="color:{{ $p->is_alive ? '#e8e0d0' : 'rgba(232,224,208,0.4)' }}">
                            {{ $p->pseudo }}
                        </span>
                        @if($p->is_mayor) <span class="text-xs" data-badge="mayor">👑</span> @endif
                    </div>
                    @if($showRole)
                        <span class="text-xs px-2 py-0.5 rounded-full role-badge-werewolf">🐺 Loup</span>
                    @else
                        <span class="text-xs px-2 py-0.5 rounded-full role-badge-hidden">
                            {{ $p->is_alive ? '?' : '💀' }}
                        </span>
                    @endif
                </div>
                @endforeach
            </div>
        </div>

        {{-- ══════════ CHAT GÉNÉRAL (lecture seule) ══════════ --}}
        <div x-show="tab === 'chat'" x-cloak>
            <div class="chat-area mb-3" id="general-chat" x-ref="generalChat">
                <template x-for="msg in chat" :key="msg.timestamp + msg.pseudo + msg.message">
                    <p class="chat-msg">
                        <span class="pseudo" x-text="msg.pseudo + ' :'"></span>
                        <span x-text="msg.message"></span>
                    </p>
                </template>
                <p x-show="chat.length === 0" class="text-xs text-center" style="color:rgba(232,224,208,0.3);margin:auto;">
                    Aucun message pour l'instant.
                </p>
            </div>
            <div class="readonly-input">💀 Tu es mort. Observe en silence.</div>
        </div>

        {{-- ══════════ CHAT LOUPS (lecture seule, ex-loups uniquement) ══════════ --}}
        @if($isWolf)
        <div x-show="tab === 'wolves'" x-cloak>
            <div class="chat-area mb-3" id="wolf-chat" x-ref="wolfChat">
                <template x-for="msg in wolvesChat" :key="msg.timestamp + msg.pseudo + msg.message">
                    <p class="chat-msg wolf">
                        <span class="pseudo" x-text="msg.pseudo + ' :'"></span>
                        <span x-text="msg.message"></span>
                    </p>
                </template>
                <p x-show="wolvesChat.length === 0" class="text-xs text-center" style="color:rgba(232,224,208,0.3);margin:auto;">
                    Aucun message loup.
                </p>
            </div>
            <div class="readonly-input">🐺 Tu es mort. Lecture seule.</div>
        </div>
        @endif

    </div>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        gsap.to('#game-screen', { filter: 'grayscale(30%)', duration: 1.2, ease: 'power2.out' });
    });

    // Auto-scroll des panneaux de chat à l'arrivée d'un nouveau message
    document.addEventListener('alpine:init', () => {
        ['general-chat', 'wolf-chat'].forEach((id) => {
            const el = document.getElementById(id);
            if (!el) return;
            new MutationObserver(() => { el.scrollTop = el.scrollHeight; })
                .observe(el, { childList: true });
        });
    });
</script>
@endpush
