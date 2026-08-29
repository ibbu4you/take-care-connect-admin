<?php

namespace App\Policies;

use App\Models\Deal;
use App\Models\User;
use App\Support\Scope;

/**
 * Who may work the pipeline.
 *
 * Three separate permissions, because they are three different acts:
 *
 *  · `deals.update`  edit the card
 *  · `deals.move`    drag it to another column
 *  · `deals.close`   write revenue against it
 *
 * Splitting them means the board can be handed to somebody read-only, and that
 * closing — the one action every attainment figure is a sum of — is granted
 * deliberately rather than as a side effect of being able to edit a title.
 */
class DealPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canAny(['deals.view', 'deals.view.all']);
    }

    public function view(User $actor, Deal $deal): bool
    {
        if (! $this->viewAny($actor)) {
            return false;
        }

        if (Scope::seesEverything($actor, 'deals')) {
            return true;
        }

        return $deal->owner_id === $actor->getKey();
    }

    public function create(User $actor): bool
    {
        return $actor->can('deals.create');
    }

    public function update(User $actor, Deal $deal): bool
    {
        return $actor->can('deals.update')
            && ! $deal->stage->isClosed()
            && $this->view($actor, $deal);
    }

    public function move(User $actor, Deal $deal): bool
    {
        return $actor->can('deals.move')
            && ! $deal->stage->isClosed()
            && $this->view($actor, $deal);
    }

    /**
     * Closing writes revenue, so it happens once.
     *
     * A closed deal stays closed: reopening one would make "won this month" a
     * figure that can change after the month has been reported.
     */
    public function close(User $actor, Deal $deal): bool
    {
        return $actor->can('deals.close')
            && ! $deal->stage->isClosed()
            && $this->view($actor, $deal);
    }

    public function delete(User $actor, Deal $deal): bool
    {
        return $actor->can('deals.delete');
    }
}
