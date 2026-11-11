<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Roles;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "Somebody who can actually do this job."
 *
 * A bare `exists:users,id` accepted anyone: a deactivated account, a deleted
 * one (which then left the work assigned to nobody visible), or a person on the
 * wrong team — and for an interview, naming someone its content owner hands
 * them the subject's contact details.
 */
final class ActiveMember implements ValidationRule
{
    /** @param  list<string>  $roles  any one of these; Admin always qualifies */
    public function __construct(
        private readonly array $roles,
        private readonly string $message = 'Choose an active member of the right team.',
    ) {}

    public static function anchor(): self
    {
        return new self([Roles::ANCHOR], 'Choose an active anchor.');
    }

    public static function content(): self
    {
        return new self([Roles::CONTENT], 'Choose an active member of the content team.');
    }

    public static function creative(): self
    {
        return new self([Roles::CREATIVE], 'Choose an active member of the creative team.');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = is_numeric($value) ? User::query()->whereKey((int) $value)->first() : null;

        if ($user === null || ! $user->is_active || ! $user->hasAnyRole([...$this->roles, Roles::ADMIN])) {
            $fail($this->message);
        }
    }
}
