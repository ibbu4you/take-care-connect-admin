<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Version numbers that are never handed out twice.
 *
 * The next version used to be "highest remaining + 1", so deleting v3 and
 * uploading again produced a second, different v3 — and "the v3 you approved"
 * stopped meaning one thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliverables', function (Blueprint $table) {
            $table->unsignedInteger('versions_issued')->default(0);
        });

        DB::table('deliverables')->update([
            'versions_issued' => DB::raw('(SELECT COALESCE(MAX(version), 0) FROM deliverable_files WHERE deliverable_files.deliverable_id = deliverables.id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('deliverables', function (Blueprint $table) {
            $table->dropColumn('versions_issued');
        });
    }
};
