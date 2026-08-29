<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People a manager is talking to who are not clients yet.
 *
 * ── Why this is not just a client with a status ────────────────────────────
 * A manager speaks to far more people than ever become clients. Filed as
 * clients, the directory of people you actually look after fills with names
 * nobody ever heard back from, and every count on every page — clients, open
 * work, the export — starts describing a different thing.
 *
 * So a lead is its own record with its own list, and converting one creates the
 * client. The lead survives the conversion rather than turning into it: how a
 * client was found is worth keeping, and so is the record of the ninety who
 * said no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();

            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();

            // Digits only, so a search for "98765 43210" finds a number stored
            // as "+91 9876543210". Same trick as `clients`.
            $table->string('phone_normalized', 32)->nullable()->index();
            $table->string('whatsapp', 32)->nullable();

            $table->string('city')->nullable();
            $table->string('state', 120)->nullable();
            $table->string('trade', 120)->nullable();
            $table->string('website')->nullable();
            $table->string('instagram')->nullable();

            $table->string('source', 24)->default('manual')->index();
            $table->string('status', 16)->default('new')->index();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // When to pick the phone up again. The whole list sorts on this.
            $table->date('next_action_on')->nullable();
            $table->timestamp('last_contacted_at')->nullable();

            $table->decimal('estimated_value', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('lost_reason')->nullable();

            // Set on conversion. Nulled rather than cascaded if the client is
            // later removed, so the lead's own history survives it.
            $table->foreignId('converted_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'status']);
            $table->index(['status', 'next_action_on']);
        });

        /*
         | The chase list covers leads too.
         |
         | Chasing somebody who has not bought anything yet is the larger half
         | of the job, and a follow-up that could only point at a client would
         | have left it out entirely.
         */
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('client_id')
                ->constrained('leads')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
        });

        Schema::dropIfExists('leads');
    }
};
