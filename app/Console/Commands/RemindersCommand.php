<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Deal;
use App\Models\Deliverable;
use App\Models\FollowUp;
use App\Models\Interview;
use App\Models\Lead;
use App\Models\User;
use App\Support\Notifier;
use App\Support\Settings;
use App\Support\Workflow\DeliverableStage;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The morning digest.
 *
 * ── One notification a day, not one per record ─────────────────────────────
 * The obvious implementation sends a reminder for every overdue thing, and on
 * a Monday that is fourteen notifications for one person — which is how a bell
 * becomes something people clear without reading. So this collects everything
 * waiting on one person and sends them a single line: how much, of what kind,
 * and a link to the dashboard where "Needs you" already lays it out in full.
 *
 * ── Why only work somebody owns ────────────────────────────────────────────
 * Nothing here reminds anybody about a shared queue. A reminder about work
 * assigned to you is actionable; a reminder about the review queue is addressed
 * to everybody and therefore to nobody, and it arrives every single morning
 * until somebody unrelated clears it. Shared queues are the dashboard's job.
 */
class RemindersCommand extends Command
{
    protected $signature = 'tcc:reminders {--dry-run : Show what would be sent without sending it}';

    protected $description = 'Send each person one digest of what is waiting on them';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $sent = 0;
        $skipped = 0;

        foreach (User::query()->active()->get() as $person) {
            $items = $this->waitingOn($person);

            if ($items->isEmpty()) {
                continue;
            }

            /*
             | Do not send a second digest on the same day.
             |
             | The cron runs every minute to drain the queue, and somebody will
             | eventually run this by hand to see what it does. Neither should
             | produce a duplicate.
             */
            if ($this->alreadySentToday($person)) {
                $skipped++;

                continue;
            }

            $total = $items->sum('count');

            // One kind of thing: say what it is ("3 leads due a call"), not
            // "3 things need you" with nothing underneath to say which.
            $title = $items->count() === 1
                ? ucfirst($items->first()['many'])
                : "{$total} things need you today";

            /*
             | With one kind of thing waiting, the title already says all of it —
             | repeating it underneath reads like a rendering fault rather than
             | detail.
             */
            $body = $items->count() === 1
                ? null
                : $this->sentenceList($items->map(fn (array $item): string => $item['many'])->all());

            if ($dry) {
                $this->line("  <options=bold>{$person->name}</> — {$title}");

                if ($body !== null) {
                    $this->line("    <fg=gray>{$body}</>");
                }
            } else {
                Notifier::send([$person], 'reminder', $title, $body, '/dashboard');
            }

            $sent++;
        }

        $verb = $dry ? 'Would notify' : 'Notified';
        $this->components->info("{$verb} {$sent} ".($sent === 1 ? 'person' : 'people').'.');

        if ($skipped > 0) {
            $this->line("  <fg=gray>{$skipped} already had today's digest.</>");
        }

        return self::SUCCESS;
    }

    /**
     * Everything on one person's desk that is late or happening now.
     *
     * Each entry carries both phrasings so the caller can write "A follow-up is
     * due" for one and "2 follow-ups due" for several, rather than the
     * "1 follow-ups" that reads as a bug.
     *
     * @return Collection<int, array{count: int, one: string, many: string}>
     */
    private function waitingOn(User $person): Collection
    {
        $today = now()->startOfDay();

        return collect([
            $this->item(
                Lead::where('owner_id', $person->getKey())->due()->count(),
                'a lead is due a call',
                fn (int $n) => "{$n} leads due a call",
            ),
            $this->item(
                // Tomorrow's interviews nobody has reminded the business about,
                // addressed to whoever booked them.
                Interview::where('scheduled_by', $person->getKey())
                    ->whereDate('scheduled_at', now()->addDay())
                    ->whereIn('stage', ['scheduled', 'rescheduled'])
                    ->whereNull('reminder_sent_at')
                    ->count(),
                'a business needs reminding about tomorrow\'s interview',
                fn (int $n) => "{$n} businesses need reminding about tomorrow's interviews",
            ),
            $this->item(
                FollowUp::where('user_id', $person->getKey())->due()->count(),
                'a follow-up is due',
                fn (int $n) => "{$n} follow-ups due",
            ),
            $this->item(
                Interview::where('anchor_id', $person->getKey())
                    ->whereDate('scheduled_at', $today)
                    ->whereIn('stage', ['scheduled', 'rescheduled'])
                    ->count(),
                'an interview is scheduled today',
                fn (int $n) => "{$n} interviews scheduled today",
            ),
            $this->item(
                Interview::where('anchor_id', $person->getKey())
                    ->awaitingHandover()
                    ->where('conducted_at', '<', now()->subDays(Settings::int('interviews.handover_grace_days')))
                    ->count(),
                'an interview is waiting to be handed over',
                fn (int $n) => "{$n} interviews waiting to be handed over",
            ),
            $this->item(
                Article::where('writer_id', $person->getKey())
                    ->open()
                    ->whereDate('due_on', '<', $today)
                    ->count(),
                'an article is past its due date',
                fn (int $n) => "{$n} articles past their due date",
            ),
            $this->item(
                // Only work in the creative's hands. Something submitted and
                // waiting on the approver is not theirs to chase.
                Deliverable::where('assigned_to', $person->getKey())
                    ->whereIn('stage', [
                        DeliverableStage::Pending->value,
                        DeliverableStage::InProgress->value,
                        DeliverableStage::Rejected->value,
                    ])
                    ->whereDate('due_on', '<', $today)
                    ->count(),
                'a deliverable is past its due date',
                fn (int $n) => "{$n} deliverables past their due date",
            ),
            $this->item(
                Deal::where('owner_id', $person->getKey())->idle()->count(),
                'a deal has gone quiet',
                fn (int $n) => "{$n} deals have gone quiet",
            ),
        ])->filter()->values();
    }

    /**
     * @param  \Closure(int): string  $many
     * @return array{count: int, one: string, many: string}|null
     */
    private function item(int $count, string $one, \Closure $many): ?array
    {
        return $count === 0 ? null : [
            'count' => $count,
            'one' => $one,
            'many' => $count === 1 ? $one : $many($count),
        ];
    }

    /**
     * Once a day, however the first one was dealt with.
     *
     * Checking the notifications table alone meant clearing the digest earned
     * a second one on the next run. The day's send is remembered separately.
     */
    private function alreadySentToday(User $person): bool
    {
        $key = 'reminders:digest:'.$person->getKey().':'.now()->toDateString();

        if (Cache::has($key)) {
            return true;
        }

        $sent = $person->notifications()
            ->where('data->kind', 'reminder')
            ->whereDate('created_at', now()->toDateString())
            ->exists();

        if (! $sent && ! $this->option('dry-run')) {
            Cache::put($key, true, now()->endOfDay());
        }

        return $sent;
    }

    /**
     * @param  list<string>  $items
     */
    private function sentenceList(array $items): string
    {
        if (count($items) <= 1) {
            return ucfirst($items[0] ?? '');
        }

        $last = array_pop($items);

        return ucfirst(implode(', ', $items).' and '.$last).'.';
    }
}
