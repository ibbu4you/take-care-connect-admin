<?php

namespace App\Support\Workflow;

/**
 * Where a project stands.
 *
 * ── Why this is a status and not a stage ───────────────────────────────────
 * Everything else in this system that moves — interviews, articles,
 * deliverables, deals — goes through `StageMachine`, which refuses illegal
 * transitions because the order genuinely matters: an article cannot be
 * approved before it is written.
 *
 * A project has no such order. It is open until the work is delivered, and it
 * can be cancelled from anywhere, including after delivery when a client
 * pulls out. Putting it behind a transition table would invent rules nobody
 * asked for and then be worked around by people who needed to record what
 * actually happened.
 *
 * What it does have is a distinction worth keeping: `Delivered` means we did
 * the work, `Closed` means the file is finished — usually paid. Collapsing the
 * two would lose the gap between shipping and being paid for shipping, which
 * is the gap a manager most wants to see.
 */
enum ProjectStatus: string
{
    case Open = 'open';
    case Delivered = 'delivered';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Delivered => 'Delivered',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Open => 'Work is under way.',
            self::Delivered => 'Everything promised has been handed over.',
            self::Closed => 'Delivered and settled — nothing outstanding.',
            self::Cancelled => 'Called off. Kept for the record.',
        };
    }

    /** Still consuming attention, and still counted in what is owed. */
    public function isActive(): bool
    {
        return $this === self::Open;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'description' => $status->description(),
            ],
            self::cases(),
        );
    }
}
