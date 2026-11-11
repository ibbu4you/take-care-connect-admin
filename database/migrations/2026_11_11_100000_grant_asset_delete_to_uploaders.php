<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Removing an attachment now asks for `assets.delete`.
 *
 * It used to ask for `assets.upload`, so revoking `assets.delete` from
 * somebody changed nothing. Checking the right permission would have taken the
 * ability away from every anchor, writer and creative who had it until now, so
 * the roles that can upload are given it too. Only additions — a role somebody
 * already edited keeps everything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('assets.delete', 'web');

        Role::whereIn('name', [Roles::ANCHOR, Roles::CONTENT, Roles::CREATIVE])
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->hasPermissionTo('assets.upload') && $role->givePermissionTo('assets.delete'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Left in place: taking it away would stop people tidying their own uploads.
    }
};
