<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Relationship Manager's account records.
 *
 * ── Why these arrive with the interviews phase ──────────────────────────────
 * The sales module itself is built later, but an interview is booked *against*
 * a client and usually belongs to a project, and those are real foreign keys.
 * Creating the tables now means the interview schema is right the first time
 * rather than needing an ALTER once sales lands — and an interview booked in
 * the meantime can already point at the client it is for.
 *
 * Only the schema is here. Controllers, pages and the pipeline come with the
 * sales phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();

            /*
             | Digits only, written on save.
             |
             | The same business is entered as "+91 98765 43210", "098765 43210"
             | and "9876543210" by three different people. Normalising on the way
             | in gives duplicate detection something stable to match on.
             */
            $table->string('phone_normalized', 32)->nullable()->index();

            $table->string('whatsapp', 32)->nullable();
            $table->string('website')->nullable();
            $table->string('instagram')->nullable();

            $table->string('city')->nullable();
            $table->string('state', 120)->nullable();
            $table->string('trade', 120)->nullable();
            $table->text('address')->nullable();

            $table->string('source', 24)->default('manual')->index();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 16)->default('active')->index();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'status']);
            $table->index('name');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('title');
            $table->string('kind', 24)->default('interview_feature')->index();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('open')->index();
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable()->index();
            $table->decimal('value_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
        Schema::dropIfExists('clients');
    }
};
