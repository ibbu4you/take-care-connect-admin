<?php

namespace Tests\Feature;

use App\Models\Interview;
use App\Models\Lead;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\LeadSource;
use App\Support\Workflow\LeadStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * A telecaller works their own call list and books interviews off it. Once a
 * lead is qualified the Relationship Manager takes it from there, so the
 * client, deal and conversion side is closed to them.
 */
class TelecallerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_telecaller_adds_a_lead_and_owns_it(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);

        $this->actingAs($telecaller)
            ->post('/leads', [
                'name' => 'Madurai Jasmine Growers',
                'source' => LeadSource::Call->value,
                'status' => LeadStatus::New->value,
            ])
            ->assertRedirect();

        $this->assertSame($telecaller->id, Lead::firstWhere('name', 'Madurai Jasmine Growers')?->owner_id);
    }

    public function test_a_telecaller_sees_only_their_own_leads(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->lead('Mine', $telecaller->id);
        $theirs = $this->lead('Somebody else\'s', $manager->id);

        $this->actingAs($telecaller)->get('/leads')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('leads.data', 1)->where('leads.data.0.name', 'Mine'));

        $this->actingAs($telecaller)->get("/leads/{$theirs->id}")->assertForbidden();
    }

    public function test_a_telecaller_books_an_interview(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($telecaller)->get('/interviews/create')->assertOk();

        $this->actingAs($telecaller)
            ->post('/interviews', [
                'kind' => InterviewKind::BusinessArticle->value,
                'title' => 'Jasmine from Madurai',
                'subject_name' => 'Selvi Murugan',
                'anchor_id' => $anchor->id,
                'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'mode' => 'google_meet',
                'meeting_url' => 'https://meet.google.com/abc-defg-hij',
                'priority' => 'normal',
            ])
            ->assertRedirect();

        $interview = Interview::firstWhere('title', 'Jasmine from Madurai');

        $this->assertSame($telecaller->id, $interview?->scheduled_by);

        // What they booked is theirs to see afterwards.
        $this->actingAs($telecaller)->get("/interviews/{$interview->id}")->assertOk();
    }

    public function test_the_rest_of_sales_stays_with_the_relationship_manager(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $lead = $this->lead('Qualified one', $telecaller->id);

        foreach (['/clients', '/deals', '/pipeline', '/projects', '/targets', '/follow-ups'] as $path) {
            $this->actingAs($telecaller)->get($path)->assertForbidden();
        }

        $this->actingAs($telecaller)->post("/leads/{$lead->id}/convert")->assertForbidden();
        $this->assertFalse($telecaller->can('interviews.cancel'));
    }

    /** Their checklist is the call list, not the Follow-ups page they cannot open. */
    public function test_the_dashboard_shows_a_telecaller_their_call_list(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $this->lead('Call me today', $telecaller->id, now()->toDateString());

        $this->actingAs($telecaller)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('panels.leads', true)
                ->where('panels.interviews', true)
                ->where('panels.sales', false)
                ->where('todos.kind', 'calls')
                ->where('todos.items.0.title', 'Call me today')
                ->where('todos.items.0.url', fn (string $url) => str_starts_with($url, '/leads/'))
                ->where('agenda.calls.0.title', 'Call me today'),
            );
    }

    public function test_a_lead_can_be_handed_to_a_telecaller(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER, ['name' => 'Divya Krishnan']);

        $this->actingAs($this->userWithRole(Roles::ADMIN))->get('/leads')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('owners', fn ($owners) => collect($owners)->contains('id', $telecaller->id)),
            );
    }

    private function lead(string $name, int $ownerId, ?string $nextCall = null): Lead
    {
        return Lead::create([
            'code' => app(CodeGenerator::class)->next('LED'),
            'name' => $name,
            'source' => LeadSource::Call,
            'status' => LeadStatus::Contacted,
            'owner_id' => $ownerId,
            'created_by' => $ownerId,
            'next_action_on' => $nextCall,
        ]);
    }
}
