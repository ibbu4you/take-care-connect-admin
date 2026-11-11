<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reminder to call somebody back.
 *
 * Sales is mostly this. A reminder in a notebook is one nobody else can pick up
 * when that person is on leave, which is the whole reason it lives here.
 */
class FollowUp extends Model
{
    public const CHANNELS = ['call', 'meeting', 'visit', 'whatsapp', 'email'];

    protected $fillable = [
        'client_id', 'lead_id', 'deal_id', 'user_id', 'due_on',
        'channel', 'note', 'state', 'completed_at', 'outcome_note',
    ];

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'completed_at' => 'datetime'];
    }

    public function isOverdue(): bool
    {
        return $this->state === 'pending' && $this->due_on?->lt(today()) === true; // A date: due today is not yet overdue.
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('state', 'pending');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->pending()->whereDate('due_on', '<=', now());
    }
}
