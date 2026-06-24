@extends('admin.layout')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Liste des parties</h1>
    </div>

    {{-- Filtres statut --}}
    <div class="flex gap-3 mb-6">
        <a href="{{ route('admin.games.index') }}"
           class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                  {{ !$statusFilter ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-500' }}">
            Toutes
        </a>
        <a href="{{ route('admin.games.index', ['status' => 'waiting']) }}"
           class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                  {{ $statusFilter === 'waiting' ? 'bg-gray-600 text-white border-gray-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-500' }}">
            En attente
        </a>
        <a href="{{ route('admin.games.index', ['status' => 'active']) }}"
           class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                  {{ $statusFilter === 'active' ? 'bg-orange-500 text-white border-orange-500' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-500' }}">
            En cours
        </a>
        <a href="{{ route('admin.games.index', ['status' => 'finished']) }}"
           class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                  {{ $statusFilter === 'finished' ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-500' }}">
            Terminées
        </a>
    </div>

    {{-- Tableau --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        @if ($games->isEmpty())
            <p class="px-5 py-6 text-gray-500 text-sm">Aucune partie trouvée.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
                        <tr>
                            <th class="px-5 py-3 text-left">Code</th>
                            <th class="px-5 py-3 text-left">Statut</th>
                            <th class="px-5 py-3 text-left">Joueurs / Max</th>
                            <th class="px-5 py-3 text-left">Round</th>
                            <th class="px-5 py-3 text-left">Créée le</th>
                            <th class="px-5 py-3 text-left">Démarrée le</th>
                            <th class="px-5 py-3 text-left">Terminée le</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($games as $game)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-mono font-semibold text-gray-800">{{ $game->code }}</td>
                                <td class="px-5 py-3">
                                    @if ($game->status === 'waiting')
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">En attente</span>
                                    @elseif ($game->status === 'finished')
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Terminée</span>
                                    @else
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700">{{ $game->status }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-gray-600">{{ $game->game_players_count }} / {{ $game->max_players }}</td>
                                <td class="px-5 py-3 text-gray-600">{{ $game->round ?: '—' }}</td>
                                <td class="px-5 py-3 text-gray-500">{{ $game->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-3 text-gray-500">{{ $game->started_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-5 py-3 text-gray-500">{{ $game->finished_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('admin.games.show', $game->id) }}"
                                       class="text-blue-600 hover:underline text-xs font-medium">Voir</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($games->hasPages())
                <div class="px-5 py-4 border-t border-gray-100">
                    {{ $games->links() }}
                </div>
            @endif
        @endif
    </div>
@endsection
