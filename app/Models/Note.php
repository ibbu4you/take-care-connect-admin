<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A note against a client or a deal.
 *
 * Polymorphic so one component and one query serve both, and so interviews and
 * articles can use it later without another table.
 */
class Note extends Model
{
    use SoftDeletes;

    protected $fillable = ['notable_type', 'notable_id', 'user_id', 'body'];

    /** @return MorphTo<Model, $this> */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
