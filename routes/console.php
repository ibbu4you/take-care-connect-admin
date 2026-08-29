<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Everything here is driven by a single cron entry on the server:
|
|     * * * * * cd /home/USER/admin.takecareconnect.com && php artisan schedule:run >> /dev/null 2>&1
|
| ── Why the queue is a scheduled task rather than a daemon ────────────────
| Hostinger shared hosting has no supervisor and no long-lived processes, so
| there is nowhere to run `queue:work` as a service. Instead the scheduler
| starts a worker every minute that drains whatever is waiting and exits.
|
| The tradeoff is up to sixty seconds of latency on a queued job. Nothing in
| this application is user-facing enough to notice: notifications write
| straight to the database on the request that caused them, and the queue
| carries only thumbnails and exports.
|
*/

/*
 | --stop-when-empty exits as soon as the queue is drained, so the process does
 | not outlive its usefulness. --max-time is the backstop for a job that hangs:
 | without it a stuck worker would still be holding the lock an hour later and
 | nothing else would ever run.
 */
Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=55')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

/*
 | The morning digest, before the working day rather than during it.
 |
 | 08:00 India time — early enough to be read with the first coffee, late
 | enough that it is not sitting unread from the night before. Stated
 | explicitly rather than relying on the server clock, which on shared hosting
 | is usually UTC and occasionally something else entirely.
 */
Schedule::command('tcc:reminders')
    ->dailyAt('08:00')
    ->timezone('Asia/Kolkata')
    ->weekdays()
    ->withoutOverlapping();

/*
 | Housekeeping.
 |
 | Read notifications older than two months are cleared; unread ones are never
 | touched, however old, because nobody has looked at them yet. The activity
 | log is deliberately not pruned — it is the audit trail, and an audit trail
 | with a retention policy nobody asked for is not much of one.
 */
Schedule::call(function (): void {
    DB::table('notifications')
        ->whereNotNull('read_at')
        ->where('read_at', '<', now()->subDays(60))
        ->delete();
})->weeklyOn(7, '03:00')->name('prune-read-notifications');

/*
 | Sessions expire in the database and nothing removes the rows, so a year of
 | daily sign-ins leaves a table of dead sessions. Laravel ships the command;
 | it just has to be called.
 */
Schedule::command('session:prune')
    ->daily()
    ->when(fn (): bool => config('session.driver') === 'database');
