<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a deactivated account off mid-session.
 *
 * Checking `is_active` only at login is not enough: sessions here last for
 * days, so somebody switched off on Monday morning would keep working until
 * they happened to log out. Checking on every request means the next click
 * after an Admin unticks the box lands them on the login screen.
 *
 * Runs after `auth`, so a guest is redirected by the auth middleware rather
 * than falling through this one.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Your account has been deactivated. Speak to an administrator.']);
        }

        return $next($request);
    }
}
