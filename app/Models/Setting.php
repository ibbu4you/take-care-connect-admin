<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One configurable value.
 *
 * Read through {@see \App\Support\Settings} rather than directly — it caches
 * the whole table and fills in defaults for keys nobody has written yet.
 *
 * Auditable, because "why does the digest chase me after one day now" is a
 * question with an answer, and the answer is in the audit log.
 */
class Setting extends Model
{
    use Auditable;

    protected $fillable = ['key', 'value', 'updated_by'];

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
