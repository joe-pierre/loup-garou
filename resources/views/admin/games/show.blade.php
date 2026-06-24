@extends('admin.layout')

@section('content')
    {{-- Fil d'Ariane --}}
    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('admin.dashboard') }}" class="hover:underline">Admin</a>
        <span class="mx-1">›</span>
        <a href="{{ route('admin.games.index') }}" class="hover:underline">Parties</a>
        <span class="mx-1">›</span>
        <span class="text-gray-800 font-mono font-semibold">{{ $game->code }}</span>
    </nav>

    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-gray-800">Partie <span class="font-mono">{{ $game->code }}</span></h1>
    </div>

    {{-- Navigation ancres --}}
    <div class="flex flex-wrap gap-2 mb-8 pb-3 border-b border-gray-200">
        <a href="#general"    class="px-3 py-1.5 rounded text-sm font-medium bg-white border border-gray-300 text-gray-600 hover:border-gray-500 hover:text-gray-900 transition-colors">Infos générales</a>
        <a href="#players"   class="px-3 py-1.5 rounded text-sm font-medium bg-white border border-gray-300 text-gray-600 hover:border-gray-500 hover:text-gray-900 transition-colors">Joueurs ({{ $game->gamePlayers->count() }})</a>
        <a href="#actions"   class="px-3 py-1.5 rounded text-sm font-medium bg-white border border-gray-300 text-gray-600 hover:border-gray-500 hover:text-gray-900 transition-colors">Actions ({{ $game->actions->count() }})</a>
        <a href="#chat"      class="px-3 py-1.5 rounded text-sm font-medium bg-white border border-gray-300 text-gray-600 hover:border-gray-500 hover:text-gray-900 transition-colors">Chat ({{ $game->messages->count() }})</a>
        <a href="#exclusions" class="px-3 py-1.5 rounded text-sm font-medium bg-white border border-gray-300 text-gray-600 hover:border-gray-500 hover:text-gray-900 transition-colors">Exclusions ({{ $game->exclusions->count() }})</a>
    </div>

    {{-- ═══════════════════════════════════════════════
         SECTION 1 — Infos générales
    ════════════════════════════════════════════════ --}}
    <section id="general" class="mb-12 scroll-mt-4">
        <h2 class="text-base font-semibold text-gray-600 uppercase tracking-wide mb-3">Infos générales</h2>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5 mb-4">
            @php
                $statusColors = [
                    'waiting'          => 'bg-gray-100 text-gray-600',
                    'electing_mayor'   => 'bg-purple-100 text-purple-700',
                    'night'            => 'bg-indigo-100 text-indigo-700',
                    'wolves_turn'      => 'bg-indigo-100 text-indigo-700',
                    'processing_night' => 'bg-indigo-100 text-indigo-700',
                    'day'              => 'bg-yellow-100 text-yellow-700',
                    'processing_day'   => 'bg-yellow-100 text-yellow-700',
                    'finished'         => 'bg-green-100 text-green-700',
                ];
                $statusColor = $statusColors[$game->status] ?? 'bg-gray-100 text-gray-600';
            @endphp

            <dl class="grid grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-5">
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Code</dt>
                    <dd class="font-mono font-semibold text-gray-800">{{ $game->code }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Statut</dt>
                    <dd><span class="inline-block px-2 py-0.5 rounded text-xs font-medium {{ $statusColor }}">{{ $game->status }}</span></dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Round</dt>
                    <dd class="text-gray-800">{{ $game->round ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Vainqueur</dt>
                    <dd>
                        @if ($game->winner_team === 'villagers')
                            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Villageois</span>
                        @elseif ($game->winner_team === 'werewolves')
                            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">Loups-garous</span>
                        @elseif ($game->status === 'finished')
                            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">Annulée</span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Phase deadline</dt>
                    <dd class="text-gray-700 text-sm">{{ $game->phase_deadline?->format('d/m/Y H:i:s') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Démarrée le</dt>
                    <dd class="text-gray-700 text-sm">{{ $game->started_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Terminée le</dt>
                    <dd class="text-gray-700 text-sm">{{ $game->finished_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Créée le</dt>
                    <dd class="text-gray-700 text-sm">{{ $game->created_at->format('d/m/Y H:i') }}</dd>
                </div>
            </dl>
        </div>

        @if ($game->settings)
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-600 mb-3">Paramètres (settings)</h3>
                <pre class="text-xs bg-gray-50 rounded p-3 overflow-x-auto text-gray-700 leading-relaxed">{{ json_encode($game->settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        @endif
    </section>

    {{-- ═══════════════════════════════════════════════
         SECTION 2 — Joueurs
    ════════════════════════════════════════════════ --}}
    <section id="players" class="mb-12 scroll-mt-4">
        <h2 class="text-base font-semibold text-gray-600 uppercase tracking-wide mb-3">Joueurs</h2>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            @if ($game->gamePlayers->isEmpty())
                <p class="px-5 py-6 text-gray-500 text-sm">Aucun joueur.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
                            <tr>
                                <th class="px-5 py-3 text-left">Pseudo</th>
                                <th class="px-5 py-3 text-left">Rôle</th>
                                <th class="px-5 py-3 text-left">Vivant</th>
                                <th class="px-5 py-3 text-left">Maire</th>
                                <th class="px-5 py-3 text-left">Inactif</th>
                                <th class="px-5 py-3 text-left">Prêt</th>
                                <th class="px-5 py-3 text-left">Utilisateur</th>
                                <th class="px-5 py-3 text-left">Rejoint le</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($game->gamePlayers as $player)
                                @php
                                    $roleColors = [
                                        'werewolf'   => 'bg-red-100 text-red-700',
                                        'white_wolf' => 'bg-orange-100 text-orange-700',
                                        'seer'       => 'bg-purple-100 text-purple-700',
                                        'witch'      => 'bg-blue-100 text-blue-700',
                                        'hunter'     => 'bg-green-100 text-green-700',
                                        'villager'   => 'bg-gray-100 text-gray-600',
                                    ];
                                    $roleColor = $roleColors[$player->role] ?? 'bg-gray-100 text-gray-600';
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 font-medium text-gray-800">{{ $player->pseudo }}</td>
                                    <td class="px-5 py-3">
                                        @if ($player->role)
                                            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium {{ $roleColor }}">{{ $player->role }}</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if ($player->is_alive)
                                            <span class="text-green-600 font-bold">✓</span>
                                        @else
                                            <span class="text-red-500 font-bold">✗</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if ($player->is_mayor)
                                            <span class="text-yellow-500 font-bold" title="Maire">★</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if ($player->is_inactive)
                                            <span class="text-orange-500 font-bold" title="Inactif">!</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if ($player->is_ready)
                                            <span class="text-green-600 font-bold">✓</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-gray-500 text-xs">{{ $player->user?->email ?? '—' }}</td>
                                    <td class="px-5 py-3 text-gray-500 text-xs">{{ $player->joined_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    {{-- ═══════════════════════════════════════════════
         SECTION 3 — Actions
    ════════════════════════════════════════════════ --}}
    <section id="actions" class="mb-12 scroll-mt-4">
        @php
            $sortedActions  = $game->actions->sortBy([['round', 'asc'], ['phase', 'asc'], ['id', 'asc']]);
            $actionsCount   = $sortedActions->count();
            $displayActions = $sortedActions->take(50);
        @endphp

        <h2 class="text-base font-semibold text-gray-600 uppercase tracking-wide mb-3">
            Actions <span class="text-gray-400 font-normal normal-case text-sm">({{ $actionsCount }})</span>
        </h2>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            @if ($sortedActions->isEmpty())
                <p class="px-5 py-6 text-gray-500 text-sm">Aucune action.</p>
            @else
                @if ($actionsCount > 50)
                    <p class="px-5 py-2 text-xs text-orange-700 bg-orange-50 border-b border-orange-100">
                        Affichage limité aux 50 premières actions sur {{ $actionsCount }}.
                    </p>
                @endif
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
                            <tr>
                                <th class="px-5 py-3 text-left">Round</th>
                                <th class="px-5 py-3 text-left">Phase</th>
                                <th class="px-5 py-3 text-left">Type</th>
                                <th class="px-5 py-3 text-left">Joueur</th>
                                <th class="px-5 py-3 text-left">Cible</th>
                                <th class="px-5 py-3 text-left">Poids</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($displayActions as $action)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-gray-700">{{ $action->round }}</td>
                                    <td class="px-5 py-3 text-gray-600">{{ $action->phase }}</td>
                                    <td class="px-5 py-3 font-mono text-xs text-gray-700">{{ $action->type }}</td>
                                    <td class="px-5 py-3 text-gray-800">{{ $action->player?->pseudo ?? '—' }}</td>
                                    <td class="px-5 py-3 text-gray-700">{{ $action->target?->pseudo ?? '—' }}</td>
                                    <td class="px-5 py-3 text-gray-600">{{ $action->weight }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    {{-- ═══════════════════════════════════════════════
         SECTION 4 — Chat
    ════════════════════════════════════════════════ --}}
    <section id="chat" class="mb-12 scroll-mt-4">
        @php
            $sortedMessages = $game->messages->sortBy([['round', 'asc'], ['id', 'asc']]);
            $channelColors  = [
                'general'    => 'bg-blue-100 text-blue-700',
                'werewolves' => 'bg-red-100 text-red-700',
                'dead'       => 'bg-gray-100 text-gray-500',
            ];
        @endphp

        <h2 class="text-base font-semibold text-gray-600 uppercase tracking-wide mb-3">
            Chat <span class="text-gray-400 font-normal normal-case text-sm">({{ $sortedMessages->count() }})</span>
        </h2>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            @if ($sortedMessages->isEmpty())
                <p class="px-5 py-6 text-gray-500 text-sm">Aucun message.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
                            <tr>
                                <th class="px-5 py-3 text-left">Round</th>
                                <th class="px-5 py-3 text-left">Phase</th>
                                <th class="px-5 py-3 text-left">Canal</th>
                                <th class="px-5 py-3 text-left">Pseudo</th>
                                <th class="px-5 py-3 text-left">Message</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($sortedMessages as $msg)
                                @php $channelColor = $channelColors[$msg->channel] ?? 'bg-gray-100 text-gray-500'; @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-gray-700">{{ $msg->round }}</td>
                                    <td class="px-5 py-3 text-gray-600">{{ $msg->phase }}</td>
                                    <td class="px-5 py-3">
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium {{ $channelColor }}">{{ $msg->channel }}</span>
                                    </td>
                                    <td class="px-5 py-3 font-medium text-gray-800">{{ $msg->player?->pseudo ?? '—' }}</td>
                                    <td class="px-5 py-3 text-gray-700">{{ $msg->message }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    {{-- ═══════════════════════════════════════════════
         SECTION 5 — Exclusions
    ════════════════════════════════════════════════ --}}
    <section id="exclusions" class="mb-12 scroll-mt-4">
        <h2 class="text-base font-semibold text-gray-600 uppercase tracking-wide mb-3">Exclusions</h2>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            @if ($game->exclusions->isEmpty())
                <p class="px-5 py-6 text-gray-500 text-sm">Aucune exclusion.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
                            <tr>
                                <th class="px-5 py-3 text-left">Utilisateur</th>
                                <th class="px-5 py-3 text-left">Joueur (pseudo)</th>
                                <th class="px-5 py-3 text-left">Raison</th>
                                <th class="px-5 py-3 text-left">Exclu le</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($game->exclusions as $exclusion)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-gray-600 text-xs">{{ $exclusion->user?->email ?? '—' }}</td>
                                    <td class="px-5 py-3 font-medium text-gray-800">{{ $exclusion->player?->pseudo ?? '—' }}</td>
                                    <td class="px-5 py-3 text-gray-700">{{ $exclusion->reason }}</td>
                                    <td class="px-5 py-3 text-gray-500 text-xs">{{ $exclusion->excluded_at?->format('d/m/Y H:i:s') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    <div class="mt-2 mb-8">
        <a href="{{ route('admin.games.index') }}" class="text-blue-600 hover:underline text-sm">← Retour à la liste</a>
    </div>
@endsection
