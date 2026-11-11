<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a piece of work actually went live.
 *
 * This closes the creative loop exactly as `published_url` closes the article
 * one: until the link is recorded, nobody can tell a published reel from an
 * approved one that never got posted.
 */
class SocialPost extends Model
{
    /** The platforms the team actually posts to. */
    public const PLATFORMS = ['instagram', 'youtube', 'facebook', 'linkedin'];

    protected $fillable = [
        'deliverable_id', 'interview_id', 'platform', 'url', 'url_hash',
        'external_id', 'posted_at', 'posted_by', 'metrics',
    ];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'metrics' => 'array'];
    }

    protected static function booted(): void
    {
        // The hash is the unique key: the URL itself is too long for a unique
        // index, and a prefix index would call two different YouTube links
        // duplicates because they share the first 191 characters.
        static::saving(function (SocialPost $post): void {
            $post->url_hash = static::hashFor($post->platform, $post->url);
        });
    }

    public static function hashFor(string $platform, string $url): string
    {
        return hash('sha256', mb_strtolower(trim($platform)).'|'.static::canonical($url));
    }

    /**
     * One spelling per post, for spotting the same link twice.
     *
     * "https://www.instagram.com/p/abc/?igsh=x" and "instagram.com/p/abc" are
     * the same post; comparing the raw text let it be recorded against two
     * pieces of work. Scheme, "www.", case of the host, a trailing slash,
     * the fragment and tracking parameters are dropped — except YouTube's
     * `v`, which is the video itself.
     */
    public static function canonical(string $url): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'])) {
            return mb_strtolower(trim($url));
        }

        $host = preg_replace('/^(www|m)\./', '', mb_strtolower($parts['host']));
        $path = rtrim($parts['path'] ?? '', '/');

        parse_str($parts['query'] ?? '', $query);
        $keep = array_intersect_key($query, array_flip(['v', 'id', 'story_fbid', 'fbid']));
        ksort($keep);

        return $host.$path.($keep !== [] ? '?'.http_build_query($keep) : '');
    }

    public function platformLabel(): string
    {
        return match ($this->platform) {
            'instagram' => 'Instagram',
            'youtube' => 'YouTube',
            'facebook' => 'Facebook',
            'linkedin' => 'LinkedIn',
            default => ucfirst((string) $this->platform),
        };
    }

    /** @return BelongsTo<Deliverable, $this> */
    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    /** @return BelongsTo<Interview, $this> */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    /** @return BelongsTo<User, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
