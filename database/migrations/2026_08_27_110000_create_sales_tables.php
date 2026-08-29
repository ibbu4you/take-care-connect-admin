<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Relationship Manager's side: deals, targets and follow-ups.
 *
 * `clients` and `projects` already exist — they arrived with the interviews
 * phase, because an interview is booked against a client and the foreign keys
 * had to be right the first time.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | One row per card on the pipeline board.
         |
         | Deliberately not split into "leads" and "deals". A lead that converts
         | is the same conversation with the same person about the same money —
         | two tables would mean copying it across at an arbitrary moment and
         | then reconciling two histories. Outcome and revenue live on the card,
         | so the board reads one table and attainment is a SUM over it.
         */
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();

            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();

            $table->string('title');
            $table->string('stage', 24)->default('intake')->index();
            $table->timestamp('stage_entered_at')->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 24)->default('manual')->index();

            $table->decimal('expected_value', 12, 2)->default(0);

            // Null until the deal closes. Required on a win, because attainment
            // is a sum of what was actually signed, not of what was hoped for.
            $table->decimal('actual_value', 12, 2)->nullable();

            $table->char('currency', 3)->default('INR');
            $table->unsignedTinyInteger('probability')->nullable();

            $table->date('expected_close_on')->nullable()->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->string('outcome', 8)->nullable()->index();
            $table->string('lost_reason', 160)->nullable();

            /*
             | Manual ordering within a column.
             |
             | A board that reorders itself when somebody drags a card is a board
             | nobody trusts. Cards keep the position they were put in.
             */
            $table->unsignedInteger('board_order')->default(0);

            // "Nothing has happened here for three weeks" is the single most
            // useful question a sales manager asks, and it wants an index.
            $table->timestamp('last_activity_at')->nullable()->index();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'stage']);
            $table->index(['stage', 'closed_at']);
        });

        /*
         | Monthly targets, per person.
         |
         | Stored as 'YYYY-MM' rather than a date range: a target belongs to a
         | month as a unit, and a range invites the question of what happens at
         | the boundary. The unique key is what stops two rows quietly
         | disagreeing about the same month.
         */
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('period', 7)->index();

            $table->unsignedInteger('interviews_target')->nullable();
            $table->unsignedInteger('deals_target')->nullable();
            $table->decimal('revenue_target', 12, 2)->nullable();

            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period']);
        });

        /*
         | The chase list.
         |
         | Sales is mostly remembering to call somebody back, and a reminder
         | that lives in a notebook is a reminder nobody else can pick up when
         | that person is away.
         */
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->date('due_on')->index();
            $table->string('channel', 16)->default('call');
            $table->string('note', 500)->nullable();

            $table->string('state', 16)->default('pending')->index();
            $table->timestamp('completed_at')->nullable();
            $table->string('outcome_note', 500)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'state', 'due_on']);
        });

        /*
         | Notes against a client or a deal.
         |
         | Polymorphic so the same component and the same query serve both, and
         | so articles and interviews can use it later without another table.
         */
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->morphs('notable');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
        Schema::dropIfExists('follow_ups');
        Schema::dropIfExists('targets');
        Schema::dropIfExists('deals');
    }
};
