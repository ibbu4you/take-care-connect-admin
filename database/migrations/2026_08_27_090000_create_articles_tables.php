<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The content team's side of the SOP.
 *
 * An article is created automatically when a business interview is handed over,
 * carrying the anchor's Q&A across as a starting draft. From there it belongs to
 * a writer until it is approved and published.
 *
 * ── One `stage`, not a stage AND a status ──────────────────────────────────
 * The public site has its own draft/pending_review/published status. It is
 * tempting to mirror it here as a second column, but this application does not
 * write to that site — the writer exports a bundle and publishes by hand — so a
 * second column would only ever be a copy of the first, free to drift from it.
 * The export derives the site's status from the stage instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | A small local taxonomy, seeded from the live site's categories.
         |
         | A free-text field would give "Handloom", "handlooms" and "Hand Loom"
         | within a month. The export carries the category as a NAME rather than
         | an id, so if the site's list has moved on it shows up as a label
         | somebody can see and fix, not a silently wrong link.
         */
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();

            // Null for a standalone piece that did not come from an interview.
            $table->foreignId('interview_id')->nullable()->constrained('interviews')->nullOnDelete()->index();

            $table->string('title');
            $table->string('slug')->nullable()->unique();
            $table->text('excerpt')->nullable();

            // Sanitised through Purifier on the way in — the editor is TinyMCE
            // and the output is eventually pasted into a live website.
            $table->longText('body')->nullable();

            /*
             | Question-and-answer pairs promoted out of the interview.
             |
             | The craftsman profiles on the live site carry an FAQ block, so the
             | writer chooses which of the anchor's Q&A pairs belong there rather
             | than having all of them folded into the prose.
             */
            $table->json('faqs')->nullable();

            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('city')->nullable();

            // Which surface this becomes on the website: a blog post, or a
            // craftsman/business profile.
            $table->string('target_type', 16)->default('post');

            $table->foreignId('writer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('stage', 24)->default('assigned')->index();
            $table->timestamp('stage_entered_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            // What the reviewer asked for when sending it back. Kept on the row
            // so the writer sees it at the top of the editor rather than having
            // to dig through the history.
            $table->text('review_note')->nullable();

            $table->unsignedInteger('word_count_target')->nullable();
            $table->date('due_on')->nullable()->index();
            $table->string('priority', 8)->default('normal');

            // Handed over with the body so whoever publishes has everything.
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('focus_keyphrase')->nullable();

            /*
             | Where it ended up.
             |
             | The deliberate cost of keeping this system separate from the live
             | site: publishing happens over there, and the writer pastes the
             | resulting URL back here. It is what closes the loop, so an article
             | cannot be marked published without one.
             */
            $table->string('published_url', 512)->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['writer_id', 'stage']);
            $table->index(['stage', 'due_on']);
        });

        /*
         | A snapshot of the body each time it is submitted for review.
         |
         | Rewriting is the content team's core act, and "put back what it said
         | before the last round of notes" is a request that will be made. Only
         | the body is versioned: the title and SEO fields are cheap to retype,
         | and a full row-level history would be a lot of longtext for very
         | little.
         */
        Schema::create('article_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->longText('body')->nullable();
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'version']);
        });

        /** Images and files for the draft. Same shape as interview_assets. */
        Schema::create('article_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();

            $table->string('kind', 24)->default('image')->index();
            $table->string('label', 160)->nullable();

            $table->string('disk', 32)->default('workfiles');
            $table->string('path', 512)->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('url', 1000)->nullable();

            // Alt text travels with the image into the export — the live site
            // requires it, and it is far easier to write while the writer still
            // remembers what the picture shows.
            $table->string('alt_text')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_assets');
        Schema::dropIfExists('article_revisions');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('categories');
    }
};
