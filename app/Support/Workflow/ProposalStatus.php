<?php

namespace App\Support\Workflow;

/**
 * Where a proposal is.
 *
 *   draft ──submit──▶ approved ──send──▶ sent ──▶ accepted
 *     │                  ▲                  └──▶ declined
 *     └──submit (big discount)──▶ pending_approval ──approve──┘
 *                                        └──return──▶ rejected ──edit──▶ draft
 *
 * A proposal within the discount limit goes straight to approved: there is
 * nothing for a manager to look at, and making them click through every
 * routine quote is how approval becomes a rubber stamp.
 */
enum ProposalStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Waiting for approval',
            self::Approved => 'Ready to send',
            self::Rejected => 'Changes needed',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
        };
    }

    /** The colour of its chip, by what it means rather than its name. */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::PendingApproval => 'warning',
            self::Approved => 'info',
            self::Rejected => 'danger',
            self::Sent => 'info',
            self::Accepted => 'success',
            self::Declined => 'danger',
        };
    }

    /** Still being written — the only states in which the content can change. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /** It has gone to the buyer. */
    public function wasSent(): bool
    {
        return in_array($this, [self::Sent, self::Accepted, self::Declined], true);
    }
}
