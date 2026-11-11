<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One interview, one article.
 *
 * HandoverService already refuses a second handover; this is the backstop for
 * whatever a future code path forgets. Interviews that never become articles
 * keep NULL, which a unique index allows any number of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->unique('interview_id', 'articles_interview_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropUnique('articles_interview_id_unique');
        });
    }
};
