<?php

namespace App\Models;

use App\Support\Workflow\CallOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to a lead, and what came of it.
 *
 * @property CallOutcome $outcome
 */
class LeadCall extends Model
{
    protected $fillable = ['lead_id', 'user_id', 'outcome', 'note', 'called_at'];

    protected function casts(): array
    {
        return [
            'outcome' => CallOutcome::class,
            'called_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Calls where somebody picked up and spoke.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeConnected(Builder $query): Builder
    {
        return $query->whereIn('outcome', CallOutcome::connectedValues());
    }
}
