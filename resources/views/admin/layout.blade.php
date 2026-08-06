<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration — Loup-Garou</title>
    @vite(['resources/css/app.css'])
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">
    <nav class="bg-white border-b border-gray-200 shadow-sm">
        <div class="container mx-auto px-4 py-3 flex items-center gap-6">
            <span class="font-semibold text-gray-700 mr-4">Admin</span>
            <a href="{{ route('admin.dashboard') }}" class="text-gray-600 hover:text-gray-900 font-medium">Dashboard</a>
            <a href="{{ route('admin.games.index') }}" class="text-gray-600 hover:text-gray-900 font-medium">Parties</a>
            <a href="{{ route('admin.users.index') }}" class="text-gray-600 hover:text-gray-900 font-medium">Utilisateurs</a>
            <a href="{{ route('admin.leaderboard') }}" class="text-gray-600 hover:text-gray-900 font-medium">Classement</a>
            <a href="{{ route('lobby') }}" class="ml-auto text-gray-500 hover:text-gray-900 text-sm">← Retour au jeu</a>
        </div>
    </nav>
    <main class="container mx-auto px-4 py-8">
        @yield('content')
    </main>
</body>
</html>
