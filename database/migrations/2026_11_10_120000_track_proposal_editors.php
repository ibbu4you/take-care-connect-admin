<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who last changed a proposal's amounts, and who submitted it.
 *
 * "Nobody approves their own discount" used to be checked against the author
 * only. A manager could open an executive's 0% draft, raise it to 60%, submit
 * it, and approve it — the author was somebody else, so the check passed. The
 * approver now has to be none of the three people who shaped the number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->foreignId('last_edited_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->after('last_edited_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('last_edited_by');
        });
    }
};
