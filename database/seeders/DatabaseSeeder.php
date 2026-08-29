<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What a real installation needs, and nothing else.
 *
 * ── Why the demo data is not here ──────────────────────────────────────────
 * `php artisan migrate --seed` is what somebody runs on a fresh production
 * database. If that produced eleven fictional clients and seven imaginary
 * deals, the first thing the team would do on their new system is delete
 * things — and the first real month of figures would be wrong.
 *
 * So this seeds only the two things every installation genuinely needs: the
 * permission catalogue and the category list. Demo data lives in
 * {@see DemoSeeder}, which has to be asked for by name and refuses to run in
 * production at all.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            CategorySeeder::class,
        ]);

        $this->command?->newLine();
        $this->command?->info('Roles and categories seeded.');
        $this->command?->line('  Create the first account with:  php artisan tcc:setup');
        $this->command?->line('  Load demo data (local only) with: php artisan db:seed --class=DemoSeeder');
    }
}
