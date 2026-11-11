<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link somebody else could open: http(s), on a real public host.
 *
 * Laravel's `url` rule accepts ftp://, file:// and http://localhost — none of
 * which is a page a business can be sent, and a "published" article pointing at
 * one is not published. Optionally pinned to a platform's own domains, so an
 * "Instagram" link really is on Instagram.
 */
final class PublicUrl implements ValidationRule
{
    /** @param  list<string>  $hosts  allowed domains (subdomains included); empty for any public host */
    public function __construct(
        private readonly array $hosts = [],
        private readonly string $message = 'Paste the public link — starting with https://.',
    ) {}

    /** The domains each social platform publishes on. */
    public const PLATFORM_HOSTS = [
        'instagram' => ['instagram.com', 'instagr.am'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'facebook' => ['facebook.com', 'fb.watch', 'fb.com'],
        'linkedin' => ['linkedin.com', 'lnkd.in'],
    ];

    public static function forPlatform(?string $platform): self
    {
        $hosts = self::PLATFORM_HOSTS[$platform] ?? [];
        $name = ucfirst((string) $platform);

        return new self($hosts, $hosts === [] ? 'Paste the public link.' : "That is not a {$name} link.");
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // A space inside is not a link anybody can open, however it parses.
        if (preg_match('/\s/', trim((string) $value))) {
            $fail($this->message);

            return;
        }

        $parts = parse_url(trim((string) $value));
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! str_contains($host, '.')) {
            $fail($this->message);

            return;
        }

        $private = in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '[::1]'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || (filter_var($host, FILTER_VALIDATE_IP) !== false
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);

        if ($private) {
            $fail($this->message);

            return;
        }

        if ($this->hosts !== []) {
            $matches = collect($this->hosts)->contains(
                fn (string $allowed) => $host === $allowed || str_ends_with($host, '.'.$allowed),
            );

            if (! $matches) {
                $fail($this->message);
            }
        }
    }
}
