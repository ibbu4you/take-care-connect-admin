<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counters behind the human-readable codes — INT-000123, ART-000045, DLV-000009.
 *
 * A table rather than `max(id) + 1`, because every record here soft-deletes:
 * deleting the newest interview would hand its code straight to the next one,
 * and two rows sharing INT-000123 in somebody's spreadsheet is a support call
 * nobody can unpick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
