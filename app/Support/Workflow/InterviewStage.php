<?php

namespace App\Support\Workflow;

/**
 * The life of an interview.
 *
 *   scheduled ──► conducted ──► handed_over ──► in_content ──► published
 *       │  ▲          │
 *       │  └── rescheduled
 *       │
 *       └──► cancelled | no_show
 *
 * Two things are worth noticing in the transitions below.
 *
 * First, `conducted → handed_over` only exists for a business interview. The two
 * social kinds are finished the moment they are conducted; there is nobody to
 * hand them to, and the deliverables that come off them hang directly on the
 * interview. That branch lives in {@see Interview::allowedNextStages()}, which
 * narrows this list by kind.
 *
 * Second, `handed_over` cannot go back to `conducted`. Once the content team
 * holds the material, "un-handing" it would leave an article row pointing at an
 * interview that claims not to have been handed over. Correcting a bad handover
 * is a reassignment, not a reversal.
 */
enum InterviewStage: string implements Stage
{
    case Scheduled = 'scheduled';
    case Rescheduled = 'rescheduled';
    case Conducted = 'conducted';
    case HandedOver = 'handed_over';
    case InContent = 'in_content';
    case Published = 'published';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Rescheduled => 'Rescheduled',
            self::Conducted => 'Conducted',
            self::HandedOver => 'Handed over',
            self::InContent => 'With content',
            self::Published => 'Published',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No show',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Scheduled => [self::Rescheduled, self::Conducted, self::Cancelled, self::NoShow],
            self::Rescheduled => [self::Rescheduled, self::Conducted, self::Cancelled, self::NoShow],
            self::Conducted => [self::HandedOver, self::Published],
            self::HandedOver => [self::InContent],
            self::InContent => [self::Published],

            // A no-show is recoverable: the person did not turn up, so the
            // interview goes back in the diary. A cancellation is not — that
            // decision was made deliberately, and reversing it silently would
            // resurrect work somebody had written off.
            self::NoShow => [self::Rescheduled],
            self::Cancelled => [],
            self::Published => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Stages that mean the interview never happened. */
    public function isAbandoned(): bool
    {
        return in_array($this, [self::Cancelled, self::NoShow], true);
    }

    /** Whether the anchor still has work to do on it. */
    public function isOpenForAnchor(): bool
    {
        return in_array($this, [self::Scheduled, self::Rescheduled, self::Conducted], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $stage): string => $stage->value, self::cases());
    }

    /**
     * The catalogue the frontend renders as filter options.
     *
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
