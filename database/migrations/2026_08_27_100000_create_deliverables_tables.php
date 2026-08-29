<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The creative team's work: posters, reels, video edits, carousels.
 *
 * ── The polymorphic parent is the point ────────────────────────────────────
 * A deliverable hangs off an interview OR an article. That is not a nicety: two
 * of the three interview kinds never produce an article at all, so a
 * deliverable keyed to an article could never represent the reel cut from a
 * celebrity interview — which is most of what this team actually makes.
 *
 * So `deliverable_of` is a morph, and the two social interview kinds finally
 * have somewhere for their output to live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverables', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();

            // interviews | articles
            $table->morphs('deliverable_of');

            $table->string('kind', 24)->index();
            $table->string('title')->nullable();

            /*
             | Which one of its kind this is.
             |
             | A package is usually "three reels and two posters", and the team
             | talks about them as "reel 2". Without a slot number they are
             | distinguishable only by created_at, which nobody can see.
             */
            $table->unsignedTinyInteger('slot_number')->default(1);

            $table->string('stage', 16)->default('pending')->index();
            $table->timestamp('stage_entered_at')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('due_on')->nullable()->index();
            $table->string('priority', 8)->default('normal');

            $table->text('brief')->nullable();
            $table->text('caption')->nullable();
            $table->text('hashtags')->nullable();

            // Where this is destined to go: ["instagram", "youtube"].
            $table->json('platforms')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();

            // Why it came back. Kept on the row so the creative sees it at the
            // top of the deliverable rather than digging through history.
            $table->text('rejection_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['assigned_to', 'stage']);
            $table->index(['stage', 'due_on']);
        });

        /*
         | Versioned uploads.
         |
         | Creative work goes round more than once, and "send me the previous
         | cut" is a request that will be made. Versioning rather than
         | overwriting means the earlier one is still there.
         */
        Schema::create('deliverable_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverable_id')->constrained('deliverables')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');

            $table->string('disk', 32)->default('workfiles');
            $table->string('path', 512)->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // A finished reel is hundreds of megabytes. Linking to Drive has to
            // be a first-class option, not a workaround — same reasoning as the
            // interview recording.
            $table->string('url', 1000)->nullable();

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['deliverable_id', 'version']);
        });

        /*
         | Where it actually went live.
         |
         | This is what closes the creative loop, exactly as `published_url`
         | does for an article: until the link is recorded, nobody can tell a
         | published reel from an approved one that never got posted.
         */
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliverable_id')->nullable()->constrained('deliverables')->cascadeOnDelete();

            // A link can also hang directly off an interview — a live YouTube
            // upload of the whole conversation is not a "deliverable" anybody
            // designed, but it still needs recording.
            $table->foreignId('interview_id')->nullable()->constrained('interviews')->cascadeOnDelete();

            $table->string('platform', 16)->index();
            $table->string('url', 1000);

            /*
             | sha256 of platform + url, unique.
             |
             | The URL itself is too long for a unique index on MySQL, and a
             | prefix index would treat two long YouTube links sharing a prefix
             | as duplicates. Hashing sidesteps both, and works identically on
             | the SQLite the tests run on.
             */
            $table->char('url_hash', 64)->unique();

            $table->string('external_id', 120)->nullable();
            $table->timestamp('posted_at')->nullable()->index();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            // Views, likes and so on, filled in later. JSON because every
            // platform counts different things and none of it is queried.
            $table->json('metrics')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('deliverable_files');
        Schema::dropIfExists('deliverables');
    }
};
