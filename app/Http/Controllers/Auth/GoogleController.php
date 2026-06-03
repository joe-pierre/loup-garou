<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()->route('login')
                ->with('error', 'Connexion Google échouée. Veuillez réessayer.');
        }

        $user = User::updateOrCreate(
            ['google_id' => $googleUser->getId()],
            [
                'email' => $googleUser->getEmail(),
                'name'  => $googleUser->getName(),
            ]
        );

        Auth::login($user, true);

        return redirect()->route('lobby');
    }

    public function destroy()
    {
        Auth::logout();

        return redirect('/');
    }
}
