<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Values the team can change without a deploy.
 *
 * ── Why key/value rather than one row of columns ───────────────────────────
 * A single settings row with a column per setting means a migration every time
 * somebody wants a new one, and a column that is null for the six months
 * between adding it and deciding its default. Keys are cheap; the defaults live
 * in config/settings.php, so a key that has never been written still reads
 * correctly.
 *
 * `value` is text and everything is cast on the way out by App\Support\Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
