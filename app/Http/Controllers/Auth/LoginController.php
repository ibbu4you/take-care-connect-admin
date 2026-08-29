<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        /*
         | Regenerate before anything else touches the session.
         |
         | Without this the pre-login session id stays valid after
         | authentication, which is the whole mechanism of session fixation: an
         | attacker who can set a victim's cookie beforehand ends up sharing
         | their authenticated session.
         */
        $request->session()->regenerate();

        $user = $request->user();

        // saveQuietly: this is bookkeeping, not an edit somebody made. Firing
        // model events here would put "updated the user" in the audit log on
        // every single sign-in and bury the changes that matter.
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        activity()
            ->causedBy($user)
            ->event('login')
            ->log('signed in');

        return redirect()->intended('/dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        activity()
            ->causedBy($request->user())
            ->event('logout')
            ->log('signed out');

        Auth::guard('web')->logout();

        // Both are required: invalidate drops the session data, regenerateToken
        // issues a fresh CSRF token so the login form they land on works.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
