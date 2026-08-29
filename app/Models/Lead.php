<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\Scope;
use App\Support\Workflow\LeadSource;
use App\Support\Workflow\LeadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody a manager is talking to who is not a client yet.
 *
 * A manager speaks to far more people than ever become clients. Filing them all
 * as clients would leave the directory of people you actually look after full
 * of names nobody heard back from — so they live here until one of them says
 * yes, and converting creates the client.
 *
 * The lead survives its own conversion. How a client was found is worth
 * keeping, and so is the record of the ninety who said no.
 *
 * @property LeadStatus $status
 * @property LeadSource $source
 */
class Lead extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'contact_person', 'email', 'phone', 'phone_normalized',
        'whatsapp', 'city', 'state', 'trade', 'website', 'instagram',
        'source', 'status', 'owner_id', 'created_by', 'next_action_on',
        'last_contacted_at', 'estimated_value', 'notes', 'lost_reason',
        'converted_client_id', 'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'source' => LeadSource::class,
            'next_action_on' => 'date:Y-m-d',
            'last_contacted_at' => 'datetime',
            'converted_at' => 'datetime',
            'estimated_value' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        /*
         | Keep the searchable copy of the phone number in step.
         |
         | Numbers are typed however the person who took the call types them —
         | "+91 98765 43210", "098765-43210". Searching for any of those has to
         | find all of them, and the only way that works is to store the digits
         | alongside whatever was entered.
         */
        static::saving(function (Lead $lead): void {
            $lead->phone_normalized = $lead->phone === null
                ? null
                : (preg_replace('/\D+/', '', $lead->phone) ?: null);
        });
    }

    /** Past its date and still worth chasing. A converted lead is never overdue. */
    public function isOverdue(): bool
    {
        return $this->status->isOpen()
            && $this->next_action_on !== null
            && $this->next_action_on->isPast();
    }

    /** Days since anybody spoke to them. Null when nobody ever has. */
    public function daysSinceContact(): ?int
    {
        return $this->last_contacted_at === null
            ? null
            : (int) round($this->last_contacted_at->diffInDays(now()));
    }

    /**
     * Everything this person may see.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return Scope::apply($query, $user, 'leads', ['owner_id', 'created_by']);
    }

    /**
     * Still in play — neither converted nor written off.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            LeadStatus::Converted->value,
            LeadStatus::Unqualified->value,
        ]);
    }

    /**
     * Due a call today or overdue one.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->open()->whereDate('next_action_on', '<=', now());
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

    /** @return BelongsTo<Client, $this> */
    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    /** @return HasMany<FollowUp, $this> */
    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    /**
     * The contact log.
     *
     * Named `timeline` rather than `notes` because `notes` is also a column on
     * this table — the same collision that took the deal page down once, and
     * which reads as "call to a member function on a string" rather than as
     * anything to do with naming.
     *
     * @return MorphMany<Note, $this>
     */
    public function timeline(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable')->latest();
    }
}
