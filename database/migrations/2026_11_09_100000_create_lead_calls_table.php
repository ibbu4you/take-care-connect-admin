<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every call made to a lead, with what came of it.
 *
 * A lead used to carry its calls as free-text notes. That reads fine on one
 * lead and answers nothing across forty: how many calls did Divya make today,
 * how many got through, how many tries before somebody picks up. Each of those
 * needs the outcome as a value, not as a sentence somebody may or may not have
 * started with "No answer".
 *
 * Notes stay. A call is "what happened"; a note is "what we know".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            // Kept when the caller leaves: their calls still happened, and the
            // lead's history should not develop holes when somebody resigns.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('outcome', 32);
            $table->text('note')->nullable();
            $table->timestamp('called_at');
            $table->timestamps();

            // The two questions asked of this table: "this lead's calls" and
            // "this person's calls between two dates".
            $table->index(['lead_id', 'called_at']);
            $table->index(['user_id', 'called_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_calls');
    }
};
