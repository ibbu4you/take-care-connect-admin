<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Setting;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Values the team can change without a deploy.
 *
 * ── What is actually being protected ───────────────────────────────────────
 * Every setting here drives something that runs on a schedule or fires a
 * notification, so the bounds are not tidiness — they are what stops a settings
 * page becoming the fastest route to a quietly broken system. A deal idle
 * threshold of ten years switches the "gone quiet" list off without anybody
 * realising they switched it off.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    // ── The store ──────────────────────────────────────────────────────────

    /** A fresh install has to work before anybody opens the page. */
    public function test_an_unwritten_setting_reads_its_default(): void
    {
        $this->assertSame(0, Setting::count());
        $this->assertSame(14, Settings::int('deals.idle_days'));
        $this->assertSame(3, Settings::int('interviews.handover_grace_days'));
    }

    public function test_a_written_setting_replaces_the_default(): void
    {
        Settings::put('deals.idle_days', 21);

        $this->assertSame(21, Settings::int('deals.idle_days'));
    }

    /**
     * The cache is what makes these cheap enough to read on every request, so
     * a write that does not invalidate it is a change that appears to do
     * nothing until the cache happens to expire.
     */
    public function test_writing_a_setting_invalidates_the_cache(): void
    {
        $this->assertSame(14, Settings::int('deals.idle_days'));

        Settings::put('deals.idle_days', 30);

        $this->assertSame(30, Settings::int('deals.idle_days'));
    }

    /** A value written straight into the database still has to behave. */
    public function test_a_value_beyond_the_bounds_is_clamped_on_read(): void
    {
        Setting::create(['key' => 'deals.idle_days', 'value' => '9999']);
        Settings::forget();

        $this->assertSame(120, Settings::int('deals.idle_days'));

        Setting::where('key', 'deals.idle_days')->update(['value' => '0']);
        Settings::forget();

        $this->assertSame(3, Settings::int('deals.idle_days'));
    }

    // ── It actually changes behaviour ──────────────────────────────────────

    /**
     * The point of the whole page: changing the number changes what the "gone
     * quiet" list contains, with no deploy.
     */
    public function test_the_idle_threshold_changes_which_deals_count_as_quiet(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $client = Client::create([
            'name' => 'A quiet client',
            'owner_id' => $manager->id,
            'created_by' => $manager->id,
            'source' => 'manual',
            'status' => 'active',
        ]);

        $deal = Deal::create([
            'code' => app(CodeGenerator::class)->next('DEA'),
            'client_id' => $client->id,
            'title' => 'A twenty-day-old conversation',
            'stage' => 'proposal',
            'owner_id' => $manager->id,
            'expected_value' => 50000,
            'last_activity_at' => now()->subDays(20),
        ]);

        $this->assertSame(1, Deal::idle()->count(), 'Twenty days is past the default fourteen.');

        Settings::put('deals.idle_days', 30);

        $this->assertSame(0, Deal::idle()->count(), 'Thirty days has not passed yet.');
        $this->assertFalse($deal->fresh()->isIdle());
    }

    // ── The page ───────────────────────────────────────────────────────────

    public function test_an_admin_opens_settings(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Index')
                ->has('fields', count(config('settings.fields')))
                ->where('can.update', true));
    }

    public function test_somebody_without_the_permission_cannot_reach_settings(): void
    {
        $this->actingAs($this->userWithRole(Roles::CONTENT))->get('/settings')->assertForbidden();
        $this->actingAs($this->userWithRole(Roles::RELATIONSHIP_MANAGER))->get('/settings')->assertForbidden();
    }

    public function test_an_admin_saves_a_setting(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->patch('/settings', ['values' => $this->values(['deals.idle_days' => 21])])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(21, Settings::int('deals.idle_days'));
    }

    public function test_a_value_outside_the_bounds_is_refused(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->patch('/settings', ['values' => $this->values(['deals.idle_days' => 1])])
            ->assertSessionHasErrors('values.deals.idle_days');

        $this->assertSame(14, Settings::int('deals.idle_days'));
    }

    public function test_who_changed_a_setting_is_recorded(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);

        $this->actingAs($admin)->patch('/settings', ['values' => $this->values(['deals.idle_days' => 21])]);

        $this->assertSame($admin->id, Setting::firstWhere('key', 'deals.idle_days')->updated_by);
    }

    // ── Categories ─────────────────────────────────────────────────────────

    public function test_an_admin_adds_a_category(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->post('/settings/categories', ['name' => 'Boat building'])
            ->assertRedirect();

        $category = Category::firstWhere('name', 'Boat building');

        $this->assertNotNull($category);
        $this->assertSame('boat-building', $category->slug);
    }

    public function test_a_duplicate_category_is_refused(): void
    {
        Category::create(['name' => 'Pottery', 'slug' => 'pottery', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->post('/settings/categories', ['name' => 'Pottery'])
            ->assertSessionHasErrors('name');
    }

    public function test_a_category_can_be_switched_off(): void
    {
        $category = Category::create(['name' => 'Pottery', 'slug' => 'pottery', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->patch("/settings/categories/{$category->id}", ['name' => 'Pottery', 'is_active' => false])
            ->assertRedirect();

        $this->assertFalse($category->fresh()->is_active);
    }

    /**
     * The slug is what the export bundle carries to the website. Changing it
     * after articles exist would silently repoint work already published.
     */
    public function test_renaming_a_used_category_leaves_its_slug_alone(): void
    {
        $category = Category::create(['name' => 'Pottery', 'slug' => 'pottery', 'sort_order' => 1, 'is_active' => true]);
        $this->articleIn($category);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->patch("/settings/categories/{$category->id}", ['name' => 'Pottery & terracotta', 'is_active' => true]);

        $category->refresh();

        $this->assertSame('Pottery & terracotta', $category->name);
        $this->assertSame('pottery', $category->slug);
    }

    public function test_renaming_an_unused_category_updates_its_slug(): void
    {
        $category = Category::create(['name' => 'Pottery', 'slug' => 'pottery', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->patch("/settings/categories/{$category->id}", ['name' => 'Terracotta', 'is_active' => true]);

        $this->assertSame('terracotta', $category->fresh()->slug);
    }

    /**
     * Deleting one would null the category on articles already exported and
     * published under that name.
     */
    public function test_a_category_with_articles_cannot_be_deleted(): void
    {
        $category = Category::create(['name' => 'Pottery', 'slug' => 'pottery', 'sort_order' => 1, 'is_active' => true]);
        $this->articleIn($category);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->delete("/settings/categories/{$category->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_an_unused_category_can_be_deleted(): void
    {
        $category = Category::create(['name' => 'Pottery', 'slug' => 'pottery', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->delete("/settings/categories/{$category->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_somebody_without_the_permission_cannot_change_a_category(): void
    {
        $category = Category::create(['name' => 'Pottery', 'slug' => 'pottery', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->post('/settings/categories', ['name' => 'Sneaking one in'])
            ->assertForbidden();

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->delete("/settings/categories/{$category->id}")
            ->assertForbidden();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * The form posts every field, so a partial payload would fail validation
     * for reasons unrelated to what a test is about.
     *
     * @param  array<string, int>  $overrides
     * @return array<string, int>
     */
    private function values(array $overrides = []): array
    {
        return [...Settings::defaults(), ...$overrides];
    }

    private function articleIn(Category $category): Article
    {
        return Article::create([
            'code' => app(CodeGenerator::class)->next('ART'),
            'title' => 'A pot',
            'stage' => 'drafting',
            'status' => 'draft',
            'category_id' => $category->id,
        ]);
    }
}
