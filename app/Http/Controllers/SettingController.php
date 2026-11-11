<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The numbers the team can change without a deploy.
 *
 * ── What is deliberately not here ──────────────────────────────────────────
 * Anything that belongs in `.env` — database credentials, the app key, the
 * upload limits — and anything that is a decision rather than a preference.
 * A settings page that can switch off two-factor or rename a role is a
 * settings page that becomes the fastest route to a broken install.
 *
 * What is here is the handful of durations that drive reminders and default
 * deadlines, plus the category list, which is a copy of the website's and
 * drifts from it over time.
 */
class SettingController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('settings.view'), 403);

        return Inertia::render('Settings/Index', [
            'fields' => Settings::forDisplay(),
            'categories' => Category::query()
                ->withCount('articles')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'is_active' => $category->is_active,
                    'articles_count' => $category->articles_count,
                ]),
            'can' => ['update' => $request->user()->can('settings.update')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('settings.update'), 403);

        /*
         | Rules are built from the same config the form renders from.
         |
         | Written out by hand they would be a second copy of the bounds, and
         | the copy that gets forgotten is always the validation one — which is
         | the half that actually protects anything.
         */
        $rules = [];

        foreach (Settings::fields() as $field) {
            $rules[self::path($field['key'])] = [
                'required', 'integer', "min:{$field['min']}", "max:{$field['max']}",
            ];
        }

        $validated = $request->validate($rules, $this->messages());

        foreach ($validated['values'] as $key => $value) {
            Settings::put($key, $value, $request->user()->id);
        }

        return back()->with('success', 'Settings saved.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('settings.update'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('categories', 'name')],
        ]);

        Category::create([
            'name' => $validated['name'],
            'slug' => $this->slugFor($validated['name']),
            'sort_order' => (int) Category::max('sort_order') + 1,
            'is_active' => true,
        ]);

        return back()->with('success', "{$validated['name']} added.");
    }

    public function updateCategory(Request $request, Category $category): RedirectResponse
    {
        abort_unless($request->user()->can('settings.update'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('categories', 'name')->ignore($category)],
            'is_active' => ['required', 'boolean'],
        ]);

        /*
         | The slug follows the name only while nothing has been filed under it.
         |
         | The slug is what the export bundle carries to the website. Changing
         | it after articles exist would silently repoint work already
         | published, so a rename after that point is a label change here and
         | nothing more.
         */
        $category->update([
            'name' => $validated['name'],
            'slug' => $category->articles()->exists() ? $category->slug : $this->slugFor($validated['name'], $category),
            'is_active' => $validated['is_active'],
        ]);

        return back()->with('success', 'Saved.');
    }

    /**
     * A slug for this name that is non-empty and unused.
     *
     * Two names can share one ("Food & Drink" and "Food and Drink"), and a
     * name with nothing sluggable in it has none at all; both used to reach
     * the unique index and return a 500. Refused instead, in words.
     */
    private function slugFor(string $name, ?Category $ignore = null): string
    {
        $slug = Str::slug($name);

        // A name in Tamil, Hindi or any other script has no Latin slug. That
        // is a perfectly good category name, so it is given a numbered one.
        if ($slug === '') {
            $base = 'category';
            $slug = $base;
            $n = 2;

            while (Category::withoutGlobalScopes()->where('slug', $slug)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                ->exists()) {
                $slug = $base.'-'.$n++;
            }

            return $slug;
        }

        $taken = Category::withoutGlobalScopes()
            ->where('slug', $slug)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => 'That is too close to an existing category name. Choose something more distinct.',
            ]);
        }

        return $slug;
    }

    public function destroyCategory(Request $request, Category $category): RedirectResponse
    {
        abort_unless($request->user()->can('settings.update'), 403);

        /*
         | A category with articles is switched off, not deleted.
         |
         | Deleting one would null the category on every article filed under it,
         | and those articles have already been exported and published with that
         | name on them. Inactive keeps the history and stops it being chosen
         | again, which is what "delete" actually means here.
         */
        if ($category->articles()->exists()) {
            return back()->with(
                'error',
                "{$category->name} has articles filed under it. Switch it off instead — that keeps the history and stops it being picked again.",
            );
        }

        $name = $category->name;
        $category->delete();

        return back()->with('success', "{$name} removed.");
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        $messages = [];

        foreach (Settings::fields() as $field) {
            $key = self::path($field['key']);
            $label = strtolower($field['label']);

            $messages["{$key}.min"] = "“{$field['label']}” cannot be less than {$field['min']} {$field['unit']}.";
            $messages["{$key}.max"] = "“{$field['label']}” cannot be more than {$field['max']} {$field['unit']}.";
            $messages["{$key}.required"] = "Give a number for “{$label}”.";
        }

        return $messages;
    }

    /**
     * The validation path for one setting key.
     *
     * ── Why the dot is escaped ─────────────────────────────────────────────
     * Setting keys are namespaced — `deals.idle_days` — and the validator reads
     * a dot as "step into a nested array". Unescaped, `values.deals.idle_days`
     * asks for `$data['values']['deals']['idle_days']`, which does not exist,
     * so every field fails as required no matter what was posted. Escaping it
     * keeps the key a single flat string.
     */
    private static function path(string $key): string
    {
        return 'values.'.str_replace('.', '\.', $key);
    }
}
