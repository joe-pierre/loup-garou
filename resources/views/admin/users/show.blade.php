@extends('admin.layout')

@section('content')
    {{-- Fil d'Ariane --}}
    <nav class="text-sm text-gray-500 mb-4">
        <a href="{{ route('admin.dashboard') }}" class="hover:underline">Admin</a>
        <span class="mx-1">›</span>
        <a href="{{ route('admin.users.index') }}" class="hover:underline">Utilisateurs</a>
        <span class="mx-1">›</span>
        <span class="text-gray-800 font-semibold">{{ $user->name }}</span>
    </nav>

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800">{{ $user->name }}</h1>
    </div>

    {{-- ═══════════════════════════════════════════════
         SECTION 1 — Infos du compte
    ════════════════════════════════════════════════ --}}
    <section class="mb-10">
        <h2 class="text-base font-semibold text-gray-600 uppercase tracking-wide mb-3">Infos du compte</h2>
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
            <dl class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-5">
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Nom</dt>
                    <dd class="font-semibold text-gray-800">{{ $user->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Email</dt>
                    <dd class="text-gray-700">{{ $user->email }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Google ID</dt>
                    <dd class="font-mono text-xs text-gray-600">{{ $user->google_id ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Inscrit le</dt>
                    <dd class="text-gray-700 text-sm">{{ $user->created_at->format('d/m/Y H:i') }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase text-gray-400 font-medium mb-1">Rôle</dt>
                    <dd>
                        @if ($user->is_admin)
                            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Admin</span>
                        @else
                            <span class="text-gray-400 text-sm">Utilisateur standard</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </div>
    </section>

    {{-- ═══════════════════════════════════════════════
         SECTION 2 — Historique des parties
    ════════════════════════════════════════════════ --}}
    <section class="mb-10">
        @php
            $sortedPlayers = $user->gamePlayers->sortByDesc('joined_at');
        @endphp

        <h2 class="text-base font-semibold text-gray-600 uppercase tracking-wide mb-3">
            Historique des parties
            <span class="text-gray-400 font-normal normal-case text-sm">({{ $sortedPlayers->count() }})</span>
        </h2>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            @if ($sortedPlayers->isEmpty())
                <p class="px-5 py-6 text-gray-500 text-sm">Aucune partie.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
                            <tr>
                                <th class="px-5 py-3 text-left">Partie</th>
                                <th class="px-5 py-3 text-left">Pseudo</th>
                                <th class="px-5 py-3 text-left">Rôle</th>
                                <th class="px-5 py-3 text-left">Vivant en fin</th>
                                <th class="px-5 py-3 text-left">Maire</th>
                                <th class="px-5 py-3 text-left">Statut partie</th>
                                <th class="px-5 py-3 text-left">Résultat</th>
                                <th class="px-5 py-3 text-left">Rejoint le</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($sortedPlayers as $gp)
                                @php
                                    $game = $gp->game;

                                    $roleColors = [
                                        'werewolf'   => 'bg-red-100 text-red-700',
                                        'white_wolf' => 'bg-orange-100 text-orange-700',
                                        'seer'       => 'bg-purple-100 text-purple-700',
                                        'witch'      => 'bg-blue-100 text-blue-700',
                                        'hunter'     => 'bg-green-100 text-green-700',
                                        'villager'   => 'bg-gray-100 text-gray-600',
                                    ];
                                    $roleColor = $roleColors[$gp->role] ?? 'bg-gray-100 text-gray-600';

                                    $winnerColors = [
                                        'villagers'  => 'bg-green-100 text-green-700',
                                        'werewolves' => 'bg-red-100 text-red-700',
                                    ];
                                    $winnerColor = $winnerColors[$game->winner_team] ?? 'bg-gray-100 text-gray-500';

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
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('admin.games.show', $game->id) }}" class="font-mono font-semibold text-blue-600 hover:underline">
                                            {{ $game->code }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 font-medium text-gray-800">{{ $gp->pseudo }}</td>
                                    <td class="px-5 py-3">
                                        @if ($gp->role)
                                            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium {{ $roleColor }}">{{ $gp->role }}</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if ($gp->is_alive)
                                            <span class="text-green-600 font-bold">✓</span>
                                        @else
                                            <span class="text-red-500 font-bold">✗</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if ($gp->is_mayor)
                                            <span class="text-yellow-500 font-bold" title="Maire">★</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium {{ $statusColor }}">{{ $game->status }}</span>
                                    </td>
                                    <td class="px-5 py-3">
                                        @if ($game->winner_team)
                                            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium {{ $winnerColor }}">{{ $game->winner_team }}</span>
                                        @elseif ($game->status === 'finished')
                                            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">Annulée</span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-gray-500 text-xs">{{ $gp->joined_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    <div class="mt-2 mb-8">
        <a href="{{ route('admin.users.index') }}" class="text-blue-600 hover:underline text-sm">← Retour à la liste</a>
    </div>
@endsection
