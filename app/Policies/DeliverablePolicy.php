<?php

namespace App\Policies;

use App\Models\Deliverable;
use App\Models\Interview;
use App\Models\User;
use App\Support\Scope;
use App\Support\Workflow\DeliverableStage;

/**
 * Who may commission, make, approve and publish creative work.
 *
 * The separation that shapes this class: **the person who makes the work does
 * not approve it, and the person who approves it does not publish it.**
 *
 * That is not bureaucracy for its own sake. A poster goes out under the
 * organisation's name with a client's phone number on it — the client-facing
 * Relationship Manager is the one who will hear about it if that number is
 * wrong, so they approve. The creative who made it is the one with the platform
 * account open, so they post. Neither can do both halves alone.
 */
class DeliverablePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canAny(['deliverables.view', 'deliverables.view.all']);
    }

    /**
     * Seeing one.
     *
     * A creative holds no `.all` at all, so this is the whole mechanism by
     * which they reach the work on their own desk — and, through the parent
     * check below, the interview it was cut from.
     */
    public function view(User $actor, Deliverable $deliverable): bool
    {
        if (! $this->viewAny($actor)) {
            return false;
        }

        if (Scope::seesEverything($actor, 'deliverables')) {
            return true;
        }

        if ($deliverable->assigned_to === $actor->getKey() || $deliverable->assigned_by === $actor->getKey()) {
            return true;
        }

        /*
         | Somebody on the parent interview can see its deliverables.
         |
         | The anchor who ran the shoot should be able to see the reel cut from
         | it without being assigned the editing work — otherwise they have no
         | way of knowing whether their own interview ever went out.
         */
        $parent = $deliverable->deliverableOf;

        if ($parent instanceof Interview) {
            return in_array($actor->getKey(), array_filter([
                $parent->anchor_id,
                $parent->scheduled_by,
                $parent->creative_owner_id,
                $parent->content_owner_id,
            ]), true);
        }

        return false;
    }

    /** Commissioning work — an anchor or the content desk, off their own material. */
    public function create(User $actor): bool
    {
        return $actor->can('deliverables.create');
    }

    public function update(User $actor, Deliverable $deliverable): bool
    {
        return $actor->can('deliverables.update')
            && $deliverable->stage !== DeliverableStage::Published
            && $this->handsOn($actor, $deliverable);
    }

    /**
     * Seeing a piece is wider than changing it.
     *
     * The interview's creative may look at every piece cut from it, but only
     * the person making a piece — or whoever requested it, runs the interview,
     * or oversees everything — may change or post it. Reading and editing used
     * to be the same test, so one creative could rewrite or post another's reel.
     */
    private function handsOn(User $actor, Deliverable $deliverable): bool
    {
        if (! $this->view($actor, $deliverable)) {
            return false;
        }

        $id = $actor->getKey();

        if (Scope::seesEverything($actor, 'deliverables')
            || $deliverable->assigned_to === $id
            || $deliverable->assigned_by === $id) {
            return true;
        }

        $parent = $deliverable->deliverableOf;

        return $parent instanceof Interview
            && in_array($id, array_filter([$parent->anchor_id, $parent->scheduled_by, $parent->content_owner_id]), true);
    }

    /**
     * Uploading a cut and sending it for approval.
     *
     * Restricted to the assignee: `deliverables.work` is "do the creative work",
     * and doing it on somebody else's behalf is a reassignment, not a shortcut.
     */
    public function work(User $actor, Deliverable $deliverable): bool
    {
        return $actor->can('deliverables.work')
            && $deliverable->assigned_to === $actor->getKey()
            && $deliverable->stage->isWithCreative();
    }

    public function submit(User $actor, Deliverable $deliverable): bool
    {
        return $this->work($actor, $deliverable);
    }

    /**
     * Approving, or sending it back.
     *
     * Not the person who made it — see the note at the top of this class.
     */
    public function approve(User $actor, Deliverable $deliverable): bool
    {
        return $actor->can('deliverables.approve')
            && $deliverable->stage === DeliverableStage::Review
            && ! $this->shapedBy($actor, $deliverable)
            && $this->view($actor, $deliverable);
    }

    /**
     * Marking it posted and recording the link.
     *
     * Only from approved or scheduled: posting something that never passed
     * approval would make the approval step optional in practice.
     */
    public function publish(User $actor, Deliverable $deliverable): bool
    {
        return $actor->can('deliverables.publish')
            && $deliverable->stage->isReadyToPost()
            // Whoever approved it does not also post it: two people see every
            // piece before it goes out under the organisation's name.
            && $deliverable->approved_by !== $actor->getKey()
            && $this->handsOn($actor, $deliverable);
    }

    public function assign(User $actor, Deliverable $deliverable): bool
    {
        return $actor->can('deliverables.assign') && $this->view($actor, $deliverable);
    }

    public function recordLink(User $actor, Deliverable $deliverable): bool
    {
        // Extra links are for work already out — the same reel cross-posted.
        // On anything earlier, a link would mark unapproved work as live.
        return $actor->can('social.links.record')
            && $deliverable->stage === DeliverableStage::Published
            && $this->view($actor, $deliverable);
    }

    public function delete(User $actor, Deliverable $deliverable): bool
    {
        return $actor->can('deliverables.delete') && $deliverable->stage !== DeliverableStage::Published;
    }

    /**
     * Whether this person made the piece under review.
     *
     * The assignee alone could be changed by reassigning first, so it also
     * counts who submitted it and who uploaded any version of it.
     */
    private function shapedBy(User $actor, Deliverable $deliverable): bool
    {
        $id = $actor->getKey();

        return $deliverable->assigned_to === $id
            || $deliverable->submitted_by === $id
            || $deliverable->files()->where('uploaded_by', $id)->exists();
    }

    public function downloadFile(User $actor, Deliverable $deliverable): bool
    {
        return $this->view($actor, $deliverable);
    }
}
