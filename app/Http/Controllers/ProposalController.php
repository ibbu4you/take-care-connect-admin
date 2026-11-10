<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\FollowUp;
use App\Models\Proposal;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Services\StageMachine;
use App\Support\Notifier;
use App\Support\Scope;
use App\Support\Settings;
use App\Support\Workflow\PipelineStage;
use App\Support\Workflow\ProposalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Proposals: write, check, send, and record the answer.
 *
 * ── The discount check ─────────────────────────────────────────────────────
 * A proposal within the discount limit (Settings → Sales) is ready to send the
 * moment it is submitted. Above it, it waits for somebody holding
 * `proposals.approve`, who is notified. Making a manager click through every
 * routine quote is how approval becomes a rubber stamp; checking only the ones
 * that give money away is how it stays meaningful.
 *
 * ── Sending ────────────────────────────────────────────────────────────────
 * The buyer gets a link to a printable page, signed so it opens without an
 * account and cannot be guessed or altered, and expiring when the proposal
 * does. Sending moves the deal to "Proposal sent" and sets the follow-up, so
 * the salesperson's next step is already on their list.
 */
class ProposalController extends Controller
{
    public function __construct(
        private readonly CodeGenerator $codes,
        private readonly StageMachine $stages,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canAny(['proposals.view', 'proposals.view.all']), 403);

        $status = (string) $request->query('status', '');

        $proposals = $this->visible($user)
            ->with(['deal:id,code,title,client_id,owner_id', 'deal.client:id,name', 'creator:id,name'])
            ->when($status !== '', fn (Builder $q) => $q->where('status', $status))
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Proposal $proposal) => $this->row($proposal));

        $counts = fn (ProposalStatus $s) => (clone $this->visible($user))->where('status', $s->value)->count();

        return Inertia::render('Proposals/Index', [
            'proposals' => $proposals,
            'filters' => ['status' => $status],
            'statuses' => array_map(fn (ProposalStatus $s) => ['value' => $s->value, 'label' => $s->label()], ProposalStatus::cases()),
            'counts' => [
                'pending' => $counts(ProposalStatus::PendingApproval),
                'ready' => $counts(ProposalStatus::Approved),
                'sent' => $counts(ProposalStatus::Sent),
                'accepted' => (clone $this->visible($user))->where('status', ProposalStatus::Accepted->value)
                    ->whereBetween('responded_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            ],
            'can' => ['approve' => $user->can('proposals.approve')],
        ]);
    }

    public function create(Request $request, Deal $deal): Response
    {
        $this->authorizeWrite($request->user(), $deal);

        $previous = $deal->proposals()->first();

        return Inertia::render('Proposals/Form', [
            'proposal' => null,
            'deal' => $this->dealSummary($deal),
            // A new version starts from the last one: a revised quote is usually
            // the same lines with one price changed.
            'defaults' => [
                'title' => $previous?->title ?? $deal->title,
                'items' => $previous?->items ?? [['description' => '', 'quantity' => 1, 'rate' => 0]],
                'discount_percent' => $previous ? (float) $previous->discount_percent : 0,
                'valid_until' => now()->addDays(15)->toDateString(),
                'terms' => $previous?->terms ?? $this->defaultTerms(),
                'internal_note' => '',
            ],
            'threshold' => Settings::int('sales.discount_approval_percent'),
        ]);
    }

    public function store(Request $request, Deal $deal): RedirectResponse
    {
        $this->authorizeWrite($request->user(), $deal);
        $validated = $this->validated($request);

        $proposal = DB::transaction(function () use ($request, $deal, $validated) {
            $proposal = new Proposal([
                'code' => $this->codes->next('PRO'),
                'deal_id' => $deal->id,
                'version' => ((int) $deal->proposals()->max('version')) + 1,
                'status' => ProposalStatus::Draft,
                'title' => $validated['title'],
                'valid_until' => $validated['valid_until'] ?? null,
                'terms' => $validated['terms'] ?? null,
                'internal_note' => $validated['internal_note'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $proposal->fillAmounts($validated['items'], (float) ($validated['discount_percent'] ?? 0));
            $this->guardTotal($proposal);
            $proposal->last_edited_by = $request->user()->id;
            $proposal->save();

            $deal->forceFill(['last_activity_at' => now()])->save();

            return $proposal;
        });

        return redirect()->route('proposals.show', $proposal)->with('success', "{$proposal->code} saved as a draft.");
    }

    public function show(Request $request, Proposal $proposal): Response
    {
        $user = $request->user();
        $this->authorizeView($user, $proposal);

        $proposal->load(['deal.client:id,name,contact_person,phone,email,city', 'deal.owner:id,name', 'creator:id,name', 'approver:id,name', 'sender:id,name']);
        $writable = $this->canWrite($user, $proposal->deal);

        return Inertia::render('Proposals/Show', [
            'proposal' => [
                ...$this->row($proposal),
                'items' => $proposal->items,
                'subtotal' => (float) $proposal->subtotal,
                'discount_amount' => (float) $proposal->discount_amount,
                'valid_until' => $proposal->valid_until?->toDateString(),
                'terms' => $proposal->terms,
                'internal_note' => $proposal->internal_note,
                'review_note' => $proposal->review_note,
                'approver' => $proposal->approver?->name,
                'approved_at' => $proposal->approved_at?->toIso8601String(),
                'sender' => $proposal->sender?->name,
                'sent_at' => $proposal->sent_at?->toIso8601String(),
                'responded_at' => $proposal->responded_at?->toIso8601String(),
                'needs_approval' => $proposal->needsApproval(),
                // Only once it may go out; before that there is nothing to share.
                'share_url' => in_array($proposal->status, [ProposalStatus::Approved, ProposalStatus::Sent, ProposalStatus::Accepted], true)
                    ? $this->shareUrl($proposal)
                    : null,
            ],
            'deal' => $this->dealSummary($proposal->deal),
            'threshold' => Settings::int('sales.discount_approval_percent'),
            'can' => [
                'edit' => $writable && $proposal->status->isEditable(),
                'submit' => $writable && $proposal->status->isEditable(),
                'approve' => $this->mayApprove($user, $proposal),
                'send' => $writable && $proposal->status === ProposalStatus::Approved,
                'respond' => $writable && $proposal->status === ProposalStatus::Sent,
            ],
        ]);
    }

    public function edit(Request $request, Proposal $proposal): Response
    {
        $this->authorizeWrite($request->user(), $proposal->deal);
        abort_unless($proposal->status->isEditable(), 403, 'Only a draft, or one sent back for changes, can be edited.');

        return Inertia::render('Proposals/Form', [
            'proposal' => ['id' => $proposal->id, 'code' => $proposal->code, 'version' => $proposal->version],
            'deal' => $this->dealSummary($proposal->deal),
            'defaults' => [
                'title' => $proposal->title,
                'items' => $proposal->items,
                'discount_percent' => (float) $proposal->discount_percent,
                'valid_until' => $proposal->valid_until?->toDateString() ?? '',
                'terms' => $proposal->terms ?? '',
                'internal_note' => $proposal->internal_note ?? '',
            ],
            'threshold' => Settings::int('sales.discount_approval_percent'),
        ]);
    }

    public function update(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorizeWrite($request->user(), $proposal->deal);
        abort_unless($proposal->status->isEditable(), 403);

        $validated = $this->validated($request);

        $proposal->fill([
            'title' => $validated['title'],
            'valid_until' => $validated['valid_until'] ?? null,
            'terms' => $validated['terms'] ?? null,
            'internal_note' => $validated['internal_note'] ?? null,
            // Changed after being sent back: it is a draft again until resubmitted.
            'status' => ProposalStatus::Draft,
        ]);
        $proposal->fillAmounts($validated['items'], (float) ($validated['discount_percent'] ?? 0));
        $this->guardTotal($proposal);
        $proposal->last_edited_by = $request->user()->id;
        $proposal->save();

        return redirect()->route('proposals.show', $proposal)->with('success', 'Saved.');
    }

    /** Ready to go: straight to "ready to send", or to a manager if the discount is large. */
    public function submit(Request $request, Proposal $proposal): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeWrite($user, $proposal->deal);
        abort_unless($proposal->status->isEditable(), 403);

        if ($proposal->needsApproval()) {
            $proposal->forceFill(['status' => ProposalStatus::PendingApproval, 'review_note' => null, 'submitted_by' => $user->id])->save();

            Notifier::toPermission(
                'proposals.approve',
                'proposal.approval',
                "{$proposal->code} needs your approval",
                "{$user->name} is offering {$proposal->discount_percent}% off on {$proposal->deal->title}.",
                "/proposals/{$proposal->id}",
                $user,
            );

            return back()->with('success', 'Sent for approval — the discount is above the limit.');
        }

        $proposal->forceFill([
            'status' => ProposalStatus::Approved,
            'submitted_by' => $user->id,
            'approved_by' => null,
            'approved_at' => now(),
            'review_note' => null,
        ])->save();

        return back()->with('success', 'Ready to send.');
    }

    public function approve(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorizeApproval($request->user(), $proposal);

        $proposal->forceFill([
            'status' => ProposalStatus::Approved,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'review_note' => $request->string('note')->toString() ?: null,
        ])->save();

        Notifier::send(
            [$proposal->creator],
            'proposal.approved',
            "{$proposal->code} approved",
            "You can send it to {$proposal->deal->client?->name}.",
            "/proposals/{$proposal->id}",
            $request->user(),
        );

        return back()->with('success', 'Approved.');
    }

    /** Back to the salesperson with a reason. */
    public function sendBack(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->authorizeApproval($request->user(), $proposal);

        $validated = $request->validate(['note' => ['required', 'string', 'max:500']], [
            'note.required' => 'Say what to change.',
        ]);

        $proposal->forceFill(['status' => ProposalStatus::Rejected, 'review_note' => $validated['note']])->save();

        Notifier::send(
            [$proposal->creator],
            'proposal.returned',
            "{$proposal->code} needs changes",
            $validated['note'],
            "/proposals/{$proposal->id}",
            $request->user(),
        );

        return back()->with('success', 'Sent back for changes.');
    }

    /**
     * Mark it sent — the page has just opened WhatsApp with the link.
     *
     * Moves the deal to "Proposal sent" if it was behind it, and sets the next
     * step to following up, replacing whatever was pending.
     */
    public function send(Request $request, Proposal $proposal): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeWrite($user, $proposal->deal);
        abort_unless($proposal->status === ProposalStatus::Approved, 403);

        $deal = $proposal->deal;

        DB::transaction(function () use ($user, $proposal, $deal) {
            $proposal->forceFill(['status' => ProposalStatus::Sent, 'sent_by' => $user->id, 'sent_at' => now()])->save();

            $followUpOn = now()->addDays(3)->toDateString();

            FollowUp::where('deal_id', $deal->id)->pending()->update([
                'state' => 'done', 'completed_at' => now(), 'outcome_note' => 'Proposal sent',
            ]);

            FollowUp::create([
                'deal_id' => $deal->id,
                'client_id' => $deal->client_id,
                // The deal owner chases it, even if somebody else sent it.
                'user_id' => $deal->owner_id ?? $user->id,
                'due_on' => $followUpOn,
                'channel' => 'call',
                'note' => "Follow up on proposal {$proposal->code}",
                'state' => 'pending',
            ]);

            $deal->forceFill([
                'last_activity_at' => now(),
                'next_step' => "Follow up on proposal {$proposal->code}",
                'next_step_on' => $followUpOn,
                // The forecast needs a close date; the proposal's own expiry is
                // the honest default when nobody has set one.
                'expected_close_on' => $deal->expected_close_on ?? $proposal->valid_until ?? now()->addDays(30)->toDateString(),
                'expected_value' => $proposal->total,
            ])->save();

            if (in_array($deal->stage, [PipelineStage::New, PipelineStage::Contacted, PipelineStage::Meeting], true)) {
                $this->stages->transition($deal, PipelineStage::Proposal, $user, "Proposal {$proposal->code} sent", PipelineStage::cases());
            }
        });

        return back()->with('success', 'Marked as sent. Follow-up set for three days from now.');
    }

    public function respond(Request $request, Proposal $proposal, string $answer): RedirectResponse
    {
        $this->authorizeWrite($request->user(), $proposal->deal);
        abort_unless($proposal->status === ProposalStatus::Sent, 403);
        abort_unless(in_array($answer, ['accepted', 'declined'], true), 404);

        $proposal->forceFill([
            'status' => $answer === 'accepted' ? ProposalStatus::Accepted : ProposalStatus::Declined,
            'responded_at' => now(),
        ])->save();

        $proposal->deal->forceFill(['last_activity_at' => now()])->save();

        return back()->with('success', $answer === 'accepted'
            ? 'Accepted. Close the deal as won once it is signed.'
            : 'Declined. Write a new version, or close the deal as lost.');
    }

    /** The printable page, for the team. */
    public function print(Request $request, Proposal $proposal): View
    {
        $this->authorizeView($request->user(), $proposal);

        return $this->document($proposal);
    }

    /** The same page for the buyer, through the signed link — no account needed. */
    public function shared(Proposal $proposal): View
    {
        abort_unless($proposal->status->wasSent() || $proposal->status === ProposalStatus::Approved, 404);

        return $this->document($proposal);
    }

    private function document(Proposal $proposal): View
    {
        $proposal->load(['deal.client', 'creator:id,name,email,phone']);

        return view('proposals.document', ['proposal' => $proposal]);
    }

    private function shareUrl(Proposal $proposal): string
    {
        $expires = $proposal->valid_until?->copy()->endOfDay()->addDays(7) ?? now()->addDays(30);

        return URL::temporarySignedRoute('proposals.shared', $expires, ['proposal' => $proposal->id]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'items.*.rate' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Required: it is the close date the forecast falls back on when
            // the proposal goes out, and how long the client link works.
            'valid_until' => ['required', 'date', 'after_or_equal:today'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ], [
            'items.min' => 'Add at least one line.',
            'items.*.description.required' => 'Every line needs a description.',
        ]);
    }

    /** Proposals on deals this person can see. @return Builder<Proposal> */
    private function visible(User $user): Builder
    {
        $query = Proposal::query();

        if ($user->can('proposals.view.all')) {
            return $query;
        }

        $deals = Scope::apply(Deal::query(), $user, 'deals', ['owner_id'])->select('id');

        return $query->whereIn('deal_id', $deals);
    }

    private function canWrite(User $user, Deal $deal): bool
    {
        return $user->can('proposals.create') && $user->can('view', $deal) && ! $deal->stage->isClosed();
    }

    private function authorizeWrite(User $user, Deal $deal): void
    {
        abort_unless($this->canWrite($user, $deal), 403);
    }

    private function authorizeView(User $user, Proposal $proposal): void
    {
        abort_unless(
            $user->can('proposals.view.all')
            || ($user->can('proposals.view') && $user->can('view', $proposal->deal)),
            403,
        );
    }

    private function authorizeApproval(User $user, Proposal $proposal): void
    {
        abort_unless($user->can('proposals.approve') && $proposal->status === ProposalStatus::PendingApproval, 403);
        // Nobody approves their own discount.
        abort_if($this->shapedBy($user, $proposal), 403, 'Somebody else has to approve a discount you wrote, changed or submitted.');
    }

    /**
     * Whether this person had a hand in the number: wrote it, last changed it,
     * or submitted it. Any of the three approving it is approving their own
     * discount.
     */
    private function shapedBy(User $user, Proposal $proposal): bool
    {
        return in_array($user->id, array_filter([$proposal->created_by, $proposal->last_edited_by, $proposal->submitted_by]), true);
    }

    private function mayApprove(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.approve')
            && $proposal->status === ProposalStatus::PendingApproval
            && ! $this->shapedBy($user, $proposal);
    }

    /**
     * The ceiling every money column here can hold. Checked on the computed
     * total, because each line can be within its own limits and the sum still
     * not fit — which MySQL would answer with an error page.
     */
    private function guardTotal(Proposal $proposal): void
    {
        if ((float) $proposal->subtotal > 999999999) {
            throw ValidationException::withMessages(['items' => 'The total is larger than a proposal can hold (₹99,99,99,999). Check the quantities and rates.']);
        }
    }

    /** @return array<string, mixed> */
    private function row(Proposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'code' => $proposal->code,
            'version' => $proposal->version,
            'title' => $proposal->title,
            'status' => $proposal->status->value,
            'status_label' => $proposal->status->label(),
            'status_tone' => $proposal->status->tone(),
            'total' => (float) $proposal->total,
            'discount_percent' => (float) $proposal->discount_percent,
            'deal' => $proposal->deal ? ['id' => $proposal->deal->id, 'code' => $proposal->deal->code, 'title' => $proposal->deal->title] : null,
            'client' => $proposal->deal?->client?->name,
            'created_by' => $proposal->creator?->name,
            'updated_at' => $proposal->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function dealSummary(Deal $deal): array
    {
        $deal->loadMissing('client:id,name,contact_person,phone,email,city');

        return [
            'id' => $deal->id,
            'code' => $deal->code,
            'title' => $deal->title,
            'stage' => $deal->stage->value,
            'client' => $deal->client?->only(['id', 'name', 'contact_person', 'phone', 'email', 'city']),
        ];
    }

    private function defaultTerms(): string
    {
        return implode("\n", [
            'Take Care Connect is a non-profit; this proposal covers the work described above.',
            '50% on confirmation, the balance on delivery.',
            'Prices are in Indian rupees.',
        ]);
    }
}
