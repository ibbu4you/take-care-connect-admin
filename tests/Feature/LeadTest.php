<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Services\LeadConverter;
use App\Support\Roles;
use App\Support\Workflow\LeadSource;
use App\Support\Workflow\LeadStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Leads — the people a manager is talking to who are not clients yet.
 *
 * ── What is actually being protected ───────────────────────────────────────
 * Conversion. It writes three records and every partial outcome is worse than a
 * failure: a client nothing points at looks like it appeared from nowhere, and
 * a lead marked converted with no client behind it is a dead end nobody can
 * undo from the interface.
 *
 * The second thing is that the lead *survives* its own conversion. Moving the
 * row would throw away where the client came from and make the conversion rate
 * unanswerable, because the numerator would have been deleted.
 */
class LeadTest extends TestCase
{
    use RefreshDatabase;

    // ── Conversion ─────────────────────────────────────────────────────────

    public function test_converting_creates_a_client_from_the_lead(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $lead = $this->lead(['owner_id' => $manager->id]);

        $this->actingAs($manager)
            ->post("/leads/{$lead->id}/convert")
            ->assertRedirect();

        $client = Client::firstWhere('name', $lead->name);

        $this->assertNotNull($client);
        $this->assertSame($lead->phone, $client->phone);
        $this->assertSame($lead->trade, $client->trade);
        $this->assertSame($lead->source->value, $client->source, 'The source has to carry across.');
        $this->assertSame($manager->id, $client->owner_id, 'The manager who worked it keeps the account.');
    }

    /**
     * Moving the row would throw away where the client came from and how long
     * they took to say yes.
     */
    public function test_the_lead_survives_its_own_conversion(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $lead = $this->lead(['owner_id' => $manager->id]);

        $this->actingAs($manager)->post("/leads/{$lead->id}/convert");

        $lead->refresh();
        $client = Client::firstWhere('name', $lead->name);

        $this->assertNotSoftDeleted($lead);
        $this->assertSame(LeadStatus::Converted, $lead->status);
        $this->assertSame($client->id, $lead->converted_client_id);
        $this->assertNotNull($lead->converted_at);
    }

    public function test_converting_can_open_the_first_deal(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $lead = $this->lead(['owner_id' => $manager->id, 'estimated_value' => 145000]);

        $this->actingAs($manager)->post("/leads/{$lead->id}/convert", [
            'deal_title' => 'Three-interview feature package',
        ]);

        $deal = Deal::firstWhere('title', 'Three-interview feature package');

        $this->assertNotNull($deal);
        $this->assertSame(Client::firstWhere('name', $lead->name)->id, $deal->client_id);
        $this->assertSame('145000.00', $deal->expected_value, 'The estimate seeds the deal when no value is given.');
    }

    public function test_converting_without_a_deal_title_opens_no_deal(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $lead = $this->lead(['owner_id' => $manager->id]);

        $this->actingAs($manager)->post("/leads/{$lead->id}/convert");

        $this->assertSame(0, Deal::count());
    }

    /**
     * A second conversion would make a second client for the same business and
     * leave the lead pointing at only one of them.
     */
    public function test_a_converted_lead_cannot_be_converted_again(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $lead = $this->lead(['owner_id' => $manager->id]);

        $this->actingAs($manager)->post("/leads/{$lead->id}/convert");
        $this->actingAs($manager)->post("/leads/{$lead->id}/convert")->assertForbidden();

        $this->assertSame(1, Client::count());
    }

    public function test_the_conversion_is_recorded_on_the_contact_log(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $lead = $this->lead(['owner_id' => $manager->id]);

        $this->actingAs($manager)->post("/leads/{$lead->id}/convert");

        $this->assertSame(1, $lead->timeline()->count());
        $this->assertStringContainsString('Converted to client', $lead->timeline()->first()->body);
    }

    // ── Working a lead ─────────────────────────────────────────────────────

    /**
     * Nobody would remember to set a "last contacted" field, so logging the
     * call sets it — that is the only way the list can order by who has gone
     * longest without hearing from anybody.
     */
    public function test_logging_a_call_stamps_the_contact_date_and_moves_a_new_lead_on(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $lead = $this->lead(['owner_id' => $manager->id, 'status' => LeadStatus::New]);

        $this->actingAs($manager)
            ->post("/leads/{$lead->id}/notes", [
                'body' => 'Left a message with the office.',
                'next_action_on' => now()->addWeek()->toDateString(),
            ])
            ->assertRedirect();

        $lead->refresh();

        $this->assertNotNull($lead->last_contacted_at);
        $this->assertSame(LeadStatus::Contacted, $lead->status);
        $this->assertSame(now()->addWeek()->toDateString(), $lead->next_action_on->toDateString());
        $this->assertSame(1, Note::count());
    }

    /** A lead already further along must not be dragged back by a note. */
    public function test_logging_a_call_does_not_reset_a_qualified_lead(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $lead = $this->lead(['owner_id' => $manager->id, 'status' => LeadStatus::Qualified]);

        $this->actingAs($manager)->post("/leads/{$lead->id}/notes", ['body' => 'Chased the paperwork.']);

        $this->assertSame(LeadStatus::Qualified, $lead->fresh()->status);
    }

    public function test_marking_a_lead_not_a_fit_keeps_the_reason(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $lead = $this->lead(['owner_id' => $manager->id]);

        $this->actingAs($manager)->patch("/leads/{$lead->id}/status", [
            'status' => LeadStatus::Unqualified->value,
            'lost_reason' => 'Too small a budget this year.',
        ]);

        $lead->refresh();

        $this->assertSame(LeadStatus::Unqualified, $lead->status);
        $this->assertSame('Too small a budget this year.', $lead->lost_reason);
    }

    /**
     * Converted is the result of pressing Convert, which creates a client.
     * Setting it from the dropdown would leave a lead claiming a client that
     * does not exist.
     */
    public function test_converted_cannot_be_set_from_the_status_dropdown(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $lead = $this->lead(['owner_id' => $manager->id]);

        $this->actingAs($manager)
            ->patch("/leads/{$lead->id}/status", ['status' => LeadStatus::Converted->value])
            ->assertSessionHasErrors('status');

        $this->assertSame(0, Client::count());
    }

    /** The same number arrives three ways; searching for any of them finds it. */
    public function test_a_phone_number_is_searchable_however_it_was_typed(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $this->lead(['owner_id' => $manager->id, 'phone' => '+91 98765 43210']);

        $this->actingAs($manager)
            ->get('/leads?search=9876543210')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('leads.data', 1));
    }

    // ── Access ─────────────────────────────────────────────────────────────

    public function test_a_manager_sees_their_own_leads_only(): void
    {
        $mine = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $theirs = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->lead(['owner_id' => $mine->id, 'created_by' => $mine->id]);
        $this->lead(['owner_id' => $theirs->id, 'created_by' => $theirs->id]);

        $this->actingAs($mine)
            ->get('/leads')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Leads/Index')
                ->has('leads.data', 1));
    }

    public function test_an_admin_sees_every_lead(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);

        $this->lead();
        $this->lead();

        $this->actingAs($admin)
            ->get('/leads')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('leads.data', 2));
    }

    /**
     * Prospecting is sales work. An anchor has no reason to see who is being
     * cold-called, and a Leads section in a writer's sidebar is the same
     * mistake as a Sales section in a video editor's.
     */
    public function test_nobody_outside_sales_can_reach_leads(): void
    {
        foreach ([Roles::ANCHOR, Roles::CONTENT, Roles::CREATIVE] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get('/leads')
                ->assertForbidden();
        }
    }

    public function test_a_manager_cannot_open_somebody_elses_lead(): void
    {
        $mine = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $lead = $this->lead(['owner_id' => $this->userWithRole(Roles::RELATIONSHIP_MANAGER)->id]);

        $this->actingAs($mine)->get("/leads/{$lead->id}")->assertForbidden();
    }

    public function test_a_manager_adds_a_lead(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->actingAs($manager)
            ->post('/leads', [
                'name' => 'Kodaikanal Honey Works',
                'source' => LeadSource::Field->value,
                'status' => LeadStatus::New->value,
            ])
            ->assertRedirect();

        $lead = Lead::firstWhere('name', 'Kodaikanal Honey Works');

        $this->assertNotNull($lead);
        $this->assertStringStartsWith('LED-', $lead->code);
        $this->assertSame($manager->id, $lead->owner_id);
    }

    // ── Deleting ───────────────────────────────────────────────────────────

    /**
     * "Not a fit" is the answer to a lead that said no, and it keeps the
     * record — which is the point of writing prospects down at all.
     */
    public function test_a_converted_lead_cannot_be_deleted(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $lead = $this->lead();

        app(LeadConverter::class)->convert($lead, $admin);

        $this->actingAs($admin)->delete("/leads/{$lead->id}")->assertForbidden();
        $this->assertNotSoftDeleted($lead->fresh());
    }

    public function test_an_open_lead_can_be_deleted(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $lead = $this->lead();

        $this->actingAs($admin)->delete("/leads/{$lead->id}")->assertRedirect();
        $this->assertSoftDeleted($lead);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function lead(array $attributes = []): Lead
    {
        return Lead::create([
            'code' => app(CodeGenerator::class)->next('LED'),
            'name' => 'Lead '.fake()->unique()->words(2, true),
            'contact_person' => fake()->name(),
            'phone' => '+91 '.fake()->numerify('##### #####'),
            'trade' => 'Spice trading',
            'city' => 'Karaikudi',
            'source' => LeadSource::Referral,
            'status' => LeadStatus::Interested,
            'owner_id' => $attributes['owner_id'] ?? $this->userWithRole(Roles::RELATIONSHIP_MANAGER)->id,
            ...$attributes,
        ]);
    }
}
