<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::withCount('gamePlayers')
            ->orderByDesc('created_at')
            ->paginate(50);

        return view('admin.users.index', compact('users'));
    }

    public function show(Request $request, int $id)
    {
        $user = User::with([
            'gamePlayers.game',
        ])->findOrFail($id);

        return view('admin.users.show', compact('user'));
    }
}
