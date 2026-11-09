<?php

namespace App\Support\Workflow;

/**
 * The life of a poster, a reel or an edit.
 *
 *   pending ──► in_progress ──► review ──► approved ──► scheduled ──► published
 *                    ▲             │                        │
 *                    └── rejected ─┘                        └──► published
 *
 * `rejected → review` exists alongside `rejected → in_progress` on purpose, and
 * for the same reason the article workflow has it: a creative who reads the note,
 * decides the cut already answers it, and resubmits must not be stuck. Without
 * the direct edge, every round of feedback after the first dead-ends.
 *
 * `scheduled` is optional. Plenty of work is approved and posted the same
 * afternoon; forcing it through a scheduling step it never had would make the
 * stage a lie.
 */
enum DeliverableStage: string implements Stage
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Review = 'review';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In progress',
            self::Review => 'In review',
            self::Rejected => 'Rejected',
            self::Approved => 'Approved',
            self::Scheduled => 'Scheduled',
            self::Published => 'Published',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress],
            self::InProgress => [self::Review],
            self::Review => [self::Approved, self::Rejected],
            self::Rejected => [self::InProgress, self::Review],
            self::Approved => [self::Scheduled, self::Published],
            self::Scheduled => [self::Published],
            self::Published => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Whether the creative is the one who has to act next. */
    public function isWithCreative(): bool
    {
        return in_array($this, [self::Pending, self::InProgress, self::Rejected], true);
    }

    public function isAwaitingApproval(): bool
    {
        return $this === self::Review;
    }

    /** Cleared to go out, but not out yet. */
    public function isReadyToPost(): bool
    {
        return in_array($this, [self::Approved, self::Scheduled], true);
    }

    /**
     * The board's columns, in order.
     *
     * `rejected` is deliberately absent: a rejected deliverable is work in
     * somebody's hands, not a resting place, and giving it a column of its own
     * would let things sit there looking filed. It shows as a flag on the
     * in-progress card instead.
     *
     * `scheduled` IS a column. It used to be missing, and a deliverable queued
     * for posting dropped off the board entirely between Approved and Published.
     *
     * @return list<self>
     */
    public static function boardColumns(): array
    {
        return [self::Pending, self::InProgress, self::Review, self::Approved, self::Scheduled, self::Published];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $stage): string => $stage->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $stage): array => ['value' => $stage->value, 'label' => $stage->label()],
            self::cases(),
        );
    }
}
