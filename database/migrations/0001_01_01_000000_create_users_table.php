<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff accounts.
 *
 * Everyone in this table is an employee — there is no public registration and
 * no customer login. Accounts are created by an Admin, which is why there is an
 * `is_active` flag but no email verification flow: deactivating someone must
 * lock them out immediately without deleting the work they own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Staff profile. Kept on the row rather than in a side table: every
            // one of these is read on the pages that list people, and a join
            // for a job title is a join for nothing.
            $table->string('phone', 32)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('team', 40)->nullable();
            $table->string('avatar_path')->nullable();
            $table->text('bio')->nullable();

            /*
             | Per-user permission revocations.
             |
             | spatie/laravel-permission is additive only: a role grant cannot be
             | taken back from one person without inventing a near-duplicate
             | role. This column is the subtraction, and User::hasPermissionTo()
             | is where it is applied — see the override on the model.
             |
             | A JSON column rather than a pivot table because it is consulted on
             | every single permission check, and that must not be a join.
             */
            $table->json('denied_permissions')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            // Encrypted at rest: a database dump must not be enough to mint
            // valid TOTP codes or spend someone's recovery codes.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
