<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\FollowUp;
use App\Models\Target;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\PipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The sales desk.
 *
 * The rules worth protecting here are about money: a deal cannot be closed as
 * won without the figure that was actually signed, a closed deal stays closed,
 * and attainment is summed from those figures rather than stored anywhere.
 */
class SalesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private ?User $manager = null;

    protected function setUp(): void
    {
        parent::setUp();

    }

    private function manager(): User
    {
        return $this->manager ??= $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
    }

    // ── Clients ────────────────────────────────────────────────────────────

    public function test_a_manager_can_add_a_client(): void
    {
        $this->actingAs($this->manager())
            ->post('/clients', ['name' => 'Madurai Jasmine', 'status' => 'active'])
            ->assertRedirect();

        $client = Client::firstOrFail();

        $this->assertSame('Madurai Jasmine', $client->name);
        $this->assertSame($this->manager()->id, $client->owner_id);
    }

    /** Three people type the same number three ways; search has to cope. */
    public function test_a_phone_number_is_normalised_for_searching(): void
    {
        $this->actingAs($this->manager())->post('/clients', [
            'name' => 'Thanjavur Bronze',
            'status' => 'active',
            'phone' => '+91 98765 43210',
        ]);

        $this->assertSame('919876543210', Client::firstOrFail()->phone_normalized);
    }

    public function test_searching_by_a_differently_formatted_number_finds_the_client(): void
    {
        $this->actingAs($this->manager())->post('/clients', [
            'name' => 'Thanjavur Bronze',
            'status' => 'active',
            'phone' => '+91 98765 43210',
        ]);

        $this->actingAs($this->manager())
            ->get('/clients?search='.urlencode('9876543210'))
            ->assertInertia(function (AssertableInertia $page) {
                $names = collect($page->toArray()['props']['clients']['data'])->pluck('name');

                $this->assertContains('Thanjavur Bronze', $names);
            });
    }

    /** A manager works their own book. */
    public function test_a_manager_does_not_see_another_managers_clients(): void
    {
        $mine = $this->client();
        $stranger = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->actingAs($stranger)->get("/clients/{$mine->id}")->assertForbidden();
        $this->actingAs($this->manager())->get("/clients/{$mine->id}")->assertOk();
    }

    public function test_an_anchor_cannot_edit_a_client(): void
    {
        $client = $this->client();

        $this->actingAs($this->userWithRole(Roles::ANCHOR))
            ->patch("/clients/{$client->id}", ['name' => 'Renamed', 'status' => 'active'])
            ->assertForbidden();
    }

    public function test_the_client_list_exports_as_csv(): void
    {
        $this->client();

        $response = $this->actingAs($this->manager())->get('/clients/export')->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Kamala Handlooms', $response->streamedContent());
    }

    // ── The pipeline ───────────────────────────────────────────────────────

    public function test_a_deal_can_be_opened_against_a_client(): void
    {
        $client = $this->client();

        $this->actingAs($this->manager())
            ->post('/deals', [
                'client_id' => $client->id,
                'title' => 'Three-interview package',
                'stage' => 'new',
                'expected_value' => 145000,
            ])
            ->assertRedirect();

        $deal = Deal::firstOrFail();

        $this->assertSame($client->id, $deal->client_id);
        $this->assertSame(PipelineStage::New, $deal->stage);
        $this->assertStringStartsWith('DEA-', $deal->code);
    }

    /**
     * Without this the weighted figure stays at whatever the deal was created
     * with, and a board of proposals reports the same number as a board of cold
     * leads.
     */
    public function test_the_probability_follows_the_stage(): void
    {
        $deal = $this->deal();

        $this->assertSame(PipelineStage::New->defaultProbability(), $deal->probability);

        $this->actingAs($this->manager())->post("/deals/{$deal->id}/move", ['stage' => 'contacted']);

        $this->assertSame(PipelineStage::Contacted->defaultProbability(), $deal->fresh()->probability);
    }

    public function test_a_deal_can_be_dragged_to_another_column(): void
    {
        $deal = $this->deal();

        $this->actingAs($this->manager())
            ->post("/deals/{$deal->id}/move", ['stage' => 'contacted'])
            ->assertRedirect();

        $this->assertSame(PipelineStage::Contacted, $deal->fresh()->stage);
    }

    /**
     * A deal that went quiet genuinely is cold again. A board that refuses to
     * let it move back is one people work around by closing it and opening a
     * new one, which destroys the history the board exists to keep.
     */
    public function test_a_deal_can_move_backwards(): void
    {
        $deal = $this->deal();

        $this->actingAs($this->manager())->post("/deals/{$deal->id}/move", ['stage' => 'contacted']);
        $this->actingAs($this->manager())->post("/deals/{$deal->id}/move", ['stage' => 'new']);

        $this->assertSame(PipelineStage::New, $deal->fresh()->stage);
    }

    /** Moving is a separate permission from editing, so the board can be read-only. */
    public function test_moving_requires_its_own_permission(): void
    {
        $deal = $this->deal();
        $manager = $this->manager();

        $manager->update(['denied_permissions' => ['deals.move']]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($manager->fresh())
            ->post("/deals/{$deal->id}/move", ['stage' => 'contacted'])
            ->assertForbidden();
    }

    // ── Closing ────────────────────────────────────────────────────────────

    /** Attainment is a sum of what was signed, not of what was hoped for. */
    public function test_closing_as_won_requires_the_actual_value(): void
    {
        $deal = $this->deal();

        $this->actingAs($this->manager())
            ->post("/deals/{$deal->id}/close", ['outcome' => 'won'])
            ->assertSessionHasErrors('actual_value');

        $this->assertNull($deal->fresh()->outcome);
    }

    public function test_closing_as_lost_requires_a_reason(): void
    {
        $deal = $this->deal();

        $this->actingAs($this->manager())
            ->post("/deals/{$deal->id}/close", ['outcome' => 'lost'])
            ->assertSessionHasErrors('lost_reason');
    }

    public function test_a_won_deal_records_what_was_signed(): void
    {
        $deal = $this->deal();

        $this->actingAs($this->manager())
            ->post("/deals/{$deal->id}/close", ['outcome' => 'won', 'actual_value' => 120000])
            ->assertRedirect();

        $deal->refresh();

        $this->assertSame(PipelineStage::Won, $deal->stage);
        $this->assertSame('won', $deal->outcome);
        $this->assertEquals(120000, (float) $deal->actual_value);
        $this->assertNotNull($deal->closed_at);
    }

    /** Reopening would make "won this month" a figure that changes after reporting. */
    public function test_a_closed_deal_stays_closed(): void
    {
        $deal = $this->wonDeal();

        $this->actingAs($this->manager())
            ->post("/deals/{$deal->id}/move", ['stage' => 'proposal'])
            ->assertForbidden();

        $this->actingAs($this->manager())
            ->post("/deals/{$deal->id}/close", ['outcome' => 'lost', 'lost_reason' => 'Changed my mind'])
            ->assertForbidden();

        $this->assertSame(PipelineStage::Won, $deal->fresh()->stage);
    }

    public function test_a_closed_deal_cannot_be_edited(): void
    {
        $deal = $this->wonDeal();

        $this->actingAs($this->manager())
            ->patch("/deals/{$deal->id}", ['title' => 'Renamed', 'expected_value' => 1])
            ->assertForbidden();
    }

    // ── Weighting and idleness ─────────────────────────────────────────────

    public function test_the_weighted_value_reflects_the_stage(): void
    {
        $deal = $this->deal(['expected_value' => 100000]);

        $this->actingAs($this->manager())->post("/deals/{$deal->id}/move", ['stage' => 'contacted']);

        $deal->refresh();

        // 100,000 at contacted's 15%.
        $this->assertEqualsWithDelta(15000, $deal->weightedValue(), 0.01);
    }

    /** A closed deal contributes nothing to the open pipeline figure. */
    public function test_a_closed_deal_has_no_weighted_value(): void
    {
        $this->assertSame(0.0, $this->wonDeal()->weightedValue());
    }

    public function test_a_deal_with_no_recent_activity_is_flagged_as_quiet(): void
    {
        $deal = $this->deal();
        $deal->forceFill(['last_activity_at' => now()->subDays(20)])->save();

        $this->assertTrue($deal->fresh()->isIdle());
        $this->assertSame(1, Deal::idle()->count());
    }

    /**
     * Chasing a deal counts as activity — otherwise a deal somebody is actively
     * working still shows up on the "nothing has happened" list.
     */
    public function test_completing_a_follow_up_counts_as_activity_on_the_deal(): void
    {
        $deal = $this->deal();
        $deal->forceFill(['last_activity_at' => now()->subDays(20)])->save();

        $followUp = FollowUp::create([
            'deal_id' => $deal->id,
            'user_id' => $this->manager()->id,
            'due_on' => now(),
            'channel' => 'call',
            'state' => 'pending',
        ]);

        $this->actingAs($this->manager())->post("/follow-ups/{$followUp->id}/complete");

        $this->assertFalse($deal->fresh()->isIdle());
    }

    // ── Targets ────────────────────────────────────────────────────────────

    public function test_an_admin_can_set_a_target(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->post('/targets', [
                'user_id' => $this->manager()->id,
                'period' => now()->format('Y-m'),
                'revenue_target' => 400000,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('targets', [
            'user_id' => $this->manager()->id,
            'revenue_target' => 400000,
        ]);
    }

    /** Setting a target twice for one month is a correction, not a second target. */
    public function test_setting_a_target_twice_updates_it(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $period = now()->format('Y-m');

        $this->actingAs($admin)->post('/targets', [
            'user_id' => $this->manager()->id, 'period' => $period, 'revenue_target' => 400000,
        ]);
        $this->actingAs($admin)->post('/targets', [
            'user_id' => $this->manager()->id, 'period' => $period, 'revenue_target' => 500000,
        ]);

        $this->assertSame(1, Target::count());
        $this->assertEquals(500000, (float) Target::first()->revenue_target);
    }

    public function test_a_manager_cannot_set_their_own_target(): void
    {
        $this->actingAs($this->manager())
            ->post('/targets', [
                'user_id' => $this->manager()->id,
                'period' => now()->format('Y-m'),
                'revenue_target' => 1,
            ])
            ->assertForbidden();
    }

    /** Summed from closed deals on read, so it cannot go stale. */
    public function test_attainment_is_computed_from_closed_deals(): void
    {
        Target::create([
            'user_id' => $this->manager()->id,
            'period' => now()->format('Y-m'),
            'revenue_target' => 200000,
        ]);

        $this->wonDeal(['actual_value' => 100000]);

        $this->actingAs($this->manager())
            ->get('/targets')
            ->assertInertia(function (AssertableInertia $page) {
                $row = collect($page->toArray()['props']['rows'])
                    ->firstWhere('user.id', $this->manager()->id);

                $this->assertSame(50, $row['attainment']);
                $this->assertEquals(100000, $row['revenue_actual']);
            });
    }

    /** No target is not the same as zero attainment. */
    public function test_no_target_reports_null_attainment_rather_than_zero(): void
    {
        $this->wonDeal(['actual_value' => 100000]);

        $this->actingAs($this->manager())
            ->get('/targets')
            ->assertInertia(function (AssertableInertia $page) {
                $row = collect($page->toArray()['props']['rows'])
                    ->firstWhere('user.id', $this->manager()->id);

                $this->assertNull($row['attainment']);
            });
    }

    /**
     * The deal page must load with a timeline on it.
     *
     * The relation was originally called `notes()` while the table already had
     * a free-text `notes` column. An attribute always beats a relation of the
     * same name, so `$deal->notes` returned the string and the page died with
     * "member function map() on null" — a 500 with nothing rendered.
     */
    public function test_the_deal_page_loads_with_its_timeline(): void
    {
        $deal = $this->deal();

        $this->actingAs($this->manager())
            ->post("/deals/{$deal->id}/notes", ['body' => 'Called the owner.'])
            ->assertRedirect();

        $this->actingAs($this->manager())
            ->get("/deals/{$deal->id}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $timeline = $page->toArray()['props']['deal']['timeline'];

                $this->assertCount(1, $timeline);
                $this->assertSame('Called the owner.', $timeline[0]['body']);
            });
    }

    /** Posting a note is activity, so it lifts the deal off the quiet list. */
    public function test_adding_a_note_counts_as_activity(): void
    {
        $deal = $this->deal();
        $deal->forceFill(['last_activity_at' => now()->subDays(20)])->save();

        $this->actingAs($this->manager())
            ->post("/deals/{$deal->id}/notes", ['body' => 'Chased by phone.']);

        $this->assertFalse($deal->fresh()->isIdle());
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function client(): Client
    {
        return Client::create([
            'name' => 'Kamala Handlooms',
            'city' => 'Kanchipuram',
            'status' => 'active',
            'owner_id' => $this->manager()->id,
            'created_by' => $this->manager()->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function deal(array $overrides = []): Deal
    {
        return Deal::create([
            'code' => app(CodeGenerator::class)->next('DEA'),
            'client_id' => $this->client()->id,
            'title' => 'A package '.fake()->unique()->word(),
            'stage' => PipelineStage::New,
            'stage_entered_at' => now(),
            'owner_id' => $this->manager()->id,
            'expected_value' => 100000,
            'last_activity_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function wonDeal(array $overrides = []): Deal
    {
        $deal = $this->deal();

        $this->actingAs($this->manager())->post("/deals/{$deal->id}/close", [
            'outcome' => 'won',
            'actual_value' => $overrides['actual_value'] ?? 100000,
        ]);

        return $deal->fresh();
    }
}
