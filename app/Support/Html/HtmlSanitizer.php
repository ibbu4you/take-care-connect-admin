<?php

namespace App\Support\Html;

use Mews\Purifier\Facades\Purifier;

/**
 * Cleans rich text before it is stored.
 *
 * ── Why this is not optional ────────────────────────────────────────────────
 * The article body is written in TinyMCE and eventually pasted into
 * takecareconnect.com, a public website. Anything that survives this method
 * ends up in front of readers, so a `<script>` smuggled in through a paste from
 * Word — or an `onerror` on an image — would travel all the way across.
 *
 * Sanitising on WRITE rather than on read is deliberate. Cleaning on output
 * means the dangerous version stays in the database, waiting for the one place
 * that forgets to filter — the export bundle, a report, a future API. Cleaning
 * on input means what is stored is already safe, everywhere, for good.
 *
 * The allow-list below is what the live site's editor actually produces. It is
 * deliberately short: an editor that can emit arbitrary markup is an editor
 * whose output nobody can review.
 */
class HtmlSanitizer
{
    /** The profile name registered in config/purifier.php. */
    private const PROFILE = 'article';

    public static function clean(?string $html): ?string
    {
        if (blank($html)) {
            return null;
        }

        $clean = trim((string) Purifier::clean($html, self::PROFILE));

        /*
         | An editor emptied of content leaves "<p></p>" or "<p>&nbsp;</p>"
         | behind. Storing that makes an empty article look like it has a body,
         | which then passes the "has a body" check on the way to review.
         |
         | Text alone is not the test, though: a body that is one image and a
         | caption has no words but is emphatically content, and returning null
         | for it would silently throw the image away. So a void element counts
         | as content too.
         */
        $stripped = trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5));
        $hasMedia = preg_match('/<(img|hr|table)\b/i', $clean) === 1;

        return ($stripped === '' && ! $hasMedia) ? null : $clean;
    }

    /**
     * Plain text, for excerpts, word counts and search.
     *
     * Entities are decoded so "don&rsquo;t" counts as one word rather than
     * three, and whitespace is collapsed so pasted markup does not leave a
     * paragraph of blank lines.
     */
    public static function toText(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
