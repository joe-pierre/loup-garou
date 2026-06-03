<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Game\LobbyController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', fn () => view('auth.login'))->name('login');

Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
Route::post('/logout', [GoogleController::class, 'destroy'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/lobby', fn () => view('lobby.index'))->name('lobby');
    Route::post('/game', [LobbyController::class, 'create'])->name('game.create');
});
