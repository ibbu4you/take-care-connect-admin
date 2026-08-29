<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Fold the Super Admin role into Admin.
 *
 * ── Why the role went ──────────────────────────────────────────────────────
 * It granted exactly the same 74 permissions as Admin. The only differences
 * were invisible on the Roles page: it bypassed the Gate entirely, and other
 * admins could not edit it. Two roles that look identical and behave
 * differently is a worse answer than one role.
 *
 * The recovery job it really did is now the console's — `tcc:password` and
 * `tcc:two-factor-reset` work over SSH even when nobody can sign in, which was
 * never true of a role you had to log in to use. What survives is the guard
 * that actually prevents a lockout: the last Admin cannot be demoted,
 * deactivated or deleted.
 *
 * ── Order matters here ─────────────────────────────────────────────────────
 * Everybody holding the old role is given the new one *before* the old row is
 * deleted. The other way round and the assignments cascade away with it,
 * leaving the owner of the system with no roles at all and no way back in.
 */
return new class extends Migration
{
    private const OLD_ROLE = 'Super Admin';

    private const NEW_ROLE = 'Admin';

    public function up(): void
    {
        $old = DB::table('roles')->where('name', self::OLD_ROLE)->first();

        if ($old === null) {
            return;
        }

        $new = DB::table('roles')->where('name', self::NEW_ROLE)->first();

        if ($new === null) {
            // Nothing to fold into. Renaming keeps every assignment intact,
            // which is the safe outcome on an install that somehow has one
            // role and not the other.
            DB::table('roles')->where('id', $old->id)->update(['name' => self::NEW_ROLE]);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        $holders = DB::table('model_has_roles')
            ->where('role_id', $old->id)
            ->where('model_type', 'App\\Models\\User')
            ->pluck('model_id');

        foreach ($holders as $userId) {
            $alreadyAdmin = DB::table('model_has_roles')
                ->where('role_id', $new->id)
                ->where('model_id', $userId)
                ->where('model_type', 'App\\Models\\User')
                ->exists();

            if (! $alreadyAdmin) {
                DB::table('model_has_roles')->insert([
                    'role_id' => $new->id,
                    'model_id' => $userId,
                    'model_type' => 'App\\Models\\User',
                ]);
            }
        }

        // Only now that everybody has been carried across.
        DB::table('model_has_roles')->where('role_id', $old->id)->delete();
        DB::table('role_has_permissions')->where('role_id', $old->id)->delete();
        DB::table('roles')->where('id', $old->id)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Deliberately not reversible.
     *
     * Recreating the role is easy; working out which of today's admins used to
     * be Super Admins is not — that information is gone the moment the rows
     * above are deleted. A `down()` that recreated an empty role and claimed
     * the migration had been undone would be worse than one that says so.
     */
    public function down(): void
    {
        // No going back.
    }
};
