<?php

namespace App\Support\Workflow;

use App\Services\StageMachine;

/**
 * A stage in one of this application's workflows.
 *
 * Implemented by backed enums — InterviewStage now, ArticleStage and
 * DeliverableStage later — so {@see StageMachine} can move any
 * record through any workflow without knowing which one it is looking at.
 *
 * The enum owns its own transition rules. That is deliberate: "what may follow
 * what" is the definition of the workflow, and keeping it beside the cases means
 * there is exactly one place to read the process off.
 */
interface Stage
{
    /** How this stage is written for a person. */
    public function label(): string;

    /**
     * The stages this one may move to.
     *
     * An empty list means terminal.
     *
     * @return list<static>
     */
    public function allowedNext(): array;

    /** Whether the record is finished, one way or another. */
    public function isTerminal(): bool;
}
