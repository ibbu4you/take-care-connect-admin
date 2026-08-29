<?php

namespace App\Support\Workflow;

/**
 * How far a conversation with a prospect has got.
 *
 * ── Why this is a status and not a stage ───────────────────────────────────
 * Interviews, articles and deliverables go through `StageMachine`, which
 * refuses illegal moves because the order genuinely matters. A conversation
 * has no such order: somebody Interested goes quiet and is back to Contacted,
 * somebody Unqualified rings back in March. Enforcing a sequence here would
 * invent rules nobody asked for, and be worked around by people recording what
 * actually happened.
 *
 * The one exception is Converted, which is a door that only opens outwards —
 * it means a client record exists, and un-setting it would leave that client
 * behind with nothing pointing at it.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Interested = 'interested';
    case Qualified = 'qualified';
    case Unqualified = 'unqualified';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Interested => 'Interested',
            self::Qualified => 'Qualified',
            self::Unqualified => 'Not a fit',
            self::Converted => 'Converted',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::New => 'Nobody has spoken to them yet.',
            self::Contacted => 'Reached out. No answer either way.',
            self::Interested => 'They want to hear more.',
            self::Qualified => 'Worth the work — ready to become a client.',
            self::Unqualified => 'Not a fit, or not now. Kept for the record.',
            self::Converted => 'Now a client.',
        };
    }

    /** Still worth somebody's time this week. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Converted, self::Unqualified], true);
    }

    /**
     * Converting is a one-way door: it creates a client, and reversing the
     * status would leave that client with nothing pointing back at it.
     */
    public function isFinal(): bool
    {
        return $this === self::Converted;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * The ones somebody may set by hand.
     *
     * Converted is left out on purpose: it is the result of pressing Convert,
     * which creates a client. Offering it in a dropdown would let somebody mark
     * a lead converted with no client behind it.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_values(array_map(
            static fn (self $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'description' => $status->description(),
            ],
            array_filter(self::cases(), static fn (self $status): bool => ! $status->isFinal()),
        ));
    }
}
