<?php

namespace App\Models;

use App\Support\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's target for one month.
 *
 * The period is 'YYYY-MM' rather than a date range: a target belongs to a month
 * as a unit, and a range only invites arguments about the boundary.
 */
class Target extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id', 'period', 'interviews_target', 'deals_target',
        'revenue_target', 'set_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'interviews_target' => 'integer',
            'deals_target' => 'integer',
            'revenue_target' => 'decimal:2',
        ];
    }

    /** The month this belongs to, as a person would write it. */
    public function periodLabel(): string
    {
        return Carbon::createFromFormat('!Y-m', $this->period)->format('F Y');
    }

    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
