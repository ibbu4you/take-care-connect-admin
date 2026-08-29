<?php

namespace App\Support;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Drop-in audit logging for a business model.
 *
 * `use Auditable;` and every create, update and delete is recorded against the
 * person who did it, with the before-and-after values.
 *
 * ── The three options, and why each one matters ─────────────────────────────
 * · logFillable()          — logs what the model declares as editable, so a
 *                            column added to $fillable is audited automatically
 *                            and nobody has to remember a second list.
 * · logOnlyDirty()         — records only what actually changed. Without it,
 *                            every save writes the whole row and finding the one
 *                            edit that matters means reading forty unchanged
 *                            fields.
 * · dontSubmitEmptyLogs()  — a save that changed nothing writes no entry at all,
 *                            so the log stays a record of decisions rather than
 *                            of button presses.
 *
 * A model holding anything sensitive should NOT use this trait — it should
 * declare its own getActivitylogOptions() with an explicit allow-list, the way
 * User does, so a password hash or a 2FA secret can never reach the log.
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
