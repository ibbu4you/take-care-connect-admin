<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Who changed what, and when.
 *
 * Read-only by construction: there is no route to edit or delete an entry, and
 * there deliberately never will be. An audit log somebody can tidy up is not an
 * audit log.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('audit.view'), 403);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'event' => (string) $request->query('event', ''),
            'causer' => (string) $request->query('causer', ''),
        ];

        $activities = Activity::query()
            ->with('causer:id,name,email')
            ->when($filters['event'] !== '', fn ($query) => $query->where('event', $filters['event']))
            ->when($filters['causer'] !== '', fn ($query) => $query->where('causer_id', $filters['causer']))
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $term = "%{$filters['search']}%";

                $query->where(function ($scoped) use ($term) {
                    $scoped->where('description', 'like', $term)
                        ->orWhere('subject_type', 'like', $term);
                });
            })
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Activity $activity) => [
                'id' => $activity->id,
                'description' => $activity->description,
                'event' => $activity->event,
                // The FQCN is noise to a reader: App\Models\User -> "User".
                'subject' => $activity->subject_type ? class_basename($activity->subject_type) : null,
                'subject_id' => $activity->subject_id,
                'causer' => $activity->causer ? [
                    'name' => $activity->causer->name,
                    'email' => $activity->causer->email,
                ] : null,
                'changes' => $this->summariseChanges($activity),
                'created_at' => $activity->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Audit/Index', [
            'activities' => $activities,
            'filters' => $filters,
            'events' => Activity::query()
                ->select('event')
                ->whereNotNull('event')
                ->distinct()
                ->orderBy('event')
                ->pluck('event'),
            'causers' => User::query()
                ->whereIn('id', Activity::query()->whereNotNull('causer_id')->distinct()->pluck('causer_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name]),
        ]);
    }

    /**
     * Flatten spatie's `{attributes, old}` payload into field/from/to rows.
     *
     * Doing it here rather than in the component keeps the page dumb, and means
     * the shape the table renders is decided in one place instead of being
     * re-derived per row in JavaScript.
     *
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    private function summariseChanges(Activity $activity): array
    {
        $properties = $activity->properties ?? collect();
        $attributes = $properties->get('attributes', []);
        $old = $properties->get('old', []);

        if (! is_array($attributes)) {
            return [];
        }

        $changes = [];

        foreach ($attributes as $field => $to) {
            $from = is_array($old) ? ($old[$field] ?? null) : null;

            $changes[] = [
                'field' => $field,
                'from' => $this->readable($from),
                'to' => $this->readable($to),
            ];
        }

        return $changes;
    }

    /**
     * Turn any stored value into one short, readable line.
     *
     * ── Why this handles more than it looks like it needs to ────────────────
     * Audited columns are not all scalars. An interview's `qa` is a list of
     * {question, answer} pairs, and a user's `denied_permissions` is a list of
     * strings — so a naive implode over the values hits `strval()` on an array
     * and takes the whole page down with it. That is precisely how this page
     * started 500-ing the moment interviews began being audited.
     *
     * Long values are truncated too: the log is only useful if a row stays one
     * line, and a rewritten article body would otherwise fill the screen.
     */
    private function readable(mixed $value): string
    {
        $text = match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            is_array($value) => $this->readableArray($value),
            default => json_encode($value) ?: 'unreadable',
        };

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 120).'…' : $text;
    }

    /**
     * A list of strings reads as a list. Anything more nested only usefully
     * reads as a count — "3 items" tells the reader something; a flattened dump
     * of three question-and-answer pairs does not.
     *
     * @param  array<array-key, mixed>  $value
     */
    private function readableArray(array $value): string
    {
        if ($value === []) {
            return 'none';
        }

        $flat = array_filter($value, static fn (mixed $item): bool => is_scalar($item) || $item === null);

        if (count($flat) !== count($value)) {
            return count($value).' '.(count($value) === 1 ? 'item' : 'items');
        }

        return implode(', ', array_map(
            static fn (mixed $item): string => is_bool($item) ? ($item ? 'Yes' : 'No') : (string) $item,
            $value,
        ));
    }
}
