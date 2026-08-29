<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\Settings;
use App\Support\Workflow\PipelineStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One card on the pipeline board.
 *
 * @property PipelineStage $stage
 */
class Deal extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    /**
     * How long a deal may go without movement before it counts as quiet.
     *
     * Named for the threshold rather than the measurement, because `idleDays()`
     * on an instance is the opposite question — how long *this* deal has been
     * quiet — and two methods a letter apart answering different questions is
     * how somebody compares a deal against itself.
     *
     * Configurable, because the right answer differs by business: a fortnight
     * suits a studio selling packages and is far too long for somebody closing
     * in days. Settings::int clamps it, so this can never become a value that
     * quietly switches the "gone quiet" list off altogether.
     */
    public static function idleThreshold(): int
    {
        return Settings::int('deals.idle_days');
    }

    protected $fillable = [
        'code',
        'client_id',
        'project_id',
        'title',
        'stage',
        'stage_entered_at',
        'owner_id',
        'source',
        'expected_value',
        'actual_value',
        'currency',
        'probability',
        'expected_close_on',
        'closed_at',
        'outcome',
        'lost_reason',
        'board_order',
        'last_activity_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'stage' => PipelineStage::class,
            'stage_entered_at' => 'datetime',
            'expected_close_on' => 'date:Y-m-d',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'expected_value' => 'decimal:2',
            'actual_value' => 'decimal:2',
            'probability' => 'integer',
            'board_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        /*
         | The probability follows the stage unless somebody has set it by hand.
         |
         | Without this the weighted pipeline figure would stay at the value the
         | deal was created with, and a board full of proposals would report the
         | same number as a board full of cold leads.
         */
        static::saving(function (Deal $deal): void {
            if ($deal->probability === null || $deal->isDirty('stage')) {
                $deal->probability = $deal->stage->defaultProbability();
            }
        });
    }

    /**
     * What this deal contributes to the pipeline figure.
     *
     * Weighted, because the unweighted sum of every open deal is a number
     * nobody believes and therefore nobody uses.
     */
    public function weightedValue(): float
    {
        if ($this->stage->isClosed()) {
            return 0.0;
        }

        return round((float) $this->expected_value * ($this->probability ?? 0) / 100, 2);
    }

    /** Days since anything happened. Null when it is closed or brand new. */
    public function idleDays(): ?int
    {
        if ($this->stage->isClosed() || $this->last_activity_at === null) {
            return null;
        }

        return (int) $this->last_activity_at->diffInDays(now());
    }

    public function isIdle(): bool
    {
        return ($this->idleDays() ?? 0) >= self::idleThreshold();
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<FollowUp, $this> */
    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    /**
     * The append-only timeline of notes on this deal.
     *
     * NOT called `notes()`. The deals table already has a free-text `notes`
     * column, and an attribute always wins over a relation of the same name —
     * so `$deal->notes` would return the string and every call on it would fail
     * with "member function map() on null". Naming them apart is the whole fix.
     *
     * @return MorphMany<Note, $this>
     */
    public function timeline(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable')->latest();
    }

    /** @return MorphMany<StageEvent, $this> */
    public function stageEvents(): MorphMany
    {
        return $this->morphMany(StageEvent::class, 'subject')->latest('changed_at');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('outcome');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWon(Builder $query): Builder
    {
        return $query->where('outcome', PipelineStage::Won->value);
    }

    /**
     * Open deals nobody has touched for a fortnight.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeIdle(Builder $query): Builder
    {
        return $query->open()->where(function (Builder $scoped) {
            $scoped->where('last_activity_at', '<', now()->subDays(self::idleThreshold()))
                ->orWhereNull('last_activity_at');
        });
    }
}
