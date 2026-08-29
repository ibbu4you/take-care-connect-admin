<?php

namespace App\Support\Workflow;

/**
 * The life of an article.
 *
 *   assigned ──► drafting ──► internal_review ──► approved ──► published
 *                    ▲              │
 *                    └── revisions ─┘
 *
 * The loop in the middle is the point of the whole thing. A review either
 * approves or sends back with notes, and a piece can go round more than once —
 * so `revisions` returns to `drafting` rather than being a dead end, and the
 * reviewer's note is kept on the article where the writer will actually see it.
 *
 * `approved → drafting` exists for the case everybody eventually hits: the
 * article is approved, and then somebody spots a wrong figure before it has been
 * published. Once it IS published it is terminal here — the correction happens
 * on the live site, and pretending otherwise would leave this system claiming a
 * draft state for something the public can already read.
 */
enum ArticleStage: string implements Stage
{
    case Assigned = 'assigned';
    case Drafting = 'drafting';
    case InternalReview = 'internal_review';
    case Revisions = 'revisions';
    case Approved = 'approved';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Assigned',
            self::Drafting => 'Drafting',
            self::InternalReview => 'In review',
            self::Revisions => 'Revisions',
            self::Approved => 'Approved',
            self::Published => 'Published',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Assigned => [self::Drafting],
            self::Drafting => [self::InternalReview],
            self::InternalReview => [self::Approved, self::Revisions],
            /*
             | Both, deliberately.
             |
             | The normal path is revisions -> drafting (the writer saves, which
             | signals work has restarted) -> internal_review. But a writer who
             | opens the article, decides the note was already addressed and
             | resubmits without editing must not be stuck: without the direct
             | edge, every round of notes after the first would dead-end.
             */
            self::Revisions => [self::Drafting, self::InternalReview],
            self::Approved => [self::Published, self::Drafting],
            self::Published => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Whether the writer is the one who has to act next. */
    public function isWithWriter(): bool
    {
        return in_array($this, [self::Assigned, self::Drafting, self::Revisions], true);
    }

    /** Whether it is sitting with a reviewer. */
    public function isAwaitingReview(): bool
    {
        return $this === self::InternalReview;
    }

    /**
     * What the live site would call this.
     *
     * Derived rather than stored, so it cannot drift from the stage. Mirrors
     * takecareconnect.com's ContentStatus, which is what the export bundle
     * tells whoever publishes to set.
     */
    public function websiteStatus(): string
    {
        return match ($this) {
            self::Published => 'published',
            self::Approved => 'pending_review',
            default => 'draft',
        };
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
