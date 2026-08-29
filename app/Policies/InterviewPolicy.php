<?php

namespace App\Policies;

use App\Models\Interview;
use App\Models\User;
use App\Support\Scope;
use App\Support\Workflow\InterviewStage;

/**
 * Who may see and act on an interview.
 *
 * Two layers, doing different jobs. The permission answers "is this person in
 * this line of work at all"; the rest of each method answers "is this
 * *their* interview, and is it at a point where that action makes sense".
 *
 * The second half is what stops an anchor conducting somebody else's interview,
 * and what stops a handover being attempted on one that was cancelled last week.
 */
class InterviewPolicy
{
    /**
     * The columns that make somebody a participant.
     *
     * All four, not just the anchor: the manager who booked it wants to know it
     * happened, the writer needs the material, and the creative cutting the reel
     * needs the context. A creative holds no `interviews.view.all` at all — this
     * list is the whole reason they can see the one interview they are working
     * on and no others.
     *
     * @var list<string>
     */
    private const PARTICIPANTS = [
        'anchor_id',
        'scheduled_by',
        'content_owner_id',
        'creative_owner_id',
    ];

    public function viewAny(User $actor): bool
    {
        return $actor->canAny(['interviews.view', 'interviews.view.all']);
    }

    public function view(User $actor, Interview $interview): bool
    {
        if (! $this->viewAny($actor)) {
            return false;
        }

        if (Scope::seesEverything($actor, 'interviews')) {
            return true;
        }

        return $this->isParticipant($actor, $interview);
    }

    public function create(User $actor): bool
    {
        return $actor->can('interviews.create');
    }

    /** Booking and rescheduling is the relationship manager's job. */
    public function schedule(User $actor, Interview $interview): bool
    {
        return $actor->can('interviews.schedule') && $this->view($actor, $interview);
    }

    public function update(User $actor, Interview $interview): bool
    {
        if (! $actor->can('interviews.update') || ! $this->view($actor, $interview)) {
            return false;
        }

        // A finished interview is a record, not a working document. Editing one
        // after publication would silently change what a published article was
        // written from.
        return ! in_array($interview->stage, [InterviewStage::Published, InterviewStage::Cancelled], true);
    }

    /**
     * Only the anchor on the interview may mark it conducted.
     *
     * It is a statement about what they personally did, so it is not something
     * a colleague can record on their behalf — an Admin can, via the Gate
     * bypass, for the case where somebody has left.
     */
    public function conduct(User $actor, Interview $interview): bool
    {
        return $actor->can('interviews.conduct')
            && $interview->anchor_id === $actor->getKey()
            && $interview->stage->isOpenForAnchor();
    }

    public function handover(User $actor, Interview $interview): bool
    {
        return $actor->can('interviews.handover')
            && $interview->kind->requiresArticle()
            && $interview->stage === InterviewStage::Conducted
            && $this->isParticipant($actor, $interview);
    }

    public function reassign(User $actor, Interview $interview): bool
    {
        return $actor->can('interviews.reassign') && $this->view($actor, $interview);
    }

    public function cancel(User $actor, Interview $interview): bool
    {
        return $actor->can('interviews.cancel')
            && ! $interview->stage->isTerminal()
            && $this->view($actor, $interview);
    }

    public function delete(User $actor, Interview $interview): bool
    {
        return $actor->can('interviews.delete');
    }

    /** Attaching the transcript, the recording and the photos. */
    public function uploadAssets(User $actor, Interview $interview): bool
    {
        return $actor->can('assets.upload')
            && $this->view($actor, $interview)
            && ! $interview->stage->isTerminal();
    }

    /**
     * Opening a file.
     *
     * Derived from being able to see the interview rather than from a blanket
     * `assets.view.all`, which is why neither the content team nor the creative
     * team holds that string: seeing the record is what earns access to what is
     * attached to it.
     */
    public function downloadAsset(User $actor, Interview $interview): bool
    {
        return $this->view($actor, $interview);
    }

    public function deleteAsset(User $actor, Interview $interview): bool
    {
        // Whoever may attach material may remove it, up until the point it has
        // been handed on and somebody else is relying on it.
        return $this->uploadAssets($actor, $interview)
            && $interview->stage->isOpenForAnchor();
    }

    private function isParticipant(User $actor, Interview $interview): bool
    {
        foreach (self::PARTICIPANTS as $column) {
            if ($interview->{$column} === $actor->getKey()) {
                return true;
            }
        }

        return false;
    }
}
