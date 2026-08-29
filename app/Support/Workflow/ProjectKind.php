<?php

namespace App\Support\Workflow;

/**
 * What a client actually bought.
 *
 * A project is the container the sales conversation turns into: the deal is
 * "will they buy", the project is "what we now owe them". The kind is what
 * tells an anchor looking at a booking whether it is one interview or the
 * fourth of six.
 */
enum ProjectKind: string
{
    case InterviewFeature = 'interview_feature';
    case SocialCampaign = 'social_campaign';
    case Sponsorship = 'sponsorship';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::InterviewFeature => 'Interview feature',
            self::SocialCampaign => 'Social campaign',
            self::Sponsorship => 'Sponsorship',
            self::Other => 'Other',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::InterviewFeature => 'One or more interviews written up and published.',
            self::SocialCampaign => 'A run of posters, reels and edits over a period.',
            self::Sponsorship => 'A sponsored slot or series.',
            self::Other => 'Anything that does not fit the three above.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'description' => $kind->description(),
            ],
            self::cases(),
        );
    }
}
