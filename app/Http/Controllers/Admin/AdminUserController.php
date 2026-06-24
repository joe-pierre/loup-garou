<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.users.index');
    }

    public function show(Request $request, int $id)
    {
        return view('admin.users.show');
    }
}
