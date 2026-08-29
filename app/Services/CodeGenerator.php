<?php

namespace App\Services;

use App\Models\Sequence;
use Illuminate\Support\Facades\DB;

/**
 * Human-readable codes: INT-000123, ART-000045, DLV-000009.
 *
 * These are how the team refers to work out loud and in WhatsApp, so they have
 * to be short, stable and never reused.
 *
 * ── Why not max(id) + 1 ─────────────────────────────────────────────────────
 * Every record here soft-deletes. Deriving the next code from the highest
 * existing row means deleting the newest interview hands its code straight to
 * the next one — and two different interviews called INT-000123 in somebody's
 * notes is a mess nobody can untangle afterwards.
 *
 * ── Why the row lock ────────────────────────────────────────────────────────
 * Two people creating an interview in the same second would otherwise both read
 * the same `next_value` and both be given it. `lockForUpdate()` inside a
 * transaction makes the second one wait for the first to commit.
 */
class CodeGenerator
{
    private const PAD = 6;

    public function next(string $key): string
    {
        $prefix = mb_strtoupper($key);

        $value = DB::transaction(function () use ($prefix): int {
            $sequence = Sequence::query()
                ->where('key', $prefix)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                // firstOrCreate cannot be locked, so the row is created here and
                // the unique index on `key` settles any race between two
                // processes both finding it missing.
                $sequence = Sequence::create(['key' => $prefix, 'next_value' => 1]);
            }

            $current = $sequence->next_value;

            $sequence->update(['next_value' => $current + 1]);

            return $current;
        });

        return sprintf('%s-%s', $prefix, str_pad((string) $value, self::PAD, '0', STR_PAD_LEFT));
    }
}
