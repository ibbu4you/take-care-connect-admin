<?php

use App\Models\Deal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline stages renamed from temperatures to facts.
 *
 *   intake → new          cold → contacted     warm → contacted
 *   qualified → meeting   proposal → proposal  won / lost unchanged
 *
 * "Warm" folds into "contacted" rather than "meeting" on purpose: nothing about
 * a warm deal says a meeting happened, and the new "meeting" stage claims one
 * did. A deal that really has been met is one drag away.
 *
 * The stage history is rewritten the same way, so a deal's timeline reads in
 * the vocabulary the board now uses instead of mixing the two.
 *
 * Probabilities move with the stage — but only where a deal still carries its
 * old stage's default. One that somebody set by hand keeps their number;
 * otherwise a proposal would keep weighing 75% in a forecast that now says 60%.
 */
return new class extends Migration
{
    private const MAP = [
        'intake' => 'new',
        'cold' => 'contacted',
        'warm' => 'contacted',
        'qualified' => 'meeting',
    ];

    /** Old stage => [its old default probability, the new stage's default]. */
    private const PROBABILITY = [
        'intake' => [5, 5],
        'cold' => [10, 15],
        'warm' => [30, 15],
        'qualified' => [55, 35],
        'proposal' => [75, 60],
    ];

    public function up(): void
    {
        foreach (self::PROBABILITY as $stage => [$old, $new]) {
            DB::table('deals')->where('stage', $stage)->where('probability', $old)->update(['probability' => $new]);
        }

        $this->remap(self::MAP);

        Schema::table('deals', function (Blueprint $table) {
            $table->string('stage', 24)->default('new')->change();
        });
    }

    public function down(): void
    {
        // Best effort: the two stages that folded into one cannot be told apart.
        $this->remap(['new' => 'intake', 'contacted' => 'cold', 'meeting' => 'qualified']);

        Schema::table('deals', function (Blueprint $table) {
            $table->string('stage', 24)->default('intake')->change();
        });
    }

    /** @param array<string, string> $map */
    private function remap(array $map): void
    {
        $subject = (new Deal)->getMorphClass();

        foreach ($map as $from => $to) {
            DB::table('deals')->where('stage', $from)->update(['stage' => $to]);

            DB::table('stage_events')->where('subject_type', $subject)->where('from_stage', $from)->update(['from_stage' => $to]);
            DB::table('stage_events')->where('subject_type', $subject)->where('to_stage', $from)->update(['to_stage' => $to]);
        }
    }
};
