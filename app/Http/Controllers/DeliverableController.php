<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Deliverable;
use App\Models\Interview;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Services\StageMachine;
use App\Support\Notifier;
use App\Support\Roles;
use App\Support\Workflow\DeliverableKind;
use App\Support\Workflow\DeliverableStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DeliverableController extends Controller
{
    public function __construct(
        private readonly StageMachine $stages,
        private readonly CodeGenerator $codes,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Deliverable::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'kind' => (string) $request->query('kind', ''),
            'stage' => (string) $request->query('stage', ''),
            'assignee' => (string) $request->query('assignee', ''),
        ];

        $deliverables = $this->scopedQuery($request->user())
            ->with(['assignee:id,name', 'deliverableOf', 'files', 'socialPosts'])
            ->when($filters['search'] !== '', function (Builder $query) use ($filters) {
                $term = "%{$filters['search']}%";

                $query->where(function (Builder $scoped) use ($term) {
                    $scoped->where('title', 'like', $term)->orWhere('code', 'like', $term);
                });
            })
            ->when($filters['kind'] !== '', fn (Builder $q) => $q->where('kind', $filters['kind']))
            ->when($filters['stage'] !== '', fn (Builder $q) => $q->where('stage', $filters['stage']))
            ->when($filters['assignee'] !== '', fn (Builder $q) => $q->where('assigned_to', $filters['assignee']))
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Deliverable $deliverable) => $this->row($deliverable));

        return Inertia::render('Deliverables/Index', [
            'deliverables' => $deliverables,
            'filters' => $filters,
            'kinds' => DeliverableKind::options(),
            'stages' => DeliverableStage::options(),
            'creatives' => $this->creativeOptions(),
            'counts' => $this->counts($request->user()),
        ]);
    }

    /**
     * The board.
     *
     * Grouped by stage rather than listed, because creative work is a pipeline
     * the whole team looks at together — "what is waiting on approval" is a
     * question about the column, not about any one card.
     */
    public function board(Request $request): Response
    {
        $this->authorize('viewAny', Deliverable::class);

        $deliverables = $this->scopedQuery($request->user())
            ->with(['assignee:id,name', 'deliverableOf', 'files', 'socialPosts'])
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->get()
            ->map(fn (Deliverable $deliverable) => $this->row($deliverable));

        // Keyed by stage so the page renders columns without re-filtering, and
        // an empty column still appears rather than silently vanishing.
        $columns = [];

        foreach (DeliverableStage::boardColumns() as $stage) {
            $columns[] = [
                'stage' => $stage->value,
                'label' => $stage->label(),
                'items' => $deliverables->where('stage', $stage->value)->values(),
            ];
        }

        return Inertia::render('Deliverables/Board', [
            'columns' => $columns,
            // Rejected work has no column of its own — it lives with whoever
            // has to fix it — so it is surfaced separately.
            'rejected' => $deliverables->where('stage', DeliverableStage::Rejected->value)->values(),
            'counts' => $this->counts($request->user()),
        ]);
    }

    public function show(Request $request, Deliverable $deliverable): Response
    {
        $this->authorize('view', $deliverable);

        $deliverable->load([
            'assignee:id,name',
            'assigner:id,name',
            'approver:id,name',
            'deliverableOf',
            'files.uploader:id,name',
            'socialPosts.poster:id,name',
            'stageEvents.changedBy:id,name',
        ]);

        return Inertia::render('Deliverables/Show', [
            'deliverable' => [
                ...$this->row($deliverable),
                'brief' => $deliverable->brief,
                'hashtags' => $deliverable->hashtags,
                'rejection_note' => $deliverable->rejection_note,
                'submitted_at' => $deliverable->submitted_at?->toIso8601String(),
                'approved_at' => $deliverable->approved_at?->toIso8601String(),
                'approver' => $deliverable->approver?->only(['id', 'name']),
                'assigner' => $deliverable->assigner?->only(['id', 'name']),
                'missing_for_review' => $deliverable->missingForReview(),

                'files' => $deliverable->files->map(fn ($file) => [
                    'id' => $file->id,
                    'version' => $file->version,
                    'is_link' => $file->isLink(),
                    'url' => $file->target(),
                    'original_filename' => $file->original_filename,
                    'size_bytes' => $file->size_bytes,
                    'duration_seconds' => $file->duration_seconds,
                    'note' => $file->note,
                    'uploader' => $file->uploader?->name,
                    'created_at' => $file->created_at?->toIso8601String(),
                ]),

                'social_posts' => $deliverable->socialPosts->map(fn ($post) => [
                    'id' => $post->id,
                    'platform' => $post->platform,
                    'platform_label' => $post->platformLabel(),
                    'url' => $post->url,
                    'posted_at' => $post->posted_at?->toIso8601String(),
                    'posted_by' => $post->poster?->name,
                ]),

                'history' => $deliverable->stageEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'from' => $event->from_stage,
                    'to' => $event->to_stage,
                    'note' => $event->note,
                    'by' => $event->changedBy?->name,
                    'at' => $event->changed_at?->toIso8601String(),
                ]),
            ],

            'platforms' => SocialPost::PLATFORMS,
            'creatives' => $this->creativeOptions(),

            'can' => [
                'update' => $request->user()->can('update', $deliverable),
                'work' => $request->user()->can('work', $deliverable),
                'submit' => $request->user()->can('submit', $deliverable),
                'approve' => $request->user()->can('approve', $deliverable),
                'publish' => $request->user()->can('publish', $deliverable),
                'assign' => $request->user()->can('assign', $deliverable),
                'recordLink' => $request->user()->can('recordLink', $deliverable),
            ],
        ]);
    }

    /**
     * Commission a piece of work against an interview or an article.
     *
     * The parent is resolved from a type string rather than trusted as a class
     * name: accepting `deliverable_of_type` straight from the request would let
     * anybody attach a deliverable to any model in the application.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Deliverable::class);

        $validated = $request->validate([
            'parent_type' => ['required', Rule::in(['interview', 'article'])],
            'parent_id' => ['required', 'integer'],
            'kind' => ['required', Rule::in(DeliverableKind::values())],
            'title' => ['nullable', 'string', 'max:255'],
            'quantity' => ['integer', 'min:1', 'max:10'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_on' => ['nullable', 'date'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high'])],
            'brief' => ['nullable', 'string', 'max:5000'],
        ]);

        $parent = $validated['parent_type'] === 'interview'
            ? Interview::findOrFail($validated['parent_id'])
            : Article::findOrFail($validated['parent_id']);

        // Whoever commissions the work has to be able to see what it is for.
        $this->authorize('view', $parent);

        $kind = DeliverableKind::from($validated['kind']);
        $quantity = (int) ($validated['quantity'] ?? 1);

        /*
         | "Three reels" is one request, not three trips through the form.
         |
         | The slot number is what makes them distinguishable afterwards — the
         | team says "reel 2", and without it they differ only by created_at,
         | which nobody can see.
         */
        $existing = Deliverable::where('deliverable_of_type', $parent->getMorphClass())
            ->where('deliverable_of_id', $parent->getKey())
            ->where('kind', $kind->value)
            ->max('slot_number') ?? 0;

        DB::transaction(function () use ($request, $parent, $kind, $quantity, $existing, $validated) {
            for ($slot = 1; $slot <= $quantity; $slot++) {
                $deliverable = Deliverable::create([
                    'code' => $this->codes->next('DLV'),
                    'deliverable_of_type' => $parent->getMorphClass(),
                    'deliverable_of_id' => $parent->getKey(),
                    'kind' => $kind,
                    'title' => $validated['title'] ?? null,
                    'slot_number' => $existing + $slot,
                    'stage' => DeliverableStage::Pending,
                    'stage_entered_at' => now(),
                    'assigned_to' => $validated['assigned_to'] ?? null,
                    'assigned_by' => $request->user()->id,
                    'due_on' => $validated['due_on'] ?? null,
                    'priority' => $validated['priority'],
                    'brief' => $validated['brief'] ?? null,
                    'platforms' => $kind->defaultPlatforms(),
                ]);

                $this->stages->recordCreation(
                    $deliverable,
                    $request->user(),
                    'Commissioned from '.($parent->code ?? 'unattached'),
                );
            }

            // The interview's creative owner follows the assignment, so the
            // anchor can see from their own record who is cutting it.
            if ($parent instanceof Interview && filled($validated['assigned_to'] ?? null)) {
                $parent->update(['creative_owner_id' => $validated['assigned_to']]);
            }
        });

        if (filled($validated['assigned_to'] ?? null)) {
            Notifier::send(
                [User::find($validated['assigned_to'])],
                'assigned',
                $quantity === 1
                    ? "New {$kind->label()} to make"
                    : "{$quantity} new {$kind->label()}s to make",
                $validated['brief'] ?? null,
                '/deliverables/board',
                $request->user(),
            );
        }

        return back()->with('success', $quantity === 1 ? 'Commissioned.' : "{$quantity} commissioned.");
    }

    public function update(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('update', $deliverable);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'brief' => ['nullable', 'string', 'max:5000'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'hashtags' => ['nullable', 'string', 'max:1000'],
            'platforms' => ['array'],
            'platforms.*' => [Rule::in(SocialPost::PLATFORMS)],
            'due_on' => ['nullable', 'date'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high'])],
        ]);

        $deliverable->update($validated);

        return back()->with('success', 'Saved.');
    }

    /** Send the current cut for approval. */
    public function submit(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('submit', $deliverable);

        $missing = $deliverable->missingForReview();

        if ($missing !== []) {
            return back()->withErrors([
                'review' => 'Still missing '.$this->sentenceList($missing).'.',
            ]);
        }

        DB::transaction(function () use ($request, $deliverable) {
            $deliverable->forceFill([
                'submitted_at' => now(),
                // The previous round's note has been acted on; leaving it in
                // place makes the next reviewer think it is current.
                'rejection_note' => null,
            ])->save();

            $this->stages->transition($deliverable, DeliverableStage::Review, $request->user());
        });

        Notifier::toPermission(
            'deliverables.approve',
            'review',
            "{$deliverable->code} is ready to approve",
            $deliverable->parentLabel(),
            "/deliverables/{$deliverable->id}",
            $request->user(),
        );

        return back()->with('success', 'Sent for approval.');
    }

    public function approve(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('approve', $deliverable);

        DB::transaction(function () use ($request, $deliverable) {
            $deliverable->forceFill([
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ])->save();

            $this->stages->transition($deliverable, DeliverableStage::Approved, $request->user());
        });

        Notifier::send(
            [$deliverable->assignee],
            'approved',
            "{$deliverable->code} approved",
            'Ready to post.',
            "/deliverables/{$deliverable->id}",
            $request->user(),
        );

        return back()->with('success', 'Approved. Ready to post.');
    }

    public function reject(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('approve', $deliverable);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ], [
            'note.required' => 'Say what needs changing — sending it back with no notes helps nobody.',
        ]);

        DB::transaction(function () use ($request, $deliverable, $validated) {
            $deliverable->forceFill([
                'approved_by' => $request->user()->id,
                'rejection_note' => $validated['note'],
            ])->save();

            $this->stages->transition($deliverable, DeliverableStage::Rejected, $request->user(), $validated['note']);
        });

        Notifier::send(
            [$deliverable->assignee],
            'revisions',
            "{$deliverable->code} needs changes",
            $validated['note'],
            "/deliverables/{$deliverable->id}",
            $request->user(),
        );

        return back()->with('success', 'Sent back.');
    }

    /**
     * Mark it posted, and record where.
     *
     * The link is required for the same reason an article needs its published
     * URL: without it, nobody can tell a posted reel from an approved one that
     * never went out.
     */
    public function publish(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('publish', $deliverable);

        $validated = $request->validate([
            'platform' => ['required', Rule::in(SocialPost::PLATFORMS)],
            'url' => ['required', 'url', 'max:1000'],
            'posted_at' => ['nullable', 'date'],
        ], [
            'url.required' => 'Paste the link to the post — that is what marks this finished.',
        ]);

        $hash = SocialPost::hashFor($validated['platform'], $validated['url']);

        if (SocialPost::where('url_hash', $hash)->exists()) {
            return back()->withErrors([
                'url' => 'That link is already recorded against another deliverable.',
            ]);
        }

        DB::transaction(function () use ($request, $deliverable, $validated) {
            $deliverable->socialPosts()->create([
                'platform' => $validated['platform'],
                'url' => $validated['url'],
                'posted_at' => $validated['posted_at'] ?? now(),
                'posted_by' => $request->user()->id,
            ]);

            $deliverable->forceFill(['published_at' => $validated['posted_at'] ?? now()])->save();

            $this->stages->transition(
                $deliverable,
                DeliverableStage::Published,
                $request->user(),
                $validated['url'],
            );
        });

        return back()->with('success', 'Recorded. Nice work.');
    }

    /** An extra link for something already published — the same reel cross-posted. */
    public function recordLink(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('recordLink', $deliverable);

        $validated = $request->validate([
            'platform' => ['required', Rule::in(SocialPost::PLATFORMS)],
            'url' => ['required', 'url', 'max:1000'],
        ]);

        $hash = SocialPost::hashFor($validated['platform'], $validated['url']);

        if (SocialPost::where('url_hash', $hash)->exists()) {
            return back()->withErrors(['url' => 'That link is already recorded.']);
        }

        $deliverable->socialPosts()->create([
            'platform' => $validated['platform'],
            'url' => $validated['url'],
            'posted_at' => now(),
            'posted_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Link recorded.');
    }

    public function assign(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('assign', $deliverable);

        $validated = $request->validate([
            'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $deliverable->update(['assigned_to' => $validated['assigned_to']]);

        $parent = $deliverable->deliverableOf;

        if ($parent instanceof Interview) {
            $parent->update(['creative_owner_id' => $validated['assigned_to']]);
        }

        return back()->with('success', 'Assigned.');
    }

    /**
     * What this person may see.
     *
     * Mirrors DeliverablePolicy::view() exactly, including the parent-interview
     * clause. That second half is what puts a manager's own commissioned work on
     * their board: they are neither the assignee nor the assigner, but they
     * booked the interview it came from — and the policy already lets them open
     * it, so a list that hid it would be the two disagreeing.
     *
     * @return Builder<Deliverable>
     */
    private function scopedQuery(User $user): Builder
    {
        return Deliverable::visibleTo($user);
    }

    /**
     * @return array<string, int>
     */
    private function counts(User $user): array
    {
        return [
            'mine' => (clone $this->scopedQuery($user))
                ->where('assigned_to', $user->id)
                ->open()
                ->count(),
            'inReview' => (clone $this->scopedQuery($user))
                ->where('stage', DeliverableStage::Review->value)
                ->count(),
            'awaitingPublish' => (clone $this->scopedQuery($user))->awaitingPublish()->count(),
            'publishedThisMonth' => (clone $this->scopedQuery($user))
                ->whereBetween('published_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    private function creativeOptions(): Collection
    {
        return User::query()
            ->active()
            ->role(Roles::CREATIVE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);
    }

    /**
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

    /**
     * @return array<string, mixed>
     */
    private function row(Deliverable $deliverable): array
    {
        $file = $deliverable->files->first();

        return [
            'id' => $deliverable->id,
            'code' => $deliverable->code,
            'kind' => $deliverable->kind->value,
            'kind_label' => $deliverable->kind->label(),
            'is_video' => $deliverable->kind->isVideo(),
            'title' => $deliverable->title,
            'slot_number' => $deliverable->slot_number,
            'stage' => $deliverable->stage->value,
            'stage_label' => $deliverable->stage->label(),
            'assignee' => $deliverable->assignee?->only(['id', 'name']),
            'due_on' => $deliverable->due_on?->toDateString(),
            'priority' => $deliverable->priority,
            'caption' => $deliverable->caption,
            'platforms' => $deliverable->platforms ?? [],
            'parent_label' => $deliverable->parentLabel(),
            'parent_code' => $deliverable->parentCode(),
            'parent_kind' => $deliverable->deliverable_of_type === Interview::class ? 'interview' : 'article',
            'parent_id' => $deliverable->deliverable_of_id,
            'file_count' => $deliverable->files->count(),
            'current_version' => $file?->version,
            'has_social_link' => $deliverable->socialPosts->isNotEmpty(),
            'social_url' => $deliverable->socialPosts->first()?->url,
            'published_at' => $deliverable->published_at?->toIso8601String(),
        ];
    }
}
