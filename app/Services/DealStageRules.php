<?php

namespace App\Services;

use App\Models\Deal;
use App\Support\Workflow\PipelineStage;
use App\Support\Workflow\ProposalStatus;

/**
 * What a deal must already have before it can sit in a stage.
 *
 * Each pipeline stage is a claim — "we have met them", "a proposal went out".
 * This is what keeps the claim true: a card cannot be dragged into "Meeting
 * held" without a meeting on record, or into "Proposal sent" without a sent
 * proposal. That is what makes the board, and the forecast weighted by it,
 * mean the same thing whoever is reading it.
 *
 * Moving backwards is never blocked — a stalled negotiation really is back to
 * "contacted" — so these only describe what a stage needs, not where a deal
 * came from.
 */
class DealStageRules
{
    /**
     * Plain sentences for each thing that is missing; empty when the move is fine.
     *
     * @return list<string>
     */
    public function missing(Deal $deal, PipelineStage $to): array
    {
        $missing = [];

        if ($to === PipelineStage::Meeting && ! $this->hasMeetingHeld($deal)) {
            $missing[] = 'log the meeting first (a meeting or visit marked "Held")';
        }

        if (in_array($to, [PipelineStage::Proposal, PipelineStage::Negotiation], true)) {
            if (! $this->hasSentProposal($deal)) {
                $missing[] = 'send a proposal first';
            }

            // The forecast is built on this date. A deal at proposal stage with
            // no expected close date is invisible to it.
            if ($deal->expected_close_on === null) {
                $missing[] = 'set the expected close date';
            }
        }

        return $missing;
    }

    /** One sentence for the person who tried the move. */
    public function explain(PipelineStage $to, array $missing): string
    {
        return "To move this to {$to->label()}, ".implode(' and ', $missing).'.';
    }

    private function hasMeetingHeld(Deal $deal): bool
    {
        return $deal->activities()
            ->whereIn('type', ['meeting', 'visit'])
            ->where('outcome', 'held')
            ->exists();
    }

    private function hasSentProposal(Deal $deal): bool
    {
        return $deal->proposals()
            ->whereIn('status', [ProposalStatus::Sent->value, ProposalStatus::Accepted->value, ProposalStatus::Declined->value])
            ->exists();
    }
}
