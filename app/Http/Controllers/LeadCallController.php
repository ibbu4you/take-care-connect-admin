<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadCall;
use App\Support\Workflow\CallOutcome;
use App\Support\Workflow\LeadStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Logging a call to a lead.
 *
 * One form, three answers, at the moment the caller has just put the phone
 * down: what happened, anything worth writing, and when to try again. The
 * outcome then moves the lead along by itself — a conversation means they have
 * been contacted, "interested" means they are interested — so nobody has to
 * remember a second step.
 */
class LeadCallController extends Controller
{
    public function store(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('update', $lead);

        $validated = $request->validate([
            'outcome' => ['required', Rule::enum(CallOutcome::class)],
            'note' => ['nullable', 'string', 'max:5000'],
            // Taken exactly as sent, blank included: the form pre-fills the
            // outcome's usual retry, so a blank date is somebody deciding there
            // is no next call — "not interested" — not somebody forgetting.
            'next_action_on' => ['nullable', 'date'],
        ]);

        $outcome = CallOutcome::from($validated['outcome']);

        DB::transaction(function () use ($request, $lead, $outcome, $validated) {
            LeadCall::create([
                'lead_id' => $lead->id,
                'user_id' => $request->user()->id,
                'outcome' => $outcome,
                'note' => $validated['note'] ?? null,
                'called_at' => now(),
            ]);

            $lead->next_action_on = $validated['next_action_on'] ?? null;

            if ($outcome->connected()) {
                // "Last contact" means last spoken to. A string of missed calls
                // is not contact, and counting it would hide the leads nobody
                // has actually reached.
                $lead->last_contacted_at = now();

                if ($lead->status === LeadStatus::New) {
                    $lead->status = LeadStatus::Contacted;
                }
            }

            if ($outcome === CallOutcome::Interested
                && in_array($lead->status, [LeadStatus::New, LeadStatus::Contacted], true)) {
                $lead->status = LeadStatus::Interested;
            }

            $lead->save();
        });

        return back()->with('success', "Call logged: {$outcome->label()}.");
    }
}
