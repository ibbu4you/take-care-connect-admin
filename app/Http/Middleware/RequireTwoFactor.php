<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stands between a password and the rest of the application.
 *
 * One job: somebody who has *chosen* to enrol in two-factor and has not yet
 * answered the challenge this session is sent to it.
 *
 * ── Nobody is ever pushed into enrolling ───────────────────────────────────
 * There used to be a second job here — accounts whose role required a second
 * factor were redirected to the setup screen before they could reach anything
 * else. Nobody asked for that, and being met by a QR code on the way to your
 * own dashboard is a poor way to be introduced to a security feature.
 *
 * Two-factor is opt-in, from Profile, by the person who wants it. This
 * middleware only honours that choice once it has been made.
 *
 * The 2FA routes themselves are excluded, or the redirect would loop.
 */
class RequireTwoFactor
{
    /** Paths that must stay reachable while the user is mid-challenge. */
    private const EXEMPT = [
        'two-factor/*',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $request->is(self::EXEMPT)) {
            return $next($request);
        }

        if ($user->hasTwoFactorEnabled() && ! $request->session()->get('two_factor_passed')) {
            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
