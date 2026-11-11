<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Reset somebody's password from the server.
 *
 * The way back in when an administrator has lost their password and there is
 * no second administrator to reset it in the UI — which, on a small team, is
 * the normal case rather than the exceptional one.
 */
class PasswordCommand extends Command
{
    protected $signature = 'tcc:password {email} {--generate : Generate one instead of asking}';

    protected $description = 'Reset a password from the command line';

    public function handle(): int
    {
        $user = User::where('email', strtolower(trim($this->argument('email'))))->first();

        if ($user === null) {
            $this->components->error('No account with that email address.');

            return self::FAILURE;
        }

        if ($this->option('generate')) {
            $password = collect(range(1, 3))
                ->map(fn (): string => Str::upper(Str::random(4)))
                ->implode('-');
        } else {
            $password = $this->secret('New password (hidden)');

            if ($password !== $this->secret('Confirm password')) {
                $this->components->error('The two entries do not match.');

                return self::FAILURE;
            }

            $validator = Validator::make(
                ['password' => $password],
                ['password' => ['required', Password::min(10)->letters()->numbers()]],
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $this->components->error($message);
                }

                return self::FAILURE;
            }
        }

        // A fresh remember token too: "remember me" cookies otherwise keep
        // signing the old holder in after the password has changed.
        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
        ])->save();

        activity()
            ->performedOn($user)
            ->withProperties(['via' => 'console'])
            ->log('password reset');

        /*
         | Log the reset out of every session.
         |
         | A password reset usually follows "somebody may have my password", and
         | leaving their live sessions signed in defeats the point of resetting
         | it. Sessions live in the database on this host, so this is one delete.
         */
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }

        $this->components->info("Password reset for {$user->email}.");

        if ($this->option('generate')) {
            $this->line('  <options=bold;fg=yellow>'.$password.'</>');
            $this->line('  <fg=gray>Shown once.</>');
        }

        $this->line('  <fg=gray>Any signed-in sessions for this account have been ended.</>');

        return self::SUCCESS;
    }
}
