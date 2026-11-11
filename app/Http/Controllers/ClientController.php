<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Deal;
use App\Models\User;
use App\Support\Csv;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'owner' => (string) $request->query('owner', ''),
        ];

        $clients = $this->scopedQuery($request->user())
            ->with(['owner:id,name'])
            ->withCount(['interviews', 'projects'])
            ->when($filters['search'] !== '', function (Builder $query) use ($filters) {
                $term = "%{$filters['search']}%";
                // Digits only, so a search for "98765 43210" finds a number
                // stored as "+91 9876543210".
                $digits = preg_replace('/\D+/', '', $filters['search']);

                $query->where(function (Builder $scoped) use ($term, $digits) {
                    $scoped->where('name', 'like', $term)
                        ->orWhere('contact_person', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('trade', 'like', $term)
                        ->orWhere('city', 'like', $term);

                    if ($digits !== '') {
                        $scoped->orWhere('phone_normalized', 'like', "%{$digits}%");
                    }
                });
            })
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['owner'] !== '', fn (Builder $q) => $q->where('owner_id', $filters['owner']))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Client $client) => $this->row($client, $request->user()));

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'filters' => $filters,
            'owners' => $this->managerOptions(),
            'counts' => [
                'total' => (clone $this->scopedQuery($request->user()))->count(),
                'active' => (clone $this->scopedQuery($request->user()))->where('status', 'active')->count(),
                'dormant' => (clone $this->scopedQuery($request->user()))->where('status', 'dormant')->count(),
            ],
            'can' => [
                'create' => $request->user()->can('clients.create'),
                'export' => $request->user()->can('clients.export'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Client::class);

        return Inertia::render('Clients/Form', ['client' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $validated = $this->validated($request);

        $client = Client::create([
            ...$validated,
            'owner_id' => $request->user()->id,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('clients.show', $client)
            ->with('success', "{$client->name} added.");
    }

    public function show(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $client->load([
            'owner:id,name',
            'projects:id,client_id,code,title,status,due_on',
            'interviews:id,client_id,code,title,kind,stage,scheduled_at',
        ]);

        return Inertia::render('Clients/Show', [
            'client' => [
                ...$this->row($client, $request->user()),
                'whatsapp' => $client->whatsapp,
                'website' => $client->website,
                'instagram' => $client->instagram,
                'state' => $client->state,
                'address' => $client->address,
                'notes' => $client->notes,
                'source' => $client->source,

                'projects' => $client->projects->map(fn ($project) => [
                    'id' => $project->id,
                    'code' => $project->code,
                    'title' => $project->title,
                    'status' => $project->status,
                    'due_on' => $project->due_on?->toDateString(),
                ]),

                'interviews' => $client->interviews->map(fn ($interview) => [
                    'id' => $interview->id,
                    'code' => $interview->code,
                    'title' => $interview->title,
                    'kind' => $interview->kind->value,
                    'stage' => $interview->stage->value,
                    'scheduled_at' => $interview->scheduled_at?->toIso8601String(),
                ]),

                'deals' => Deal::where('client_id', $client->id)
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn (Deal $deal) => [
                        'id' => $deal->id,
                        'code' => $deal->code,
                        'title' => $deal->title,
                        'stage' => $deal->stage->value,
                        'expected_value' => (float) $deal->expected_value,
                        'actual_value' => $deal->actual_value !== null ? (float) $deal->actual_value : null,
                        'outcome' => $deal->outcome,
                    ]),
            ],

            'can' => [
                'update' => $request->user()->can('update', $client),
                'createDeal' => $request->user()->can('deals.create'),
                'bookInterview' => $request->user()->can('interviews.create'),
                'createProject' => $request->user()->can('projects.create'),
            ],
        ]);
    }

    public function edit(Request $request, Client $client): Response
    {
        $this->authorize('update', $client);

        return Inertia::render('Clients/Form', [
            'client' => [
                'id' => $client->id,
                ...$client->only([
                    'name', 'contact_person', 'email', 'phone', 'whatsapp', 'website',
                    'instagram', 'city', 'state', 'trade', 'address', 'status', 'notes', 'source',
                ]),
                'owner_id' => $client->owner_id,
            ],
            // Handing a client to another manager — when somebody leaves, their
            // book has to go somewhere. An administrative call.
            'owners' => $request->user()->can('assign', $client) ? $this->ownerOptions() : [],
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $validated = $this->validated($request, $client);

        if ($request->filled('owner_id') && (int) $request->input('owner_id') !== (int) $client->owner_id) {
            $this->authorize('assign', $client);

            $request->validate([
                'owner_id' => ['integer', Rule::in(array_column($this->ownerOptions(), 'id'))],
            ], ['owner_id.in' => 'Choose an active relationship or sales manager.']);

            $validated['owner_id'] = (int) $request->input('owner_id');
        }

        $previousOwner = $client->owner_id;

        DB::transaction(function () use ($client, $validated, $previousOwner) {
            $client->update($validated);

            // The open deals the previous manager was running go with the
            // client; closed ones stay theirs, as history.
            if (isset($validated['owner_id']) && $previousOwner !== null && $validated['owner_id'] !== (int) $previousOwner) {
                Deal::where('client_id', $client->id)
                    ->where('owner_id', $previousOwner)
                    ->open()
                    ->update(['owner_id' => $validated['owner_id']]);
            }
        });

        return redirect()
            ->route('clients.show', $client)
            ->with('success', 'Saved.');
    }

    /**
     * The client list as CSV.
     *
     * Streamed rather than assembled in memory: this is the export somebody
     * runs on the whole book at the end of a quarter.
     */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('clients.export'), 403);

        $query = $this->scopedQuery($request->user())->with('owner:id,name');
        $filename = 'clients-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            Csv::put($handle, [
                'Code', 'Name', 'Contact', 'Phone', 'Email',
                'Trade', 'City', 'State', 'Status', 'Owner', 'Added',
            ]);

            // Chunked so a book of ten thousand clients does not have to fit in
            // memory all at once.
            $query->orderBy('name')->chunk(200, function ($clients) use ($handle) {
                foreach ($clients as $client) {
                    Csv::put($handle, [
                        $client->id,
                        $client->name,
                        $client->contact_person,
                        $client->phone,
                        $client->email,
                        $client->trade,
                        $client->city,
                        $client->state,
                        $client->status,
                        $client->owner?->name,
                        $client->created_at?->toDateString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Client $client = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'url', 'max:255'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:120'],
            'trade' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['active', 'dormant', 'archived'])],
            'source' => ['nullable', 'string', 'max:24'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /**
     * @return Builder<Client>
     */
    private function scopedQuery(User $user): Builder
    {
        return Client::visibleTo($user);
    }

    /**
     * People who can look after a client.
     *
     * @return list<array{id: int, name: string}>
     */
    private function ownerOptions(): array
    {
        return User::query()
            ->active()
            ->role([Roles::RELATIONSHIP_MANAGER, Roles::SALES_EXECUTIVE, Roles::SALES_MANAGER])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->all();
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
    private function row(Client $client, User $actor): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'contact_person' => $client->contact_person,
            'email' => $client->email,
            'phone' => $client->phone,
            'city' => $client->city,
            'trade' => $client->trade,
            'status' => $client->status,
            'owner' => $client->owner?->only(['id', 'name']),
            'interviews_count' => $client->interviews_count ?? null,
            'projects_count' => $client->projects_count ?? null,
            'created_at' => $client->created_at?->toIso8601String(),
            // Answered by the policy per row, so the list never offers a button
            // that would then be refused.
            'can' => ['edit' => $actor->can('update', $client)],
        ];
    }
}
