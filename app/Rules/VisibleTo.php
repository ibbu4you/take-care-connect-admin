<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Scope;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;

/**
 * "A record this person can see", for an id arriving in a form.
 *
 * A bare `exists:clients,id` accepts any client in the database, so a manager
 * could file a project — or open a deal — under a colleague's client by
 * editing the id, and the new page then showed that client's phone and email.
 * This applies the same scoping the list pages use.
 */
final class VisibleTo implements ValidationRule
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $ownerColumns
     */
    public function __construct(
        private readonly Builder $query,
        private readonly User $user,
        private readonly string $module,
        private readonly array $ownerColumns,
        private readonly string $message,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            $fail($this->message);

            return;
        }

        $visible = Scope::apply(clone $this->query, $this->user, $this->module, $this->ownerColumns)
            ->whereKey((int) $value)
            ->exists();

        if (! $visible) {
            $fail($this->message);
        }
    }
}
