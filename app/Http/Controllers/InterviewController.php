<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConductInterviewRequest;
use App\Http\Requests\StoreInterviewRequest;
use App\Models\Article;
use App\Models\Client;
use App\Models\Deliverable;
use App\Models\Interview;
use App\Models\Project;
use App\Models\SocialPost;
use App\Models\User;
use App\Rules\ActiveMember;
use App\Services\CodeGenerator;
use App\Services\HandoverService;
use App\Services\StageMachine;
use App\Support\Notifier;
use App\Support\Roles;
use App\Support\Scope;
use App\Support\Workflow\ArticleStage;
use App\Support\Workflow\DeliverableKind;
use App\Support\Workflow\DeliverableStage;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use App\Support\Workflow\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InterviewController extends Controller
{
    public function __construct(
        private readonly StageMachine $stages,
        private readonly HandoverService $handovers,
        private readonly CodeGenerator $codes,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Interview::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'kind' => (string) $request->query('kind', ''),
            'stage' => (string) $request->query('stage', ''),
            'anchor' => (string) $request->query('anchor', ''),
            'mine' => $request->boolean('mine'),
        ];

        $interviews = $this->scopedQuery($request->user())
            ->with(['anchor:id,name', 'contentOwner:id,name', 'client:id,name'])
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $term = "%{$filters['search']}%";

                // Grouped so the OR-chain cannot escape and widen the other
                // filters applied alongside it.
                $query->where(function ($scoped) use ($term) {
                    $scoped->where('title', 'like', $term)
                        ->orWhere('subject_name', 'like', $term)
                        ->orWhere('subject_company', 'like', $term)
                        ->orWhere('code', 'like', $term);
                });
            })
            ->when($filters['kind'] !== '', fn ($query) => $query->where('kind', $filters['kind']))
            ->when($filters['stage'] !== '', fn ($query) => $query->where('stage', $filters['stage']))
            ->when($filters['anchor'] !== '', fn ($query) => $query->where('anchor_id', $filters['anchor']))
            ->when($filters['mine'], fn ($query) => $query->where('anchor_id', $request->user()->id))
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at', 'desc')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Interview $interview) => $this->row($interview, $request->user()));

        return Inertia::render('Interviews/Index', [
            'interviews' => $interviews,
            'filters' => $filters,
            'kinds' => InterviewKind::options(),
            'stages' => InterviewStage::options(),
            'anchors' => $this->anchorOptions(),
            'counts' => $this->counts($request->user()),
            'can' => [
                'create' => $request->user()->can('create', Interview::class),
            ],
        ]);
    }

    /**
     * The anchor's own diary.
     *
     * A separate page rather than a filter on the list, because it answers a
     * different question — not "what interviews exist" but "what do I have to
     * do today", which wants a different ordering and a different emphasis.
     */
    public function myQueue(Request $request): Response
    {
        $this->authorize('viewAny', Interview::class);

        $user = $request->user();

        $mine = Interview::query()
            ->where('anchor_id', $user->id)
            ->open()
            ->with(['client:id,name'])
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Interview $interview) => $this->row($interview, $user));

        return Inertia::render('Interviews/MyQueue', [
            // Still to happen — not "anything except conducted", which also
            // counted handed-over, with-content and no-show interviews.
            'upcoming' => $mine->filter(fn (array $row) => in_array($row['stage'], [
                InterviewStage::Scheduled->value, InterviewStage::Rescheduled->value,
            ], true))->values(),
            'toFinish' => $mine->filter(fn (array $row) => $row['stage'] === InterviewStage::Conducted->value)->values(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Interview::class);

        /*
         | Most bookings start from a client or a project page rather than from
         | a blank form, so both arrive as query parameters and are filled in.
         | A project implies its client, which saves choosing the same thing
         | twice and saves choosing them inconsistently.
         */
        $project = $request->filled('project')
            ? Project::visibleTo($request->user())->find($request->integer('project'))
            : null;

        return Inertia::render('Interviews/Form', [
            'interview' => null,
            'preselected' => [
                'client_id' => $project?->client_id ?? $request->query('client'),
                'project_id' => $project?->getKey(),
            ],
            ...$this->formOptions($request),
        ]);
    }

    public function store(StoreInterviewRequest $request): RedirectResponse
    {
        $interview = DB::transaction(function () use ($request) {
            $kind = InterviewKind::from($request->string('kind')->toString());

            $interview = Interview::create([
                ...$request->safe()->except(['kind']),
                'kind' => $kind,
                'code' => $this->codes->next('INT'),
                'scheduled_by' => $request->user()->id,
                'stage' => InterviewStage::Scheduled,
                'stage_entered_at' => now(),
                'requires_social' => true,
            ]);

            $this->stages->recordCreation($interview, $request->user(), 'Interview booked');

            // The anchor's assignment is recorded from the start, so the history
            // reads as a chain from the moment it was booked rather than
            // beginning at the first reassignment.
            $this->handovers->assign(
                $interview,
                User::findOrFail($request->integer('anchor_id')),
                $request->user(),
                'anchor',
            );

            return $interview;
        });

        Notifier::send(
            [$interview->anchor],
            'assigned',
            "New interview: {$interview->title}",
            $interview->scheduled_at?->format('D j M, g:ia'),
            "/interviews/{$interview->id}",
            $request->user(),
        );

        return redirect()
            ->route('interviews.show', $interview)
            ->with('success', "{$interview->code} booked.");
    }

    public function show(Request $request, Interview $interview): Response
    {
        $this->authorize('view', $interview);

        $interview->load([
            'anchor:id,name,job_title',
            'scheduler:id,name',
            'contentOwner:id,name',
            'creativeOwner:id,name',
            'client:id,name',
            'project:id,code,title',
            'assets.uploader:id,name',
            'stageEvents.changedBy:id,name',
        ]);

        return Inertia::render('Interviews/Show', [
            'interview' => [
                ...$this->row($interview, $request->user()),
                'subject_designation' => $interview->subject_designation,
                'subject_company' => $interview->subject_company,
                'subject_phone' => $interview->subject_phone,
                'subject_email' => $interview->subject_email,
                'mode' => $interview->mode,
                'location' => $interview->location,
                'meeting_url' => $interview->meeting_url,
                'recording_url' => $interview->recording_url,
                'duration_minutes' => $interview->duration_minutes,
                'qa_summary' => $interview->qa_summary,
                'language' => $interview->language,
                'consent_to_publish' => $interview->consent_to_publish,
                'notes' => $interview->notes,
                'cancelled_reason' => $interview->cancelled_reason,
                'conducted_at' => $interview->conducted_at?->toIso8601String(),
                'handed_over_at' => $interview->handed_over_at?->toIso8601String(),
                'scheduler' => $interview->scheduler?->only(['id', 'name']),
                'content_owner' => $interview->contentOwner?->only(['id', 'name']),
                'creative_owner' => $interview->creativeOwner?->only(['id', 'name']),
                'project' => $interview->project?->only(['id', 'code', 'title']),

                'assets' => $interview->assets->map(fn ($asset) => [
                    'id' => $asset->id,
                    'kind' => $asset->kind,
                    'label' => $asset->label,
                    'is_link' => $asset->isLink(),
                    'url' => $asset->target(),
                    'original_filename' => $asset->original_filename,
                    'size_bytes' => $asset->size_bytes,
                    'uploader' => $asset->uploader?->name,
                    'created_at' => $asset->created_at?->toIso8601String(),
                ]),

                'history' => $interview->stageEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'from' => $event->from_stage,
                    'to' => $event->to_stage,
                    'note' => $event->note,
                    'by' => $event->changedBy?->name,
                    'at' => $event->changed_at?->toIso8601String(),
                ]),

                // What is still missing before this can be handed over, computed
                // server-side so the page never has to re-derive the rule.
                'missing_for_handover' => $interview->missingForHandover(),

                // Which WhatsApp messages the business has had, and from whom.
                'messages' => $this->messagesSent($interview),

                // The public link to send once their story is out: the article
                // for a business interview, otherwise the first social post.
                'story_url' => Article::where('interview_id', $interview->id)
                    ->whereNotNull('published_url')
                    ->value('published_url')
                    ?? SocialPost::where('interview_id', $interview->id)
                        ->orWhereIn('deliverable_id', $interview->deliverables()->pluck('id'))
                        ->value('url'),
            ],

            'writers' => $this->writerOptions(),
            'anchors' => $this->anchorOptions(),

            // Everything the commission dialog needs, so creative work can be
            // ordered straight off the interview it comes from. For the two
            // social kinds this is the only route their output has.
            'deliverableKinds' => DeliverableKind::options(),
            'creatives' => $this->creativeOptions(),
            'deliverables' => $interview->deliverables()
                ->with('assignee:id,name')
                ->get()
                ->map(fn ($deliverable) => [
                    'id' => $deliverable->id,
                    'code' => $deliverable->code,
                    'kind_label' => $deliverable->kind->label(),
                    'slot_number' => $deliverable->slot_number,
                    'stage' => $deliverable->stage->value,
                    'assignee' => $deliverable->assignee?->only(['id', 'name']),
                    'social_url' => $deliverable->socialPosts()->value('url'),
                ]),

            'can' => [
                'update' => $request->user()->can('update', $interview),
                'commission' => $request->user()->can('create', Deliverable::class),
                'conduct' => $request->user()->can('conduct', $interview),
                'handover' => $request->user()->can('handover', $interview),
                'reassign' => $request->user()->can('reassign', $interview),
                'cancel' => $request->user()->can('cancel', $interview),
                'upload' => $request->user()->can('uploadAssets', $interview),
                'deleteAsset' => $request->user()->can('deleteAsset', $interview),
                'message' => $request->user()->can('schedule', $interview) || $request->user()->can('update', $interview),
                'reschedule' => $request->user()->can('schedule', $interview)
                    && in_array(InterviewStage::Rescheduled, $interview->allowedNextStages(), true),
                // A social interview has no article to publish it, so it is
                // marked published here once its posts are out.
                'publish' => $request->user()->can('update', $interview)
                    && $interview->stage === InterviewStage::Conducted
                    && ! $interview->kind->requiresArticle(),
            ],
        ]);
    }

    /**
     * @return array<string, array{at: string, by: string|null}|null>
     */
    private function messagesSent(Interview $interview): array
    {
        $sent = [];

        foreach (['confirmation', 'reminder', 'story'] as $message) {
            $at = $interview->{"{$message}_sent_at"};

            $sent[$message] = $at === null ? null : [
                'at' => $at->toIso8601String(),
                'by' => User::whereKey($interview->{"{$message}_sent_by"})->value('name'),
            ];
        }

        return $sent;
    }

    /** Mark conducted and record the context around it. */
    public function conduct(ConductInterviewRequest $request, Interview $interview): RedirectResponse
    {
        DB::transaction(function () use ($request, $interview) {
            $interview->fill($request->safe()->only([
                'qa_summary', 'recording_url', 'language', 'notes',
            ]));

            $interview->consent_to_publish = $request->boolean('consent_to_publish');
            // Editing the notes later keeps the time it happened. Falling back
            // to "now" on every save moved the conducted date each time.
            $interview->conducted_at = $request->date('conducted_at') ?? $interview->conducted_at ?? now();
            $interview->save();

            // Only move the stage if it has not already been moved — saving the
            // Q&A again on an already-conducted interview is an edit, not a
            // second conducting.
            if ($interview->stage !== InterviewStage::Conducted) {
                $this->stages->transition(
                    $interview,
                    InterviewStage::Conducted,
                    $request->user(),
                    null,
                    $interview->allowedNextStages(),
                );
            }
        });

        $interview->refresh();

        $message = $interview->kind->requiresArticle() && ! $interview->isReadyForHandover()
            ? 'Saved. Still missing '.$this->sentenceList($interview->missingForHandover()).' before handover.'
            : 'Saved.';

        return back()->with('success', $message);
    }

    public function handover(Request $request, Interview $interview): RedirectResponse
    {
        $this->authorize('handover', $interview);

        $validated = $request->validate([
            'content_owner_id' => ['nullable', 'integer', ActiveMember::content()],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->handovers->handOver(
            $interview,
            $request->user(),
            isset($validated['content_owner_id']) ? User::find($validated['content_owner_id']) : null,
            $validated['note'] ?? null,
        );

        return back()->with('success', "{$interview->code} handed to the content team.");
    }

    public function reassign(Request $request, Interview $interview): RedirectResponse
    {
        $this->authorize('reassign', $interview);

        $validated = $request->validate([
            'role' => ['required', Rule::in(['anchor', 'content', 'creative'])],
            'assigned_to' => ['required', 'integer', match ($request->input('role')) {
                'content' => ActiveMember::content(),
                'creative' => ActiveMember::creative(),
                default => ActiveMember::anchor(),
            }],
            'brief' => ['nullable', 'string', 'max:1000'],
        ]);

        // The byline of a published article is a public record.
        if ($validated['role'] === 'content' && Article::where('interview_id', $interview->id)->where('stage', ArticleStage::Published->value)->exists()) {
            throw ValidationException::withMessages([
                'assigned_to' => 'The article is already published under its writer.',
            ]);
        }

        $this->handovers->assign(
            $interview,
            User::findOrFail($validated['assigned_to']),
            $request->user(),
            $validated['role'],
            $validated['brief'] ?? null,
        );

        return back()->with('success', 'Reassigned.');
    }

    /** Cancel, or record that the subject never turned up. */
    public function cancel(Request $request, Interview $interview): RedirectResponse
    {
        $this->authorize('cancel', $interview);

        $validated = $request->validate([
            'stage' => ['required', Rule::in([InterviewStage::Cancelled->value, InterviewStage::NoShow->value])],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        // The reason is saved only once the move has gone through: a refused
        // cancel used to leave a reason on an interview that was still on.
        DB::transaction(function () use ($interview, $validated, $request) {
            $this->stages->transition(
                $interview,
                InterviewStage::from($validated['stage']),
                $request->user(),
                $validated['reason'],
                $interview->allowedNextStages(),
            );

            $interview->update(['cancelled_reason' => $validated['reason']]);

            // Pieces nobody has started yet go with it, rather than sitting in
            // Pending for ever. Anything with work uploaded is left for a person
            // to decide about.
            $interview->deliverables()
                ->where('stage', DeliverableStage::Pending->value)
                ->whereDoesntHave('files')
                ->get()
                ->each->delete();
        });

        return back()->with('success', 'Updated.');
    }

    /**
     * The two moves that have no action of their own.
     *
     *   rescheduled  a new date, from scheduled, rescheduled or no-show
     *   published    a social interview, which has no article to publish it
     *
     * Everything else has a dedicated route that carries its own rules —
     * conduct needs the date it happened, handover needs the transcript and a
     * writer, cancel needs the permission and a reason, and "with content" and
     * a business interview's "published" follow the article. Accepting them
     * here as well let any of those rules be stepped around.
     */
    public function moveStage(Request $request, Interview $interview): RedirectResponse
    {
        // Rescheduling is the booking manager's job; publishing is the team's.
        $this->authorize(
            $request->input('stage') === InterviewStage::Rescheduled->value ? 'schedule' : 'update',
            $interview,
        );

        $validated = $request->validate([
            'stage' => ['required', Rule::in([InterviewStage::Rescheduled->value, InterviewStage::Published->value])],
            'scheduled_at' => [
                Rule::requiredIf($request->input('stage') === InterviewStage::Rescheduled->value),
                'nullable', 'date', 'after:now',
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'stage.in' => 'That move has its own button — use it, so its checks run.',
            'scheduled_at.required' => 'Pick the new date and time.',
            'scheduled_at.after' => 'The new date has to be in the future.',
        ]);

        $target = InterviewStage::from($validated['stage']);

        if ($target === InterviewStage::Published && $interview->kind->requiresArticle()) {
            throw ValidationException::withMessages([
                'stage' => 'This interview is published when its article is.',
            ]);
        }

        DB::transaction(function () use ($interview, $target, $validated, $request) {
            $this->stages->transition(
                $interview,
                $target,
                $request->user(),
                $validated['note'] ?? null,
                $interview->allowedNextStages(),
            );

            if ($target === InterviewStage::Rescheduled) {
                // A new date starts clean: last time's no-show reason belongs
                // to last time.
                $interview->update([
                    'scheduled_at' => $validated['scheduled_at'],
                    'cancelled_reason' => null,
                ]);
            }
        });

        return back()->with('success', $target === InterviewStage::Rescheduled ? 'Rescheduled.' : 'Marked published.');
    }

    /**
     * "a and b", "a, b and c" — the same phrasing HandoverService uses, so the
     * toast and the refusal message read alike.
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

    /**
     * Only interviews this person is on, unless they hold `.all`.
     *
     * @return Builder<Interview>
     */
    private function scopedQuery(User $user)
    {
        return Scope::apply(Interview::query(), $user, 'interviews', [
            'anchor_id',
            'scheduled_by',
            'content_owner_id',
            'creative_owner_id',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function counts(User $user): array
    {
        return [
            'thisWeek' => (clone $this->scopedQuery($user))
                ->whereBetween('scheduled_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->open()
                ->count(),
            'awaitingHandover' => (clone $this->scopedQuery($user))->awaitingHandover()->count(),
            'conductedThisMonth' => (clone $this->scopedQuery($user))
                ->whereBetween('conducted_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'open' => (clone $this->scopedQuery($user))->open()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function formOptions(Request $request): array
    {
        return [
            'kinds' => InterviewKind::options(),
            'anchors' => $this->anchorOptions(),

            /*
             | Scoped, not every client in the database.
             |
             | A manager who can only see their own book must not be offered
             | everybody else's in a dropdown — that is a directory leak wearing
             | a picker, and it also lets them file work against an account they
             | cannot then open.
             */
            'clients' => Client::visibleTo($request->user())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name])
                ->values(),

            // Carries client_id so the form can narrow the list to the client
            // that has been chosen, rather than offering every package sold.
            'projects' => Project::visibleTo($request->user())
                ->whereIn('status', [ProjectStatus::Open->value, ProjectStatus::Delivered->value])
                ->orderBy('title')
                ->get(['id', 'code', 'title', 'client_id'])
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'code' => $project->code,
                    'title' => $project->title,
                    'client_id' => $project->client_id,
                ])
                ->values(),
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    private function anchorOptions()
    {
        return User::query()
            ->active()
            ->role(Roles::ANCHOR)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    private function writerOptions()
    {
        return User::query()
            ->active()
            ->role(Roles::CONTENT)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);
    }

    /**
     * Who can be handed a poster or a reel to make.
     *
     * Needed on the interview page so creative work can be commissioned
     * straight off the interview it comes from — which, for the two social
     * kinds, is the only route their output has.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function creativeOptions()
    {
        return User::query()
            ->active()
            ->role(Roles::CREATIVE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Interview $interview, User $actor): array
    {
        return [
            'id' => $interview->id,
            'code' => $interview->code,
            'kind' => $interview->kind->value,
            'kind_label' => $interview->kind->label(),
            'requires_article' => $interview->requires_article,
            'title' => $interview->title,
            'subject_name' => $interview->subject_name,
            'subject_company' => $interview->subject_company,
            'client' => $interview->client?->only(['id', 'name']),
            'anchor' => $interview->anchor?->only(['id', 'name']),
            'stage' => $interview->stage->value,
            'stage_label' => $interview->stage->label(),
            'scheduled_at' => $interview->scheduled_at?->toIso8601String(),
            'due_on' => $interview->due_on?->toDateString(),
            'priority' => $interview->priority,
            'is_ready_for_handover' => $interview->kind->requiresArticle()
                ? $interview->isReadyForHandover()
                : null,
        ];
    }
}
