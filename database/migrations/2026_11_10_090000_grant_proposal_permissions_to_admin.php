<?php

use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Give the Admin role the new `proposals.*` permissions.
 *
 * RolePermissionSeeder creates permissions on every deploy but deliberately
 * leaves existing roles alone (so a deploy never reverts an edit made on the
 * Roles page). That is right for every role but one: Admin is defined as
 * "everything", and an Admin who cannot see the module that just shipped is a
 * deploy that looks broken.
 *
 * Relationship Managers get `proposals.view` and `proposals.create` as well,
 * because they already own deals and a proposal is part of working one. Left to
 * the usual route — Reset on the Roles page — their deal pages would show a
 * Proposals section with no way to write one until somebody remembered. Only
 * additions: nothing an existing role holds is taken away.
 *
 * The two new sales roles are created with their proposals from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        $admin = Role::where('name', Roles::ADMIN)->where('guard_name', 'web')->first();

        if ($admin === null) {
            return; // A fresh install: the seeder creates Admin with everything.
        }

        $names = Permissions::forModule('proposals');

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $admin->givePermissionTo($names);

        Role::where('name', Roles::RELATIONSHIP_MANAGER)->where('guard_name', 'web')->first()
            ?->givePermissionTo(['proposals.view', 'proposals.create']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Left in place: removing them would take away a module, not undo a fix.
    }
};
