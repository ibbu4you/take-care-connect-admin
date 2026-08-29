<?php

namespace Database\Seeders;

use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission, and grants a role its defaults the first time it
 * appears.
 *
 * ── Why it does not re-sync a role that already exists ─────────────────────
 * It used to, and `scripts/deploy.sh` now seeds on every deploy — which
 * together meant every permission change made on the Roles page was silently
 * reverted the next time anybody deployed. Nothing would report it: the page
 * saved, the deploy succeeded, and the grant was simply gone.
 *
 * That also made `RoleController::reset()` pointless, and its existence is the
 * clue to the intended model. Putting a role back to its code definition is a
 * deliberate act with a button behind it, not a side effect of shipping.
 *
 * So: permissions are always created, because a permission missing from the
 * table makes the feature it guards invisible with no error. Roles are only
 * populated on creation. A capability added to an existing role in
 * {@see Roles::definitions()} reaches production when somebody presses Reset on
 * that role — which is visible, attributable, and reversible.
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

        $created = 0;

        foreach (Roles::definitions() as $role => $permissions) {
            $existing = Role::where('name', $role)->where('guard_name', 'web')->first();

            if ($existing !== null) {
                continue;   // Already configured — possibly deliberately. Leave it.
            }

            Role::create(['name' => $role, 'guard_name' => 'web'])->syncPermissions($permissions);
            $created++;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            '%d permissions available. %s',
            count(Permissions::all()),
            $created > 0
                ? "Granted defaults to {$created} new role(s)."
                : 'Existing roles left as they are — use Reset on the Roles page to restore defaults.',
        ));
    }
}
