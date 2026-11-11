<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Roles;
use Spatie\Permission\Models\Role;

/**
 * Who may administer accounts.
 *
 * The permission checks are the easy half. The rules that matter are the guards
 * against an Admin locking the organisation out of itself, and none of them can
 * live in the permission catalogue because they all depend on *which* account is
 * being acted on relative to *who* is acting.
 *
 * ── What replaced the Super Admin ──────────────────────────────────────────
 * There used to be a role above this one, and several of these rules were about
 * protecting it. It held exactly the same permissions as Admin, so it protected
 * a distinction nobody could see.
 *
 * What it was really protecting was the ability to get back in, and that is now
 * a single explicit rule: **the last Admin cannot be demoted, deactivated or
 * deleted**. Admins are otherwise peers — any of them can edit any other, which
 * is what a small team actually wants.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.view');
    }

    public function view(User $actor, User $subject): bool
    {
        return $actor->can('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.create');
    }

    public function update(User $actor, User $subject): bool
    {
        return $actor->can('users.update');
    }

    /**
     * Nobody deletes themselves, and nobody deletes the last Admin.
     *
     * Self-deletion is always a mistake: the account is soft-deleted, the
     * session dies on the next request, and the person who could undo it is the
     * one who just removed their own access.
     */
    public function delete(User $actor, User $subject): bool
    {
        if ($actor->is($subject)) {
            return false;
        }

        if (self::isLastAdmin($subject)) {
            return false;
        }

        return $actor->can('users.delete');
    }

    /**
     * Deactivating is separated from updating because it is the one edit that
     * takes somebody's access away mid-session, and it carries the same
     * lockout risk as deletion.
     */
    public function deactivate(User $actor, User $subject): bool
    {
        if ($actor->is($subject)) {
            return false;
        }

        if (self::isLastAdmin($subject)) {
            return false;
        }

        return $this->update($actor, $subject);
    }

    /**
     * Clearing somebody else's two-factor enrolment.
     *
     * This is the "I got a new phone" button, and it is also the one action
     * that removes a login factor from an account that is not yours.
     *
     * Self-reset is excluded because there is a better path for it: Profile,
     * which asks for the current password first. Here there is no password
     * prompt, so it must not be usable on oneself.
     */
    public function resetTwoFactor(User $actor, User $subject): bool
    {
        if ($actor->is($subject)) {
            return false;
        }

        return $this->update($actor, $subject);
    }

    /**
     * Changing what one person may do, over and above their roles.
     *
     * The last Admin is excluded because revocations now apply to everybody,
     * admins included — so without this, revoking `users.update` from the only
     * Admin would leave nobody able to grant it back.
     */
    public function managePermissions(User $actor, User $subject): bool
    {
        if (self::isLastAdmin($subject)) {
            return false;
        }

        return $actor->can('users.permissions.manage');
    }

    /**
     * Handing out a role.
     *
     * Nobody can give a role that does more than they can do themselves. With
     * `users.update` alone being enough, an account editor could make anybody
     * — themselves included — an Admin. The comparison is against what the
     * role grants *now* (the Roles page can change it), and against what the
     * actor holds after their own revocations.
     */
    public function assignRole(User $actor, string $role): bool
    {
        if (! $actor->can('users.update')) {
            return false;
        }

        $grants = Role::where('name', $role)->first()?->permissions->pluck('name') ?? collect();

        return $grants->every(fn (string $permission) => $actor->can($permission));
    }

    /**
     * The permissions without which nobody can administer the system.
     *
     * Losing any one of them from the last Admin — by demotion, revocation,
     * deactivation or by editing the Admin role itself — leaves nobody able to
     * give it back short of editing the database.
     */
    public const ADMIN_ESSENTIALS = [
        'users.view',
        'users.update',
        'users.permissions.manage',
        'roles.view',
        'roles.update',
    ];

    /**
     * The only active Admin left.
     *
     * Deliberately counts *active* admins: a deactivated one cannot sign in to
     * fix anything, so an organisation whose only other Admin is switched off
     * is one account away from nobody being able to administer it.
     */
    public static function isLastAdmin(User $subject): bool
    {
        if (! $subject->hasRole(Roles::ADMIN) || ! $subject->is_active) {
            return false;
        }

        return User::query()
            ->role(Roles::ADMIN)
            ->active()
            ->where('id', '!=', $subject->getKey())
            ->doesntExist();
    }
}
