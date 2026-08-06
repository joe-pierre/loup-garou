@extends('admin.layout')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Classement</h1>
    </div>

    {{-- Filtres période --}}
    <div class="flex gap-3 mb-8">
        <a href="{{ route('admin.leaderboard', ['period' => 'current_month']) }}"
           class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                  {{ $period === 'current_month' ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-500' }}">
            Ce mois-ci
        </a>
        <a href="{{ route('admin.leaderboard', ['period' => 'previous_month']) }}"
           class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                  {{ $period === 'previous_month' ? 'bg-gray-600 text-white border-gray-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-500' }}">
            Mois précédent
        </a>
        <a href="{{ route('admin.leaderboard', ['period' => 'all']) }}"
           class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                  {{ $period === 'all' ? 'bg-gray-600 text-white border-gray-600' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-500' }}">
            Tout
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Top victoires --}}
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="px-5 py-3 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">🏆 Top victoires</h2>
            </div>
            @if ($topWins->isEmpty())
                <p class="px-5 py-6 text-gray-500 text-sm">Aucune donnée sur cette période.</p>
            @else
                <ol class="divide-y divide-gray-100">
                    @foreach ($topWins as $index => $row)
                        <li class="px-5 py-3 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="text-gray-400 text-sm w-5 text-right shrink-0">{{ $index + 1 }}</span>
                                <div class="min-w-0">
                                    <a href="{{ route('admin.users.show', $row->id) }}" class="text-blue-600 hover:underline font-medium text-sm block truncate">
                                        {{ $row->name }}
                                    </a>
                                    <span class="text-gray-500 text-xs block truncate">{{ $row->email }}</span>
                                </div>
                            </div>
                            <span class="text-gray-800 font-semibold text-sm shrink-0">{{ $row->wins_count }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        {{-- Top parties jouées --}}
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="px-5 py-3 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">🎮 Top parties jouées</h2>
            </div>
            @if ($topGamesPlayed->isEmpty())
                <p class="px-5 py-6 text-gray-500 text-sm">Aucune donnée sur cette période.</p>
            @else
                <ol class="divide-y divide-gray-100">
                    @foreach ($topGamesPlayed as $index => $row)
                        <li class="px-5 py-3 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="text-gray-400 text-sm w-5 text-right shrink-0">{{ $index + 1 }}</span>
                                <div class="min-w-0">
                                    <a href="{{ route('admin.users.show', $row->id) }}" class="text-blue-600 hover:underline font-medium text-sm block truncate">
                                        {{ $row->name }}
                                    </a>
                                    <span class="text-gray-500 text-xs block truncate">{{ $row->email }}</span>
                                </div>
                            </div>
                            <span class="text-gray-800 font-semibold text-sm shrink-0">{{ $row->games_count }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        {{-- Top taux de victoire --}}
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="px-5 py-3 border-b border-gray-100">
                <h2 class="font-semibold text-gray-800">📈 Top taux de victoire</h2>
                <p class="text-xs text-gray-500 mt-0.5">Minimum {{ $minGamesForWinRate }} parties jouées sur la période</p>
            </div>
            @if ($topWinRate->isEmpty())
                <p class="px-5 py-6 text-gray-500 text-sm">Aucun joueur n'atteint le seuil de {{ $minGamesForWinRate }} parties sur cette période.</p>
            @else
                <ol class="divide-y divide-gray-100">
                    @foreach ($topWinRate as $index => $row)
                        <li class="px-5 py-3 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="text-gray-400 text-sm w-5 text-right shrink-0">{{ $index + 1 }}</span>
                                <div class="min-w-0">
                                    <a href="{{ route('admin.users.show', $row->id) }}" class="text-blue-600 hover:underline font-medium text-sm block truncate">
                                        {{ $row->name }}
                                    </a>
                                    <span class="text-gray-500 text-xs block truncate">{{ $row->email }}</span>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <span class="text-gray-800 font-semibold text-sm">{{ $row->win_rate }}%</span>
                                <span class="text-gray-400 text-xs block">{{ $row->wins_count }}/{{ $row->games_count }}</span>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
@endsection
