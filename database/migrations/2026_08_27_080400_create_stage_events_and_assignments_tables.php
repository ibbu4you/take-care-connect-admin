<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared workflow plumbing: how something moved, and whose desk it sat on.
 *
 * Both tables are polymorphic and serve every workflow in the system —
 * interviews now, articles, deliverables and deals later. One history table
 * rather than four means "what happened to this record" is the same query and
 * the same component wherever it is asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_events', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');

            // Null on the first event: a record entering its opening stage came
            // from nowhere, and writing 'created' there would invent a stage
            // that is not in any workflow.
            $table->string('from_stage', 24)->nullable();
            $table->string('to_stage', 24);

            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('changed_at')->index();
            $table->timestamps();
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->morphs('assignable');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            // Which hat the assignee is wearing: anchor | content | creative.
            $table->string('role', 24);

            $table->string('state', 16)->default('open')->index();
            $table->date('due_on')->nullable()->index();
            $table->text('brief')->nullable();

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['assigned_to', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('stage_events');
    }
};
