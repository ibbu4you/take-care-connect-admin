<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\Scope;
use App\Support\Workflow\DeliverableKind;
use App\Support\Workflow\DeliverableStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A poster, a reel, an edit.
 *
 * Hangs off an interview or an article. Two of the three interview kinds never
 * produce an article, so the polymorphic parent is what lets their output exist
 * at all.
 *
 * @property DeliverableKind $kind
 * @property DeliverableStage $stage
 */
class Deliverable extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'deliverable_of_type',
        'deliverable_of_id',
        'kind',
        'title',
        'slot_number',
        'stage',
        'stage_entered_at',
        'assigned_to',
        'assigned_by',
        'approved_by',
        'due_on',
        'priority',
        'brief',
        'caption',
        'hashtags',
        'platforms',
        'submitted_at',
        'approved_at',
        'published_at',
        'rejection_note',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DeliverableKind::class,
            'stage' => DeliverableStage::class,
            'platforms' => 'array',
            'stage_entered_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'due_on' => 'date:Y-m-d',
            'slot_number' => 'integer',
        ];
    }

    /**
     * What is still missing before this can go for approval.
     *
     * The same shape as the interview handover and the article review checks,
     * for the same reason: the creative should see what is outstanding on the
     * page rather than by pressing a button and reading an error.
     *
     * @return list<string>
     */
    public function missingForReview(): array
    {
        $missing = [];

        if ($this->files()->count() === 0) {
            $missing[] = 'the file itself';
        }

        // A reel posted with no caption is a reel somebody has to go back and
        // edit on the platform, which is far more annoying than writing it here.
        if (blank($this->caption)) {
            $missing[] = 'a caption';
        }

        return $missing;
    }

    public function isReadyForReview(): bool
    {
        return $this->missingForReview() === [];
    }

    /** A published deliverable with no link is indistinguishable from an unposted one. */
    public function hasSocialLink(): bool
    {
        return $this->socialPosts()->exists();
    }

    /** The latest upload — what a reviewer is actually looking at. */
    public function currentFile(): ?DeliverableFile
    {
        return $this->files()->orderByDesc('version')->first();
    }

    public function nextVersion(): int
    {
        return (int) ($this->files()->max('version') ?? 0) + 1;
    }

    /**
     * A readable name for the thing this belongs to.
     *
     * Used in lists, where "DLV-000004" alone tells nobody which shoot it came
     * from.
     */
    public function parentLabel(): string
    {
        $parent = $this->deliverableOf;

        return match (true) {
            $parent instanceof Interview => $parent->title,
            $parent instanceof Article => $parent->title,
            default => 'Unattached',
        };
    }

    public function parentCode(): ?string
    {
        $parent = $this->deliverableOf;

        return $parent instanceof Interview || $parent instanceof Article ? $parent->code : null;
    }

    /**
     * The interview or article this belongs to.
     *
     * ── Why the columns are named rather than the relation renamed ──────────
     * The method is `deliverableOf` but the columns are `deliverable_of_*`, so
     * Laravel's default derivation (`deliverableOf_type`) is wrong and something
     * has to be told.
     *
     * Passing `morphTo('deliverable_of')` fixes the columns but renames the
     * relation, and eager loading then stores the resolved parent under
     * `deliverable_of` while this accessor still reads `deliverableOf` — so
     * `with('deliverableOf')` loads both keys, the accessor finds the empty one,
     * and every parent silently reads as missing. It renders as wrong text
     * rather than an error, which is the worst way for it to fail.
     *
     * Naming the columns keeps the relation name and the accessor in agreement.
     *
     * @return MorphTo<Model, $this>
     */
    public function deliverableOf(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'deliverable_of_type', 'deliverable_of_id');
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

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<DeliverableFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(DeliverableFile::class)->orderByDesc('version');
    }

    /** @return HasMany<SocialPost, $this> */
    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    /** @return MorphMany<StageEvent, $this> */
    public function stageEvents(): MorphMany
    {
        return $this->morphMany(StageEvent::class, 'subject')->latest('changed_at');
    }

    /**
     * Everything this person may see.
     *
     * Mirrors DeliverablePolicy::view(), including the parent-interview clause —
     * a deliverable is visible to everybody on the interview it was cut from,
     * which is a relationship rather than a column here.
     *
     * On the model rather than in each controller so the board, the dashboard
     * and the reports cannot drift from one another, or from the policy.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return Scope::apply(
            $query,
            $user,
            'deliverables',
            ['assigned_to', 'assigned_by'],
            function (Builder $scoped) use ($user): void {
                $scoped->orWhereHasMorph(
                    'deliverableOf',
                    [Interview::class],
                    function (Builder $interview) use ($user): void {
                        $interview->where(function (Builder $participant) use ($user): void {
                            $participant->where('anchor_id', $user->getKey())
                                ->orWhere('scheduled_by', $user->getKey())
                                ->orWhere('creative_owner_id', $user->getKey())
                                ->orWhere('content_owner_id', $user->getKey());
                        });
                    },
                );
            },
        );
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('stage', '!=', DeliverableStage::Published->value);
    }

    /**
     * Approved or scheduled but never posted — the list worth chasing.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAwaitingPublish(Builder $query): Builder
    {
        return $query->whereIn('stage', [
            DeliverableStage::Approved->value,
            DeliverableStage::Scheduled->value,
        ]);
    }
}
