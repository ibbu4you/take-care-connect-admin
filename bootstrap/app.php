<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // Signs a session out when its account's password changes
            // elsewhere. Without it, "other devices have been signed out"
            // after a password change was not true.
            AuthenticateSession::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            // Applied alongside `auth` on every authenticated route.
            'active' => EnsureUserIsActive::class,

            // Sits between the password and everything else. Deliberately not
            // applied to the two-factor routes themselves, or the redirect it
            // issues would loop.
            '2fa' => RequireTwoFactor::class,

            // spatie/laravel-permission. Registered by hand because the package
            // stopped auto-aliasing in v6 and every route in this app leans on
            // `permission:`.
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // The login form is the only place a token can expire in normal use —
        // leaving a tab open overnight. Excluding it turns a dead-end 419 into
        // an ordinary "those credentials did not match".
        $middleware->validateCsrfTokens(except: []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         | Render 403 and 404 as real pages inside the admin shell rather than
         | as Laravel's bare error templates, so somebody who follows a stale
         | link still has the sidebar and a way back.
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            // A CSRF token that died in a background tab: bounce back to the
            // form, input kept, with a message — instead of the 419 page and a
            // lost form. Checked first: the error-page branch below would
            // otherwise swallow it, which is why this never used to run.
            if ($response->getStatusCode() === 419) {
                return back()
                    ->withInput($request->except(['password', 'password_confirmation', 'current_password', '_token']))
                    ->with('error', 'That page expired. Please try again.');
            }

            if (! app()->environment('local')
                && in_array($response->getStatusCode(), [403, 404, 500, 503], true)
                && $request->header('X-Inertia')) {
                return Inertia::render('Errors/Status', [
                    'status' => $response->getStatusCode(),
                ])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->create();
