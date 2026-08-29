<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Support\Collection;

/**
 * Telling people that something happened to work they care about.
 *
 * ── The two rules this enforces everywhere ─────────────────────────────────
 *
 * 1. **Never notify the person who did it.** They were just there. A bell that
 *    lights up for your own actions is a bell people stop looking at, which
 *    defeats the one job it has.
 *
 * 2. **Never notify the same person twice for one event.** Somebody who is both
 *    the writer and the reviewer on an article should get one notification, not
 *    two — so the recipient list is always deduped by id.
 *
 * Both are easy to get right once and impossible to remember at twenty call
 * sites, which is why they live here rather than in the controllers.
 */
class Notifier
{
    /**
     * Notify specific people.
     *
     * Nulls are filtered, so a caller can pass an optional owner without
     * checking first: `Notifier::send([$article->writer, $article->reviewer], …)`.
     *
     * @param  iterable<User|null>  $recipients
     */
    public static function send(
        iterable $recipients,
        string $kind,
        string $title,
        ?string $body = null,
        ?string $url = null,
        ?User $actor = null,
    ): int {
        $people = collect($recipients)
            ->filter(fn (?User $user): bool => $user instanceof User)
            ->unique(fn (User $user) => $user->getKey())
            ->reject(fn (User $user): bool => $actor !== null && $user->is($actor))
            // A deactivated account cannot sign in to read it.
            ->filter(fn (User $user): bool => (bool) $user->is_active);

        if ($people->isEmpty()) {
            return 0;
        }

        $notification = new WorkflowNotification($kind, $title, $body, $url, $actor?->name);

        $people->each(fn (User $user) => $user->notify($notification));

        return $people->count();
    }

    /**
     * Notify everybody who holds a permission.
     *
     * The way to reach "the content team" or "whoever can approve this" without
     * hard-coding a role — so granting somebody a permission also starts
     * including them in the conversation about it, which is what people expect
     * and what a role check would quietly get wrong.
     */
    public static function toPermission(
        string $permission,
        string $kind,
        string $title,
        ?string $body = null,
        ?string $url = null,
        ?User $actor = null,
    ): int {
        return self::send(self::holdersOf($permission), $kind, $title, $body, $url, $actor);
    }

    /**
     * Everybody who can currently do a thing.
     *
     * Resolved in PHP rather than SQL: the effective permission set is role
     * grants plus direct grants minus per-user revocations, and the revocation
     * half lives in a JSON column that no join can see. At this scale — a team,
     * not a userbase — checking each active account is cheaper than getting it
     * subtly wrong.
     *
     * @return Collection<int, User>
     */
    public static function holdersOf(string $permission): Collection
    {
        return User::query()
            ->active()
            ->get()
            ->filter(fn (User $user): bool => $user->can($permission))
            ->values();
    }
}
