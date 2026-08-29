<?php

namespace App\Support\Workflow;

/**
 * What the creative team actually makes.
 *
 * The kinds carry their own default platforms, because "a reel goes to
 * Instagram" is knowledge the team has and the form should not ask for every
 * single time.
 */
enum DeliverableKind: string
{
    case Poster = 'poster';
    case Reel = 'reel';
    case VideoEdit = 'video_edit';
    case Carousel = 'carousel';
    case Story = 'story';
    case Thumbnail = 'thumbnail';
    case Short = 'short';

    public function label(): string
    {
        return match ($this) {
            self::Poster => 'Poster',
            self::Reel => 'Reel',
            self::VideoEdit => 'Video edit',
            self::Carousel => 'Carousel',
            self::Story => 'Story',
            self::Thumbnail => 'Thumbnail',
            self::Short => 'YouTube Short',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Poster => 'A single still for the feed.',
            self::Reel => 'A short vertical video, usually 30–60 seconds.',
            self::VideoEdit => 'The long-form cut of the interview.',
            self::Carousel => 'A swipeable set of stills.',
            self::Story => 'A 24-hour vertical post.',
            self::Thumbnail => 'The cover image for a video.',
            self::Short => 'A vertical short for YouTube.',
        };
    }

    /**
     * Where this normally goes.
     *
     * @return list<string>
     */
    public function defaultPlatforms(): array
    {
        return match ($this) {
            self::Poster, self::Carousel, self::Story => ['instagram'],
            self::Reel => ['instagram', 'facebook'],
            self::VideoEdit, self::Thumbnail, self::Short => ['youtube'],
        };
    }

    /** Video work needs a duration; a poster does not. */
    public function isVideo(): bool
    {
        return in_array($this, [self::Reel, self::VideoEdit, self::Short, self::Story], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * @return list<array{value: string, label: string, description: string, platforms: list<string>, is_video: bool}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'description' => $kind->description(),
                'platforms' => $kind->defaultPlatforms(),
                'is_video' => $kind->isVideo(),
            ],
            self::cases(),
        );
    }
}
