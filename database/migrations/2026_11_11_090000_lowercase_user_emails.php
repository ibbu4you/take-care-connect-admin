<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Emails in lower case, to match how sign-in compares them.
 *
 * Skips any address whose lower-case form already belongs to another account
 * rather than failing on the unique index; `tcc:health` is the place to look
 * for those by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('users')->select('id', 'email')->get() as $user) {
            $lower = mb_strtolower(trim($user->email));

            if ($lower === $user->email) {
                continue;
            }

            $taken = DB::table('users')->where('email', $lower)->where('id', '!=', $user->id)->exists();

            if (! $taken) {
                DB::table('users')->where('id', $user->id)->update(['email' => $lower]);
            }
        }
    }

    public function down(): void
    {
        // Nothing to restore: the original capitalisation was not kept.
    }
};
