<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the business was told what, and by whom.
 *
 * Three messages go to the business an interview is about: the confirmation
 * when it is booked, a reminder before it, and the link once their story is
 * live. They are sent over WhatsApp from the team member's own phone, so the
 * app cannot know they went unless it records the moment somebody pressed the
 * button — which is what these columns are. Without them two people remind the
 * same shopkeeper twice, or nobody does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            foreach (['confirmation', 'reminder', 'story'] as $message) {
                $table->timestamp("{$message}_sent_at")->nullable();
                $table->foreignId("{$message}_sent_by")->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            foreach (['confirmation', 'reminder', 'story'] as $message) {
                $table->dropConstrainedForeignId("{$message}_sent_by");
                $table->dropColumn("{$message}_sent_at");
            }
        });
    }
};
