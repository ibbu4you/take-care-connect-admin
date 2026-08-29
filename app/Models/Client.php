<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client account.
 *
 * The schema arrives with the interviews phase because an interview is booked
 * against a client; the sales module that manages these is built later.
 */
class Client extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name', 'contact_person', 'email', 'phone', 'phone_normalized',
        'whatsapp', 'website', 'instagram', 'city', 'state', 'trade', 'address',
        'source', 'owner_id', 'created_by', 'status', 'notes', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    protected static function booted(): void
    {
        // Normalised on the way in, so duplicate detection has something stable
        // to match on: the same number arrives as "+91 98765 43210",
        // "098765 43210" and "9876543210" from three different people.
        static::saving(function (Client $client): void {
            $client->phone_normalized = filled($client->phone)
                ? preg_replace('/\D+/', '', $client->phone)
                : null;
        });
    }

    /** @return BelongsTo<User, $this> */
    /**
     * Everything this person may see.
     *
     * On the model so the list, the pickers that offer clients elsewhere, and
     * ClientPolicy all read one definition. A picker scoped more loosely than
     * the list is how somebody ends up filing work against an account they
     * cannot then open.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return Scope::apply($query, $user, 'clients', ['owner_id', 'created_by']);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Interview, $this> */
    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }
}
