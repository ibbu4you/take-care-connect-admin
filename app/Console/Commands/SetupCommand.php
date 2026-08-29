<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * The first account.
 *
 * ── Why this exists rather than a seeder ───────────────────────────────────
 * A seeded administrator has a password written in a file that is in the
 * repository, on the deployer's laptop and in the shell history of whoever ran
 * it. That is fine for a demo and unacceptable for the account that can read
 * every client record in the business.
 *
 * So the first real account is made here: the password is typed (hidden) or
 * generated, shown once, and never written anywhere but the hash column.
 */
class SetupCommand extends Command
{
    protected $signature = 'tcc:setup
                            {--name= : Full name}
                            {--email= : Sign-in address}
                            {--generate : Generate a password instead of asking for one}';

    protected $description = 'Create the first administrator account';

    public function handle(): int
    {
        $this->components->info('Take Care Connect Admin — first account');

        if (! $this->rolesExist()) {
            $this->components->error('Roles are not seeded yet.');
            $this->line('  Run <options=bold>php artisan migrate --seed</> first.');

            return self::FAILURE;
        }

        /*
         | Refuse to make a second one silently.
         |
         | Somebody re-running the deploy script should not quietly acquire an
         | extra all-powerful account — most of the time they have forgotten
         | they already have one, and the right answer is a password reset.
         */
        $existing = User::role(Roles::ADMIN)->get();

        if ($existing->isNotEmpty()) {
            $this->components->warn('An administrator already exists:');

            foreach ($existing as $person) {
                $this->line("  · {$person->name} <{$person->email}>");
            }

            if (! $this->confirm('Create another one anyway?', false)) {
                $this->line('  Nothing changed. To reset a password: php artisan tcc:password '.$existing->first()->email);

                return self::SUCCESS;
            }
        }

        $name = $this->option('name') ?: $this->ask('Full name');
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email address'))));

        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        [$password, $generated] = $this->password();

        if ($password === null) {
            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
            'job_title' => 'Administrator',
            'email_verified_at' => now(),
        ]);

        $user->assignRole(Roles::ADMIN);

        $this->newLine();
        $this->components->info('Account created.');
        $this->line("  Name   <options=bold>{$user->name}</>");
        $this->line("  Email  <options=bold>{$user->email}</>");

        if ($generated) {
            $this->newLine();
            $this->line('  Password  <options=bold;fg=yellow>'.$password.'</>');
            $this->line('  <fg=gray>Shown once. Copy it now — it is not recoverable.</>');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: string|null, 1: bool}
     */
    private function password(): array
    {
        if ($this->option('generate')) {
            // Readable groups: this gets typed once by hand on a phone before
            // it gets changed, and ambiguous glyphs make that miserable.
            return [
                collect(range(1, 3))
                    ->map(fn (): string => Str::upper(Str::random(4)))
                    ->implode('-'),
                true,
            ];
        }

        $password = $this->secret('Password (hidden)');
        $confirm = $this->secret('Confirm password');

        if ($password !== $confirm) {
            $this->components->error('The two entries do not match.');

            return [null, false];
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', Password::min(10)->letters()->numbers()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return [null, false];
        }

        return [$password, false];
    }

    private function rolesExist(): bool
    {
        return \Spatie\Permission\Models\Role::where('name', Roles::ADMIN)->exists();
    }
}
