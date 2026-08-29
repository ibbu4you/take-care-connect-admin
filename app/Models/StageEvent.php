<?php

namespace App\Models;

use App\Services\StageMachine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement through a workflow.
 *
 * Written only by {@see StageMachine}, never by hand, so the
 * history cannot disagree with the column it describes.
 */
class StageEvent extends Model
{
    protected $fillable = [
        'subject_type',
        'subject_id',
        'from_stage',
        'to_stage',
        'changed_by',
        'note',
        'changed_at',
    ];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
