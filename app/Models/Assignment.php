<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Whose desk a piece of work was put on, and by whom.
 *
 * Kept alongside the denormalised owner columns on the record itself. The
 * columns answer "who has it now" in one index; this table is the history —
 * every reassignment, with the brief that came with it.
 */
class Assignment extends Model
{
    protected $fillable = [
        'assignable_type',
        'assignable_id',
        'assigned_to',
        'assigned_by',
        'role',
        'state',
        'due_on',
        'brief',
        'accepted_at',
        'submitted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date:Y-m-d',
            'accepted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
