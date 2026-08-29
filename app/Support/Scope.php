<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Row-level scoping: "my work" versus "everyone's work".
 *
 * Most roles here hold `{module}.view` but not `{module}.view.all`, which means
 * their lists must be narrowed to rows they are actually on. Doing that inline
 * in each controller is how one index method eventually forgets and leaks the
 * whole table, so every list query goes through this instead.
 *
 * The ownership test is a list of columns rather than a single `owner_id`
 * because a row usually has several people on it: an interview belongs to the
 * anchor who runs it, the manager who booked it, the writer it was handed to
 * and the creative cutting the reel — and all four should see it.
 */
final class Scope
{
    /**
     * Narrow a query to rows the user owns, unless they hold `{module}.view.all`.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $ownerColumns
     * @param  (\Closure(Builder<TModel>, User): void)|null  $also  extra OR conditions,
     *                                                              for ownership that is not a plain column — see the note below
     * @return Builder<TModel>
     */
    public static function apply(
        Builder $query,
        User $user,
        string $module,
        array $ownerColumns,
        ?\Closure $also = null,
    ): Builder {
        if (self::seesEverything($user, $module)) {
            return $query;
        }

        /*
         | Wrapped in its own closure so the OR-chain cannot escape and widen a
         | filter the caller already applied. Without the nesting,
         | `where('stage', 'draft')->scope(...)` would become
         | "stage is draft OR I am the anchor", which returns rows in every
         | stage the moment the user is on one of them.
         */
        return $query->where(function (Builder $scoped) use ($user, $ownerColumns, $also): void {
            foreach ($ownerColumns as $column) {
                $scoped->orWhere($column, $user->getKey());
            }

            /*
             | Ownership that is not a column on this table.
             |
             | A deliverable is visible to everybody on the interview it was cut
             | from, which is a relationship rather than a foreign key here. The
             | policy already says so; without this hook the LIST would disagree
             | with it, and somebody would be able to open a record by URL that
             | their own list never showed them.
             */
            if ($also !== null) {
                $also($scoped, $user);
            }
        });
    }

    /** Whether this user sees the whole module rather than just their own rows. */
    public static function seesEverything(User $user, string $module): bool
    {
        return $user->can("{$module}.view.all");
    }
}
