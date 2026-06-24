@extends('admin.layout')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Utilisateurs</h1>
        <span class="text-sm text-gray-500">{{ $users->total() }} au total</span>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
        @if ($users->isEmpty())
            <p class="px-5 py-6 text-gray-500 text-sm">Aucun utilisateur.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
                        <tr>
                            <th class="px-5 py-3 text-left">Nom</th>
                            <th class="px-5 py-3 text-left">Email</th>
                            <th class="px-5 py-3 text-left">Google ID</th>
                            <th class="px-5 py-3 text-left">Admin</th>
                            <th class="px-5 py-3 text-left">Inscrit le</th>
                            <th class="px-5 py-3 text-left">Parties jouées</th>
                            <th class="px-5 py-3 text-left"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($users as $user)
                            <tr class="hover:bg-gray-50">
                                <td class="px-5 py-3 font-medium text-gray-800">{{ $user->name }}</td>
                                <td class="px-5 py-3 text-gray-600">{{ $user->email }}</td>
                                <td class="px-5 py-3 font-mono text-xs text-gray-500">
                                    @if ($user->google_id)
                                        {{ Str::limit($user->google_id, 12, '...') }}
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    @if ($user->is_admin)
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">Admin</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-gray-500 text-xs">{{ $user->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-3 text-gray-700">{{ $user->game_players_count }}</td>
                                <td class="px-5 py-3">
                                    <a href="{{ route('admin.users.show', $user->id) }}" class="text-blue-600 hover:underline text-xs">Voir</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($users->hasPages())
                <div class="px-5 py-4 border-t border-gray-100">
                    {{ $users->links() }}
                </div>
            @endif
        @endif
    </div>
@endsection
