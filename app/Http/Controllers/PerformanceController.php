<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Deal;
use App\Models\Deliverable;
use App\Models\Interview;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\Target;
use App\Models\User;
use App\Support\Roles;
use App\Support\Stats;
use App\Support\Workflow\DeliverableStage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who is carrying what, and where work is sitting.
 *
 * ── Why this is not a leaderboard ──────────────────────────────────────────
 * The obvious version of this page ranks people by output, and the obvious
 * version is worse than useless. An anchor who did three long interviews is not
 * behind one who did nine short ones; a writer on a 4,000-word feature is not
 * behind one who published four short profiles. A page that implies otherwise
 * gets quoted in conversations it has no business being in.
 *
 * So the columns are **load and turnaround**, not a score, and every table is
 * sorted by what is on somebody's desk right now rather than by what they
 * finished. The question this answers is "who is swamped and where is work
 * stuck", which is a manager's actual question.
 *
 * Split by discipline because the measurements genuinely differ — "published
 * this month" means something different to a writer and to a video editor, and
 * one table with twelve columns mostly reading N/A would be worse than four.
 *
 * Behind `reports.view.all`: this is everybody's work, so it needs the
 * organisation-wide scope rather than the own-work one.
 */
class PerformanceController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()->can('reports.view.all'), 403);

        [$from, $to, $months] = $this->window($request);

        return Inertia::render('Reports/Performance', [
            'window' => [
                'months' => $months,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $months === 1 ? 'This month' : "Last {$months} months",
            ],
            'options' => [1, 3, 6, 12],

            'anchors' => $this->anchors($from, $to),
            'writers' => $this->writers($from, $to),
            'creatives' => $this->creatives($from, $to),
            'managers' => $this->managers($from, $to),
            'callers' => $this->callers($from, $to),
        ]);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, median: float|null}
     */
    private function anchors(Carbon $from, Carbon $to): array
    {
        return $this->table(
            $this->withRole(Roles::ANCHOR)->map(function (User $person) use ($from, $to): array {
                $conducted = Interview::where('anchor_id', $person->id)
                    ->whereBetween('conducted_at', [$from, $to])
                    ->get();

                return [
                    ...$this->person($person),
                    'done' => $conducted->count(),
                    'handed_over' => $conducted->whereNotNull('handed_over_at')->count(),

                    /*
                     | Load is present tense.
                     |
                     | "How many are open" is not a question about the window —
                     | somebody swamped today is swamped today, whatever they
                     | did in March. Only the finished counts and the turnaround
                     | look backwards.
                     */
                    'open' => Interview::where('anchor_id', $person->id)->open()->count(),
                    'waiting' => Interview::where('anchor_id', $person->id)->awaitingHandover()->count(),

                    'turnaround' => Stats::median(
                        $conducted
                            ->map(fn (Interview $i) => Stats::daysBetween($i->conducted_at, $i->handed_over_at))
                            ->filter(fn (?int $days): bool => $days !== null)
                            ->values()
                            ->all(),
                    ),
                ];
            }),
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, median: float|null}
     */
    private function writers(Carbon $from, Carbon $to): array
    {
        return $this->table(
            $this->withRole(Roles::CONTENT)->map(function (User $person) use ($from, $to): array {
                $published = Article::where('writer_id', $person->id)
                    ->whereBetween('published_at', [$from, $to])
                    ->get();

                $open = Article::where('writer_id', $person->id)->open()->get();

                return [
                    ...$this->person($person),
                    'done' => $published->count(),
                    'open' => $open->count(),
                    'overdue' => $open->filter(fn (Article $a) => $a->due_on?->isPast() === true)->count(),

                    'turnaround' => Stats::median(
                        $published
                            ->map(fn (Article $a) => Stats::daysBetween($a->created_at, $a->published_at))
                            ->filter(fn (?int $days): bool => $days !== null)
                            ->values()
                            ->all(),
                    ),
                ];
            }),
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, median: float|null}
     */
    private function creatives(Carbon $from, Carbon $to): array
    {
        return $this->table(
            $this->withRole(Roles::CREATIVE)->map(function (User $person) use ($from, $to): array {
                $published = Deliverable::where('assigned_to', $person->id)
                    ->whereBetween('published_at', [$from, $to])
                    ->get();

                $open = Deliverable::where('assigned_to', $person->id)->open()->get();

                return [
                    ...$this->person($person),
                    'done' => $published->count(),
                    'open' => $open->count(),

                    /*
                     | Rework is here because it is the number worth knowing and
                     | the one nobody volunteers. A high count is far more often
                     | a vague brief than a careless editor — which is exactly
                     | why it belongs on a shared page next to whoever wrote the
                     | brief, rather than in a private conversation about the
                     | person who did the work.
                     */
                    'rework' => $open->where('stage', DeliverableStage::Rejected)->count(),

                    'turnaround' => Stats::median(
                        $published
                            ->map(fn (Deliverable $d) => Stats::daysBetween($d->created_at, $d->published_at))
                            ->filter(fn (?int $days): bool => $days !== null)
                            ->values()
                            ->all(),
                    ),
                ];
            }),
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, median: float|null}
     */
    private function managers(Carbon $from, Carbon $to): array
    {
        $period = Target::currentPeriod();

        return $this->table(
            $this->withRole(Roles::RELATIONSHIP_MANAGER)->map(function (User $person) use ($from, $to, $period): array {
                $closed = Deal::where('owner_id', $person->id)
                    ->won()
                    ->whereBetween('closed_at', [$from, $to])
                    ->get();

                $won = (float) $closed->sum(fn (Deal $deal) => (float) $deal->actual_value);
                $target = (float) (Target::where('user_id', $person->id)->where('period', $period)->value('revenue_target') ?? 0);

                return [
                    ...$this->person($person),
                    'done' => $closed->count(),
                    'won_value' => round($won, 2),
                    'target' => $target > 0 ? $target : null,

                    // No target is not 0% attainment, it is no target. A red 0%
                    // against somebody nobody set one for is a lie about them.
                    'attainment' => $target > 0 ? (int) round($won / $target * 100) : null,

                    'open' => Deal::where('owner_id', $person->id)->open()->count(),
                    'quiet' => Deal::where('owner_id', $person->id)->idle()->count(),
                    'turnaround' => null,
                ];
            }),
        );
    }

    /**
     * Sort by what is on somebody's desk, and hand back the team median.
     *
     * Ordered by open work rather than by output, which is the whole framing of
     * the page: the top of each table is whoever is carrying the most right
     * now, not whoever produced the most.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, median: float|null}
     */
    /**
     * Everybody who works the phones: telecallers, and relationship managers,
     * who call leads too.
     *
     * "Connected" is the figure that matters, not "calls": forty calls with
     * three conversations is a bad list or a bad hour, not a hard-working day,
     * and the connect rate is what tells those apart.
     *
     * @return array<string, mixed>
     */
    private function callers(Carbon $from, Carbon $to): array
    {
        $people = $this->withRole(Roles::TELECALLER)
            ->concat($this->withRole(Roles::RELATIONSHIP_MANAGER))
            ->unique('id')
            ->values();

        return $this->table(
            $people->map(function (User $person) use ($from, $to): array {
                $calls = LeadCall::where('user_id', $person->id)->whereBetween('called_at', [$from, $to]);

                $made = (clone $calls)->count();
                $connected = (clone $calls)->connected()->count();

                return [
                    ...$this->person($person),
                    'done' => $made,
                    'connected' => $connected,
                    'rate' => $made > 0 ? (int) round($connected / $made * 100) : null,
                    'interested' => (clone $calls)->where('outcome', 'interested')->count(),
                    'booked' => Interview::where('scheduled_by', $person->id)->whereBetween('created_at', [$from, $to])->count(),
                    'open' => Lead::where('owner_id', $person->id)->open()->count(),
                    'overdue' => Lead::where('owner_id', $person->id)->open()
                        ->whereNotNull('next_action_on')->whereDate('next_action_on', '<', now())->count(),
                    'turnaround' => null,
                ];
            }),
        );
    }

    private function table(Collection $rows): array
    {
        $turnarounds = $rows
            ->pluck('turnaround')
            ->filter(fn ($value): bool => $value !== null)
            ->map(fn ($value): float => (float) $value)
            ->values()
            ->all();

        return [
            'rows' => $rows->sortByDesc('open')->values()->all(),
            'median' => Stats::median($turnarounds),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function person(User $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'initials' => $person->initials(),
            'avatar_url' => $person->avatarUrl(),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function withRole(string $role): Collection
    {
        return User::query()->active()->role($role)->orderBy('name')->get();
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: int}
     */
    private function window(Request $request): array
    {
        $months = (int) $request->query('months', 3);
        $months = in_array($months, [1, 3, 6, 12], true) ? $months : 3;

        return [
            now()->subMonths($months - 1)->startOfMonth(),
            now()->endOfMonth(),
            $months,
        ];
    }
}
