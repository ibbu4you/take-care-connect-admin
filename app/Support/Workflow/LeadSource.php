<?php

namespace App\Support\Workflow;

/**
 * Where a lead came from.
 *
 * ── Why this is worth recording ────────────────────────────────────────────
 * It is the one field that changes what a manager does next quarter. Knowing
 * that referrals convert three times better than field visits is the difference
 * between a plan and a habit, and the reports can only say so if somebody
 * writes it down at the moment the name arrives.
 *
 * Kept short on purpose. A list of twenty sources is a list nobody picks from
 * accurately, and an inaccurate source is worse than none — it produces a
 * confident answer to the question it exists to answer.
 */
enum LeadSource: string
{
    case Referral = 'referral';
    case Field = 'field';
    case WebsiteForm = 'website_form';
    case Call = 'call';
    case Social = 'social';
    case Event = 'event';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Referral => 'Referral',
            self::Field => 'Field visit',
            self::WebsiteForm => 'Website enquiry',
            self::Call => 'Inbound call',
            self::Social => 'Social media',
            self::Event => 'Event or expo',
            self::Manual => 'Added by hand',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $source): array => ['value' => $source->value, 'label' => $source->label()],
            self::cases(),
        );
    }
}
