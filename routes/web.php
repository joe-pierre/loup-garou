<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Game\ActionController;
use App\Http\Controllers\Game\ChatController;
use App\Http\Controllers\Game\GameController;
use App\Http\Controllers\Game\LobbyController;
use App\Http\Controllers\Game\VoteController;
use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════
// PAGES PUBLIQUES
// ══════════════════════════════════════════════════════
Route::get('/', fn () => view('home'))->name('home');
Route::get('/login', fn () => view('auth.login'))->name('login');

// ══════════════════════════════════════════════════════
// AUTHENTIFICATION GOOGLE OAUTH
// ══════════════════════════════════════════════════════
Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
Route::post('/logout', [GoogleController::class, 'destroy'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    // ══════════════════════════════════════════════════════
    // LOBBY — Création et jonction de parties
    // ══════════════════════════════════════════════════════
    Route::get('/lobby', fn () => view('lobby.index'))->name('lobby');
    Route::post('/game', [LobbyController::class, 'create'])->name('game.create');
    Route::post('/game/{code}/join', [LobbyController::class, 'join'])->name('game.join');
    Route::get('/game/{code}/lobby', [LobbyController::class, 'waitingRoom'])->name('game.lobby');
    Route::get('/game/{id}/lobby/state', [LobbyController::class, 'lobbyState'])->name('game.lobby.state');
    Route::post('/game/{id}/exclude/{playerId}', [LobbyController::class, 'exclude'])->name('game.exclude'); // Guard: is_host vérifié dans GameService::excludePlayer, status=waiting obligatoire

    // ══════════════════════════════════════════════════════
    // PARAMÈTRES DE PARTIE (host uniquement, status=waiting)
    // ══════════════════════════════════════════════════════
    Route::post('/game/{id}/settings/timers', [LobbyController::class, 'updateTimers'])->name('game.settings.timers'); // Guard: GamePolicy::updateSettings, status=waiting obligatoire
    Route::post('/game/{id}/settings/roles', [LobbyController::class, 'updateRoles'])->name('game.settings.roles');   // Guard: GamePolicy::updateSettings, status=waiting obligatoire

    // ══════════════════════════════════════════════════════
    // PHASES DE JEU — Vues
    // ══════════════════════════════════════════════════════
    Route::get('/game/{code}/role-reveal', [ActionController::class, 'roleReveal'])->name('game.role-reveal');
    Route::post('/game/{id}/ready', [ActionController::class, 'ready'])->name('game.ready');
    Route::get('/game/{code}/mayor-election', [GameController::class, 'mayorElection'])->name('game.mayor-election');
    Route::get('/game/{code}/day', [GameController::class, 'day'])->name('game.day');
    Route::get('/game/{code}/night', [GameController::class, 'night'])->name('game.night');
    Route::get('/game/{code}/summary', [GameController::class, 'summary'])->name('game.summary');
    Route::get('/game/{code}/finished', [GameController::class, 'finished'])->name('game.finished');
    Route::get('/game/{code}/cancelled', [GameController::class, 'cancelled'])->name('game.cancelled');
    Route::get('/game/{code}/spectator', [GameController::class, 'spectator'])->name('game.spectator');

    Route::middleware('throttle:60,1')->group(function () {
        // ══════════════════════════════════════════════════════
        // ACTIONS DE JEU — Votes
        // ══════════════════════════════════════════════════════
        Route::post('/game/{id}/vote/mayor', [VoteController::class, 'mayor'])->name('game.vote.mayor');           // Guard: joueur vivant + phase electing_mayor
        Route::post('/game/{id}/vote/day', [VoteController::class, 'day'])->name('game.vote.day');                 // Guard: joueur vivant + phase day, poids ×2 si maire
        Route::post('/game/{id}/vote/night', [VoteController::class, 'night'])->name('game.vote.night');           // Guard: loup vivant + phase wolves_turn, cible non-loup
        Route::post('/game/{id}/mayor/succession', [ActionController::class, 'mayorSuccession'])->name('game.mayor.succession'); // Guard: joueur est maire actuel + succession en cours

        // ══════════════════════════════════════════════════════
        // ACTIONS DE JEU — Rôles spéciaux (voyante, sorcière, chasseur)
        // ══════════════════════════════════════════════════════
        Route::post('/game/{id}/seer/check', [ActionController::class, 'seerCheck'])->name('game.seer.check');     // Guard: rôle seer + phase night, cible ≠ soi-même
        Route::post('/game/{id}/witch/act', [ActionController::class, 'witchAct'])->name('game.witch.act');        // Guard: rôle witch + phase night + potion disponible, auto-soin interdit
        Route::post('/game/{id}/hunter/shoot', [ActionController::class, 'hunterShoot'])->name('game.hunter.shoot'); // Guard: rôle hunter + hunter_pending en base, cible ≠ soi-même

        Route::post('/game/{id}/chat', [ChatController::class, 'send'])->name('game.chat.send'); // Guard: canal werewolves réservé aux loups, canal dead aux morts
    });

    // ══════════════════════════════════════════════════════
    // ÉTAT ET HISTORIQUE
    // ══════════════════════════════════════════════════════
    Route::get('/game/{code}/state', [GameController::class, 'state'])->name('game.state');
    Route::get('/game/{code}/history', [GameController::class, 'history'])->name('game.history'); // Guard: GamePolicy::viewHistory (joueur de la partie uniquement)

    // ══════════════════════════════════════════════════════
    // DÉCONNEXION / RECONNEXION (throttle dédié)
    // ══════════════════════════════════════════════════════
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/game/{id}/quit', [GameController::class, 'quit'])->name('game.quit');
        Route::post('/game/{id}/disconnect', [GameController::class, 'disconnect'])->name('game.disconnect');
        Route::post('/game/{code}/reconnect', [GameController::class, 'reconnect'])->name('game.reconnect');
    });

    // ══════════════════════════════════════════════════════
    // PUSH NOTIFICATIONS (WebPush)
    // ══════════════════════════════════════════════════════
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
});
