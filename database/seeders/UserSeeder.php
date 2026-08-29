<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One account per role, so every screen can be checked from the point of view
 * it was designed for.
 *
 * `updateOrCreate` on the email keeps re-seeding idempotent without resetting a
 * password somebody has since changed — the password is only written when the
 * row is first created.
 */
class UserSeeder extends Seeder
{
    /**
     * @var list<array{name: string, email: string, role: string, job_title: string}>
     */
    private const STAFF = [
        [
            'name' => 'Arjun Pillai',
            'email' => 'admin@takecareconnect.com',
            'role' => Roles::ADMIN,
            'job_title' => 'Operations manager',
        ],
        [
            'name' => 'Anjali Rao',
            'email' => 'anchor@takecareconnect.com',
            'role' => Roles::ANCHOR,
            'job_title' => 'Anchor',
        ],
        [
            'name' => 'Karthik Menon',
            'email' => 'content@takecareconnect.com',
            'role' => Roles::CONTENT,
            'job_title' => 'Content writer',
        ],
        [
            'name' => 'Priya Nair',
            'email' => 'creative@takecareconnect.com',
            'role' => Roles::CREATIVE,
            'job_title' => 'Creative designer',
        ],
        [
            'name' => 'Rahul Verma',
            'email' => 'rm@takecareconnect.com',
            'role' => Roles::RELATIONSHIP_MANAGER,
            'job_title' => 'Relationship manager',
        ],
    ];

    public function run(): void
    {
        foreach (self::STAFF as $staff) {
            $user = User::withTrashed()->firstWhere('email', $staff['email']);

            if ($user === null) {
                $user = User::create([
                    'name' => $staff['name'],
                    'email' => $staff['email'],
                    // Development credential. The deploy checklist changes it;
                    // see docs/deploy.md.
                    'password' => Hash::make('password'),
                    'job_title' => $staff['job_title'],
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);
            }

            $user->syncRoles([$staff['role']]);
        }

        $this->command?->info(sprintf('Seeded %d staff accounts (password: "password").', count(self::STAFF)));
    }
}
