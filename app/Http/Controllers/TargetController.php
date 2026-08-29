<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Interview;
use App\Models\Target;
use App\Models\User;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monthly targets, and how everyone is doing against them.
 *
 * ── Attainment is computed, never stored ───────────────────────────────────
 * A stored attainment figure is one that has to be recalculated every time a
 * deal closes, and one that quietly goes stale the first time that job is
 * missed. Summing the deals on read costs nothing at this scale and cannot
 * disagree with the deals it is a sum of.
 */
class TargetController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canAny(['targets.view', 'targets.view.all', 'targets.manage']), 403);

        $period = $this->period($request);
        $user = $request->user();

        // Somebody who can only see their own target gets a table of one row —
        // which is the honest answer, rather than an empty page.
        $people = $user->canAny(['targets.view.all', 'targets.manage'])
            ? User::query()->active()->role(Roles::RELATIONSHIP_MANAGER)->orderBy('name')->get()
            : User::query()->whereKey($user->id)->get();

        $targets = Target::where('period', $period)
            ->whereIn('user_id', $people->pluck('id'))
            ->get()
            ->keyBy('user_id');

        [$start, $end] = $this->bounds($period);

        $rows = $people->map(function (User $person) use ($targets, $start, $end) {
            $target = $targets->get($person->id);

            $wonValue = (float) Deal::where('owner_id', $person->id)
                ->won()
                ->whereBetween('closed_at', [$start, $end])
                ->sum('actual_value');

            $wonCount = Deal::where('owner_id', $person->id)
                ->won()
                ->whereBetween('closed_at', [$start, $end])
                ->count();

            $interviews = Interview::where('scheduled_by', $person->id)
                ->whereBetween('scheduled_at', [$start, $end])
                ->count();

            return [
                'user' => ['id' => $person->id, 'name' => $person->name, 'initials' => $person->initials()],
                'revenue_target' => $target?->revenue_target !== null ? (float) $target->revenue_target : null,
                'deals_target' => $target?->deals_target,
                'interviews_target' => $target?->interviews_target,
                'revenue_actual' => $wonValue,
                'deals_actual' => $wonCount,
                'interviews_actual' => $interviews,
                'attainment' => $this->attainment($wonValue, $target?->revenue_target),
                'note' => $target?->note,
            ];
        });

        return Inertia::render('Targets/Index', [
            'period' => $period,
            'periodLabel' => Carbon::createFromFormat('Y-m', $period)->format('F Y'),
            'periods' => $this->recentPeriods(),
            'rows' => $rows,
            'totals' => [
                'revenue_target' => $rows->sum(fn (array $row) => $row['revenue_target'] ?? 0),
                'revenue_actual' => $rows->sum(fn (array $row) => $row['revenue_actual']),
            ],
            'can' => ['manage' => $request->user()->can('targets.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('targets.manage'), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'revenue_target' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'deals_target' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'interviews_target' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'period.regex' => 'The period must look like 2026-08.',
        ]);

        // updateOrCreate against the unique key: setting a target twice for the
        // same month is a correction, not a second target.
        Target::updateOrCreate(
            ['user_id' => $validated['user_id'], 'period' => $validated['period']],
            [...$validated, 'set_by' => $request->user()->id],
        );

        return back()->with('success', 'Target set.');
    }

    private function period(Request $request): string
    {
        $period = (string) $request->query('period', Target::currentPeriod());

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1
            ? $period
            : Target::currentPeriod();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function bounds(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m', $period)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    private function attainment(float $actual, mixed $target): ?int
    {
        // No target is not the same as zero attainment. Returning null lets the
        // page say "no target set" rather than draw an alarming empty bar.
        return $target > 0 ? (int) round(($actual / (float) $target) * 100) : null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function recentPeriods(): array
    {
        return collect(range(0, 11))
            ->map(function (int $monthsAgo) {
                $date = now()->subMonths($monthsAgo);

                return ['value' => $date->format('Y-m'), 'label' => $date->format('F Y')];
            })
            ->all();
    }
}
