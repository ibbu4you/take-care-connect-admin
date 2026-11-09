<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadCall;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\LeadSource;
use App\Support\Workflow\LeadStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Working the phones: logging calls with an outcome, importing a list, and the
 * numbers that come out of both.
 */
class CallingTest extends TestCase
{
    use RefreshDatabase;

    // ── Logging a call ─────────────────────────────────────────────────────

    public function test_a_missed_call_is_logged_without_counting_as_contact(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $lead = $this->lead($telecaller->id);

        $this->actingAs($telecaller)
            ->post("/leads/{$lead->id}/calls", ['outcome' => 'no_answer', 'next_action_on' => now()->addDay()->toDateString()])
            ->assertRedirect();

        $lead->refresh();

        $this->assertSame(1, LeadCall::where('lead_id', $lead->id)->where('outcome', 'no_answer')->count());
        $this->assertNull($lead->last_contacted_at, 'A missed call is not contact.');
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame(now()->addDay()->toDateString(), $lead->next_action_on->toDateString());
    }

    public function test_an_interested_call_moves_the_lead_along(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $lead = $this->lead($telecaller->id);

        $this->actingAs($telecaller)
            ->post("/leads/{$lead->id}/calls", ['outcome' => 'interested', 'note' => 'Keen on an interview.'])
            ->assertRedirect();

        $lead->refresh();

        $this->assertSame(LeadStatus::Interested, $lead->status);
        $this->assertNotNull($lead->last_contacted_at);
        $this->assertNull($lead->next_action_on, 'A blank date is taken as "no next call".');
    }

    public function test_a_call_needs_a_known_outcome_and_an_owner(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $somebodyElse = $this->userWithRole(Roles::TELECALLER);
        $lead = $this->lead($telecaller->id);

        $this->actingAs($telecaller)->post("/leads/{$lead->id}/calls", ['outcome' => 'gossip'])->assertSessionHasErrors('outcome');
        $this->actingAs($somebodyElse)->post("/leads/{$lead->id}/calls", ['outcome' => 'busy'])->assertForbidden();
    }

    public function test_the_lead_page_and_list_carry_the_calls(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $lead = $this->lead($telecaller->id);
        $this->actingAs($telecaller)->post("/leads/{$lead->id}/calls", ['outcome' => 'busy']);

        $this->actingAs($telecaller)->get("/leads/{$lead->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('lead.calls.0.outcome', 'busy')
                ->where('lead.calls.0.connected', false)
                ->has('outcomes', 7));

        $this->actingAs($telecaller)->get('/leads')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('leads.data.0.calls_count', 1));
    }

    // ── Importing ──────────────────────────────────────────────────────────

    public function test_a_list_is_imported_without_duplicates(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $this->lead($telecaller->id, ['name' => 'Already here', 'phone' => '+91 98765 43210']);

        $csv = implode("\n", [
            'Business name,Owner name,Mobile No.,City,Trade,Next call',
            'Madurai Jasmine Growers,Selvi Murugan,99887 76655,madurai,Flower farming,15/11/2026',
            'Same phone as the existing lead,Somebody,098765-43210,Madurai,,',
            'Repeated in this file,Another,99887 76655,Madurai,,',
            ',No business name,90000 00000,Madurai,,',
            'Thanjavur Art Plates,Gopal Swamy,,Thanjavur,Handicrafts,',
        ]);

        $this->actingAs($telecaller)
            ->post('/leads/import', [
                'file' => UploadedFile::fake()->createWithContent('list.csv', $csv),
                'source' => LeadSource::Event->value,
            ])
            ->assertRedirect('/leads')
            ->assertSessionHas('success', 'Imported 2 leads. Skipped 1 already in the system, 1 repeated in the file, 1 without a business name.');

        $jasmine = Lead::firstWhere('name', 'Madurai Jasmine Growers');

        $this->assertSame($telecaller->id, $jasmine->owner_id);
        $this->assertSame('Selvi Murugan', $jasmine->contact_person);
        $this->assertSame(LeadSource::Event, $jasmine->source);
        $this->assertSame('2026-11-15', $jasmine->next_action_on->toDateString());
        $this->assertTrue(Lead::where('name', 'Thanjavur Art Plates')->exists(), 'A row with no phone is still imported.');
    }

    public function test_a_sheet_without_a_name_column_is_refused(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);

        $this->actingAs($telecaller)
            ->post('/leads/import', [
                'file' => UploadedFile::fake()->createWithContent('list.csv', "Phone,City\n98765 43210,Madurai"),
                'source' => LeadSource::Event->value,
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Lead::count());
    }

    public function test_only_somebody_who_assigns_leads_can_import_into_another_name(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $other = $this->userWithRole(Roles::TELECALLER);

        $this->actingAs($telecaller)->post('/leads/import', [
            'file' => UploadedFile::fake()->createWithContent('list.csv', "Business name\nMine after all"),
            'source' => LeadSource::Event->value,
            'owner_id' => $other->id,
        ]);

        $this->assertSame($telecaller->id, Lead::firstWhere('name', 'Mine after all')->owner_id);
    }

    public function test_import_is_closed_to_roles_that_do_not_work_leads(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($anchor)->get('/leads/import')->assertForbidden();
        $this->actingAs($this->userWithRole(Roles::TELECALLER))->get('/leads/import/template')->assertOk();
    }

    public function test_the_client_import_permission_that_did_nothing_is_gone(): void
    {
        $this->seedRoles();

        $this->assertFalse(Permission::where('name', 'clients.import')->exists());
    }

    // ── The numbers ────────────────────────────────────────────────────────

    public function test_performance_shows_calls_and_connect_rate(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER, ['name' => 'Divya Krishnan']);
        $lead = $this->lead($telecaller->id);

        foreach (['no_answer', 'busy', 'interested', 'call_back'] as $outcome) {
            LeadCall::create(['lead_id' => $lead->id, 'user_id' => $telecaller->id, 'outcome' => $outcome, 'called_at' => now()]);
        }

        $this->actingAs($this->userWithRole(Roles::ADMIN))->get('/reports/performance')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('callers.rows', fn ($rows) => collect($rows)
                ->contains(fn ($row) => $row['name'] === 'Divya Krishnan'
                    && $row['done'] === 4 && $row['connected'] === 2 && $row['rate'] === 50 && $row['interested'] === 1)));
    }

    public function test_the_morning_digest_reminds_a_telecaller_of_calls_due(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $this->lead($telecaller->id, ['next_action_on' => now()->subDay()->toDateString()]);

        $this->artisan('tcc:reminders')->assertSuccessful();

        $this->assertStringContainsString('due a call', (string) json_encode($telecaller->fresh()->notifications()->latest()->first()?->data));
    }

    /** @param array<string, mixed> $attributes */
    private function lead(int $ownerId, array $attributes = []): Lead
    {
        return Lead::create([
            'code' => app(CodeGenerator::class)->next('LED'),
            'name' => 'Lead '.fake()->unique()->word(),
            'source' => LeadSource::Call,
            'status' => LeadStatus::New,
            'owner_id' => $ownerId,
            'created_by' => $ownerId,
            ...$attributes,
        ]);
    }
}
