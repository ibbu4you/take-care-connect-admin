<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who set a project up, as distinct from who owns it.
 *
 * ── Why this arrives with the owner picker ─────────────────────────────────
 * A project's visibility is scoped on `owner_id`. The moment a manager can hand
 * one to a colleague, that scope alone means the person who created it watches
 * it disappear from their own list the instant they save — which reads as
 * having lost the work rather than as having delegated it.
 *
 * `clients` has carried both columns from the start for exactly this reason.
 * Projects now match, and are scoped on the pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('owner_id')
                ->constrained('users')->nullOnDelete();
        });

        // Existing rows were created by whoever owns them — that was the only
        // possibility before this column existed.
        DB::table('projects')->whereNull('created_by')->update([
            'created_by' => DB::raw('owner_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
