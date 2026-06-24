<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminGameController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.games.index');
    }

    public function show(Request $request, int $id)
    {
        return view('admin.games.show');
    }
}
