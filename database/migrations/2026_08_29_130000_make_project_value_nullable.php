<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A project's value can be unknown.
 *
 * ── The bug this fixes ─────────────────────────────────────────────────────
 * The column was NOT NULL with a default of 0.00, while the form and the
 * validator both treat Value as optional — and Laravel's ConvertEmptyStringsToNull
 * turns the blank input into an explicit null on the way in. Creating a project
 * without a value therefore sent NULL at a column that would not take it, and
 * the manager got a 500 rather than a project.
 *
 * The default never helped: a default applies when a column is *omitted*, and
 * this one was always present and explicitly null.
 *
 * ── Why nullable rather than coercing to zero ──────────────────────────────
 * "We have not agreed a price yet" and "this is free" are different facts, and
 * storing the first as 0.00 makes the open-value figure quietly understate the
 * book. Every other money column here — a lead's estimate, a deal's actual —
 * is already nullable for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('value_amount', 12, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Blank values first: NOT NULL cannot be applied over them, and a
        // rollback that fails halfway is worse than one that fills in zero.
        DB::table('projects')->whereNull('value_amount')->update(['value_amount' => 0]);

        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('value_amount', 12, 2)->nullable(false)->default(0)->change();
        });
    }
};
