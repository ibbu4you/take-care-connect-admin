<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         | No Gate::before bypass.
         |
         | There was one, for the Super Admin role. Removing that role removed
         | the reason for it: an Admin holds every permission anyway, so a
         | bypass would add nothing except silently overriding per-user
         | revocations — which are a feature, and which the permission matrix
         | shows as taking effect.
         */

        // The font files are preloaded from app.blade.php; Vite needs to know
        // to emit the right Link headers for them.
        Vite::prefetch(concurrency: 3);

        $this->configureRateLimiting();
    }

    /**
     * Login throttling.
     *
     * Two limiters doing two different jobs.
     *
     * The per-account limiter is the one that stops password guessing, so it is
     * tight: five attempts a minute against a given address from a given
     * machine.
     *
     * The per-IP limiter only exists to blunt a broad sweep across many
     * addresses, so it is deliberately loose. The whole team shares one office
     * IP — a tight per-IP cap would mean the sixth person to arrive on Monday
     * morning is locked out by their five colleagues signing in ahead of them,
     * which is a self-inflicted outage rather than a defence.
     */
    private function configureRateLimiting(): void
    {
        // Answered as a form error, like the request's own lockout — a bare
        // 429 page left somebody mistyping their password with no way back.
        $lockedOut = fn () => back()
            ->withErrors(['email' => 'Too many sign-in attempts. Wait a minute and try again.'])
            ->onlyInput('email');

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(30)->by($request->ip())->response($lockedOut),
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip())->response($lockedOut),
        ]);

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
