<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\Workflow\ArticleStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A piece of writing on its way to the website.
 *
 * @property ArticleStage $stage
 */
class Article extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'interview_id',
        'title',
        'category_id',
        'city',
        'target_type',
        'writer_id',
        'reviewer_id',
        'stage',
        'stage_entered_at',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'review_note',
        'due_on',
        'priority',
        'published_url',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'stage' => ArticleStage::class,
            'stage_entered_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'due_on' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Article $article): void {
            /*
             | The slug follows the title until the article is published.
             |
             | After that it is frozen: the slug is part of the live URL, and
             | silently changing it here would make this record disagree with
             | the page the public is reading.
             */
            if ($article->stage !== ArticleStage::Published && filled($article->title)) {
                $article->slug = static::uniqueSlug($article->title, $article->id);
            }
        });
    }

    /**
     * A slug that no other article is using.
     *
     * Suffixed rather than rejected: two craftsmen genuinely can have the same
     * headline, and refusing the second one would be a validation error the
     * writer can do nothing useful about.
     */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'article';
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $query) => $query->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * What is still missing before this can be approved.
     *
     * The same shape as the interview handover check, and for the same reason:
     * the reviewer should see what is outstanding on the page rather than by
     * pressing a button and reading an error.
     *
     * @return list<string>
     */
    public function missingForReview(): array
    {
        $missing = [];

        /*
         | The draft is a file.
         |
         | It used to be HTML typed into the admin, and readiness meant fifty
         | words plus an excerpt plus SEO fields. The team writes in Word and
         | publishes on the website by hand, so the one thing a reviewer cannot
         | proceed without is the document itself.
         */
        if (! $this->assets()->where('kind', 'document')->exists()) {
            $missing[] = 'the draft';
        }

        return $missing;
    }

    public function isReadyForReview(): bool
    {
        return $this->missingForReview() === [];
    }

    /** The draft the reviewer is actually looking at — the most recent upload. */
    public function currentDraft(): ?ArticleAsset
    {
        return $this->assets()->where('kind', 'document')->latest('id')->first();
    }

    /** @return BelongsTo<Interview, $this> */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    /** @return BelongsTo<User, $this> */
    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'writer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ArticleAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(ArticleAsset::class);
    }

    /**
     * Social work commissioned off the finished article.
     *
     * @return MorphMany<Deliverable, $this>
     */
    public function deliverables(): MorphMany
    {
        return $this->morphMany(Deliverable::class, 'deliverable_of')->orderBy('slot_number');
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
        return $query->where('stage', '!=', ArticleStage::Published->value);
    }

    /**
     * Waiting on a reviewer.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('stage', ArticleStage::InternalReview->value);
    }
}
