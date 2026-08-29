<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\Scope;
use App\Support\Workflow\ProjectKind;
use App\Support\Workflow\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A piece of work sold to a client.
 *
 * ── What this is for ───────────────────────────────────────────────────────
 * A deal answers "will they buy". A project answers "what do we now owe them,
 * and how much of it have we delivered". They are deliberately separate: a
 * client who buys a six-interview package has one deal and one project with
 * six bookings under it, and the questions you ask of each are different.
 *
 * Without it, a run of interviews for one client is six unrelated rows and
 * nobody can answer "how far through the package are we" without counting.
 *
 * @property ProjectKind $kind
 * @property ProjectStatus $status
 */
class Project extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code', 'client_id', 'title', 'kind', 'owner_id', 'created_by',
        'status', 'starts_on', 'due_on', 'value_amount', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ProjectKind::class,
            'status' => ProjectStatus::class,
            'starts_on' => 'date:Y-m-d',
            'due_on' => 'date:Y-m-d',
            'value_amount' => 'decimal:2',
        ];
    }

    /**
     * How much of the promised work has actually shipped.
     *
     * ── Why interviews rather than a field ─────────────────────────────────
     * A stored "percent complete" is a number somebody has to remember to
     * update, and the first time they forget it becomes a number that lies.
     * Counting the bookings underneath is always current, and it is the same
     * thing anybody would count by hand.
     *
     * Cancelled and no-show bookings are excluded from the denominator: a
     * project is not less complete because a subject cancelled, it is smaller.
     *
     * @return array{done: int, total: int, percent: int|null}
     */
    public function progress(): array
    {
        $interviews = $this->relationLoaded('interviews')
            ? $this->interviews
            : $this->interviews()->get();

        $counted = $interviews->reject(
            fn (Interview $interview): bool => in_array($interview->stage->value, ['cancelled', 'no_show'], true),
        );

        $done = $counted->filter(
            fn (Interview $interview): bool => in_array(
                $interview->stage->value,
                ['handed_over', 'in_content', 'published'],
                true,
            ),
        )->count();

        return [
            'done' => $done,
            'total' => $counted->count(),
            // No bookings yet is not 0% done, it is nothing to report — and a
            // 0% bar on a project that was sold yesterday reads as a failure.
            'percent' => $counted->count() > 0
                ? (int) round($done / $counted->count() * 100)
                : null,
        ];
    }

    /** Past its date and still open. A delivered project is never overdue. */
    public function isOverdue(): bool
    {
        return $this->status->isActive()
            && $this->due_on !== null
            && $this->due_on->isPast();
    }

    /**
     * Everything this person may see.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        /*
         | Owner *or* creator.
         |
         | A manager can hand a project to a colleague. Scoped on the owner
         | alone, the person who set it up would watch it vanish from their list
         | the moment they saved — which reads as having lost the work rather
         | than as having delegated it.
         */
        return Scope::apply($query, $user, 'projects', ['owner_id', 'created_by']);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ProjectStatus::Open->value);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Interview, $this> */
    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }

    /** @return HasMany<Deal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }
}
