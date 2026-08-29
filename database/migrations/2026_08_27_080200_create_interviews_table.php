<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The spine of the SOP.
 *
 * Every piece of work in this system either is an interview or hangs off one:
 * the article the content team writes, the reel the creative team cuts, and the
 * social links recorded at the end all trace back to a row here.
 *
 * ── The three kinds ────────────────────────────────────────────────────────
 * · celebrity_social  — in person. Social output only, no website article.
 * · business_article  — over Google Meet. Handed to content, becomes an article.
 * · meet_social       — over Google Meet. Social output only.
 *
 * Only `business_article` produces an article. That is the single most
 * consequential fact about a row here, so it is also stored as a boolean
 * (`requires_article`) rather than re-derived — see the note below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();

            $table->string('kind', 24)->index();

            $table->string('title');
            $table->string('subject_name');
            $table->string('subject_designation', 160)->nullable();
            $table->string('subject_company', 160)->nullable();
            $table->string('subject_phone', 32)->nullable();
            $table->string('subject_email')->nullable();

            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();

            /*
             | Four people can be on an interview, and all four should see it.
             |
             | These are denormalised owner columns: they answer "whose desk is
             | this on right now" with one index, which is what every queue and
             | dashboard asks. The assignments table beside them holds the
             | history of how it got there.
             */
            $table->foreignId('anchor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('content_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creative_owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('scheduled_at')->nullable()->index();
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            $table->string('mode', 16)->default('google_meet');
            $table->string('location')->nullable();
            $table->string('meeting_url', 512)->nullable();
            $table->string('recording_url', 512)->nullable();

            $table->string('stage', 24)->default('scheduled')->index();
            $table->timestamp('stage_entered_at')->nullable();
            $table->timestamp('conducted_at')->nullable()->index();
            $table->timestamp('handed_over_at')->nullable();

            /*
             | The raw Q&A, as the anchor typed it: an ordered list of
             | {question, answer} pairs.
             |
             | JSON rather than a child table because it is always read and
             | written whole, never queried into, and its order is part of its
             | meaning. A child table would buy nothing and cost a join on every
             | read.
             */
            $table->json('qa')->nullable();
            $table->text('qa_summary')->nullable();
            $table->string('language', 40)->nullable();

            $table->boolean('consent_to_publish')->default(false);

            /*
             | Derived from `kind`, but stored.
             |
             | "Everything still waiting for an article" is the content team's
             | whole queue, and as a stored boolean it is one indexed scan
             | instead of a WHERE kind IN (...) that cannot use the stage index
             | alongside it. Kept in step by the model on save.
             */
            $table->boolean('requires_article')->default(false)->index();
            $table->boolean('requires_social')->default(true);

            $table->date('due_on')->nullable()->index();
            $table->string('priority', 8)->default('normal');

            $table->text('notes')->nullable();
            $table->string('cancelled_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['anchor_id', 'stage']);
            $table->index(['content_owner_id', 'stage']);
            $table->index(['kind', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interviews');
    }
};
