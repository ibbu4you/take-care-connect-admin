<?php

namespace App\Support;

/**
 * Small statistics shared by the reporting pages.
 *
 * Extracted so the reports page and the performance page compute a median the
 * same way. Two copies of this would agree until one of them was changed.
 */
class Stats
{
    /**
     * Whole days between two moments, or null if that cannot be measured.
     *
     * ── Why negatives are dropped rather than made positive ────────────────
     * Carbon 3 returns a *signed* float from diffInDays, so a record whose
     * `published_at` precedes its `created_at` — backfilled data, an import, a
     * clock that slipped — yields a negative number. Left alone it renders as
     * "-2d" and drags the median below zero, which is how a report ends up
     * claiming work was finished before it started.
     *
     * abs() would be worse: it would report that record as having taken two
     * days, which is a confident answer to a question the data cannot answer.
     * The honest reading is that the duration is unknown, so it is left out of
     * the median entirely.
     *
     * Rounded to whole days because an unrounded median renders as
     * "2.0053125 days", which reads as a bug even when the number is right.
     */
    public static function daysBetween(mixed $from, mixed $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        $days = (int) round($from->diffInDays($to));

        return $days < 0 ? null : $days;
    }

    /**
     * The median, not the mean.
     *
     * ── Why the median everywhere in this application ──────────────────────
     * Every duration measured here has a long tail: one interview that sat for
     * three months, one article that waited on a client for six weeks. An
     * average is dragged badly enough by a single one of those to stop
     * describing anything, and that outlier happens roughly once a quarter.
     *
     * Inputs should already be rounded to whole days. Carbon 3 returns a float
     * from diffInDays, and an unrounded median renders as "2.0053125 days",
     * which reads as a bug even when the number is right.
     *
     * @param  list<int|float>  $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (float) $values[$middle]
            : round(((float) $values[$middle - 1] + (float) $values[$middle]) / 2, 1);
    }
}
