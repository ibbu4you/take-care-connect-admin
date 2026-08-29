<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Support\Scope;

/**
 * Who may see and edit a client account.
 *
 * A relationship manager works their own book: `clients.view` means the clients
 * they own, and only somebody holding `clients.view.all` sees the whole
 * directory. That is not secrecy — it keeps each manager's list about the people
 * they are actually responsible for calling.
 */
class ClientPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canAny(['clients.view', 'clients.view.all']);
    }

    public function view(User $actor, Client $client): bool
    {
        if (! $this->viewAny($actor)) {
            return false;
        }

        if (Scope::seesEverything($actor, 'clients')) {
            return true;
        }

        return $client->owner_id === $actor->getKey() || $client->created_by === $actor->getKey();
    }

    public function create(User $actor): bool
    {
        return $actor->can('clients.create');
    }

    public function update(User $actor, Client $client): bool
    {
        return $actor->can('clients.update') && $this->view($actor, $client);
    }

    public function delete(User $actor, Client $client): bool
    {
        return $actor->can('clients.delete');
    }

    /**
     * Moving an account to a different owner.
     *
     * Deliberately not something the losing or gaining manager decides — it is
     * an administrative call, which is why `clients.assign` sits with Admin
     * rather than with the Relationship Manager role.
     */
    public function assign(User $actor, Client $client): bool
    {
        return $actor->can('clients.assign');
    }
}
