<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Deal;
use App\Models\FollowUp;
use App\Support\Scope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The chase list.
 *
 * Grouped by overdue / today / coming up rather than sorted by date, because
 * those are three different kinds of thing: overdue is a failure, today is a
 * plan, and next week is context.
 */
class FollowUpController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->canAny(['deals.view', 'deals.view.all']), 403);

        $user = $request->user();

        $followUps = FollowUp::query()
            ->with(['client:id,name', 'deal:id,code,title', 'owner:id,name'])
            ->where('user_id', $user->id)
            ->pending()
            ->orderBy('due_on')
            ->get()
            ->map(fn (FollowUp $followUp) => $this->row($followUp));

        $today = now()->toDateString();

        return Inertia::render('FollowUps/Index', [
            'overdue' => $followUps->filter(fn (array $row) => $row['due_on'] < $today)->values(),
            'today' => $followUps->filter(fn (array $row) => $row['due_on'] === $today)->values(),
            'upcoming' => $followUps->filter(fn (array $row) => $row['due_on'] > $today)->values(),
            'channels' => FollowUp::CHANNELS,
            'recentlyDone' => FollowUp::query()
                ->with(['client:id,name'])
                ->where('user_id', $user->id)
                ->where('state', 'done')
                ->latest('completed_at')
                ->take(10)
                ->get()
                ->map(fn (FollowUp $followUp) => $this->row($followUp)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canAny(['deals.view', 'deals.view.all']), 403);

        $user = $request->user();

        // Only records this person can see — otherwise a follow-up on somebody
        // else's client or deal puts its name on their follow-up list.
        $validated = $request->validate([
            'client_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                if (! Scope::apply(Client::query(), $user, 'clients', ['owner_id', 'created_by'])->whereKey($value)->exists()) {
                    $fail('Choose one of your clients.');
                }
            }],
            'deal_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                if (! Scope::apply(Deal::query(), $user, 'deals', ['owner_id'])->whereKey($value)->exists()) {
                    $fail('Choose one of your deals.');
                }
            }],
            'due_on' => ['required', 'date'],
            'channel' => ['required', Rule::in(FollowUp::CHANNELS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        FollowUp::create([...$validated, 'user_id' => $request->user()->id, 'state' => 'pending']);

        return back()->with('success', 'Follow-up added.');
    }

    /** Tick it off, optionally recording what came of it. */
    public function complete(Request $request, FollowUp $followUp): RedirectResponse
    {
        $this->authoriseOwn($request, $followUp);

        $validated = $request->validate([
            'outcome_note' => ['nullable', 'string', 'max:500'],
        ]);

        $followUp->update([
            'state' => 'done',
            'completed_at' => now(),
            'outcome_note' => $validated['outcome_note'] ?? null,
        ]);

        /*
         | Chasing a deal counts as activity on it.
         |
         | Without this, a deal somebody is actively working would still appear
         | on the "nothing has happened here for a fortnight" list — and that
         | list is only useful if it is telling the truth.
         */
        $followUp->deal?->forceFill(['last_activity_at' => now()])->save();

        return back()->with('success', 'Done.');
    }

    public function destroy(Request $request, FollowUp $followUp): RedirectResponse
    {
        $this->authoriseOwn($request, $followUp);

        $followUp->delete();

        return back()->with('success', 'Removed.');
    }

    /**
     * A follow-up is a personal reminder, so it belongs to the person who set
     * it. Somebody with the team-wide view can tidy up after a colleague who
     * has left.
     */
    private function authoriseOwn(Request $request, FollowUp $followUp): void
    {
        abort_unless(
            $followUp->user_id === $request->user()->id || $request->user()->can('deals.view.all'),
            403,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(FollowUp $followUp): array
    {
        return [
            'id' => $followUp->id,
            'due_on' => $followUp->due_on?->toDateString(),
            'channel' => $followUp->channel,
            'note' => $followUp->note,
            'state' => $followUp->state,
            'outcome_note' => $followUp->outcome_note,
            'completed_at' => $followUp->completed_at?->toIso8601String(),
            'client' => $followUp->client?->only(['id', 'name']),
            'deal' => $followUp->deal?->only(['id', 'code', 'title']),
        ];
    }
}
