<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Deal;
use App\Models\Deliverable;
use App\Models\FollowUp;
use App\Models\Interview;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\Note;
use App\Models\Proposal;
use App\Models\StageEvent;
use App\Models\Target;
use App\Models\User;
use App\Support\Scope;
use App\Support\Settings;
use App\Support\Workflow\ArticleStage;
use App\Support\Workflow\DeliverableStage;
use App\Support\Workflow\InterviewStage;
use App\Support\Workflow\PipelineStage;
use App\Support\Workflow\ProposalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The landing screen.
 *
 * ── Composed from permissions, not from role names ──────────────────────────
 * Each panel appears because the viewer holds the permission that panel is
 * about, never because they "are an Anchor". Somebody holding two roles — common
 * in a small team, where the person booking interviews also cuts the reels —
 * gets both sets of tiles rather than whichever role happened to be checked
 * first.
 *
 * ── Every figure is scoped the same way its module is ───────────────────────
 * A tile that counted rows the viewer cannot open would be worse than no tile:
 * they would click it, land on an empty list, and stop trusting the numbers.
 * So each query runs through the same {@see Scope} the module's own list uses.
 */
class DashboardController extends Controller
{
    /** Every column that puts somebody "on" an interview, for {@see Scope}. */
    private const INTERVIEW_COLUMNS = ['anchor_id', 'scheduled_by', 'content_owner_id', 'creative_owner_id'];

    private const ARTICLE_COLUMNS = ['writer_id', 'reviewer_id'];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $panels = [
            'sales' => $user->canAny(['deals.view', 'deals.view.all']),
            'leads' => $user->canAny(['leads.view', 'leads.view.all']),
            'interviews' => $user->canAny(['interviews.view', 'interviews.view.all']),
            'content' => $user->canAny(['articles.view', 'articles.view.all']),
            'creative' => $user->canAny(['deliverables.view', 'deliverables.view.all']),
            'organisation' => $user->can('reports.view.all'),
        ];

        return Inertia::render('Dashboard', [
            'greeting' => $this->greeting(),
            'panels' => $panels,

            'attention' => $this->attention($user, $panels),

            // The cards. Each picks the module it describes from what this
            // person can see, so every role gets a full page rather than a
            // dashboard with holes where somebody else's numbers would be.
            'progress' => $this->progress($user, $panels),
            'breakdown' => $this->breakdown($user, $panels),
            'gauge' => $this->gauge($user, $panels),
            'activity' => $this->activity($user),
            'todos' => $this->todos($user, $panels),
            'agenda' => $this->agenda($user, $panels),
            'proposals' => $this->proposals($user),
        ]);
    }

    /**
     * The one list on this page that is not a number: things actually blocked.
     *
     * A dashboard of counts tells somebody how they are doing. This tells them
     * what to open next, which is the only reason to look at it before ten in
     * the morning.
     *
     * @param  array<string, bool>  $panels
     * @return list<array<string, mixed>>
     */
    private function attention(User $user, array $panels): array
    {
        $items = [];

        if ($panels['interviews']) {
            $stuck = $this->scope(Interview::query(), $user, 'interviews', [
                'anchor_id', 'scheduled_by', 'content_owner_id', 'creative_owner_id',
            ])->awaitingHandover()->with('anchor:id,name')->limit(5)->get();

            foreach ($stuck as $interview) {
                // The reason it is stuck, not just that it is — otherwise the
                // reader has to open it to find out what to do.
                $missing = $interview->missingForHandover();

                $items[] = [
                    'kind' => 'interview',
                    'title' => $interview->title,
                    'detail' => $missing === []
                        ? 'Ready to hand over'
                        : 'Missing '.$this->sentenceList($missing),
                    'tone' => $missing === [] ? 'success' : 'warning',
                    'url' => "/interviews/{$interview->id}",
                    'code' => $interview->code,
                ];
            }
        }

        if ($panels['leads']) {
            /*
             | A call somebody promised to make and has not.
             |
             | This belongs beside a stuck interview rather than in a tile: a
             | count of overdue calls tells a manager how they are doing, and a
             | list of who is waiting tells them what to do in the next ten
             | minutes. The second is the only reason to open a dashboard first
             | thing.
             */
            $waiting = Lead::visibleTo($user)
                ->open()
                ->whereNotNull('next_action_on')
                ->whereDate('next_action_on', '<', now())
                ->orderBy('next_action_on')
                ->limit(5)
                ->get();

            foreach ($waiting as $lead) {
                // Calendar days: measured to the clock, 6pm turned yesterday into "2 days ago".
                $days = (int) $lead->next_action_on->copy()->startOfDay()->diffInDays(today());

                $items[] = [
                    'kind' => 'lead',
                    'title' => $lead->name,
                    'detail' => $days === 1
                        ? 'You said you would call yesterday'
                        : "You said you would call {$days} days ago",
                    'tone' => 'warning',
                    'url' => "/leads/{$lead->id}",
                    'code' => $lead->code,
                ];
            }
        }

        if ($panels['content']) {
            $overdue = $this->scope(Article::query(), $user, 'articles', ['writer_id', 'reviewer_id'])
                ->open()
                ->whereNotNull('due_on')
                ->whereDate('due_on', '<', now())
                ->limit(5)
                ->get();

            foreach ($overdue as $article) {
                $items[] = [
                    'kind' => 'article',
                    'title' => $article->title,
                    'detail' => 'Past its deadline · '.$article->stage->label(),
                    'tone' => 'danger',
                    'url' => "/articles/{$article->id}",
                    'code' => $article->code,
                ];
            }
        }

        if ($panels['creative']) {
            $rejected = Deliverable::visibleTo($user)
                ->where('stage', DeliverableStage::Rejected->value)
                ->limit(5)
                ->get();

            foreach ($rejected as $deliverable) {
                $items[] = [
                    'kind' => 'deliverable',
                    'title' => $deliverable->title ?: $deliverable->kind->label(),
                    'detail' => 'Sent back — needs changes',
                    'tone' => 'danger',
                    'url' => "/deliverables/{$deliverable->id}",
                    'code' => $deliverable->code,
                ];
            }
        }

        if ($panels['sales']) {
            $quiet = $this->scope(Deal::query(), $user, 'deals', ['owner_id'])
                ->idle()
                ->with('client:id,name')
                ->limit(5)
                ->get();

            foreach ($quiet as $deal) {
                $items[] = [
                    'kind' => 'deal',
                    'title' => $deal->title,
                    'detail' => $deal->idleDays() !== null
                        ? "Nothing for {$deal->idleDays()} days · {$deal->client?->name}"
                        : 'No activity recorded',
                    'tone' => 'warning',
                    'url' => "/deals/{$deal->id}",
                    'code' => $deal->code,
                ];
            }
        }

        // Capped so the panel stays a to-do list rather than a second inbox.
        return array_slice($items, 0, 8);
    }

    /**
     * The progress ring: how far through this month's goal.
     *
     * A revenue target first, because it is the one number a target is set
     * for; an interview target next; otherwise the share of the queue already
     * out of the door.
     *
     * @param  array<string, bool>  $panels
     * @return array<string, mixed>|null
     */
    private function progress(User $user, array $panels): ?array
    {
        $target = Target::where('user_id', $user->id)->where('period', Target::currentPeriod())->first();
        $month = [now()->startOfMonth(), now()->endOfMonth()];

        $commission = Settings::int('sales.commission_percent');

        if ($panels['sales'] && (float) ($target?->revenue_target ?? 0) > 0) {
            // The salesperson's own wins: the incentive is theirs, not the team's.
            $done = (float) Deal::where('owner_id', $user->id)->won()->whereBetween('closed_at', $month)->sum('actual_value');

            return [
                'kind' => 'revenue',
                'done' => $done,
                'total' => (float) $target->revenue_target,
                'incentive' => $commission > 0 ? round($done * $commission / 100, 2) : null,
            ];
        }

        if ($panels['interviews'] && (int) ($target?->interviews_target ?? 0) > 0) {
            return [
                'kind' => 'interviews',
                'done' => Interview::where('anchor_id', $user->id)->whereBetween('conducted_at', $month)->count(),
                'total' => (int) $target->interviews_target,
            ];
        }

        if ($panels['sales']) {
            $done = (float) Deal::where('owner_id', $user->id)->won()->whereBetween('closed_at', $month)->sum('actual_value');

            return [
                'kind' => 'revenue',
                'done' => $done,
                'total' => null,
                'incentive' => $commission > 0 ? round($done * $commission / 100, 2) : null,
            ];
        }

        if ($panels['content']) {
            $articles = fn () => $this->scope(Article::query(), $user, 'articles', self::ARTICLE_COLUMNS);
            $done = $articles()->whereBetween('published_at', $month)->count();

            return ['kind' => 'articles', 'done' => $done, 'total' => $done + $articles()->open()->count()];
        }

        if ($panels['creative']) {
            $done = Deliverable::visibleTo($user)->whereBetween('published_at', $month)->count();

            return ['kind' => 'deliverables', 'done' => $done, 'total' => $done + Deliverable::visibleTo($user)->open()->count()];
        }

        if ($panels['interviews']) {
            $interviews = fn () => $this->scope(Interview::query(), $user, 'interviews', self::INTERVIEW_COLUMNS);
            $done = $interviews()->whereBetween('conducted_at', $month)->count();

            return ['kind' => 'interviews', 'done' => $done, 'total' => $done + $interviews()->open()->count()];
        }

        return null;
    }

    /**
     * The bar chart: where the open work sits, stage by stage.
     *
     * Open stages are drawn solid; the closed ones (won, published, rejected…)
     * are drawn pale and count only this month, otherwise a year of history
     * would flatten every open bar to nothing.
     *
     * @param  array<string, bool>  $panels
     * @return array<string, mixed>|null
     */
    private function breakdown(User $user, array $panels): ?array
    {
        return match (true) {
            $panels['sales'] => $this->stageBreakdown(
                'deals',
                fn () => $this->scope(Deal::query(), $user, 'deals', ['owner_id']),
                PipelineStage::cases(),
                [PipelineStage::Won, PipelineStage::Lost],
                'owner_id',
            ),
            $panels['interviews'] => $this->stageBreakdown(
                'interviews',
                fn () => $this->scope(Interview::query(), $user, 'interviews', self::INTERVIEW_COLUMNS),
                InterviewStage::cases(),
                [InterviewStage::Published, InterviewStage::Cancelled, InterviewStage::NoShow],
                'anchor_id',
            ),
            $panels['content'] => $this->stageBreakdown(
                'articles',
                fn () => $this->scope(Article::query(), $user, 'articles', self::ARTICLE_COLUMNS),
                ArticleStage::cases(),
                [ArticleStage::Published],
                'writer_id',
            ),
            $panels['creative'] => $this->stageBreakdown(
                'deliverables',
                fn () => Deliverable::visibleTo($user),
                DeliverableStage::cases(),
                [DeliverableStage::Published],
                'assigned_to',
            ),
            default => null,
        };
    }

    /**
     * @param  \Closure(): Builder<Model>  $base
     * @param  array<int, \BackedEnum>  $stages
     * @param  array<int, \BackedEnum>  $closed
     * @return array<string, mixed>
     */
    private function stageBreakdown(string $kind, \Closure $base, array $stages, array $closed, string $personColumn): array
    {
        $closedValues = array_map(static fn (\BackedEnum $stage) => $stage->value, $closed);
        $openValues = [];
        $bars = [];

        foreach ($stages as $stage) {
            $isClosed = in_array($stage->value, $closedValues, true);
            $query = $base()->where('stage', $stage->value);

            if ($isClosed) {
                $query->where('stage_entered_at', '>=', now()->startOfMonth());
            } else {
                $openValues[] = $stage->value;
            }

            /** @phpstan-ignore method.notFound */
            $bars[] = ['label' => $stage->label(), 'value' => $query->count(), 'open' => ! $isClosed];
        }

        $peopleIds = $base()->whereIn('stage', $openValues)->whereNotNull($personColumn)
            ->distinct()->pluck($personColumn);

        return [
            'kind' => $kind,
            'total' => array_sum(array_map(static fn (array $bar) => $bar['open'] ? $bar['value'] : 0, $bars)),
            'bars' => $bars,
            'people' => User::whereIn('id', $peopleIds->take(3))->get()->map(fn (User $person) => $this->person($person))->all(),
            'people_count' => $peopleIds->count(),
        ];
    }

    /**
     * The half-circle gauge and the three rows under it.
     *
     * @param  array<string, bool>  $panels
     * @return array<string, mixed>|null
     */
    private function gauge(User $user, array $panels): ?array
    {
        $month = [now()->startOfMonth(), now()->endOfMonth()];

        // Somebody who sells but does not book interviews: deals won against
        // their deals target, and the next three things to do on open deals.
        if ($panels['sales'] && ! $panels['interviews']) {
            $deals = fn () => $this->scope(Deal::query(), $user, 'deals', ['owner_id']);
            $won = Deal::where('owner_id', $user->id)->won()->whereBetween('closed_at', $month)->count();
            $target = (int) (Target::where('user_id', $user->id)->where('period', Target::currentPeriod())->value('deals_target') ?? 0);

            return [
                'kind' => 'deals',
                'value' => $won,
                'max' => max($won, $target),
                'items' => $deals()->open()->whereNotNull('next_step_on')->orderBy('next_step_on')->limit(3)->get()
                    ->map(fn (Deal $deal) => [
                        'id' => $deal->id,
                        'date' => $deal->next_step_on->format('M j, Y'),
                        'meta' => $deal->next_step ?? $deal->title,
                        'status' => $deal->stage->label(),
                        'tone' => $deal->nextStepOverdue() ? 'danger' : 'info',
                        'url' => "/deals/{$deal->id}",
                    ])->all(),
            ];
        }

        if ($panels['interviews']) {
            $interviews = fn () => $this->scope(Interview::query(), $user, 'interviews', self::INTERVIEW_COLUMNS);

            $done = $interviews()->whereBetween('conducted_at', $month)->count();
            $planned = $interviews()->whereBetween('scheduled_at', $month)
                ->where('stage', '!=', InterviewStage::Cancelled->value)->count();

            // Upcoming first; if the diary is thin, the most recent ones fill in.
            $upcoming = $interviews()->open()->where('scheduled_at', '>=', now()->startOfDay())
                ->orderBy('scheduled_at')->limit(3)->get();
            $rows = $upcoming->count() >= 3 ? $upcoming : $upcoming->concat(
                $interviews()->where('scheduled_at', '<', now()->startOfDay())
                    ->latest('scheduled_at')->limit(3 - $upcoming->count())->get(),
            );

            return [
                'kind' => 'interviews',
                'value' => $done,
                'max' => max($done, $planned),
                'items' => $rows->map(fn (Interview $interview) => [
                    'id' => $interview->id,
                    'date' => $interview->scheduled_at?->format('M j, Y') ?? 'Not scheduled',
                    'meta' => $interview->kind->label(),
                    'status' => $interview->stage->label(),
                    'tone' => $this->stageTone($interview->stage->value),
                    'url' => "/interviews/{$interview->id}",
                ])->values()->all(),
            ];
        }

        if ($panels['content']) {
            $articles = fn () => $this->scope(Article::query(), $user, 'articles', self::ARTICLE_COLUMNS);
            $done = $articles()->whereBetween('published_at', $month)->count();

            return [
                'kind' => 'articles',
                'value' => $done,
                'max' => $done + $articles()->open()->count(),
                'items' => $articles()->open()->orderByRaw('due_on is null')->orderBy('due_on')->limit(3)->get()
                    ->map(fn (Article $article) => [
                        'id' => $article->id,
                        'date' => ($article->due_on ?? $article->created_at)?->format('M j, Y'),
                        'meta' => $article->code,
                        'status' => $article->stage->label(),
                        'tone' => $this->stageTone($article->stage->value),
                        'url' => "/articles/{$article->id}",
                    ])->all(),
            ];
        }

        if ($panels['creative']) {
            $done = Deliverable::visibleTo($user)->whereBetween('published_at', $month)->count();

            return [
                'kind' => 'deliverables',
                'value' => $done,
                'max' => $done + Deliverable::visibleTo($user)->open()->count(),
                'items' => Deliverable::visibleTo($user)->open()->orderByRaw('due_on is null')->orderBy('due_on')
                    ->limit(3)->get()
                    ->map(fn (Deliverable $deliverable) => [
                        'id' => $deliverable->id,
                        'date' => ($deliverable->due_on ?? $deliverable->created_at)?->format('M j, Y'),
                        'meta' => $deliverable->kind->label(),
                        'status' => $deliverable->stage->label(),
                        'tone' => $this->stageTone($deliverable->stage->value),
                        'url' => "/deliverables/{$deliverable->id}",
                    ])->all(),
            ];
        }

        return null;
    }

    /**
     * Stage moves per day for the last year — the line chart.
     *
     * Somebody who can see the whole organisation's reports sees the whole
     * team's moves; everybody else sees their own, which is the only figure
     * every role is entitled to.
     *
     * @return array<string, mixed>
     */
    private function activity(User $user): array
    {
        $team = $user->can('reports.view.all');
        $start = now()->subDays(364)->startOfDay();

        $query = StageEvent::query()->where('changed_at', '>=', $start);

        if (! $team) {
            $query->where('changed_by', $user->id);
        }

        $counts = $query->selectRaw('DATE(changed_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $days = [];

        for ($day = $start->copy(); $day->lte(now()); $day->addDay()) {
            $days[] = (int) ($counts[$day->toDateString()] ?? 0);
        }

        return ['scope' => $team ? 'team' : 'mine', 'end' => now()->toDateString(), 'days' => $days];
    }

    /**
     * The checklist: follow-ups for anybody in sales, the call list for
     * somebody who works leads without the pipeline (a telecaller — the
     * Follow-ups page sits behind deals.view, so linking them there would 403),
     * and their own notes otherwise.
     *
     * @param  array<string, bool>  $panels
     * @return array<string, mixed>
     */
    private function todos(User $user, array $panels): array
    {
        if (! $panels['sales'] && $panels['leads']) {
            $today = LeadCall::where('user_id', $user->id)->whereDate('called_at', now());

            return [
                'kind' => 'calls',
                'stats' => [
                    'today' => (clone $today)->count(),
                    'connected' => (clone $today)->connected()->count(),
                ],
                'items' => $this->callList($user)->limit(3)->get()->map(function (Lead $lead) {
                    $due = $lead->next_action_on;

                    return [
                        'id' => $lead->id,
                        'title' => $lead->name,
                        'body' => collect([$lead->contact_person, $lead->phone])->filter()->implode(' · '),
                        'tags' => [
                            match (true) {
                                $due->isToday() => ['label' => 'Today', 'tone' => 'danger'],
                                $due->isPast() => ['label' => 'Overdue', 'tone' => 'danger'],
                                default => ['label' => 'Upcoming', 'tone' => 'info'],
                            },
                            ['label' => $lead->status->label(), 'tone' => 'warning'],
                        ],
                        'date' => $due->format('M d'),
                        'done' => false,
                        'url' => "/leads/{$lead->id}",
                    ];
                })->all(),
            ];
        }

        if ($panels['sales']) {
            $with = ['client:id,name', 'lead:id,name', 'deal:id,title'];

            $pending = FollowUp::with($with)->where('user_id', $user->id)->pending()
                ->orderBy('due_on')->limit(3)->get();
            $done = $pending->count() >= 3 ? new Collection : FollowUp::with($with)
                ->where('user_id', $user->id)->whereNotNull('completed_at')
                ->latest('completed_at')->limit(3 - $pending->count())->get();

            return [
                'kind' => 'followups',
                'items' => $pending->concat($done)->map(function (FollowUp $followUp) {
                    $finished = $followUp->completed_at !== null;
                    $due = $followUp->due_on;

                    $when = match (true) {
                        $finished => ['label' => 'Done', 'tone' => 'neutral'],
                        $due === null => ['label' => 'No date', 'tone' => 'neutral'],
                        $due->isToday() => ['label' => 'Today', 'tone' => 'danger'],
                        $due->isPast() => ['label' => 'Overdue', 'tone' => 'danger'],
                        default => ['label' => 'Upcoming', 'tone' => 'info'],
                    };

                    return [
                        'id' => $followUp->id,
                        'title' => $followUp->deal?->title ?? $followUp->lead?->name ?? $followUp->client?->name ?? 'Follow-up',
                        'body' => $followUp->note ?: ($followUp->outcome_note ?: ''),
                        'tags' => [
                            $when,
                            ['label' => Str::headline($followUp->channel ?? 'call'), 'tone' => $finished ? 'neutral' : 'warning'],
                        ],
                        'date' => $due?->format('M d'),
                        'done' => $finished,
                        'url' => match (true) {
                            $followUp->deal_id !== null => "/deals/{$followUp->deal_id}",
                            $followUp->lead_id !== null => "/leads/{$followUp->lead_id}",
                            $followUp->client_id !== null => "/clients/{$followUp->client_id}",
                            default => '/follow-ups',
                        },
                    ];
                })->values()->all(),
            ];
        }

        return [
            'kind' => 'notes',
            'items' => Note::with('notable')->where('user_id', $user->id)->latest()->limit(3)->get()
                ->map(fn (Note $note) => [
                    'id' => $note->id,
                    'title' => $note->notable?->title ?? $note->notable?->name ?? $note->notable?->code ?? 'Deleted record',
                    'body' => Str::limit(trim(strip_tags((string) $note->body)), 140),
                    'tags' => [['label' => Str::headline(class_basename((string) $note->notable_type)), 'tone' => 'neutral']],
                    'date' => $note->created_at?->format('M d'),
                    'done' => false,
                    'url' => null,
                ])->all(),
        ];
    }

    /**
     * The coloured cards on the right: what is coming up, in tabs.
     *
     * @param  array<string, bool>  $panels
     * @return array<string, list<array<string, mixed>>>
     */
    private function agenda(User $user, array $panels): array
    {
        $tabs = [];

        if ($panels['interviews']) {
            $tabs['interviews'] = $this->scope(Interview::query(), $user, 'interviews', self::INTERVIEW_COLUMNS)
                ->open()
                ->where('scheduled_at', '>=', now()->startOfDay())
                ->with(['anchor', 'scheduler', 'contentOwner', 'creativeOwner'])
                ->orderBy('scheduled_at')
                ->limit(4)
                ->get()
                ->map(function (Interview $interview) {
                    $start = $interview->scheduled_at;
                    $end = $start?->copy()->addMinutes($interview->duration_minutes ?: 45);

                    return [
                        'id' => "interview-{$interview->id}",
                        'title' => $interview->title,
                        'when' => $start && $end ? $start->format('D j M').' · '.$this->timeRange($start, $end) : 'Not scheduled',
                        'people' => $this->people([$interview->anchor, $interview->scheduler, $interview->contentOwner, $interview->creativeOwner]),
                        'place' => $interview->mode === 'google_meet'
                            ? 'On Google Meet'
                            : ($interview->location ? 'At '.$interview->location : 'In person'),
                        'tag' => $interview->kind->label(),
                        'url' => "/interviews/{$interview->id}",
                    ];
                })->all();
        }

        if (! $panels['sales'] && $panels['leads']) {
            $tabs['calls'] = $this->callList($user)
                ->whereDate('next_action_on', '>=', now())
                ->with('owner')
                ->limit(4)
                ->get()
                ->map(fn (Lead $lead) => [
                    'id' => "lead-{$lead->id}",
                    'title' => $lead->name,
                    'when' => $lead->next_action_on->format('D j M'),
                    'people' => $this->people([$lead->owner]),
                    'place' => collect([$lead->contact_person, $lead->phone])->filter()->implode(' · ') ?: $lead->code,
                    'tag' => $lead->status->label(),
                    'url' => "/leads/{$lead->id}",
                ])->all();
        } elseif ($panels['sales']) {
            $tabs['calls'] = FollowUp::with(['client:id,name', 'lead:id,name', 'deal:id,title', 'owner'])
                ->where('user_id', $user->id)
                ->pending()
                ->whereDate('due_on', '>=', now())
                ->orderBy('due_on')
                ->limit(4)
                ->get()
                ->map(fn (FollowUp $followUp) => [
                    'id' => "follow-up-{$followUp->id}",
                    'title' => $followUp->deal?->title ?? $followUp->lead?->name ?? $followUp->client?->name ?? 'Follow-up',
                    'when' => $followUp->due_on?->format('D j M') ?? '',
                    'people' => $this->people([$followUp->owner]),
                    'place' => $followUp->due_on?->isToday() ? 'Due today' : 'Due '.$followUp->due_on?->diffForHumans(),
                    'tag' => Str::headline($followUp->channel ?? 'call'),
                    'url' => '/follow-ups',
                ])->all();
        }

        if ($panels['content'] || $panels['creative']) {
            $deadlines = new Collection;

            if ($panels['content']) {
                $deadlines = $deadlines->concat(
                    $this->scope(Article::query(), $user, 'articles', self::ARTICLE_COLUMNS)
                        ->open()->whereDate('due_on', '>=', now())->with('writer')
                        ->orderBy('due_on')->limit(4)->get()
                        ->map(fn (Article $article) => [
                            'id' => "article-{$article->id}",
                            'title' => $article->title,
                            'due' => $article->due_on,
                            'people' => $this->people([$article->writer]),
                            'place' => $article->stage->label(),
                            'tag' => 'Article',
                            'url' => "/articles/{$article->id}",
                        ]),
                );
            }

            if ($panels['creative']) {
                $deadlines = $deadlines->concat(
                    Deliverable::visibleTo($user)
                        ->open()->whereDate('due_on', '>=', now())->with('assignee')
                        ->orderBy('due_on')->limit(4)->get()
                        ->map(fn (Deliverable $deliverable) => [
                            'id' => "deliverable-{$deliverable->id}",
                            'title' => $deliverable->title ?: $deliverable->kind->label(),
                            'due' => $deliverable->due_on,
                            'people' => $this->people([$deliverable->assignee]),
                            'place' => $deliverable->stage->label(),
                            'tag' => $deliverable->kind->label(),
                            'url' => "/deliverables/{$deliverable->id}",
                        ]),
                );
            }

            $tabs['deadlines'] = $deadlines->sortBy('due')->take(4)->map(function (array $item) {
                /** @var Carbon $due */
                $due = $item['due'];
                unset($item['due']);

                return [...$item, 'when' => 'Due '.$due->format('D j M')];
            })->values()->all();
        }

        return $tabs;
    }

    /**
     * Open leads with a next call on them, soonest first — the call list.
     *
     * @return Builder<Lead>
     */
    private function callList(User $user): Builder
    {
        return Lead::visibleTo($user)->open()->whereNotNull('next_action_on')->orderBy('next_action_on');
    }

    /**
     * Proposals waiting on this person: to approve, to send, or to fix.
     *
     * @return array<string, mixed>|null
     */
    private function proposals(User $user): ?array
    {
        if (! $user->canAny(['proposals.view', 'proposals.view.all'])) {
            return null;
        }

        $mine = fn () => Proposal::whereIn('deal_id', $this->scope(Deal::query(), $user, 'deals', ['owner_id'])->select('id'));

        $toApprove = $user->can('proposals.approve')
            // Never one this person wrote, changed or submitted: they may not approve those.
            ? Proposal::where('status', ProposalStatus::PendingApproval->value)
                ->where('created_by', '!=', $user->id)
                ->where(fn ($q) => $q->whereNull('last_edited_by')->orWhere('last_edited_by', '!=', $user->id))
                ->where(fn ($q) => $q->whereNull('submitted_by')->orWhere('submitted_by', '!=', $user->id))
            : null;

        $items = collect()
            ->concat($toApprove ? (clone $toApprove)->with('deal:id,title')->latest()->limit(3)->get()->map(fn (Proposal $p) => [$p, 'Approve']) : [])
            ->concat($mine()->whereIn('status', [ProposalStatus::Approved->value, ProposalStatus::Rejected->value])->with('deal:id,title')->latest()->limit(3)->get()
                ->map(fn (Proposal $p) => [$p, $p->status === ProposalStatus::Approved ? 'Send' : 'Fix']))
            ->take(4)
            ->map(fn (array $pair) => [
                'id' => $pair[0]->id,
                'title' => $pair[0]->title,
                'deal' => $pair[0]->deal?->title,
                'total' => (float) $pair[0]->total,
                'action' => $pair[1],
            ])->values()->all();

        return [
            'to_approve' => $toApprove ? (clone $toApprove)->count() : null,
            'ready' => $mine()->where('status', ProposalStatus::Approved->value)->count(),
            'sent' => $mine()->where('status', ProposalStatus::Sent->value)->count(),
            'items' => $items,
        ];
    }

    /** "8:00 – 8:45 AM", or "11:30 AM – 12:15 PM" across noon. */
    private function timeRange(Carbon $start, Carbon $end): string
    {
        return $start->format('A') === $end->format('A')
            ? $start->format('g:i').' – '.$end->format('g:i A')
            : $start->format('g:i A').' – '.$end->format('g:i A');
    }

    /**
     * @param  array<int, User|null>  $users
     * @return list<array<string, string|null>>
     */
    private function people(array $users): array
    {
        return (new Collection($users))->filter()->unique('id')
            ->map(fn (User $person) => $this->person($person))->values()->all();
    }

    /** @return array<string, string|null> */
    private function person(User $user): array
    {
        return ['name' => $user->name, 'initials' => $user->initials(), 'avatar_url' => $user->avatarUrl()];
    }

    /** The colour of a status pill, by what the stage means rather than its name. */
    private function stageTone(string $stage): string
    {
        return match ($stage) {
            'conducted', 'handed_over', 'in_content', 'published', 'approved', 'won' => 'success',
            'cancelled', 'no_show', 'rejected', 'lost' => 'danger',
            'scheduled', 'rescheduled', 'internal_review', 'revisions', 'review', 'pending' => 'warning',
            default => 'info',
        };
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    private function scope(Builder $query, User $user, string $module, array $columns): Builder
    {
        return Scope::apply($query, $user, $module, $columns);
    }

    /**
     * "a and b", "a, b and c" — the same phrasing the interview page uses, so
     * the dashboard and the record it links to read alike.
     *
     * @param  list<string>  $items
     */
    private function sentenceList(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    private function greeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}
