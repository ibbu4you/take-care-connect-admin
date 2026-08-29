<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\Scope;

/**
 * Who may see and change a project.
 *
 * ── Why the read rule is wider than the write rule ─────────────────────────
 * A manager owns their own projects, but an anchor booked onto an interview
 * needs to see which package it belongs to and how far through it they are —
 * "this is the fourth of six" changes how they run the conversation. So Anchor
 * and Content hold `projects.view` while neither holds `projects.create` or
 * `projects.update`: they read the container their work sits in, and only the
 * sales side changes what was sold.
 *
 * Creative deliberately holds neither. The project behind a reel is shown on
 * the deliverable itself, and granting the list as well would put a whole
 * Sales section in a video editor's sidebar for one link.
 */
class ProjectPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canAny(['projects.view', 'projects.view.all']);
    }

    public function view(User $actor, Project $project): bool
    {
        if (! $this->viewAny($actor)) {
            return false;
        }

        if (Scope::seesEverything($actor, 'projects')) {
            return true;
        }

        if ($project->owner_id === $actor->getKey() || $project->created_by === $actor->getKey()) {
            return true;
        }

        /*
         | Whoever is working on it can read it.
         |
         | Mirrors the deliverable rule: somebody assigned to an interview under
         | this project can open the project behind it. Without this an anchor
         | sees "PRJ-000004" on their booking and cannot click it, which is
         | worse than not showing the link at all.
         */
        return $project->interviews()
            ->where(function ($interview) use ($actor) {
                $interview->where('anchor_id', $actor->getKey())
                    ->orWhere('scheduled_by', $actor->getKey())
                    ->orWhere('content_owner_id', $actor->getKey())
                    ->orWhere('creative_owner_id', $actor->getKey());
            })
            ->exists();
    }

    public function create(User $actor): bool
    {
        return $actor->can('projects.create');
    }

    public function update(User $actor, Project $project): bool
    {
        return $actor->can('projects.update') && $this->view($actor, $project);
    }

    /**
     * Deleting is for a project created by mistake, not for one that ended
     * badly — that is what Cancelled is for, and it keeps the history.
     */
    public function delete(User $actor, Project $project): bool
    {
        if ($project->interviews()->exists()) {
            return false;
        }

        return $actor->can('projects.delete');
    }
}
