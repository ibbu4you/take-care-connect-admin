<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props every page receives.
     *
     * ── Why the whole permission list is shared ─────────────────────────────
     * The sidebar, every action button and every disabled state ask the same
     * question the backend asks, so the frontend needs the same answer. It is
     * the *effective* list — role grants plus direct grants minus revocations —
     * computed by the same method the Gate consults, so the menu can never
     * offer a page that then answers 403.
     *
     * It costs a couple of kilobytes per response. A bitmask would be smaller
     * and would cost far more the first time somebody had to debug it.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'job_title' => $user->job_title,
                    'avatar_url' => $user->avatarUrl(),
                    'initials' => $user->initials(),
                    'roles' => $user->getRoleNames()->all(),
                ] : null,

                'permissions' => $user ? $user->effectivePermissions() : [],
            ],

            /*
             | The bell's unread count.
             |
             | A closure, so it is only counted when Inertia actually builds the
             | response — a partial reload that does not ask for `notifications`
             | should not run the query. Shared rather than fetched by the bell
             | itself, because polling for a number the server already knows on
             | every page load is a request for nothing.
             */
            'notifications' => [
                'unread' => fn () => $user ? $user->unreadNotifications()->count() : 0,
            ],

            'app' => [
                'name' => config('app.name'),
            ],

            /*
             | The CSRF token, sent back on every visit as X-CSRF-TOKEN.
             |
             | Rather than leaving it to axios to read the XSRF-TOKEN cookie: a
             | browser holding a stale duplicate of that cookie (left by an
             | earlier configuration) sends the wrong one, and every form then
             | fails with "page expired". The token in the page is always the
             | one this session issued.
             */
            'csrf_token' => fn () => csrf_token(),

            /*
             | One-shot messages, read once by the toast layer.
             |
             | Closures so they are only evaluated when Inertia actually builds
             | the response — a partial reload that does not ask for `flash`
             | should not consume the session's flash bag.
             */
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
            ],
        ];
    }
}
