<?php

namespace App\Services;

use App\Exceptions\InvalidStageTransition;
use App\Models\StageEvent;
use App\Models\User;
use App\Support\Workflow\Stage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only thing in this application that writes a `stage` column.
 *
 * Every workflow — interviews now, articles, deliverables and deals later —
 * moves through here. Centralising it buys four guarantees that are otherwise
 * re-implemented (and eventually forgotten) at every call site:
 *
 *  1. Illegal moves are refused. A record cannot skip from scheduled straight
 *     to published because one controller forgot the middle step.
 *  2. `stage_entered_at` is stamped, so "how long has this been sitting there"
 *     is answerable without reconstructing it from the event log.
 *  3. A stage_events row is written, always. History cannot drift from the
 *     column it describes, because nothing can change one without the other.
 *  4. The whole thing is one transaction, so a failure cannot leave a record
 *     moved with no record of the move.
 */
class StageMachine
{
    /**
     * Move a record to a new stage.
     *
     * @param  list<Stage>|null  $allowed  overrides the stage's own rules — used
     *                                     where the record narrows them, e.g. an
     *                                     interview that cannot be handed over
     *                                     because it is not a business one
     *
     * @throws InvalidStageTransition when the move is not permitted by the workflow
     */
    public function transition(
        Model $model,
        Stage $to,
        ?User $by = null,
        ?string $note = null,
        ?array $allowed = null,
    ): Model {
        /** @var Stage $from */
        $from = $model->stage;

        // Moving to the stage it is already in is a no-op rather than an error:
        // a double-submitted form should not be a failure the user has to read.
        if ($from === $to) {
            return $model;
        }

        $permitted = $allowed ?? $from->allowedNext();

        if (! in_array($to, $permitted, true)) {
            throw InvalidStageTransition::between($from, $to);
        }

        return DB::transaction(function () use ($model, $from, $to, $by, $note): Model {
            $model->stage = $to;
            $model->stage_entered_at = now();
            $model->save();

            StageEvent::create([
                'subject_type' => $model->getMorphClass(),
                'subject_id' => $model->getKey(),
                'from_stage' => $from->value,
                'to_stage' => $to->value,
                'changed_by' => $by?->getKey(),
                'note' => $note,
                'changed_at' => now(),
            ]);

            return $model;
        });
    }

    /**
     * Record a newly created record entering its opening stage.
     *
     * `from_stage` is null: it came from nowhere. Without this the history of
     * every record would begin at its second move, and "when was this booked"
     * would have no entry at all.
     */
    public function recordCreation(Model $model, ?User $by = null, ?string $note = null): void
    {
        /** @var Stage $stage */
        $stage = $model->stage;

        StageEvent::create([
            'subject_type' => $model->getMorphClass(),
            'subject_id' => $model->getKey(),
            'from_stage' => null,
            'to_stage' => $stage->value,
            'changed_by' => $by?->getKey(),
            'note' => $note,
            'changed_at' => now(),
        ]);
    }
}
