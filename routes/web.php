<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Game\ActionController;
use App\Http\Controllers\Game\ChatController;
use App\Http\Controllers\Game\GameController;
use App\Http\Controllers\Game\LobbyController;
use App\Http\Controllers\Game\VoteController;
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
    Route::post('/game/{code}/join', [LobbyController::class, 'join'])->name('game.join');
    Route::get('/game/{code}/lobby', [LobbyController::class, 'waitingRoom'])->name('game.lobby');
    Route::post('/game/{id}/exclude/{playerId}', [LobbyController::class, 'exclude'])->name('game.exclude');
    Route::get('/game/{code}/role-reveal', [ActionController::class, 'roleReveal'])->name('game.role-reveal');
    Route::post('/game/{id}/ready', [ActionController::class, 'ready'])->name('game.ready');
    Route::post('/game/{id}/vote/mayor', [VoteController::class, 'mayor'])->name('game.vote.mayor');
    Route::post('/game/{id}/vote/night', [VoteController::class, 'night'])->name('game.vote.night');
    Route::post('/game/{id}/chat', [ChatController::class, 'send'])->name('game.chat.send');
    Route::post('/game/{id}/seer/check', [ActionController::class, 'seerCheck'])->name('game.seer.check');
    Route::post('/game/{id}/mayor/succession', [ActionController::class, 'mayorSuccession'])->name('game.mayor.succession');
    Route::get('/game/{code}/day', [GameController::class, 'day'])->name('game.day');
    Route::get('/game/{code}/night', [GameController::class, 'night'])->name('game.night');
});
