<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The access-control rules the whole application leans on.
 *
 * These are the tests worth having: every route, every list query and every
 * action button asks one of these questions, so a regression here is a
 * regression everywhere at once.
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalogue_and_the_seeded_table_agree(): void
    {
        $this->seedRoles();

        $this->assertSame(
            count(Permissions::all()),
            Permission::count(),
            'Every catalogue entry should exist exactly once in the permissions table.',
        );

        $this->assertSame(
            count(Permissions::all()),
            count(array_unique(Permissions::all())),
            'The catalogue must not contain a duplicate name.',
        );
    }

    public function test_all_six_roles_are_seeded(): void
    {
        $this->seedRoles();

        foreach (Roles::names() as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role]);
        }
    }

    /**
     * Every string a role grants must be a real permission. Without this, a
     * typo in Roles.php silently grants nothing and the role looks fine.
     */
    public function test_no_role_grants_a_permission_that_does_not_exist(): void
    {
        $catalogue = Permissions::all();

        foreach (Roles::definitions() as $role => $granted) {
            foreach ($granted as $permission) {
                $this->assertContains(
                    $permission,
                    $catalogue,
                    "Role [{$role}] grants [{$permission}], which is not in the catalogue.",
                );
            }
        }
    }

    /**
     * Admin is the top role, and holds everything by grant rather than by
     * bypass. The distinction matters: an unknown ability is refused, where the
     * old Gate::before would have allowed it.
     */
    public function test_an_admin_holds_every_permission_in_the_catalogue(): void
    {
        $user = $this->userWithRole(Roles::ADMIN);

        $this->assertTrue($user->can('deals.close'));
        $this->assertTrue($user->can('settings.update'));
        $this->assertFalse(
            $user->can('a.permission.that.does.not.exist'),
            'Nothing bypasses the check any more, so an unknown ability is refused.',
        );
    }

    public function test_a_revoked_permission_is_denied_even_though_the_role_grants_it(): void
    {
        $user = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->assertTrue($user->can('deals.close'), 'Precondition: the role grants this.');

        $user->update(['denied_permissions' => ['deals.close']]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = $user->fresh();

        $this->assertFalse($user->can('deals.close'));
        $this->assertTrue($user->can('deals.move'), 'Revoking one permission must not affect the others.');
    }

    public function test_a_revocation_is_reflected_in_the_list_shared_with_the_frontend(): void
    {
        $user = $this->userWithRole(Roles::RELATIONSHIP_MANAGER, [
            'denied_permissions' => ['deals.close'],
        ]);

        $this->assertNotContains('deals.close', $user->effectivePermissions());
        $this->assertContains('deals.move', $user->effectivePermissions());
    }

    /**
     * Revocations apply to everybody, admins included.
     *
     * The Super Admin used to be exempt, because it bypassed the Gate. With
     * that role gone, a revocation means what the matrix says it means for
     * every account — and the one thing that must not happen, stripping the
     * *last* admin of its administration, is guarded in UserPolicy and
     * UpdateUserRequest, which can see how many are left.
     */
    public function test_a_revocation_applies_to_an_admin_too(): void
    {
        $user = $this->userWithRole(Roles::ADMIN, [
            'denied_permissions' => ['settings.update'],
        ]);

        $this->assertFalse($user->can('settings.update'));
        $this->assertTrue($user->can('users.update'), 'Revoking one must not affect the others.');
    }

    public function test_a_directly_granted_permission_is_added_to_the_role_grants(): void
    {
        $user = $this->userWithRole(Roles::ANCHOR);

        $this->assertFalse($user->can('deals.view.all'));

        $user->givePermissionTo('deals.view.all');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = $user->fresh();

        $this->assertTrue($user->can('deals.view.all'));
        $this->assertContains('deals.view.all', $user->effectivePermissions());
    }

    /**
     * The scoping contract. Roles other than Admin see their own work, so a
     * `.all` string appearing on one by accident silently widens every list in
     * that module.
     */
    public function test_only_the_expected_roles_hold_cross_team_scope(): void
    {
        $this->seedRoles();

        $expected = [
            Roles::ANCHOR => [],
            Roles::CREATIVE => [],
            Roles::RELATIONSHIP_MANAGER => [
                // The one deliberate exception. An RM reports back to a client
                // on everything published for them, and a social link is a
                // public URL — so this widens reporting without widening access
                // to anybody's private work.
                'social.links.view.all',
            ],
            Roles::CONTENT => [
                'articles.view.all',
                'interviews.view.all',
                'deliverables.view.all',
                'social.links.view.all',
                'clients.view.all',
                'projects.view.all',
                'reports.view.all',
            ],
        ];

        foreach ($expected as $role => $allowed) {
            $held = array_values(array_filter(
                Roles::definitions()[$role],
                static fn (string $permission): bool => str_contains($permission, '.all'),
            ));

            sort($held);
            sort($allowed);

            $this->assertSame($allowed, $held, "Role [{$role}] holds unexpected cross-team scope.");
        }
    }

    public function test_only_an_admin_can_manage_people(): void
    {
        $this->seedRoles();

        foreach ([Roles::ANCHOR, Roles::CONTENT, Roles::CREATIVE, Roles::RELATIONSHIP_MANAGER] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->assertFalse($user->fresh()->can('users.create'), "[{$role}] must not create accounts.");
            $this->assertFalse($user->fresh()->can('roles.update'), "[{$role}] must not edit roles.");
            $this->assertFalse($user->fresh()->can('settings.update'), "[{$role}] must not change settings.");
        }
    }

    public function test_seeding_twice_does_not_duplicate_or_lose_anything(): void
    {
        $this->seedRoles();

        $user = User::factory()->create();
        $user->assignRole(Roles::ANCHOR);

        $before = Permission::count();

        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame($before, Permission::count());
        $this->assertTrue($user->fresh()->hasRole(Roles::ANCHOR), 'Re-seeding must not unassign anybody.');
    }
}
