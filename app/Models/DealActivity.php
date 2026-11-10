<?php

namespace App\Models;

use App\Support\Workflow\ActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A call, meeting, visit, WhatsApp or email on a deal.
 *
 * @property ActivityType $type
 */
class DealActivity extends Model
{
    protected $fillable = ['deal_id', 'user_id', 'type', 'outcome', 'note', 'happened_at'];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'happened_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function outcomeLabel(): ?string
    {
        return $this->outcome === null ? null : ($this->type->outcomes()[$this->outcome] ?? $this->outcome);
    }
}
