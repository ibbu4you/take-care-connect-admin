<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Project;
use App\Models\User;
use App\Rules\VisibleTo;
use App\Services\CodeGenerator;
use App\Support\Notifier;
use App\Support\Roles;
use App\Support\Workflow\ProjectKind;
use App\Support\Workflow\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a client bought, and how much of it has shipped.
 *
 * The list is ordered by what is late first and what is due next after that,
 * because the only reason to open it is to find out what needs attention —
 * alphabetical order would be tidier and less useful.
 */
class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'kind' => (string) $request->query('kind', ''),
            'owner' => (string) $request->query('owner', ''),
        ];

        $projects = $this->scoped($request->user())
            // Interviews loaded once for the whole page, with only what the
            // progress bar reads — it used to query them row by row.
            ->with(['client:id,name', 'owner:id,name', 'interviews:id,project_id,stage'])
            ->withCount('interviews')
            ->when($filters['search'] !== '', function (Builder $query) use ($filters) {
                $term = "%{$filters['search']}%";

                // Grouped, so the OR-chain cannot escape and widen the status
                // and owner filters applied alongside it.
                $query->where(function (Builder $scoped) use ($term) {
                    $scoped->where('title', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', $term));
                });
            })
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['kind'] !== '', fn (Builder $q) => $q->where('kind', $filters['kind']))
            ->when($filters['owner'] !== '', fn (Builder $q) => $q->where('owner_id', $filters['owner']))
            /*
             | Late first, then soonest, then undated.
             |
             | `due_on IS NULL` sorts the undated ones to the bottom rather than
             | the top, where MySQL would otherwise put them — a project with no
             | date is the least urgent thing here, not the most.
             */
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Project $project) => $this->row($project, $request->user()));

        $mine = $this->scoped($request->user());

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'filters' => $filters,
            'owners' => $this->managerOptions(),
            'kinds' => ProjectKind::options(),
            'statuses' => ProjectStatus::options(),
            'counts' => [
                'open' => (clone $mine)->open()->count(),
                'overdue' => (clone $mine)->open()->whereDate('due_on', '<', now())->count(),
                /*
                 | Closed counts as delivered, because it is: Closed means
                 | delivered *and* settled. A tile reading "Delivered 0" with a
                 | finished project visible in the table underneath is the kind
                 | of small contradiction that makes people stop trusting the
                 | numbers on a page.
                 */
                'delivered' => (clone $mine)->whereIn('status', [
                    ProjectStatus::Delivered->value,
                    ProjectStatus::Closed->value,
                ])->count(),
                'open_value' => round((float) (clone $mine)->open()->sum('value_amount'), 2),
            ],
            'can' => ['create' => $request->user()->can('projects.create')],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Project::class);

        return Inertia::render('Projects/Form', [
            'project' => null,
            // Pre-selected when this is reached from a client page, which is
            // where most projects are actually started.
            'preselected_client' => $request->query('client'),
            ...$this->formOptions($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $validated = $this->validated($request);

        $project = Project::create([
            ...$validated,
            'code' => app(CodeGenerator::class)->next('PRJ'),
            // Whoever was chosen, falling back to the person creating it —
            // a project with no owner is one nobody is answerable for.
            'owner_id' => $validated['owner_id'] ?? $request->user()->id,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', "{$project->code} created.");
    }

    public function show(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $project->load([
            'client:id,name,contact_person,phone,city',
            'owner:id,name',
            'interviews:id,project_id,client_id,code,title,kind,stage,scheduled_at,anchor_id',
            'interviews.anchor:id,name',
            'deals:id,project_id,code,title,stage,expected_value,actual_value,outcome',
        ]);

        return Inertia::render('Projects/Show', [
            'project' => [
                ...$this->row($project, $request->user()),
                'notes' => $project->notes,
                'starts_on' => $project->starts_on?->toDateString(),
                'client' => $project->client?->only(['id', 'name', 'contact_person', 'phone', 'city']),

                'interviews' => $project->interviews->map(fn ($interview) => [
                    'id' => $interview->id,
                    'code' => $interview->code,
                    'title' => $interview->title,
                    'kind' => $interview->kind->value,
                    'stage' => $interview->stage->value,
                    'scheduled_at' => $interview->scheduled_at?->toIso8601String(),
                    'anchor' => $interview->anchor?->only(['id', 'name']),
                ]),

                'deals' => $project->deals->map(fn (Deal $deal) => [
                    'id' => $deal->id,
                    'code' => $deal->code,
                    'title' => $deal->title,
                    'stage' => $deal->stage->value,
                    'expected_value' => (float) $deal->expected_value,
                    'actual_value' => $deal->actual_value !== null ? (float) $deal->actual_value : null,
                    'outcome' => $deal->outcome,
                ]),
            ],

            'statuses' => ProjectStatus::options(),
            'can' => [
                'update' => $request->user()->can('update', $project),
                'delete' => $request->user()->can('delete', $project),
                'bookInterview' => $request->user()->can('interviews.create'),
            ],
        ]);
    }

    public function edit(Request $request, Project $project): Response
    {
        $this->authorize('update', $project);

        return Inertia::render('Projects/Form', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'client_id' => $project->client_id,
                'title' => $project->title,
                'kind' => $project->kind->value,
                'status' => $project->status->value,
                'starts_on' => $project->starts_on?->toDateString(),
                'due_on' => $project->due_on?->toDateString(),
                'value_amount' => $project->value_amount !== null ? (float) $project->value_amount : null,
                'notes' => $project->notes,
                'owner_id' => $project->owner_id,
            ],
            'preselected_client' => null,
            ...$this->formOptions($request),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $this->validated($request);

        /*
         | A project's client is settled once work is filed under it.
         |
         | Moving it would leave its interviews and deals pointing at the old
         | client while the project claimed the new one — two answers to "whose
         | work is this", and the wrong manager seeing it.
         */
        if ((int) $validated['client_id'] !== (int) $project->client_id
            && ($project->interviews()->exists() || $project->deals()->exists())) {
            throw ValidationException::withMessages([
                'client_id' => 'This project already has interviews or deals filed under its client, so the client cannot change. Start a new project instead.',
            ]);
        }

        $project->update($validated);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Saved.');
    }

    /**
     * Change only the status, from the project page.
     *
     * Separate from a full edit because "mark delivered" is a single decision
     * somebody makes with the project open in front of them, and sending them
     * through the whole form to change one field is how a status ends up never
     * being changed at all.
     */
    public function status(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'status' => ['required', Rule::in(ProjectStatus::values())],
        ]);

        $status = ProjectStatus::from($validated['status']);

        if ($status === $project->status) {
            return back();
        }

        $project->update(['status' => $status]);

        return back()->with('success', "{$project->code} is now {$status->label()}.");
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $code = $project->code;
        $project->delete();

        return redirect()
            ->route('projects.index')
            ->with('success', "{$code} removed.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', 'integer', new VisibleTo(
                Client::query(), $request->user(), 'clients', ['owner_id', 'created_by'], 'Choose one of your clients.',
            )],
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(ProjectKind::values())],
            'status' => ['required', Rule::in(ProjectStatus::values())],
            'starts_on' => ['nullable', 'date'],
            // A project that ends before it starts is always a typo, and it
            // makes every duration and every "overdue" reading nonsense.
            'due_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'value_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'notes' => ['nullable', 'string', 'max:5000'],

            /*
             | Who runs it.
             |
             | Validated against the people who can actually work on one rather
             | than against `users,id`: any id would pass that rule, including
             | an anchor's, and the result would be a project owned by somebody
             | who cannot open it.
             */
            'owner_id' => ['nullable', 'integer', Rule::in($this->ownerIds())],
        ], [
            'due_on.after_or_equal' => 'The due date cannot be before the start date.',
        ]);
    }

    /**
     * Everybody who could run a project.
     *
     * Derived from who holds `projects.update` rather than from a role name, so
     * granting somebody the permission also makes them assignable — which is
     * what an Admin expects after ticking the box, and what a hard-coded role
     * check would quietly get wrong.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function ownerOptions(): Collection
    {
        return Notifier::holdersOf('projects.update')
            ->sortBy('name')
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values();
    }

    /**
     * @return list<int>
     */
    private function ownerIds(): array
    {
        return $this->ownerOptions()->pluck('id')->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Request $request): array
    {
        return [
            'kinds' => ProjectKind::options(),
            'statuses' => ProjectStatus::options(),
            'owners' => $this->ownerOptions(),
            'default_owner_id' => $request->user()->id,
            // Only clients this person can actually see, or the picker becomes
            // a directory of the whole book for somebody scoped to their own.
            'clients' => Client::visibleTo($request->user())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name])
                ->values(),
        ];
    }

    /**
     * @return Builder<Project>
     */
    private function scoped(User $user): Builder
    {
        return Project::visibleTo($user);
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    private function managerOptions(): Collection
    {
        return User::query()
            ->active()
            ->role(Roles::RELATIONSHIP_MANAGER)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Project $project, User $actor): array
    {
        return [
            'id' => $project->id,
            'code' => $project->code,
            'title' => $project->title,
            'kind' => $project->kind->value,
            'kind_label' => $project->kind->label(),
            'status' => $project->status->value,
            'client' => $project->client?->only(['id', 'name']),
            'owner' => $project->owner?->only(['id', 'name']),
            'due_on' => $project->due_on?->toDateString(),
            'value_amount' => $project->value_amount !== null ? (float) $project->value_amount : null,
            'interviews_count' => $project->interviews_count ?? $project->interviews()->count(),
            'progress' => $project->progress(),
            'is_overdue' => $project->isOverdue(),
            'can' => ['edit' => $actor->can('update', $project)],
        ];
    }
}
