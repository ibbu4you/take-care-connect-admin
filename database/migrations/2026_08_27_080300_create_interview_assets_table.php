<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the anchor hands over: documents, recordings, photos, links, transcripts.
 *
 * One table for both uploaded files and pasted links. A Google Meet recording is
 * usually a Drive URL rather than a file anybody wants to move around — the
 * files are gigabytes and the shared hosting has a disk quota — so `path` and
 * `url` are alternatives, and exactly one of them is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete();

            // document | recording | photo | link | transcript
            $table->string('kind', 24)->index();
            $table->string('label', 160)->nullable();

            // Set when the asset is an uploaded file.
            $table->string('disk', 32)->default('workfiles');
            $table->string('path', 512)->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Set instead when the asset is a link — a Drive recording, a
            // YouTube upload, a shared transcript.
            $table->string('url', 1000)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['interview_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_assets');
    }
};
