<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Four permissions that granted nothing.
 *
 * ── What was wrong ─────────────────────────────────────────────────────────
 * The catalogue advertised six exports. Two of them work — `leads.export` and
 * `clients.export` both have a route, a controller method and a button. The
 * other four had none of those things: no route, no controller, no UI, and in
 * three cases not a single reference anywhere in the codebase.
 *
 * They still appeared on the Roles page as tickable capabilities with helpful
 * descriptions — "Export the pipeline", "Download a report". An administrator
 * could grant "Export the pipeline" to the relationship managers, watch it
 * save, and have given them nothing.
 *
 * That is worse than the feature being absent. An absent feature is discovered
 * the moment somebody looks for the button; a permission that grants nothing
 * makes the access-control screen — the one place you go to answer "who can
 * take the client list out of this system?" — quietly wrong.
 *
 * Removed rather than implemented, because nothing here is being taken away:
 * there is no behaviour behind any of them. If the exports are wanted they can
 * be built, and the permissions come back with something behind them.
 */
return new class extends Migration
{
    private const REMOVED = [
        'deals.export',
        'interviews.export',
        'articles.export',
        'reports.export',
    ];

    public function up(): void
    {
        $ids = DB::table('permissions')->whereIn('name', self::REMOVED)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // The pivots first — spatie has no foreign keys here on every driver,
        // so an orphaned row would survive and keep granting a permission that
        // no longer exists.
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately empty.
        //
        // Recreating a permission that nothing checks would restore the
        // problem, not the capability. Rolling this back gives you four rows
        // that do nothing, which is what they were.
    }
};
