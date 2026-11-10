<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\Proposal;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\LeadSource;
use App\Support\Workflow\LeadStatus;
use App\Support\Workflow\PipelineStage;
use App\Support\Workflow\ProposalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * One test per problem the QA pass found in the sales module and around it, so
 * none of them can come back quietly.
 */
class SalesHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deal_cannot_be_opened_against_somebody_elses_client(): void
    {
        $alice = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $bob = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $bobs = $this->client($bob, ['phone' => '91234 56789', 'email' => 'secret@bob.test']);

        $this->actingAs($alice)->get("/clients/{$bobs->id}")->assertForbidden();

        $this->actingAs($alice)
            ->post('/deals', ['client_id' => $bobs->id, 'title' => 'Sneaky', 'stage' => 'new', 'expected_value' => 1])
            ->assertSessionHasErrors('client_id');

        $this->assertSame(0, Deal::where('title', 'Sneaky')->count());
    }

    public function test_a_follow_up_cannot_point_at_somebody_elses_deal_or_client(): void
    {
        $alice = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $bob = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $deal = $this->deal($bob);

        $this->actingAs($alice)
            ->post('/follow-ups', ['deal_id' => $deal->id, 'client_id' => $deal->client_id, 'due_on' => today()->toDateString(), 'channel' => 'call'])
            ->assertSessionHasErrors(['deal_id', 'client_id']);
    }

    public function test_whoever_raised_the_discount_cannot_approve_it(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $manager = $this->userWithRole(Roles::SALES_MANAGER);
        $otherManager = $this->userWithRole(Roles::SALES_MANAGER);
        $deal = $this->deal($executive);
        $proposal = $this->proposal($deal, $executive, 0);

        // The manager raises the executive's 0% draft to 60% and submits it.
        $this->actingAs($manager)->patch("/proposals/{$proposal->id}", $this->proposalPayload(60))->assertRedirect();
        $this->actingAs($manager)->post("/proposals/{$proposal->id}/submit");
        $this->assertSame(ProposalStatus::PendingApproval, $proposal->fresh()->status);

        $this->actingAs($manager)->get("/proposals/{$proposal->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.approve', false));
        $this->actingAs($manager)->post("/proposals/{$proposal->id}/approve")->assertForbidden();

        $this->actingAs($otherManager)->post("/proposals/{$proposal->id}/approve")->assertRedirect();
        $this->assertSame(ProposalStatus::Approved, $proposal->fresh()->status);
    }

    public function test_a_proposal_total_cannot_overflow(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $deal = $this->deal($executive);

        $this->actingAs($executive)->post("/deals/{$deal->id}/proposals", [
            ...$this->proposalPayload(0),
            'items' => array_fill(0, 30, ['description' => 'Huge', 'quantity' => 10000, 'rate' => 99999999]),
        ])->assertSessionHasErrors('items');
    }

    public function test_a_proposal_needs_a_valid_until_date_and_sending_always_dates_the_deal(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $deal = $this->deal($executive);

        $payload = $this->proposalPayload(0);
        unset($payload['valid_until']);
        $this->actingAs($executive)->post("/deals/{$deal->id}/proposals", $payload)->assertSessionHasErrors('valid_until');

        // An older proposal with no date still gives the deal one when it goes out.
        $proposal = $this->proposal($deal, $executive, 0);
        $proposal->forceFill(['status' => ProposalStatus::Approved, 'valid_until' => null])->save();
        $this->actingAs($executive)->post("/proposals/{$proposal->id}/send");

        $this->assertNotNull($deal->fresh()->expected_close_on);
        $this->assertSame(PipelineStage::Proposal, $deal->fresh()->stage);
    }

    public function test_city_hand_over_leaves_a_lead_somebody_is_still_calling(): void
    {
        $manager = $this->userWithRole(Roles::SALES_MANAGER);
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $telecaller = $this->userWithRole(Roles::TELECALLER);

        $called = $this->lead($telecaller, 'Madurai');
        LeadCall::create(['lead_id' => $called->id, 'user_id' => $telecaller->id, 'outcome' => 'no_answer', 'called_at' => now()]);
        $scheduled = $this->lead($telecaller, 'Madurai', ['next_action_on' => today()->addDay()->toDateString()]);
        $untouched = $this->lead($telecaller, 'Madurai');

        $this->actingAs($manager)->post('/sales/team/assign-city', ['city' => 'Madurai', 'user_id' => $executive->id]);

        $this->assertSame($telecaller->id, $called->fresh()->owner_id, 'Three missed calls in is still somebody\'s lead.');
        $this->assertSame($telecaller->id, $scheduled->fresh()->owner_id);
        $this->assertSame($executive->id, $untouched->fresh()->owner_id);
    }

    public function test_a_past_month_is_read_correctly_at_month_end(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 31)->setTime(10, 0));

        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE, ['name' => 'Novak']);
        $this->deal($executive, ['stage' => PipelineStage::Won, 'outcome' => 'won', 'actual_value' => 50000, 'closed_at' => now()->setDate(2026, 11, 20)]);

        $this->actingAs($executive)->get('/sales/leaderboard?period=2026-11')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('periodLabel', 'November 2026')
                ->where('rows.0.won_value', 50000));

        $this->actingAs($executive)->get('/targets?period=2026-11')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('periodLabel', 'November 2026'));
    }

    public function test_a_close_cannot_be_dated_in_the_future_or_backdated_by_an_executive(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $manager = $this->userWithRole(Roles::SALES_MANAGER);

        $this->actingAs($executive)->post("/deals/{$this->deal($executive)->id}/close", ['outcome' => 'won', 'actual_value' => 1000, 'closed_at' => now()->addYear()->toDateString()])
            ->assertSessionHasErrors('closed_at');
        $this->actingAs($executive)->post("/deals/{$this->deal($executive)->id}/close", ['outcome' => 'won', 'actual_value' => 1000, 'closed_at' => now()->subMonthsNoOverflow(2)->toDateString()])
            ->assertSessionHasErrors('closed_at');

        // A manager correcting last month's books may.
        $deal = $this->deal($executive);
        $this->actingAs($manager)->post("/deals/{$deal->id}/close", ['outcome' => 'won', 'actual_value' => 1000, 'closed_at' => now()->subMonthsNoOverflow(1)->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertSame('won', $deal->fresh()->outcome);
    }

    public function test_deals_past_their_close_date_stay_in_the_forecast(): void
    {
        $manager = $this->userWithRole(Roles::SALES_MANAGER);
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $this->deal($executive, ['stage' => PipelineStage::Negotiation, 'expected_value' => 500000, 'expected_close_on' => now()->subMonthsNoOverflow(1)->startOfMonth()]);

        $this->actingAs($manager)->get('/sales/team')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.forecast', 400000)
                ->where('forecast.0.month', 'Close date passed')
                ->where('forecast.0.weighted', 400000));
    }

    public function test_a_manager_logging_on_an_executives_deal_leaves_the_next_step_with_the_executive(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $manager = $this->userWithRole(Roles::SALES_MANAGER);
        $deal = $this->deal($executive);

        $this->actingAs($manager)->post("/deals/{$deal->id}/activities", [
            'type' => 'meeting', 'outcome' => 'held', 'next_step' => 'Send the proposal',
            'next_step_type' => 'email', 'next_step_on' => today()->addDay()->toDateString(),
        ]);

        $this->assertSame([$executive->id], FollowUp::where('deal_id', $deal->id)->pending()->pluck('user_id')->all());
    }

    public function test_whatsapp_and_email_take_no_outcome(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $deal = $this->deal($executive);

        $this->actingAs($executive)->post("/deals/{$deal->id}/activities", [
            'type' => 'whatsapp', 'outcome' => str_repeat('x', 60), 'next_step' => 'Call',
            'next_step_type' => 'call', 'next_step_on' => today()->addDay()->toDateString(),
        ])->assertSessionHasErrors('outcome');
    }

    public function test_import_offers_sales_staff_and_refuses_anybody_else(): void
    {
        $manager = $this->userWithRole(Roles::SALES_MANAGER);
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE, ['name' => 'Vikram Shetty']);
        $creative = $this->userWithRole(Roles::CREATIVE);

        $this->actingAs($manager)->get('/leads/import')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('owners', fn ($owners) => collect($owners)->contains('name', 'Vikram Shetty')));

        $this->actingAs($manager)->post('/leads/import', [
            'file' => UploadedFile::fake()->createWithContent('list.csv', "Business name\nSomewhere"),
            'source' => LeadSource::Event->value,
            'owner_id' => $creative->id,
        ])->assertSessionHasErrors('owner_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $attributes */
    private function client(User $owner, array $attributes = []): Client
    {
        return Client::create([
            'name' => 'Client '.fake()->unique()->word(),
            'status' => 'active',
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function deal(User $owner, array $overrides = []): Deal
    {
        return Deal::create([
            'code' => app(CodeGenerator::class)->next('DEA'),
            'client_id' => $this->client($owner)->id,
            'title' => 'Deal '.fake()->unique()->word(),
            'stage' => PipelineStage::New,
            'stage_entered_at' => now(),
            'owner_id' => $owner->id,
            'expected_value' => 100000,
            'last_activity_at' => now(),
            ...$overrides,
        ]);
    }

    private function proposal(Deal $deal, User $by, float $discount): Proposal
    {
        $proposal = new Proposal([
            'code' => app(CodeGenerator::class)->next('PRO'),
            'deal_id' => $deal->id,
            'version' => ((int) $deal->proposals()->max('version')) + 1,
            'status' => ProposalStatus::Draft,
            'title' => 'Three business stories',
            'valid_until' => today()->addDays(15),
            'created_by' => $by->id,
        ]);
        $proposal->fillAmounts([['description' => 'Story', 'quantity' => 3, 'rate' => 20000]], $discount);
        $proposal->save();

        return $proposal;
    }

    /** @return array<string, mixed> */
    private function proposalPayload(float $discount): array
    {
        return [
            'title' => 'Three business stories',
            'items' => [['description' => 'Story', 'quantity' => 3, 'rate' => 20000]],
            'discount_percent' => $discount,
            'valid_until' => today()->addDays(15)->toDateString(),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function lead(User $owner, string $city, array $attributes = []): Lead
    {
        return Lead::create([
            'code' => app(CodeGenerator::class)->next('LED'),
            'name' => 'Lead '.fake()->unique()->word(),
            'city' => $city,
            'source' => LeadSource::Call,
            'status' => LeadStatus::New,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            ...$attributes,
        ]);
    }
}
