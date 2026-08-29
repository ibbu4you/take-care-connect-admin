<?php

namespace App\Support\Workflow;

/**
 * The three kinds of interview an anchor runs.
 *
 * The distinction that matters is whether the interview produces a website
 * article. Only the business interview does; the two social kinds are tracked
 * here and end at their published YouTube and Instagram links, with no article
 * and no content-team involvement at all.
 *
 * That is why the handover requirements differ so sharply: a business interview
 * cannot be handed over without a document, a recording and typed Q&A, because
 * somebody else has to write from them. A social interview needs none of that,
 * because the person who ran it is the person who finishes it.
 */
enum InterviewKind: string
{
    /** A celebrity, interviewed in person. Social output only. */
    case CelebritySocial = 'celebrity_social';

    /** A business owner, over Google Meet. Handed to content, becomes an article. */
    case BusinessArticle = 'business_article';

    /** Over Google Meet. Social output only. */
    case MeetSocial = 'meet_social';

    public function label(): string
    {
        return match ($this) {
            self::CelebritySocial => 'Celebrity · social',
            self::BusinessArticle => 'Business · article',
            self::MeetSocial => 'Meet · social',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CelebritySocial => 'Interviewed in person. Goes to the creative team for reels and posters — no website article.',
            self::BusinessArticle => 'Over Google Meet. Handed to the content team with the write-up, recording and Q&A, and becomes an article.',
            self::MeetSocial => 'Over Google Meet. Goes to the creative team for reels and posters — no website article.',
        };
    }

    /** Only the business interview is written up for the website. */
    public function requiresArticle(): bool
    {
        return $this === self::BusinessArticle;
    }

    /** The default meeting mode, which the anchor can still override. */
    public function defaultMode(): string
    {
        return $this === self::CelebritySocial ? 'in_person' : 'google_meet';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string, description: string, requires_article: bool}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'description' => $kind->description(),
                'requires_article' => $kind->requiresArticle(),
            ],
            self::cases(),
        );
    }
}
