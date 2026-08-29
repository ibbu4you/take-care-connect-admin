<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Services\LeadConverter;
use App\Support\Roles;
use App\Support\Workflow\LeadSource;
use App\Support\Workflow\LeadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The people a manager is talking to who are not clients yet.
 *
 * The list is ordered by who needs a call first, because that is the only
 * reason to open it. Alphabetical would be tidier and would answer nothing.
 */
class LeadController extends Controller
{
    public function __construct(
        private readonly CodeGenerator $codes,
        private readonly LeadConverter $converter,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Lead::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'source' => (string) $request->query('source', ''),
            'owner' => (string) $request->query('owner', ''),
        ];

        $leads = $this->scoped($request->user())
            ->with('owner:id,name')
            ->when($filters['search'] !== '', function (Builder $query) use ($filters) {
                $term = "%{$filters['search']}%";
                $digits = preg_replace('/\D+/', '', $filters['search']);

                // Grouped, so the OR-chain cannot escape and widen the status
                // and owner filters applied alongside it.
                $query->where(function (Builder $scoped) use ($term, $digits) {
                    $scoped->where('name', 'like', $term)
                        ->orWhere('contact_person', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('trade', 'like', $term)
                        ->orWhere('city', 'like', $term)
                        ->orWhere('code', 'like', $term);

                    if ($digits !== '') {
                        $scoped->orWhere('phone_normalized', 'like', "%{$digits}%");
                    }
                });
            })
            ->when($filters['status'] !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['source'] !== '', fn (Builder $q) => $q->where('source', $filters['source']))
            ->when($filters['owner'] !== '', fn (Builder $q) => $q->where('owner_id', $filters['owner']))
            /*
             | Who needs a call first.
             |
             | Overdue at the top, then soonest, then the ones with no date at
             | all — `next_action_on IS NULL` sorts those to the bottom rather
             | than the top, where MySQL would otherwise put them.
             */
            ->orderByRaw('next_action_on IS NULL')
            ->orderBy('next_action_on')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Lead $lead) => $this->row($lead, $request->user()));

        $mine = $this->scoped($request->user());

        return Inertia::render('Leads/Index', [
            'leads' => $leads,
            'filters' => $filters,
            'statuses' => LeadStatus::options(),
            'sources' => LeadSource::options(),
            'owners' => $this->managerOptions(),
            'counts' => [
                'open' => (clone $mine)->open()->count(),
                'due' => (clone $mine)->due()->count(),
                'qualified' => (clone $mine)->where('status', LeadStatus::Qualified->value)->count(),
                'converted_this_month' => (clone $mine)
                    ->whereBetween('converted_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->count(),
            ],
            'can' => [
                'create' => $request->user()->can('leads.create'),
                'export' => $request->user()->can('leads.export'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Lead::class);

        return Inertia::render('Leads/Form', [
            'lead' => null,
            'statuses' => LeadStatus::options(),
            'sources' => LeadSource::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Lead::class);

        $lead = Lead::create([
            ...$this->validated($request),
            'code' => $this->codes->next('LED'),
            'owner_id' => $request->user()->id,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('leads.show', $lead)
            ->with('success', "{$lead->name} added.");
    }

    public function show(Request $request, Lead $lead): Response
    {
        $this->authorize('view', $lead);

        $lead->load([
            'owner:id,name',
            'creator:id,name',
            'convertedClient:id,name',
            'timeline.author:id,name',
            'followUps.owner:id,name',
        ]);

        return Inertia::render('Leads/Show', [
            'lead' => [
                ...$this->row($lead, $request->user()),
                'email' => $lead->email,
                'whatsapp' => $lead->whatsapp,
                'website' => $lead->website,
                'instagram' => $lead->instagram,
                'state' => $lead->state,
                'notes' => $lead->notes,
                'lost_reason' => $lead->lost_reason,
                'estimated_value' => $lead->estimated_value !== null ? (float) $lead->estimated_value : null,
                'last_contacted_at' => $lead->last_contacted_at?->toIso8601String(),
                'converted_at' => $lead->converted_at?->toIso8601String(),
                'converted_client' => $lead->convertedClient?->only(['id', 'name']),
                'creator' => $lead->creator?->only(['id', 'name']),

                'timeline' => $lead->timeline->map(fn (Note $note) => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'author' => $note->author?->name,
                    'created_at' => $note->created_at?->toIso8601String(),
                ]),

                'follow_ups' => $lead->followUps->map(fn ($followUp) => [
                    'id' => $followUp->id,
                    'due_on' => $followUp->due_on?->toDateString(),
                    'channel' => $followUp->channel,
                    'note' => $followUp->note,
                    'state' => $followUp->state,
                ]),
            ],

            'statuses' => LeadStatus::options(),
            'can' => [
                'update' => $request->user()->can('update', $lead),
                'convert' => $request->user()->can('convert', $lead),
                'delete' => $request->user()->can('delete', $lead),
            ],
        ]);
    }

    public function edit(Request $request, Lead $lead): Response
    {
        $this->authorize('update', $lead);

        return Inertia::render('Leads/Form', [
            'lead' => [
                'id' => $lead->id,
                'code' => $lead->code,
                ...$lead->only([
                    'name', 'contact_person', 'email', 'phone', 'whatsapp',
                    'website', 'instagram', 'city', 'state', 'trade', 'notes', 'lost_reason',
                ]),
                'status' => $lead->status->value,
                'source' => $lead->source->value,
                'next_action_on' => $lead->next_action_on?->toDateString(),
                'estimated_value' => $lead->estimated_value !== null ? (float) $lead->estimated_value : null,
            ],
            'statuses' => LeadStatus::options(),
            'sources' => LeadSource::options(),
        ]);
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('update', $lead);

        $lead->update($this->validated($request, $lead));

        return redirect()
            ->route('leads.show', $lead)
            ->with('success', 'Saved.');
    }

    /**
     * Log a conversation.
     *
     * Stamps `last_contacted_at` as well as writing the note, because "when did
     * anybody last speak to them" is a question the list has to answer and
     * nobody would remember to set a field for.
     */
    public function addNote(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('update', $lead);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'next_action_on' => ['nullable', 'date'],
        ]);

        Note::create([
            'notable_type' => $lead->getMorphClass(),
            'notable_id' => $lead->getKey(),
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);

        $lead->forceFill([
            'last_contacted_at' => now(),
            'next_action_on' => $validated['next_action_on'] ?? $lead->next_action_on,
            // A logged conversation means somebody has been contacted, so the
            // status stops claiming otherwise.
            'status' => $lead->status === LeadStatus::New ? LeadStatus::Contacted : $lead->status,
        ])->save();

        return back()->with('success', 'Logged.');
    }

    public function status(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('update', $lead);

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_column(LeadStatus::options(), 'value'))],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $status = LeadStatus::from($validated['status']);

        $lead->update([
            'status' => $status,
            'lost_reason' => $status === LeadStatus::Unqualified ? $validated['lost_reason'] ?? null : null,
        ]);

        return back()->with('success', "Marked {$status->label()}.");
    }

    public function convert(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('convert', $lead);

        $validated = $request->validate([
            'deal_title' => ['nullable', 'string', 'max:255'],
            'deal_value' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $client = $this->converter->convert($lead, $request->user(), [
            'title' => $validated['deal_title'] ?? null,
            'expected_value' => $validated['deal_value'] ?? null,
        ]);

        return redirect()
            ->route('clients.show', $client)
            ->with('success', "{$client->name} is now a client.");
    }

    public function destroy(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('delete', $lead);

        $name = $lead->name;
        $lead->delete();

        return redirect()->route('leads.index')->with('success', "{$name} removed.");
    }

    /**
     * The list as CSV.
     *
     * Streamed and chunked: this is the export somebody runs on the whole book
     * at the end of a quarter.
     */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('leads.export'), 403);

        $query = $this->scoped($request->user())->with('owner:id,name');

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'Code', 'Name', 'Contact', 'Phone', 'Email', 'Trade', 'City',
                'Source', 'Status', 'Owner', 'Next action', 'Added',
            ]);

            $query->orderBy('name')->chunk(200, function ($leads) use ($handle) {
                foreach ($leads as $lead) {
                    fputcsv($handle, [
                        $lead->code,
                        $lead->name,
                        $lead->contact_person,
                        $lead->phone,
                        $lead->email,
                        $lead->trade,
                        $lead->city,
                        $lead->source->label(),
                        $lead->status->label(),
                        $lead->owner?->name,
                        $lead->next_action_on?->toDateString(),
                        $lead->created_at?->toDateString(),
                    ]);
                }
            });

            fclose($handle);
        }, 'leads-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Lead $lead = null): array
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
            'source' => ['required', Rule::in(LeadSource::values())],
            'status' => ['required', Rule::in(array_column(LeadStatus::options(), 'value'))],
            'next_action_on' => ['nullable', 'date'],
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /**
     * @return Builder<Lead>
     */
    private function scoped(User $user): Builder
    {
        return Lead::visibleTo($user);
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
    private function row(Lead $lead, User $actor): array
    {
        return [
            'id' => $lead->id,
            'code' => $lead->code,
            'name' => $lead->name,
            'contact_person' => $lead->contact_person,
            'phone' => $lead->phone,
            'city' => $lead->city,
            'trade' => $lead->trade,
            'status' => $lead->status->value,
            'status_label' => $lead->status->label(),
            'source' => $lead->source->value,
            'source_label' => $lead->source->label(),
            'owner' => $lead->owner?->only(['id', 'name']),
            'next_action_on' => $lead->next_action_on?->toDateString(),
            'days_since_contact' => $lead->daysSinceContact(),
            'is_overdue' => $lead->isOverdue(),
            'created_at' => $lead->created_at?->toIso8601String(),
            'can' => ['edit' => $actor->can('update', $lead)],
        ];
    }
}
