<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a user holding a role, with the role catalogue already seeded.
     *
     * spatie caches the permission table per request. In a test the whole run
     * shares one process, so without forgetting the cache a user created after
     * the first assertion inherits the previous test's view of the table and
     * every permission check silently answers false.
     */
    protected function userWithRole(string $role, array $attributes = []): User
    {
        $this->seedRoles();

        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user->fresh();
    }

    protected function seedRoles(): void
    {
        if (Role::query()->exists()) {
            return;
        }

        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
