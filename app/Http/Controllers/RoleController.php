<?php

namespace App\Http\Controllers;

use App\Policies\UserPolicy;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The role matrix: what each of the five roles grants.
 *
 * Roles are editable here, but {@see Roles::definitions()} in code stays the
 * source of truth — re-seeding resets a role to its definition. That is the
 * intended direction of authority: this screen is for a considered adjustment,
 * not for drifting away from the documented model. The page says so.
 */
class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        // Ordered in PHP, not SQL: the order that matters is the seniority in
        // Roles::names(), and expressing that in SQL means MySQL's FIELD(),
        // which does not exist in the SQLite the test suite runs on.
        $order = array_flip(Roles::names());

        $roles = Role::with('permissions:id,name')
            ->get()
            ->sortBy(fn (Role $role) => $order[$role->name] ?? PHP_INT_MAX)
            ->values()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => Roles::descriptions()[$role->name] ?? '',
                'permissions' => $role->permissions->pluck('name')->all(),
                'users_count' => $role->users()->count(),
                /*
                 | Every role is editable now.
                 |
                 | The one locked row was Super Admin, locked because it
                 | bypassed the Gate and so editing its grid changed nothing.
                 | With no bypass left, what a role grants is what a role
                 | grants — including Admin's.
                 */
                'locked' => false,
            ]);

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'catalog' => Permissions::catalog(),
            'canEdit' => $request->user()->can('roles.update'),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_unless($request->user()->can('roles.update'), 403);

        $validated = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ]);

        $permissions = $validated['permissions'] ?? [];

        /*
         | The Admin role keeps the keys to the building.
         |
         | Unticking "manage accounts" or "manage roles" on Admin takes it from
         | every administrator at once, and re-seeding does not put it back
         | (existing roles are left alone) — nobody could ever reopen this page.
         */
        if ($role->name === Roles::ADMIN) {
            $missing = array_diff(UserPolicy::ADMIN_ESSENTIALS, $permissions);

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'permissions' => 'The Admin role must keep account and role management ('.implode(', ', $missing).'), or nobody could give them back.',
                ]);
            }
        }

        $this->syncAndLog($request, $role, $permissions, 'role permissions changed');

        return back()->with('success', "Updated what the {$role->name} role grants.");
    }

    /** Put a role back to the definition in code. */
    public function reset(Request $request, Role $role): RedirectResponse
    {
        abort_unless($request->user()->can('roles.update'), 403);

        $definition = Roles::definitions()[$role->name] ?? null;

        abort_if($definition === null, 404);

        $this->syncAndLog($request, $role, $definition, 'role permissions reset');

        return back()->with('success', "Reset {$role->name} to its default permissions.");
    }

    /**
     * Change what a role grants, and say so in the audit log.
     *
     * A role change alters what everybody holding it can do, so "who gave Sales
     * access to every client, and when?" has to be answerable afterwards.
     *
     * @param  array<int, string>  $permissions
     */
    private function syncAndLog(Request $request, Role $role, array $permissions, string $event): void
    {
        $before = $role->permissions()->pluck('name')->sort()->values()->all();

        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $after = $role->permissions()->pluck('name')->sort()->values()->all();

        if ($before !== $after) {
            activity()
                ->performedOn($role)
                ->causedBy($request->user())
                ->withProperties([
                    'role' => $role->name,
                    'added' => array_values(array_diff($after, $before)),
                    'removed' => array_values(array_diff($before, $after)),
                ])
                ->log($event);
        }
    }
}
