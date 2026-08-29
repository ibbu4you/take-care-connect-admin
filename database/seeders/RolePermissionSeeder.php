<?php

namespace Database\Seeders;

use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission and re-syncs the six roles to their definitions.
 *
 * Re-runnable by design: `findOrCreate` leaves existing rows and their
 * assignments alone, and `syncPermissions` brings each role back to what
 * {@see Roles::definitions()} says it should grant. So adding a capability is
 * "edit Permissions.php, edit Roles.php, seed again" — no manual SQL, and no
 * risk of a half-applied change.
 *
 * Note that re-seeding DOES reset any runtime edit made on the Roles page. That
 * is the intended direction of authority: the code is the definition, the UI is
 * a convenience for temporary adjustments.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // spatie caches the permission table aggressively. Without this, rows
        // created a moment ago are invisible to the syncPermissions() calls
        // below and every role comes out empty.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (Roles::definitions() as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Seeded %d permissions across %d roles.',
            count(Permissions::all()),
            count(Roles::names()),
        ));
    }
}
