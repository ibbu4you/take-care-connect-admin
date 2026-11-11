<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who sent a piece of work for review.
 *
 * "You may not approve your own work" used to be checked against the writer or
 * assignee field — which can be changed, so reassigning the work first made
 * anybody's draft approvable by its author. Who submitted it cannot be changed
 * after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('deliverables', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
        });

        Schema::table('deliverables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
        });
    }
};
