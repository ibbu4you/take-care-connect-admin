<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clear somebody's two-factor enrolment from the server.
 *
 * ── Why this has to exist ──────────────────────────────────────────────────
 * Two-factor is optional, but once somebody has enrolled, the enrolment lives
 * on one phone. The day that phone is lost, upgraded or wiped, that account
 * cannot sign in — and on a small team that person is often the only one who
 * could have reset it from inside the application.
 *
 * Without this command the answer to a broken phone is a database edit by
 * hand. With it, the answer is one line over SSH.
 *
 * There is a matching action in Users → the person, for the ordinary case
 * where somebody else can still sign in. This is the last resort.
 */
class TwoFactorResetCommand extends Command
{
    protected $signature = 'tcc:two-factor-reset {email}';

    protected $description = 'Clear a two-factor enrolment so the account can enrol a new device';

    public function handle(): int
    {
        $user = User::where('email', strtolower(trim($this->argument('email'))))->first();

        if ($user === null) {
            $this->components->error('No account with that email address.');

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled()) {
            $this->components->warn("{$user->email} has no two-factor enrolment to clear.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Clear two-factor for {$user->name} <{$user->email}>?", false)) {
            $this->line('  Nothing changed.');

            return self::SUCCESS;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        /*
         | End their sessions too.
         |
         | A session that already cleared the two-factor gate keeps its
         | `two_factor_passed` flag. If this reset is happening because a phone
         | was stolen rather than dropped, leaving those alive defeats the
         | point of the reset.
         */
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }

        activity()
            ->performedOn($user)
            ->withProperties(['via' => 'console'])
            ->log('two-factor reset');

        $this->components->info("Two-factor cleared for {$user->email}.");

        $this->line('  <fg=gray>They can enrol a new device from Profile whenever they like.</>');

        return self::SUCCESS;
    }
}
