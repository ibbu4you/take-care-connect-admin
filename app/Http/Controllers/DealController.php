<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Deal;
use App\Models\FollowUp;
use App\Models\Target;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Services\DealStageRules;
use App\Services\StageMachine;
use App\Support\Roles;
use App\Support\Scope;
use App\Support\Workflow\ActivityType;
use App\Support\Workflow\PipelineStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DealController extends Controller
{
    public function __construct(
        private readonly StageMachine $stages,
        private readonly CodeGenerator $codes,
        private readonly DealStageRules $rules,
    ) {}

    /**
     * The pipeline board.
     *
     * Columns rather than a list, because a pipeline is a shape: the useful
     * question is "how much is stuck at proposal", which is about the column,
     * not any one card.
     */
    public function board(Request $request): Response
    {
        $this->authorize('viewAny', Deal::class);

        $user = $request->user();

        $deals = $this->scopedQuery($user)
            ->with(['client:id,name,city', 'owner:id,name'])
            ->open()
            ->orderBy('board_order')
            ->orderByDesc('expected_value')
            ->get();

        $columns = [];

        foreach (PipelineStage::boardColumns() as $stage) {
            $inStage = $deals->where('stage', $stage);

            $columns[] = [
                'stage' => $stage->value,
                'label' => $stage->label(),
                'items' => $inStage->map(fn (Deal $deal) => $this->row($deal))->values(),
                // Per-column totals: the whole reason to look at a board rather
                // than a list is to see where the money is sitting.
                'total' => (float) $inStage->sum('expected_value'),
                'weighted' => round($inStage->sum(fn (Deal $deal) => $deal->weightedValue()), 2),
            ];
        }

        return Inertia::render('Pipeline/Board', [
            'columns' => $columns,
            'summary' => $this->summary($user),
            'stages' => PipelineStage::options(),
            'clients' => $this->clientOptions($user),
            'owners' => $this->managerOptions(),
            'can' => [
                'create' => $user->can('deals.create'),
                'move' => $user->can('deals.move'),
                'close' => $user->can('deals.close'),
            ],
        ]);
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Deal::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'stage' => (string) $request->query('stage', ''),
            'owner' => (string) $request->query('owner', ''),
            'outcome' => (string) $request->query('outcome', ''),
        ];

        $deals = $this->scopedQuery($request->user())
            ->with(['client:id,name', 'owner:id,name'])
            ->when($filters['search'] !== '', function (Builder $query) use ($filters) {
                $term = "%{$filters['search']}%";

                $query->where(function (Builder $scoped) use ($term) {
                    $scoped->where('title', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', $term));
                });
            })
            ->when($filters['stage'] !== '', fn (Builder $q) => $q->where('stage', $filters['stage']))
            ->when($filters['owner'] !== '', fn (Builder $q) => $q->where('owner_id', $filters['owner']))
            ->when($filters['outcome'] === 'open', fn (Builder $q) => $q->open())
            ->when(
                in_array($filters['outcome'], ['won', 'lost'], true),
                fn (Builder $q) => $q->where('outcome', $filters['outcome']),
            )
            ->orderByDesc('last_activity_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Deal $deal) => $this->row($deal));

        return Inertia::render('Pipeline/Index', [
            'deals' => $deals,
            'filters' => $filters,
            'stages' => PipelineStage::options(),
            'owners' => $this->managerOptions(),
            // The list is the landing view for Sales, so it carries everything
            // needed to open a deal — otherwise adding one means a detour
            // through the board and back.
            'clients' => $this->clientOptions($request->user()),
            'summary' => $this->summary($request->user()),
            'can' => ['create' => $request->user()->can('deals.create')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Deal::class);

        $validated = $request->validate([
            /*
             | Checked against the clients this person can see, not just every
             | client that exists. Otherwise opening a deal against somebody
             | else's client id put that client's phone and email on the new
             | deal page — a way round the client list's own scoping.
             */
            'client_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                if (! Scope::apply(Client::query(), $request->user(), 'clients', ['owner_id', 'created_by'])->whereKey($value)->exists()) {
                    $fail('Choose one of your clients.');
                }
            }],
            'title' => ['required', 'string', 'max:255'],
            'stage' => ['required', Rule::in(array_map(
                fn (PipelineStage $stage) => $stage->value,
                PipelineStage::boardColumns(),
            ))],
            'expected_value' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'expected_close_on' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:24'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $deal = DB::transaction(function () use ($request, $validated) {
            $deal = Deal::create([
                ...$validated,
                'code' => $this->codes->next('DEA'),
                'owner_id' => $request->user()->id,
                'stage_entered_at' => now(),
                'last_activity_at' => now(),
                'source' => $validated['source'] ?? 'manual',
            ]);

            $this->stages->recordCreation($deal, $request->user(), 'Deal opened');

            return $deal;
        });

        return back()->with('success', "{$deal->code} opened.");
    }

    public function show(Request $request, Deal $deal): Response
    {
        $this->authorize('view', $deal);

        $deal->load([
            'client:id,name,city,contact_person,phone,email',
            'owner:id,name',
            'project:id,code,title',
            'followUps.owner:id,name',
            'timeline.author:id,name',
            'stageEvents.changedBy:id,name',
            'activities.author:id,name',
            'proposals.creator:id,name',
        ]);

        return Inertia::render('Pipeline/Show', [
            'deal' => [
                ...$this->row($deal),
                'notes' => $deal->notes,
                'lost_reason' => $deal->lost_reason,
                'source' => $deal->source,
                'project' => $deal->project?->only(['id', 'code', 'title']),
                'client_detail' => $deal->client?->only(['id', 'name', 'city', 'contact_person', 'phone', 'email']),

                'follow_ups' => $deal->followUps->map(fn ($followUp) => [
                    'id' => $followUp->id,
                    'due_on' => $followUp->due_on?->toDateString(),
                    'channel' => $followUp->channel,
                    'note' => $followUp->note,
                    'state' => $followUp->state,
                    'is_overdue' => $followUp->isOverdue(),
                    'owner' => $followUp->owner?->name,
                ]),

                'timeline' => $deal->timeline->map(fn ($note) => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'author' => $note->author?->name,
                    'at' => $note->created_at?->toIso8601String(),
                ]),

                'activities' => $deal->activities->map(fn ($activity) => [
                    'id' => $activity->id,
                    'type' => $activity->type->value,
                    'type_label' => $activity->type->label(),
                    'outcome' => $activity->outcome,
                    'outcome_label' => $activity->outcomeLabel(),
                    'note' => $activity->note,
                    'author' => $activity->author?->name,
                    'at' => $activity->happened_at?->toIso8601String(),
                ]),

                'proposals' => $deal->proposals->map(fn ($proposal) => [
                    'id' => $proposal->id,
                    'code' => $proposal->code,
                    'version' => $proposal->version,
                    'title' => $proposal->title,
                    'status' => $proposal->status->value,
                    'status_label' => $proposal->status->label(),
                    'status_tone' => $proposal->status->tone(),
                    'total' => (float) $proposal->total,
                    'discount_percent' => (float) $proposal->discount_percent,
                    'created_by' => $proposal->creator?->name,
                    'updated_at' => $proposal->updated_at?->toIso8601String(),
                ]),

                'history' => $deal->stageEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'from' => $event->from_stage,
                    'to' => $event->to_stage,
                    'note' => $event->note,
                    'by' => $event->changedBy?->name,
                    'at' => $event->changed_at?->toIso8601String(),
                ]),
            ],

            'stages' => PipelineStage::options(),
            'channels' => FollowUp::CHANNELS,
            'activityTypes' => ActivityType::options(),

            'can' => [
                'update' => $request->user()->can('update', $deal),
                'move' => $request->user()->can('move', $deal),
                'close' => $request->user()->can('close', $deal),
                'propose' => $request->user()->can('proposals.create') && $request->user()->can('view', $deal),
            ],
        ]);
    }

    public function update(Request $request, Deal $deal): RedirectResponse
    {
        $this->authorize('update', $deal);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'expected_value' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'expected_close_on' => ['nullable', 'date'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $deal->update([...$validated, 'last_activity_at' => now()]);

        return back()->with('success', 'Saved.');
    }

    /**
     * Drag a card to another column.
     *
     * A separate permission from `deals.update`: somebody can be given the board
     * to look at without being able to move what is on it.
     */
    public function move(Request $request, Deal $deal): RedirectResponse
    {
        $this->authorize('move', $deal);

        $validated = $request->validate([
            'stage' => ['required', Rule::in(array_map(
                fn (PipelineStage $stage) => $stage->value,
                PipelineStage::boardColumns(),
            ))],
            // The card's new position within its column, as the board saw it.
            'board_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $target = PipelineStage::from($validated['stage']);

        // A stage is a claim about what has happened; it cannot be claimed early.
        $missing = $deal->stage === $target ? [] : $this->rules->missing($deal, $target);

        if ($missing !== []) {
            return back()->with('error', $this->rules->explain($target, $missing));
        }

        DB::transaction(function () use ($request, $deal, $target, $validated) {
            $deal->forceFill([
                'board_order' => $validated['board_order'] ?? $deal->board_order,
                'last_activity_at' => now(),
            ])->save();

            if ($deal->stage !== $target) {
                $this->stages->transition($deal, $target, $request->user());
            }
        });

        return back()->with('success', "Moved to {$target->label()}.");
    }

    /**
     * Close a deal.
     *
     * Separate again from moving, because this is the one action that writes
     * revenue — and the figure it writes is what every attainment report is a
     * sum of.
     */
    public function close(Request $request, Deal $deal): RedirectResponse
    {
        $this->authorize('close', $deal);

        $validated = $request->validate([
            'outcome' => ['required', Rule::in(['won', 'lost'])],
            // Required on a win: attainment is a sum of what was actually
            // signed, not of what somebody hoped for six weeks ago.
            'actual_value' => ['required_if:outcome,won', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'lost_reason' => ['required_if:outcome,lost', 'nullable', 'string', 'max:160'],
            /*
             | Never in the future, and only somebody who sees the whole team can
             | date a close into an earlier month. "Won this month" is reported
             | and paid on; letting anybody backdate a win moved revenue and
             | incentives between months after they had been read.
             */
            'closed_at' => array_values(array_filter([
                'nullable',
                'date',
                'before_or_equal:today',
                $request->user()->can('deals.view.all') ? null : 'after_or_equal:'.now()->startOfMonth()->toDateString(),
            ])),
        ], [
            'closed_at.before_or_equal' => 'A deal cannot be closed in the future.',
            'closed_at.after_or_equal' => 'Only a sales manager can date a close into an earlier month.',
            'actual_value.required_if' => 'Enter what was actually signed.',
            'lost_reason.required_if' => 'Say why it was lost — that is the part worth reading later.',
        ]);

        $outcome = PipelineStage::from($validated['outcome']);

        DB::transaction(function () use ($request, $deal, $outcome, $validated) {
            $deal->forceFill([
                'outcome' => $outcome->value,
                'actual_value' => $outcome === PipelineStage::Won ? $validated['actual_value'] : null,
                'lost_reason' => $outcome === PipelineStage::Lost ? $validated['lost_reason'] : null,
                'closed_at' => $validated['closed_at'] ?? now(),
                'last_activity_at' => now(),
            ])->save();

            $this->stages->transition(
                $deal,
                $outcome,
                $request->user(),
                $validated['lost_reason'] ?? null,
            );
        });

        return back()->with(
            'success',
            $outcome === PipelineStage::Won ? 'Won. Nice work.' : 'Closed as lost.',
        );
    }

    /** A note on the deal's timeline, which also counts as activity. */
    public function addNote(Request $request, Deal $deal): RedirectResponse
    {
        $this->authorize('update', $deal);

        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $deal->timeline()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        $deal->forceFill(['last_activity_at' => now()])->save();

        return back()->with('success', 'Noted.');
    }

    /**
     * The figures above the board.
     *
     * @return array<string, mixed>
     */
    private function summary(User $user): array
    {
        $open = (clone $this->scopedQuery($user))->open()->get();
        $wonThisMonth = (clone $this->scopedQuery($user))
            ->won()
            ->whereBetween('closed_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('actual_value');

        $target = Target::where('user_id', $user->id)
            ->where('period', Target::currentPeriod())
            ->value('revenue_target');

        return [
            'open_count' => $open->count(),
            'pipeline_value' => round($open->sum(fn (Deal $deal) => (float) $deal->expected_value), 2),
            'weighted_value' => round($open->sum(fn (Deal $deal) => $deal->weightedValue()), 2),
            'won_this_month' => (float) $wonThisMonth,
            'revenue_target' => $target !== null ? (float) $target : null,
            // Guarded: a target of zero would divide by nothing, and "0%
            // attained" against no target is a meaningless red bar.
            'attainment' => $target > 0 ? round(((float) $wonThisMonth / (float) $target) * 100) : null,
            'idle_count' => (clone $this->scopedQuery($user))->idle()->count(),
        ];
    }

    /**
     * @return Builder<Deal>
     */
    private function scopedQuery(User $user): Builder
    {
        return Scope::apply(Deal::query(), $user, 'deals', ['owner_id']);
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    private function clientOptions(User $user): Collection
    {
        return Scope::apply(Client::query(), $user, 'clients', ['owner_id', 'created_by'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name]);
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    private function managerOptions(): Collection
    {
        return User::query()
            ->active()
            // Everybody who can own a deal.
            ->role([Roles::RELATIONSHIP_MANAGER, Roles::SALES_EXECUTIVE, Roles::SALES_MANAGER])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Deal $deal): array
    {
        return [
            'id' => $deal->id,
            'code' => $deal->code,
            'title' => $deal->title,
            'stage' => $deal->stage->value,
            'stage_label' => $deal->stage->label(),
            'client' => $deal->client?->only(['id', 'name', 'city']),
            'owner' => $deal->owner?->only(['id', 'name']),
            'expected_value' => (float) $deal->expected_value,
            'actual_value' => $deal->actual_value !== null ? (float) $deal->actual_value : null,
            'weighted_value' => $deal->weightedValue(),
            'probability' => $deal->probability,
            'expected_close_on' => $deal->expected_close_on?->toDateString(),
            'closed_at' => $deal->closed_at?->toIso8601String(),
            'outcome' => $deal->outcome,
            'board_order' => $deal->board_order,
            'idle_days' => $deal->idleDays(),
            'is_idle' => $deal->isIdle(),
            'last_activity_at' => $deal->last_activity_at?->toIso8601String(),
            'next_step' => $deal->next_step,
            'next_step_on' => $deal->next_step_on?->toDateString(),
            'next_step_overdue' => $deal->nextStepOverdue(),
        ];
    }
}
