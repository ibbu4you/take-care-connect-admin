<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `clients.import` granted nothing.
 *
 * Same problem as 2026_08_29_140000: the Roles page offered "Import clients
 * from a spreadsheet" and there was no route, controller or screen behind it.
 *
 * `leads.import` had the same gap and has now been built — a lead list is
 * exactly what a telecaller is handed as a spreadsheet. Clients are not: a
 * client is a lead that said yes, created by converting one, so importing them
 * directly would skip the record of how they were found. Removed rather than
 * built.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('permissions')->where('name', 'clients.import')->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Pivots first: an orphaned row would keep granting a permission that
        // no longer exists.
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately empty: recreating a permission nothing checks restores
        // the problem, not a capability.
    }
};
