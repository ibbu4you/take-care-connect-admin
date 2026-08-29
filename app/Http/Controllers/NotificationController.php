<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bell, and the page behind it.
 *
 * Read state is per-person and per-notification — Laravel's `read_at` — so
 * marking one read never touches anybody else's copy of the same event.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = $request->user()
            ->notifications()
            ->paginate(30)
            ->through(fn ($notification) => $this->row($notification));

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $id): RedirectResponse
    {
        // Scoped to the user's own notifications: the id is a UUID, but that is
        // obscurity rather than authorisation.
        $request->user()->notifications()->whereKey($id)->update(['read_at' => now()]);

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All caught up.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->whereKey($id)->delete();

        return back();
    }

    public function clear(Request $request): RedirectResponse
    {
        // Only the read ones: clearing something never looked at would lose the
        // very thing the bell exists to surface.
        $request->user()->readNotifications()->delete();

        return back()->with('success', 'Cleared.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(mixed $notification): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'kind' => $data['kind'] ?? 'info',
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
            'actor' => $data['actor'] ?? null,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
