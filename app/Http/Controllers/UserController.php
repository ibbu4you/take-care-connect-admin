<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'role' => (string) $request->query('role', ''),
            'status' => (string) $request->query('status', ''),
        ];

        $users = User::query()
            ->with('roles:id,name')
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                // Grouped so the OR-chain cannot escape and widen the role and
                // status filters applied alongside it.
                $query->where(function ($scoped) use ($filters) {
                    $term = "%{$filters['search']}%";

                    $scoped->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('job_title', 'like', $term);
                });
            })
            ->when($filters['role'] !== '', fn ($query) => $query->role($filters['role']))
            ->when($filters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => $this->row($user, $request->user()));

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => Roles::names(),
            'counts' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'inactive' => User::where('is_active', false)->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Users/Form', [
            'user' => null,
            ...$this->formOptions($request),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                ...$request->safe()->only(['name', 'email', 'phone', 'job_title', 'team']),
                'password' => Hash::make($request->string('password')->toString()),
                'is_active' => $request->boolean('is_active', true),
                'denied_permissions' => $this->cleanDenials($request),
                'email_verified_at' => now(),
            ]);

            $user->syncRoles($request->input('roles', []));
            $user->syncPermissions($this->allowedDirectPermissions($request));

            return $user;
        });

        // spatie caches the permission table; without this the new account's
        // grants are invisible until the cache happens to expire.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()
            ->route('users.index')
            ->with('success', "{$user->name} can now sign in.");
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize('update', $user);

        return Inertia::render('Users/Form', [
            'user' => [
                ...$this->row($user, $request->user()),
                'phone' => $user->phone,
                'team' => $user->team,
                'direct_permissions' => $user->directPermissionNames(),
                'denied_permissions' => $user->denied_permissions ?? [],
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            ],
            ...$this->formOptions($request),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        DB::transaction(function () use ($request, $user) {
            $user->fill($request->safe()->only(['name', 'email', 'phone', 'job_title', 'team']));
            $user->is_active = $request->boolean('is_active');
            $user->denied_permissions = $this->cleanDenials($request);

            // An empty password box means "leave it alone" — the common case
            // when somebody is only correcting a job title.
            if ($request->filled('password')) {
                $user->password = Hash::make($request->string('password')->toString());
            }

            $user->save();

            $user->syncRoles($request->input('roles', []));
            $user->syncPermissions($this->allowedDirectPermissions($request));
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()
            ->route('users.index')
            ->with('success', "Saved {$user->name}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        // Soft delete: interviews, articles and deals all point at this row, and
        // a hard delete would either cascade or orphan them. The account can no
        // longer sign in, and the history keeps its author.
        $user->delete();

        return redirect()
            ->route('users.index')
            ->with('success', "{$user->name} has been removed.");
    }

    /**
     * Flip `is_active` from the list, without opening the whole form.
     *
     * The single most common administrative action — somebody joins, somebody
     * leaves — so it is one click from the list rather than four.
     */
    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with(
            'success',
            $user->is_active
                ? "{$user->name} can sign in again."
                : "{$user->name} has been deactivated and will be signed out.",
        );
    }

    /**
     * Clear somebody's two-factor enrolment so they can enrol a new device.
     *
     * The everyday case is a new phone, and without this the only way through
     * it is a database edit. There is a console equivalent
     * (`php artisan tcc:two-factor-reset`) for the case this cannot cover: the
     * only administrator, locked out, with nobody left who can click this.
     */
    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        $this->authorize('resetTwoFactor', $user);

        if (! $user->hasTwoFactorEnabled()) {
            return back()->with('error', "{$user->name} has no two-factor enrolment to clear.");
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        /*
         | End their sessions.
         |
         | A session that already passed the two-factor gate keeps that flag
         | for its lifetime. If this reset is happening because a phone was
         | stolen rather than replaced, leaving those alive defeats it.
         */
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }

        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->log('two-factor reset');

        return back()->with(
            'success',
            "Two-factor is off for {$user->name}. They can set it up again from their profile whenever they like.",
        );
    }

    /**
     * Options every user form needs: the roles, the permission catalogue, and
     * what each role grants.
     *
     * The role→permissions map goes to the browser so the matrix can recompute
     * live as roles are ticked — otherwise choosing a role would need a round
     * trip before the grid could show what it granted.
     *
     * @return array<string, mixed>
     */
    private function formOptions(Request $request): array
    {
        $rolePermissions = Role::with('permissions:id,name')
            ->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->all()])
            ->all();

        return [
            'catalog' => Permissions::catalog(),
            'roles' => collect(Roles::names())
                ->map(fn (string $name) => [
                    'name' => $name,
                    'description' => Roles::descriptions()[$name] ?? '',
                    'assignable' => $request->user()->can('assignRole', [User::class, $name]),
                ])
                ->all(),
            'rolePermissions' => $rolePermissions,
            'canManagePermissions' => $request->user()->can('users.permissions.manage'),
        ];
    }

    /**
     * A revocation only means something for a permission a role actually grants.
     *
     * Storing a denial for something the user was never given would sit in the
     * column forever, quietly waiting to block a permission granted years later
     * for an unrelated reason.
     *
     * @return list<string>
     */
    private function cleanDenials(Request $request): array
    {
        if (! $request->user()->can('users.permissions.manage')) {
            return [];
        }

        $granted = collect($request->input('roles', []))
            ->flatMap(fn (string $role) => Roles::definitions()[$role] ?? [])
            ->unique();

        return collect($request->input('denied_permissions', []))
            ->filter(fn (string $permission) => $granted->contains($permission))
            ->values()
            ->all();
    }

    /**
     * Direct grants, ignored entirely unless the actor may manage permissions.
     *
     * Without this check, `users.update` alone would be enough to grant anybody
     * anything — including to oneself.
     *
     * @return list<string>
     */
    private function allowedDirectPermissions(Request $request): array
    {
        if (! $request->user()->can('users.permissions.manage')) {
            return [];
        }

        // A permission a role already grants does not need a direct grant too.
        // Storing both makes the matrix ambiguous about where it came from.
        $fromRoles = collect($request->input('roles', []))
            ->flatMap(fn (string $role) => Roles::definitions()[$role] ?? [])
            ->unique();

        return collect($request->input('direct_permissions', []))
            ->reject(fn (string $permission) => $fromRoles->contains($permission))
            ->values()
            ->all();
    }

    /**
     * One row, with what the viewer may actually do to it.
     *
     * ── Why the abilities are computed here ─────────────────────────────────
     * UserPolicy has rules the frontend cannot reasonably re-derive: an Admin
     * the last administrator is protected, nobody may delete or deactivate themselves.
     * Re-implementing those in the component would mean two copies of the same
     * rules drifting apart — and the visible symptom is a button that 403s when
     * clicked, which reads as a broken app rather than as a refused action.
     *
     * So the policy is asked once per row and the answer is sent with the data.
     *
     * @return array<string, mixed>
     */
    private function row(User $user, ?User $actor = null): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'job_title' => $user->job_title,
            'initials' => $user->initials(),
            'avatar_url' => $user->avatarUrl(),
            'is_active' => $user->is_active,
            'roles' => $user->roles->pluck('name')->all(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'two_factor' => $user->hasTwoFactorEnabled(),

            'can' => [
                'edit' => (bool) $actor?->can('update', $user),
                'delete' => (bool) $actor?->can('delete', $user),
                'toggle' => (bool) $actor?->can('deactivate', $user),
                // Offered only where it would do something, so the menu does
                // not carry an item that answers "they have none to clear".
                'reset_two_factor' => $user->hasTwoFactorEnabled()
                    && (bool) $actor?->can('resetTwoFactor', $user),
            ],
        ];
    }
}
