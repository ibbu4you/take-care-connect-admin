<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Deal;
use App\Models\Deliverable;
use App\Models\Interview;
use App\Models\User;
use App\Support\Scope;
use App\Support\Stats;
use App\Support\Workflow\ArticleStage;
use App\Support\Workflow\DeliverableStage;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\PipelineStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the work went.
 *
 * ── One page, four questions ───────────────────────────────────────────────
 * Not a report builder. The four things this team actually asks — how the
 * pipeline converts, what the anchors produced, what content shipped, and what
 * went out on social — fit on one page over a chosen window, and a page beats a
 * builder nobody configures twice.
 *
 * Everything is scoped exactly as its module is, so a manager's report covers
 * their own book and an Admin's covers the organisation. A report that counted
 * rows the reader cannot open is a report they will not believe.
 */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canAny(['reports.view', 'reports.view.all']), 403);

        [$from, $to, $months] = $this->window($request);
        $user = $request->user();

        return Inertia::render('Reports/Index', [
            'window' => [
                'months' => $months,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $months === 1 ? 'This month' : "Last {$months} months",
            ],
            'options' => [1, 3, 6, 12],

            'pipeline' => $this->pipeline($user, $from, $to),
            'interviews' => $this->interviews($user, $from, $to),
            'content' => $this->content($user, $from, $to),
            'creative' => $this->creative($user, $from, $to),
            'people' => $request->user()->can('reports.view.all')
                ? $this->people($from, $to)
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pipeline(User $user, Carbon $from, Carbon $to): array
    {
        $closed = Scope::apply(Deal::query(), $user, 'deals', ['owner_id'])
            ->whereNotNull('outcome')
            ->whereBetween('closed_at', [$from, $to])
            ->get();

        $won = $closed->where('outcome', 'won');
        $lost = $closed->where('outcome', 'lost');

        $open = Scope::apply(Deal::query(), $user, 'deals', ['owner_id'])->open()->get();

        return [
            'won_count' => $won->count(),
            'lost_count' => $lost->count(),
            'won_value' => round($won->sum(fn (Deal $deal) => (float) $deal->actual_value), 2),
            'lost_value' => round($lost->sum(fn (Deal $deal) => (float) $deal->expected_value), 2),
            // Guarded: no closed deals is not a 0% win rate, it is no data.
            'win_rate' => $closed->count() > 0
                ? (int) round(($won->count() / $closed->count()) * 100)
                : null,
            'average_won' => $won->count() > 0
                ? round($won->sum(fn (Deal $deal) => (float) $deal->actual_value) / $won->count(), 2)
                : 0.0,

            // The shape of what is still open, so a healthy-looking win rate on
            // an empty pipeline is visible as such.
            'by_stage' => collect(PipelineStage::boardColumns())
                ->map(fn (PipelineStage $stage) => [
                    'label' => $stage->label(),
                    'stage' => $stage->value,
                    'count' => $open->where('stage', $stage)->count(),
                    'value' => round($open->where('stage', $stage)->sum(fn (Deal $d) => (float) $d->expected_value), 2),
                ])
                ->values(),

            'lost_reasons' => $lost
                ->pluck('lost_reason')
                ->filter()
                ->countBy()
                ->sortDesc()
                ->take(5)
                ->map(fn (int $count, string $reason) => ['reason' => $reason, 'count' => $count])
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function interviews(User $user, Carbon $from, Carbon $to): array
    {
        $columns = ['anchor_id', 'scheduled_by', 'content_owner_id', 'creative_owner_id'];

        $conducted = $this->between(
            Scope::apply(Interview::query(), $user, 'interviews', $columns),
            'conducted_at',
            $from,
            $to,
        )->get();

        $booked = $this->between(
            Scope::apply(Interview::query(), $user, 'interviews', $columns),
            'scheduled_at',
            $from,
            $to,
        )->get();

        return [
            'booked' => $booked->count(),
            'conducted' => $conducted->count(),
            'cancelled' => $booked->whereIn('stage', ['cancelled', 'no_show'])->count(),

            'by_kind' => collect(InterviewKind::cases())
                ->map(fn (InterviewKind $kind) => [
                    'label' => $kind->label(),
                    'kind' => $kind->value,
                    'count' => $conducted->where('kind', $kind)->count(),
                ])
                ->values(),

            /*
             | How long a handover actually takes.
             |
             | The gap between conducting an interview and handing it over is the
             | single biggest source of delay in this process, and it is
             | invisible in any per-record view.
             */
            'median_days_to_handover' => $this->median(
                $conducted
                    ->map(fn (Interview $i) => Stats::daysBetween($i->conducted_at, $i->handed_over_at))
                    ->filter(fn (?int $days): bool => $days !== null)
                    ->values()
                    ->all(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function content(User $user, Carbon $from, Carbon $to): array
    {
        $columns = ['writer_id', 'reviewer_id'];

        $published = $this->between(
            Scope::apply(Article::query(), $user, 'articles', $columns),
            'published_at',
            $from,
            $to,
        )->get();

        $open = Scope::apply(Article::query(), $user, 'articles', $columns)->open()->get();

        return [
            'published' => $published->count(),
            'open' => $open->count(),
            'overdue' => $open
                ->filter(fn (Article $a) => $a->due_on !== null && $a->due_on->isPast())
                ->count(),

            'by_stage' => collect(ArticleStage::cases())
                ->reject(fn (ArticleStage $stage) => $stage === ArticleStage::Published)
                ->map(fn (ArticleStage $stage) => [
                    'label' => $stage->label(),
                    'stage' => $stage->value,
                    'count' => $open->where('stage', $stage)->count(),
                ])
                ->values(),

            // Handover to live: the number the whole content phase exists to
            // shorten.
            'median_days_to_publish' => $this->median(
                $published
                    ->map(fn (Article $a) => Stats::daysBetween($a->created_at, $a->published_at))
                    ->filter(fn (?int $days): bool => $days !== null)
                    ->values()
                    ->all(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function creative(User $user, Carbon $from, Carbon $to): array
    {
        $columns = ['assigned_to', 'assigned_by'];

        $published = $this->between(
            Scope::apply(Deliverable::query(), $user, 'deliverables', $columns),
            'published_at',
            $from,
            $to,
        )->with('socialPosts')->get();

        $open = Scope::apply(Deliverable::query(), $user, 'deliverables', $columns)->open()->get();

        return [
            'published' => $published->count(),
            'open' => $open->count(),
            'rejected' => $open->where('stage', DeliverableStage::Rejected)->count(),
            'awaiting_publish' => $open->filter(fn (Deliverable $d) => $d->stage->isReadyToPost())->count(),

            'by_kind' => $published
                ->groupBy(fn (Deliverable $d) => $d->kind->label())
                ->map(fn ($group, string $label) => ['label' => $label, 'count' => $group->count()])
                ->values(),

            'by_platform' => $published
                ->flatMap(fn (Deliverable $d) => $d->socialPosts->pluck('platform'))
                ->countBy()
                ->map(fn (int $count, string $platform) => ['label' => ucfirst($platform), 'count' => $count])
                ->values(),
        ];
    }

    /**
     * Per-person throughput. Only for somebody with the organisation-wide view.
     *
     * @return list<array<string, mixed>>
     */
    private function people(Carbon $from, Carbon $to): array
    {
        return User::query()
            ->active()
            ->get()
            ->map(fn (User $person) => [
                'name' => $person->name,
                'initials' => $person->initials(),
                'interviews' => Interview::where('anchor_id', $person->id)
                    ->whereBetween('conducted_at', [$from, $to])->count(),
                'articles' => Article::where('writer_id', $person->id)
                    ->whereBetween('published_at', [$from, $to])->count(),
                'deliverables' => Deliverable::where('assigned_to', $person->id)
                    ->whereBetween('published_at', [$from, $to])->count(),
                'won_value' => round((float) Deal::where('owner_id', $person->id)
                    ->won()->whereBetween('closed_at', [$from, $to])->sum('actual_value'), 2),
            ])
            // Somebody who produced nothing in the window is noise, not a
            // finding — the report is about output, not attendance.
            ->filter(fn (array $row) => $row['interviews'] || $row['articles'] || $row['deliverables'] || $row['won_value'])
            ->sortByDesc(fn (array $row) => $row['interviews'] + $row['articles'] + $row['deliverables'])
            ->values()
            ->all();
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

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function between(Builder $query, string $column, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween($column, [$from, $to]);
    }

    /**
     * @param  list<int|float>  $values
     */
    private function median(array $values): ?float
    {
        return Stats::median($values);
    }
}
