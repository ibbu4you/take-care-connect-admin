<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * A worked example of the whole SOP, for local work and for demos.
 *
 * Six staff accounts, six interviews across every stage, an article mid-flow,
 * creative work in every column and a pipeline with a target to measure it
 * against — enough that every screen has something honest on it.
 *
 * Refuses to run in production. These are fictional businesses with invented
 * phone numbers, and the one thing worse than an empty system is a real one
 * full of records nobody can tell from real ones.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoSeeder will not run in production.');
            $this->command?->line('Create the first real account with: php artisan tcc:setup');

            return;
        }

        $this->call([
            RolePermissionSeeder::class,
            CategorySeeder::class,
            UserSeeder::class,
            DemoInterviewSeeder::class,
            DemoDeliverableSeeder::class,
            DemoLeadSeeder::class,
            DemoSalesSeeder::class,
            // After sales: projects attach to the clients and deals it creates.
            DemoProjectSeeder::class,
        ]);

        $this->command?->newLine();
        $this->command?->warn('Demo accounts all use the password "password".');
    }
}
