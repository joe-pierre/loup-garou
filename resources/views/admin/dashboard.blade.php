@extends('admin.layout')

@section('content')
    <h1 class="text-2xl font-bold text-gray-800 mb-8">Dashboard</h1>

    {{-- Cartes de statistiques --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-10">
        <div class="bg-blue-600 text-white rounded-lg px-5 py-4 shadow-sm">
            <p class="text-sm font-medium opacity-80">Total parties</p>
            <p class="text-3xl font-bold mt-1">{{ $totalGames }}</p>
        </div>
        <div class="bg-orange-500 text-white rounded-lg px-5 py-4 shadow-sm">
            <p class="text-sm font-medium opacity-80">En cours</p>
            <p class="text-3xl font-bold mt-1">{{ $activeGames }}</p>
        </div>
        <div class="bg-green-600 text-white rounded-lg px-5 py-4 shadow-sm">
            <p class="text-sm font-medium opacity-80">Terminées</p>
            <p class="text-3xl font-bold mt-1">{{ $finishedGames }}</p>
        </div>
        <div class="bg-gray-600 text-white rounded-lg px-5 py-4 shadow-sm">
            <p class="text-sm font-medium opacity-80">Utilisateurs</p>
            <p class="text-3xl font-bold mt-1">{{ $totalUsers }}</p>
        </div>
    </div>

    {{-- Répartition des rôles joués --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-10">
        <div class="px-5 py-4 border-b border-gray-200">
            <h2 class="text-base font-semibold text-gray-700">Répartition des rôles joués</h2>
        </div>
        <div class="p-5">
            @if ($roleChartLabels->isEmpty())
                <p class="text-gray-500 text-sm">Aucun rôle distribué pour l'instant.</p>
            @else
                <div class="h-72">
                    <canvas id="role-distribution-chart"></canvas>
                </div>

                <script>
                    (function () {
                        const ctx = document.getElementById('role-distribution-chart');
                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: @json($roleChartLabels),
                                datasets: [{
                                    label: 'Parties jouées',
                                    data: @json($roleChartData),
                                    backgroundColor: '#4f46e5',
                                }],
                            },
                            options: {
                                maintainAspectRatio: false,
                                plugins: { legend: { display: false } },
                                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                            },
                        });
                    })();
                </script>
            @endif
        </div>
    </div>

    {{-- Tableau des parties actives récentes --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="px-5 py-4 border-b border-gray-200">
            <h2 class="text-base font-semibold text-gray-700">Parties actives récentes</h2>
        </div>

        @if ($recentActive->isEmpty())
            <p class="px-5 py-6 text-gray-500 text-sm">Aucune partie active en ce moment.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
                        <tr>
                            <th class="px-5 py-3 text-left">Code</th>
                            <th class="px-5 py-3 text-left">Statut</th>
                            <th class="px-5 py-3 text-left">Joueurs</th>
                            <th class="px-5 py-3 text-left">Round</th>
                            <th class="px-5 py-3 text-left">Créée le</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($recentActive as $game)
                            <tr class="hover:bg-gray-50 cursor-pointer"
                                onclick="window.location='{{ route('admin.games.show', $game->id) }}'">
                                <td class="px-5 py-3 font-mono font-semibold text-gray-800">{{ $game->code }}</td>
                                <td class="px-5 py-3">
                                    @if ($game->status === 'waiting')
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">En attente</span>
                                    @else
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700">{{ $game->status }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-gray-600">{{ $game->game_players_count }} / {{ $game->max_players }}</td>
                                <td class="px-5 py-3 text-gray-600">{{ $game->round ?: '—' }}</td>
                                <td class="px-5 py-3 text-gray-500">{{ $game->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('admin.games.show', $game->id) }}"
                                       class="text-blue-600 hover:underline text-xs font-medium"
                                       onclick="event.stopPropagation()">Voir</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
