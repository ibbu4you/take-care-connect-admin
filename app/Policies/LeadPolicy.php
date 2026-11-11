<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Support\Scope;

/**
 * Who may see and work a lead.
 *
 * A manager works their own list. `leads.view` means the leads they own or
 * added; only `leads.view.all` opens everybody's. That is not secrecy — two
 * managers calling the same prospect is the thing it prevents, and a list of
 * four hundred names nobody owns is a list nobody works.
 *
 * Nobody outside sales holds any of it. An anchor has no reason to see who is
 * being cold-called, and a Leads section in a writer's sidebar is the same
 * mistake as a Sales section in a video editor's.
 */
class LeadPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canAny(['leads.view', 'leads.view.all']);
    }

    public function view(User $actor, Lead $lead): bool
    {
        if (! $this->viewAny($actor)) {
            return false;
        }

        if (Scope::seesEverything($actor, 'leads')) {
            return true;
        }

        return $lead->owner_id === $actor->getKey() || $lead->created_by === $actor->getKey();
    }

    public function create(User $actor): bool
    {
        return $actor->can('leads.create');
    }

    public function update(User $actor, Lead $lead): bool
    {
        return $actor->can('leads.update') && $this->view($actor, $lead);
    }

    /**
     * Turning a lead into a client.
     *
     * Refused on one already converted, because a second conversion would make
     * a second client for the same business and leave the lead pointing at only
     * one of them.
     */
    public function convert(User $actor, Lead $lead): bool
    {
        // Either sign of an earlier conversion is enough — the status alone
        // could once be edited back, which reopened the door to a second client.
        if ($lead->status->isFinal() || $lead->converted_client_id !== null) {
            return false;
        }

        return $actor->can('leads.convert') && $this->view($actor, $lead);
    }

    /**
     * Deleting is for a lead entered twice, not for one that said no.
     *
     * "Not a fit" is the answer to that, and it keeps the record — which is the
     * point of writing prospects down in the first place. A converted lead is
     * never deletable: it is the provenance of a live client.
     */
    public function delete(User $actor, Lead $lead): bool
    {
        if ($lead->status->isFinal()) {
            return false;
        }

        return $actor->can('leads.delete');
    }
}
