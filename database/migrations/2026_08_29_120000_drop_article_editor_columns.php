<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The article is a file, not typed HTML.
 *
 * ── What changed ───────────────────────────────────────────────────────────
 * There was a rich-text editor here, and it existed to produce publish-ready
 * HTML for a bundle somebody then pasted into takecareconnect.com. The team
 * writes in Word and publishes on the site by hand, so the editor was a second
 * place to write the same article — and the one nobody would keep in step.
 *
 * The draft is an uploaded document on `article_assets` now. Everything below
 * described the typed version: the HTML itself, the word target, the excerpt,
 * the FAQ pairs and the SEO fields that were copied into the site's own on
 * publication. All of it is entered on the website instead.
 *
 * `article_revisions` goes with them: every row was a snapshot of a body that
 * no longer exists. File uploads are kept rather than replaced, so the history
 * that mattered — what the reviewer actually read — is on the assets.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'body',
        'excerpt',
        'faqs',
        'word_count_target',
        'meta_title',
        'meta_description',
        'focus_keyphrase',
    ];

    public function up(): void
    {
        Schema::dropIfExists('article_revisions');

        $present = array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => Schema::hasColumn('articles', $column),
        ));

        if ($present === []) {
            return;
        }

        Schema::table('articles', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }

    /**
     * Deliberately not reversible.
     *
     * The columns could come back empty, which would be a lie: the drafts they
     * held are gone, and an article whose body is null but whose column exists
     * looks like work somebody lost rather than a decision somebody made.
     */
    public function down(): void
    {
        // No going back.
    }
};
