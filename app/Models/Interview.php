<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An interview: the spine of the SOP.
 *
 * @property InterviewKind $kind
 * @property InterviewStage $stage
 */
class Interview extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'kind',
        'title',
        'subject_name',
        'subject_designation',
        'subject_company',
        'subject_phone',
        'subject_email',
        'client_id',
        'project_id',
        'anchor_id',
        'scheduled_by',
        'content_owner_id',
        'creative_owner_id',
        'scheduled_at',
        'duration_minutes',
        'mode',
        'location',
        'meeting_url',
        'recording_url',
        'stage',
        'stage_entered_at',
        'conducted_at',
        'handed_over_at',
        'qa_summary',
        'language',
        'consent_to_publish',
        'requires_article',
        'requires_social',
        'due_on',
        'priority',
        'notes',
        'cancelled_reason',
    ];

    protected function casts(): array
    {
        return [
            'kind' => InterviewKind::class,
            'stage' => InterviewStage::class,
            'scheduled_at' => 'datetime',
            'stage_entered_at' => 'datetime',
            'conducted_at' => 'datetime',
            'handed_over_at' => 'datetime',
            'due_on' => 'date:Y-m-d',
            'consent_to_publish' => 'boolean',
            'requires_article' => 'boolean',
            'requires_social' => 'boolean',
            'duration_minutes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        /*
         | `requires_article` is derived from the kind, but stored.
         |
         | Keeping it in step here rather than trusting callers means the column
         | can never drift from the kind — which matters because the content
         | team's entire queue is an indexed read on it.
         */
        static::saving(function (Interview $interview): void {
            $interview->requires_article = $interview->kind->requiresArticle();
        });
    }

    /**
     * The stages this interview may move to next.
     *
     * Narrows the generic workflow by kind: only a business interview can be
     * handed over, because the social kinds have nobody to hand to. A social
     * interview goes straight from conducted to published once its reels are
     * out.
     *
     * @return list<InterviewStage>
     */
    public function allowedNextStages(): array
    {
        $allowed = $this->stage->allowedNext();

        if (! $this->kind->requiresArticle()) {
            $allowed = array_values(array_filter(
                $allowed,
                static fn (InterviewStage $stage): bool => $stage !== InterviewStage::HandedOver,
            ));
        }

        return $allowed;
    }

    /**
     * Whether this interview carries everything the content team needs.
     *
     * The requirements come straight from the SOP: the transcript,
     * the Google Meet recording, and the Q&A the anchor typed. Enforced again in
     * HandoverRequest — this method exists so the interview page can show what
     * is still missing *before* somebody presses the button.
     *
     * @return list<string> the missing pieces, empty when ready
     */
    public function missingForHandover(): array
    {
        if (! $this->kind->requiresArticle()) {
            return [];
        }

        $missing = [];

        /*
         | The transcript, however it was made.
         |
         | The anchor runs the recording through Turboscript and uploads the
         | exported PDF. `document` is accepted alongside `transcript` because
         | the two are the same artefact as far as the content team is
         | concerned, and refusing a file over which of two labels somebody
         | picked from a dropdown would be a pointless argument to have.
         */
        if (! $this->assets()->whereIn('kind', ['transcript', 'document'])->exists()) {
            $missing[] = 'the transcript';
        }

        // Either an uploaded recording or a pasted link counts: a Meet recording
        // usually lives in Drive and is far too large to want a copy of. It is
        // still required alongside the transcript, because checking a quote
        // against what was actually said is the reason to keep it.
        $hasRecording = filled($this->recording_url)
            || $this->assets()->where('kind', 'recording')->exists();

        if (! $hasRecording) {
            $missing[] = 'the recording link';
        }

        return $missing;
    }

    public function isReadyForHandover(): bool
    {
        return $this->missingForHandover() === [];
    }

    /** @return BelongsTo<User, $this> */
    public function anchor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anchor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    /** @return BelongsTo<User, $this> */
    public function contentOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'content_owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creativeOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creative_owner_id');
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

    /** @return HasMany<InterviewAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(InterviewAsset::class);
    }

    /**
     * Posters, reels and edits cut from this interview.
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

    /** @return MorphMany<Assignment, $this> */
    public function assignments(): MorphMany
    {
        return $this->morphMany(Assignment::class, 'assignable')->latest();
    }

    /**
     * Interviews still on somebody's plate.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('stage', [
            InterviewStage::Published->value,
            InterviewStage::Cancelled->value,
        ]);
    }

    /**
     * Conducted business interviews that have not reached the content team.
     *
     * The single most useful query in this module: it is the anchor's "finish
     * this" list and the content team's "why is it late" list at once.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAwaitingHandover(Builder $query): Builder
    {
        return $query
            ->where('requires_article', true)
            ->where('stage', InterviewStage::Conducted->value);
    }
}
