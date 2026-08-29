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
            && $this->view($actor, $deliverable);
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
            && $deliverable->assigned_to !== $actor->getKey()
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
            && $this->view($actor, $deliverable);
    }

    public function assign(User $actor, Deliverable $deliverable): bool
    {
        return $actor->can('deliverables.assign') && $this->view($actor, $deliverable);
    }

    public function recordLink(User $actor, Deliverable $deliverable): bool
    {
        return $actor->can('social.links.record') && $this->view($actor, $deliverable);
    }

    public function delete(User $actor, Deliverable $deliverable): bool
    {
        return $actor->can('deliverables.delete') && $deliverable->stage !== DeliverableStage::Published;
    }

    public function downloadFile(User $actor, Deliverable $deliverable): bool
    {
        return $this->view($actor, $deliverable);
    }
}
