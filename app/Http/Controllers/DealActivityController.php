<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\FollowUp;
use App\Services\StageMachine;
use App\Support\Workflow\ActivityType;
use App\Support\Workflow\PipelineStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Logging what happened on a deal — and what happens next.
 *
 * ── The next step is required ──────────────────────────────────────────────
 * A deal with no next step is a deal nobody is working. So every activity ends
 * with one: what, and by when. It becomes the follow-up on the salesperson's
 * list and dashboard, and the one it replaces is marked done — so there is
 * always exactly one thing to do next on a live deal, and it is never stale.
 *
 * ── The stage follows the facts ────────────────────────────────────────────
 * A call that got through moves a new deal to "Contacted"; a meeting that was
 * held moves it to "Meeting held". The salesperson logs what happened and the
 * board catches up, instead of them having to remember to drag the card.
 */
class DealActivityController extends Controller
{
    public function __construct(private readonly StageMachine $stages) {}

    public function store(Request $request, Deal $deal): RedirectResponse
    {
        $this->authorize('update', $deal);

        $type = ActivityType::tryFrom((string) $request->input('type'));
        $outcomes = $type ? array_keys($type->outcomes()) : [];

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ActivityType::class)],
            // A type with no outcomes (WhatsApp, email) takes none: anything sent
            // would otherwise be stored as-is.
            'outcome' => $outcomes === [] ? ['prohibited'] : ['required', Rule::in($outcomes)],
            'note' => ['nullable', 'string', 'max:5000'],
            'next_step' => ['required', 'string', 'max:255'],
            'next_step_type' => ['required', Rule::enum(ActivityType::class)],
            'next_step_on' => ['required', 'date', 'after_or_equal:today'],
        ], [
            'outcome.required' => 'Choose what came of it.',
            'next_step.required' => 'Say what happens next — a live deal always has a next step.',
            'next_step_on.required' => 'Say when the next step is due.',
            'next_step_on.after_or_equal' => 'The next step cannot be in the past.',
        ]);

        $nextType = ActivityType::from($validated['next_step_type']);
        $moved = null;

        DB::transaction(function () use ($request, $deal, $type, $nextType, $validated, &$moved) {
            DealActivity::create([
                'deal_id' => $deal->id,
                'user_id' => $request->user()->id,
                'type' => $type,
                'outcome' => $validated['outcome'] ?? null,
                'note' => $validated['note'] ?? null,
                'happened_at' => now(),
            ]);

            // The step this activity was presumably doing is now done.
            FollowUp::where('deal_id', $deal->id)
                ->pending()
                ->update([
                    'state' => 'done',
                    'completed_at' => now(),
                    'outcome_note' => "{$type->label()} logged",
                ]);

            FollowUp::create([
                'deal_id' => $deal->id,
                'client_id' => $deal->client_id,
                // The deal owner's, whoever logged this: a manager stepping in
                // on a deal must not take its next step off the owner's list.
                'user_id' => $deal->owner_id ?? $request->user()->id,
                'due_on' => $validated['next_step_on'],
                'channel' => $nextType->followUpChannel(),
                'note' => $validated['next_step'],
                'state' => 'pending',
            ]);

            $deal->forceFill([
                'last_activity_at' => now(),
                'next_step' => $validated['next_step'],
                'next_step_on' => $validated['next_step_on'],
            ])->save();

            $moved = $this->advance($deal, $type, $validated['outcome'] ?? null, $request);
        });

        return back()->with('success', $moved
            ? "{$type->label()} logged. Moved to {$moved->label()}."
            : "{$type->label()} logged.");
    }

    /** Move the deal forward when what just happened proves the next stage. */
    private function advance(Deal $deal, ActivityType $type, ?string $outcome, Request $request): ?PipelineStage
    {
        $met = in_array($type, [ActivityType::Meeting, ActivityType::Visit], true) && $outcome === 'held';

        $target = match (true) {
            $met && in_array($deal->stage, [PipelineStage::New, PipelineStage::Contacted], true) => PipelineStage::Meeting,
            ActivityType::spokeTo($type, $outcome) && $deal->stage === PipelineStage::New => PipelineStage::Contacted,
            default => null,
        };

        if ($target === null || ! $request->user()->can('move', $deal)) {
            return null;
        }

        $this->stages->transition($deal, $target, $request->user(), "After a {$type->label()}");

        return $target;
    }
}
