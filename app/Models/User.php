<?php

namespace App\Models;

use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * A staff account.
 *
 * There is no public registration: every account is created by an Admin, and
 * `is_active` is how somebody is switched off without deleting the interviews,
 * articles and deals that point at them.
 */
class User extends Authenticatable
{
    use HasFactory;

    /*
     | The spatie check is aliased so the override below can call it after
     | applying this application's own subtraction step. Everything funnels
     | through hasPermissionTo() — can(), canAny(), the Gate, the `permission:`
     | middleware — so overriding this one method is the entire enforcement.
     */
    use HasRoles {
        hasPermissionTo as protected spatieHasPermissionTo;
    }
    use LogsActivity;
    use Notifiable;
    use SoftDeletes;

    /**
     * Audit options, hand-written rather than the shared Auditable trait.
     *
     * Auditable logs everything in $fillable. This model's fillable list is fine
     * today, but the row also holds a password hash and two encrypted 2FA
     * columns — and the activity log is readable by every Admin. An explicit
     * allow-list means adding a sensitive column later cannot quietly start
     * copying it into a table with a much wider audience.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'job_title', 'team', 'is_active', 'denied_permissions'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'job_title',
        'team',
        'avatar_path',
        'bio',
        'is_active',
        'denied_permissions',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Stored in lower case.
     *
     * Sign-in compares in lower case, and on a case-sensitive database an
     * account created as "Asha@Example.com" could then never sign in.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)),
        );
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'denied_permissions' => 'array',
            'last_login_at' => 'datetime',
            // Encrypted at rest: a stolen database dump must not be enough to
            // mint valid TOTP codes or spend somebody's recovery codes.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Permission check, with per-user revocations applied.
     *
     * spatie is additive: a permission granted by a role cannot be taken back
     * from one person. Without this, revoking `deals.close` from a single
     * Relationship Manager would mean cloning the whole role for them, and the
     * two copies would drift the first time either was edited.
     *
     * Nobody is exempt, including administrators. The one account that must
     * not be able to strip itself of administration is the *last* one, and that
     * is guarded where it belongs — in UserPolicy and UpdateUserRequest, which
     * can see how many are left.
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $name = is_string($permission) ? $permission : ($permission->name ?? null);

        // Applies to everybody, admins included. A revocation that the top
        // role silently ignored would be a checkbox the matrix shows as ticked
        // off and the application then behaves as though it never was.
        if ($name !== null && in_array($name, $this->denied_permissions ?? [], true)) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    /**
     * What this user can actually do: role grants, plus direct grants, minus
     * revocations.
     *
     * This is the list shared to Inertia and the list the sidebar filters on,
     * so it has to be computed the same way the backend decides — otherwise the
     * menu offers a page that then answers 403.
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        $granted = $this->getAllPermissions()->pluck('name')->all();
        $denied = $this->denied_permissions ?? [];

        return array_values(array_diff($granted, $denied));
    }

    /**
     * Permissions this user holds directly rather than through a role.
     *
     * @return list<string>
     */
    public function directPermissionNames(): array
    {
        return $this->permissions->pluck('name')->all();
    }

    /**
     * Permissions this user's roles grant, before any revocation is applied.
     *
     * The user form needs these separately so it can render a role-granted
     * permission as ticked-and-attributable rather than as an ordinary tick.
     *
     * @return list<string>
     */
    public function rolePermissionNames(): array
    {
        return $this->roles
            ->loadMissing('permissions')
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Roles::ADMIN);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return filled($this->two_factor_secret) && $this->two_factor_confirmed_at !== null;
    }

    public function avatarUrl(): ?string
    {
        return filled($this->avatar_path)
            ? Storage::disk('public')->url($this->avatar_path)
            : null;
    }

    /** The two initials shown when there is no avatar. */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(
            static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)),
            array_slice(array_filter($words), 0, 2),
        );

        return implode('', $letters) ?: '?';
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
