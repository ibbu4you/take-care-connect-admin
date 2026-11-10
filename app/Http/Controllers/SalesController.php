<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\Proposal;
use App\Models\StageEvent;
use App\Models\Target;
use App\Models\User;
use App\Support\Roles;
use App\Support\Settings;
use App\Support\Workflow\LeadStatus;
use App\Support\Workflow\PipelineStage;
use App\Support\Workflow\ProposalStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sales team as a team: who is winning, where the money is, what is stuck.
 *
 * Two pages. The leaderboard is for everybody who sells — seeing the team's
 * numbers next to your own is the point of it. The team view is for whoever
 * runs sales (`deals.view.all`): the forecast, the stuck deals, why deals are
 * lost, and handing out leads by city.
 */
class SalesController extends Controller
{
    public function leaderboard(Request $request): Response
    {
        abort_unless($request->user()->canAny(['deals.view', 'deals.view.all']), 403);

        [$period, $start, $end] = $this->period($request);
        $commission = Settings::int('sales.commission_percent');

        $rows = $this->sellers()->map(function (User $person) use ($start, $end, $period, $commission) {
            $won = Deal::where('owner_id', $person->id)->won()->whereBetween('closed_at', [$start, $end]);
            $wonValue = (float) (clone $won)->sum('actual_value');
            $target = (float) (Target::where('user_id', $person->id)->where('period', $period)->value('revenue_target') ?? 0);

            return [
                'id' => $person->id,
                'name' => $person->name,
                'initials' => $person->initials(),
                'won_value' => round($wonValue, 2),
                'won_count' => (clone $won)->count(),
                'meetings' => DealActivity::where('user_id', $person->id)->whereIn('type', ['meeting', 'visit'])
                    ->where('outcome', 'held')->whereBetween('happened_at', [$start, $end])->count(),
                'proposals' => Proposal::where('sent_by', $person->id)->whereBetween('sent_at', [$start, $end])->count(),
                'calls' => DealActivity::where('user_id', $person->id)->where('type', 'call')->whereBetween('happened_at', [$start, $end])->count()
                    + LeadCall::where('user_id', $person->id)->whereBetween('called_at', [$start, $end])->count(),
                'attainment' => $target > 0 ? (int) round($wonValue / $target * 100) : null,
                'incentive' => $commission > 0 ? round($wonValue * $commission / 100, 2) : null,
            ];
        })
            // Value won first; deals won breaks a tie, so a month with no wins
            // still ranks the people closest to one.
            ->sortBy([['won_value', 'desc'], ['won_count', 'desc'], ['proposals', 'desc'], ['meetings', 'desc']])
            ->values();

        return Inertia::render('Sales/Leaderboard', [
            'period' => $period,
            'periodLabel' => Carbon::createFromFormat('!Y-m', $period)->format('F Y'),
            'periods' => $this->recentPeriods(),
            'rows' => $rows,
            'commission' => $commission,
        ]);
    }

    public function team(Request $request): Response
    {
        abort_unless($request->user()->can('deals.view.all'), 403);

        $period = Target::currentPeriod();
        $month = [now()->startOfMonth(), now()->endOfMonth()];
        $quarter = now()->subDays(90);

        $open = Deal::with(['owner:id,name', 'client:id,name'])->open()->get();
        $won = Deal::won()->whereBetween('closed_at', $month)->get();
        $closedRecently = Deal::whereIn('outcome', ['won', 'lost'])->where('closed_at', '>=', $quarter)->get();
        $teamTarget = (float) Target::where('period', $period)->sum('revenue_target');

        $people = $this->sellers()->map(function (User $person) use ($open, $won, $period) {
            $mine = $open->where('owner_id', $person->id);
            $wonValue = (float) $won->where('owner_id', $person->id)->sum('actual_value');
            $target = (float) (Target::where('user_id', $person->id)->where('period', $period)->value('revenue_target') ?? 0);

            return [
                'id' => $person->id,
                'name' => $person->name,
                'initials' => $person->initials(),
                'open' => $mine->count(),
                'pipeline' => round((float) $mine->sum('expected_value'), 2),
                'weighted' => round($mine->sum(fn (Deal $deal) => $deal->weightedValue()), 2),
                'won_value' => round($wonValue, 2),
                'attainment' => $target > 0 ? (int) round($wonValue / $target * 100) : null,
                'activities' => DealActivity::where('user_id', $person->id)->where('happened_at', '>=', now()->subDays(7))->count(),
                'awaiting' => Proposal::where('sent_by', $person->id)->where('status', ProposalStatus::Sent->value)->count(),
                'stuck' => $mine->filter(fn (Deal $deal) => $this->stuckReason($deal) !== null)->count(),
            ];
        })->sortByDesc('pipeline')->values();

        $wonCount = $closedRecently->where('outcome', 'won')->count();

        return Inertia::render('Sales/Team', [
            'summary' => [
                'pipeline' => round((float) $open->sum('expected_value'), 2),
                'weighted' => round($open->sum(fn (Deal $deal) => $deal->weightedValue()), 2),
                // Won so far, plus every open deal due by month end — including
                // ones whose date has already passed, which are due now, not never.
                'forecast' => round(
                    (float) $won->sum('actual_value')
                    + $open->filter(fn (Deal $deal) => $deal->expected_close_on !== null && $deal->expected_close_on->lte($month[1]))
                        ->sum(fn (Deal $deal) => $deal->weightedValue()),
                    2,
                ),
                'won_value' => round((float) $won->sum('actual_value'), 2),
                'won_count' => $won->count(),
                'target' => $teamTarget > 0 ? $teamTarget : null,
                'win_rate' => $closedRecently->count() > 0 ? (int) round($wonCount / $closedRecently->count() * 100) : null,
                'stuck' => $open->filter(fn (Deal $deal) => $this->stuckReason($deal) !== null)->count(),
            ],
            'people' => $people,
            'forecast' => $this->forecast($open),
            'funnel' => $this->funnel($quarter),
            'lostReasons' => $closedRecently->where('outcome', 'lost')
                ->groupBy(fn (Deal $deal) => trim((string) $deal->lost_reason) ?: 'No reason given')
                ->map(fn (Collection $deals, string $reason) => ['reason' => $reason, 'count' => $deals->count()])
                ->sortByDesc('count')->take(6)->values(),
            'stuck' => $open->map(fn (Deal $deal) => ['deal' => $deal, 'reason' => $this->stuckReason($deal)])
                ->filter(fn (array $row) => $row['reason'] !== null)
                ->sortByDesc(fn (array $row) => $row['reason']['days'])
                ->take(10)
                ->map(fn (array $row) => [
                    'id' => $row['deal']->id,
                    'code' => $row['deal']->code,
                    'title' => $row['deal']->title,
                    'client' => $row['deal']->client?->name,
                    'owner' => $row['deal']->owner?->name,
                    'stage' => $row['deal']->stage->value,
                    'value' => (float) $row['deal']->expected_value,
                    'reason' => $row['reason']['text'],
                ])->values(),
            'cities' => $this->newLeadsByCity(),
            'sellers' => $this->sellers()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'can' => ['assign' => $request->user()->can('leads.assign')],
        ]);
    }

    /**
     * Hand a city's untouched leads to one salesperson.
     *
     * Only leads nobody has touched move: status New, no call logged, and no
     * callback date set. "New" alone was not enough — a lead stays New through
     * a run of missed calls, so a telecaller three tries in would have had it
     * taken off them mid-chase, which is how a business gets two calls from two
     * people in one day.
     */
    public function assignCity(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('leads.assign'), 403);

        $validated = $request->validate([
            'city' => ['required', 'string', 'max:255'],
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $seller = $this->sellers()->firstWhere('id', (int) $validated['user_id']);
        abort_if($seller === null, 422, 'Leads can only go to somebody who sells.');

        $moved = Lead::open()
            ->where('status', LeadStatus::New->value)
            ->whereDoesntHave('calls')
            ->whereNull('next_action_on')
            ->whereRaw('LOWER(TRIM(city)) = ?', [mb_strtolower(trim($validated['city']))])
            ->update(['owner_id' => $seller->id]);

        return back()->with('success', $moved === 1
            ? "1 new lead in {$validated['city']} handed to {$seller->name}."
            : "{$moved} new leads in {$validated['city']} handed to {$seller->name}.");
    }

    /**
     * Why an open deal needs attention, or null if it does not.
     *
     * @return array{text: string, days: int}|null
     */
    private function stuckReason(Deal $deal): ?array
    {
        if ($deal->next_step_on !== null && $deal->next_step_on->isBefore(today())) {
            $days = (int) $deal->next_step_on->diffInDays(today());

            return ['text' => "Next step overdue by {$days} ".($days === 1 ? 'day' : 'days'), 'days' => $days];
        }

        if ($deal->next_step === null) {
            $days = $deal->idleDays() ?? 0;

            return ['text' => $days > 0 ? "No next step · quiet for {$days} days" : 'No next step', 'days' => $days];
        }

        if ($deal->isIdle()) {
            return ['text' => "Quiet for {$deal->idleDays()} days", 'days' => (int) $deal->idleDays()];
        }

        return null;
    }

    /**
     * Weighted value of open deals expected to close in each of the next three
     * months. Coarse on purpose — the stage weights are guesses, and this only
     * has to say whether next month looks heavier or lighter than this one.
     *
     * @param  Collection<int, Deal>  $open
     * @return list<array{month: string, weighted: float, count: int}>
     */
    private function forecast(Collection $open): array
    {
        $months = [];

        // Past their close date and still open: the deals most at risk, so
        // they get their own line rather than falling out of every bucket.
        $overdue = $open->filter(fn (Deal $deal) => $deal->expected_close_on !== null && $deal->expected_close_on->isBefore(now()->startOfMonth()));

        if ($overdue->isNotEmpty()) {
            $months[] = [
                'month' => 'Close date passed',
                'weighted' => round($overdue->sum(fn (Deal $deal) => $deal->weightedValue()), 2),
                'count' => $overdue->count(),
            ];
        }

        for ($i = 0; $i < 3; $i++) {
            $start = now()->startOfMonth()->addMonthsNoOverflow($i);
            $end = $start->copy()->endOfMonth();
            $deals = $open->filter(fn (Deal $deal) => $deal->expected_close_on?->between($start, $end));

            $months[] = [
                'month' => $start->format('M Y'),
                'weighted' => round($deals->sum(fn (Deal $deal) => $deal->weightedValue()), 2),
                'count' => $deals->count(),
            ];
        }

        $undated = $open->whereNull('expected_close_on');

        if ($undated->isNotEmpty()) {
            $months[] = [
                'month' => 'No date',
                'weighted' => round($undated->sum(fn (Deal $deal) => $deal->weightedValue()), 2),
                'count' => $undated->count(),
            ];
        }

        return $months;
    }

    /**
     * How many deals reached each stage in the last 90 days — counted from the
     * stage history, so a deal that went straight to a meeting still counts as
     * contacted on the way.
     *
     * @return list<array{label: string, count: int}>
     */
    private function funnel(Carbon $since): array
    {
        $subject = (new Deal)->getMorphClass();
        $order = [PipelineStage::New, PipelineStage::Contacted, PipelineStage::Meeting, PipelineStage::Proposal, PipelineStage::Negotiation, PipelineStage::Won];

        $reached = StageEvent::where('subject_type', $subject)
            ->where('changed_at', '>=', $since)
            ->get(['subject_id', 'to_stage'])
            ->groupBy('subject_id')
            ->map(function (Collection $events) use ($order) {
                $furthest = $events->map(fn ($e) => array_search(PipelineStage::tryFrom($e->to_stage), $order, true))
                    ->filter(fn ($index) => $index !== false)
                    ->max();

                return $furthest ?? -1;
            });

        return array_map(fn (PipelineStage $stage, int $index) => [
            'label' => $stage->label(),
            'count' => $reached->filter(fn (int $furthest) => $furthest >= $index)->count(),
        ], $order, array_keys($order));
    }

    /** @return list<array{city: string, count: int, owners: list<array{name: string, count: int}>}> */
    private function newLeadsByCity(): array
    {
        return Lead::with('owner:id,name')
            ->open()
            ->where('status', LeadStatus::New->value)
            ->whereDoesntHave('calls')
            ->whereNull('next_action_on')
            ->whereNotNull('city')
            ->get(['id', 'city', 'owner_id'])
            ->groupBy(fn (Lead $lead) => mb_convert_case(trim($lead->city), MB_CASE_TITLE))
            ->map(fn (Collection $leads, string $city) => [
                'city' => $city,
                'count' => $leads->count(),
                'owners' => $leads->groupBy(fn (Lead $lead) => $lead->owner?->name ?? 'Nobody')
                    ->map(fn (Collection $group, string $name) => ['name' => $name, 'count' => $group->count()])
                    ->values()->all(),
            ])
            ->sortByDesc('count')
            ->take(12)
            ->values()
            ->all();
    }

    /** Everybody who sells, active, by name. @return Collection<int, User> */
    private function sellers(): Collection
    {
        return User::query()->active()
            ->role([Roles::SALES_EXECUTIVE, Roles::SALES_MANAGER, Roles::RELATIONSHIP_MANAGER])
            ->orderBy('name')
            ->get();
    }

    /** @return array{0: string, 1: Carbon, 2: Carbon} */
    private function period(Request $request): array
    {
        $period = (string) $request->query('period', Target::currentPeriod());

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $period = Target::currentPeriod();
        }

        $start = Carbon::createFromFormat('!Y-m', $period)->startOfMonth();

        return [$period, $start, $start->copy()->endOfMonth()];
    }

    /** @return list<array{value: string, label: string}> */
    private function recentPeriods(): array
    {
        return collect(range(0, 5))
            ->map(fn (int $i) => now()->startOfMonth()->subMonthsNoOverflow($i))
            ->map(fn (Carbon $month) => ['value' => $month->format('Y-m'), 'label' => $month->format('F Y')])
            ->all();
    }
}
