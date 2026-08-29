<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The anchor no longer types the Q&A.
 *
 * ── What changed ───────────────────────────────────────────────────────────
 * Questions and answers were typed into the admin, pair by pair, after every
 * interview. The anchor runs the recording through Turboscript and uploads the
 * exported PDF instead — faster than retyping an interview, and a better record
 * than anybody typing from memory an hour later.
 *
 * `qa_summary` stays. It kept its name but it is a brief for the writer now
 * rather than a summary of a Q&A, which is what it had mostly become anyway.
 *
 * Nothing reads this column any more, so leaving it would be a field the next
 * person has to work out is dead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('interviews', 'qa')) {
            return;
        }

        Schema::table('interviews', function (Blueprint $table) {
            $table->dropColumn('qa');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('interviews', 'qa')) {
            return;
        }

        // The column comes back empty. The pairs themselves are gone, which is
        // why this is a rollback of the schema and not of the decision.
        Schema::table('interviews', function (Blueprint $table) {
            $table->json('qa')->nullable()->after('handed_over_at');
        });
    }
};
