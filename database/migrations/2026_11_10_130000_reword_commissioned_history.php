<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creative work is now "requested", not "commissioned".
 *
 * New history entries say "Requested from INT-000004"; this rewrites the ones
 * already recorded so a deliverable's history does not switch vocabulary
 * halfway down the page.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reword('Commissioned from ', 'Requested from ');
    }

    public function down(): void
    {
        $this->reword('Requested from ', 'Commissioned from ');
    }

    private function reword(string $from, string $to): void
    {
        DB::table('stage_events')
            ->where('note', 'like', $from.'%')
            ->orderBy('id')
            ->each(function (object $event) use ($from, $to) {
                DB::table('stage_events')
                    ->where('id', $event->id)
                    ->update(['note' => $to.substr($event->note, strlen($from))]);
            });
    }
};
