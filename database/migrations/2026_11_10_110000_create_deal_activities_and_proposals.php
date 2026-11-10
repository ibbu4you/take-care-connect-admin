<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a salesperson does on a deal, and what they send the buyer.
 *
 * ── deal_activities ────────────────────────────────────────────────────────
 * Calls, meetings, visits, WhatsApps and emails, each with an outcome where it
 * has one. A deal used to carry this as free-text notes, which made "meetings
 * held this week" unanswerable. Every activity also sets the deal's next step,
 * stored on the deal itself so the board and the list can show it without a
 * query per card.
 *
 * ── proposals ──────────────────────────────────────────────────────────────
 * Line items, a discount, a total, and the approval and sending trail. Several
 * per deal (revised quotes), numbered as versions. The money columns are
 * decimals, never floats: a proposal is a document somebody will hold the team
 * to, to the paisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->string('outcome', 32)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('happened_at');
            $table->timestamps();

            $table->index(['deal_id', 'happened_at']);
            $table->index(['user_id', 'happened_at']);
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->string('next_step', 255)->nullable()->after('last_activity_at');
            $table->date('next_step_on')->nullable()->index()->after('next_step');
        });

        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 24)->default('draft')->index();
            $table->string('title');

            // [{description, quantity, rate}], with amounts recomputed server-side
            // on every save so a tampered request cannot change the total.
            $table->json('items');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->date('valid_until')->nullable();
            $table->text('terms')->nullable();
            $table->text('internal_note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            $table->unique(['deal_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');

        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['next_step', 'next_step_on']);
        });

        Schema::dropIfExists('deal_activities');
    }
};
