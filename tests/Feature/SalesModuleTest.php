<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\Target;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Settings;
use App\Support\Workflow\LeadSource;
use App\Support\Workflow\LeadStatus;
use App\Support\Workflow\PipelineStage;
use App\Support\Workflow\ProposalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The sales module: roles, the stage rules, activities with next steps,
 * proposals with discount approval, incentives, the leaderboard and the team view.
 */
class SalesModuleTest extends TestCase
{
    use RefreshDatabase;

    // ── Roles ──────────────────────────────────────────────────────────────

    public function test_a_sales_executive_sees_their_own_deals_and_a_manager_sees_all(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $other = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $manager = $this->userWithRole(Roles::SALES_MANAGER);

        $this->deal($executive, ['title' => 'Mine']);
        $theirs = $this->deal($other, ['title' => 'Theirs']);

        $this->actingAs($executive)->get('/deals')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('deals.data', 1)->where('deals.data.0.title', 'Mine'));
        $this->actingAs($executive)->get("/deals/{$theirs->id}")->assertForbidden();

        $this->actingAs($manager)->get('/deals')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('deals.data', 2));
    }

    // ── Stage rules ────────────────────────────────────────────────────────

    public function test_a_deal_cannot_be_dragged_into_a_stage_it_has_not_earned(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $deal = $this->deal($executive);

        $this->actingAs($executive)->post("/deals/{$deal->id}/move", ['stage' => 'meeting'])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'log the meeting first'));
        $this->assertSame(PipelineStage::New, $deal->fresh()->stage);

        $this->actingAs($executive)->post("/deals/{$deal->id}/move", ['stage' => 'proposal'])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'send a proposal first'));
        $this->assertSame(PipelineStage::New, $deal->fresh()->stage);
    }

    // ── Activities and next steps ──────────────────────────────────────────

    public function test_logging_a_held_meeting_moves_the_deal_and_sets_the_next_step(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $deal = $this->deal($executive);
        $old = FollowUp::create(['deal_id' => $deal->id, 'user_id' => $executive->id, 'due_on' => today(), 'channel' => 'call', 'state' => 'pending']);

        $this->actingAs($executive)->post("/deals/{$deal->id}/activities", [
            'type' => 'meeting',
            'outcome' => 'held',
            'note' => 'They want three stories.',
            'next_step' => 'Send the proposal',
            'next_step_type' => 'email',
            'next_step_on' => today()->addDays(2)->toDateString(),
        ])->assertSessionHas('success', 'Meeting logged. Moved to Meeting held.');

        $deal->refresh();
        $this->assertSame(PipelineStage::Meeting, $deal->stage);
        $this->assertSame('Send the proposal', $deal->next_step);
        $this->assertSame('done', $old->fresh()->state, 'The step it replaces is closed.');
        $this->assertSame(1, FollowUp::where('deal_id', $deal->id)->pending()->count(), 'Exactly one next step stays open.');
    }

    public function test_an_activity_needs_an_outcome_and_a_next_step(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $deal = $this->deal($executive);

        $this->actingAs($executive)->post("/deals/{$deal->id}/activities", ['type' => 'call'])
            ->assertSessionHasErrors(['outcome', 'next_step', 'next_step_on']);

        $this->actingAs($executive)->post("/deals/{$deal->id}/activities", [
            'type' => 'whatsapp', 'next_step' => 'Call back', 'next_step_type' => 'call',
            'next_step_on' => today()->subDay()->toDateString(),
        ])->assertSessionHasErrors('next_step_on');
    }

    // ── Proposals ──────────────────────────────────────────────────────────

    public function test_amounts_are_computed_on_the_server(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $deal = $this->deal($executive);

        $this->actingAs($executive)->post("/deals/{$deal->id}/proposals", [
            'title' => 'Three stories',
            'items' => [
                ['description' => 'Business story', 'quantity' => 3, 'rate' => 20000],
                ['description' => 'Reel', 'quantity' => 2, 'rate' => 7500],
            ],
            'discount_percent' => 5,
            'valid_until' => today()->addDays(15)->toDateString(),
            'total' => 1, // Ignored: the server decides the total.
        ])->assertRedirect();

        $proposal = Proposal::firstOrFail();
        $this->assertSame('75000.00', $proposal->subtotal);
        $this->assertSame('3750.00', $proposal->discount_amount);
        $this->assertSame('71250.00', $proposal->total);
        $this->assertSame(ProposalStatus::Draft, $proposal->status);
    }

    public function test_a_small_discount_is_ready_to_send_and_a_large_one_waits_for_a_manager(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $manager = $this->userWithRole(Roles::SALES_MANAGER);
        $deal = $this->deal($executive);

        $small = $this->proposal($deal, $executive, 5);
        $this->actingAs($executive)->post("/proposals/{$small->id}/submit");
        $this->assertSame(ProposalStatus::Approved, $small->fresh()->status);

        $large = $this->proposal($deal, $executive, 25);
        $this->actingAs($executive)->post("/proposals/{$large->id}/submit");
        $this->assertSame(ProposalStatus::PendingApproval, $large->fresh()->status);
        $this->assertSame(1, $manager->fresh()->notifications()->count(), 'The manager is told.');

        $this->actingAs($executive)->post("/proposals/{$large->id}/approve")->assertForbidden();

        $this->actingAs($manager)->post("/proposals/{$large->id}/send-back", ['note' => 'Keep it to 10%.']);
        $this->assertSame(ProposalStatus::Rejected, $large->fresh()->status);
        $this->assertSame('Keep it to 10%.', $large->fresh()->review_note);
    }

    public function test_nobody_approves_their_own_discount(): void
    {
        $manager = $this->userWithRole(Roles::SALES_MANAGER);
        $deal = $this->deal($manager);
        $proposal = $this->proposal($deal, $manager, 30);

        $this->actingAs($manager)->post("/proposals/{$proposal->id}/submit");
        $this->actingAs($manager)->post("/proposals/{$proposal->id}/approve")->assertForbidden();
    }

    public function test_sending_moves_the_deal_and_the_buyer_can_open_the_signed_link(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $deal = $this->deal($executive);
        $proposal = $this->proposal($deal, $executive, 0);
        $proposal->forceFill(['status' => ProposalStatus::Approved, 'valid_until' => today()->addDays(10)])->save();

        $this->actingAs($executive)->post("/proposals/{$proposal->id}/send")->assertRedirect();

        $deal->refresh();
        $this->assertSame(PipelineStage::Proposal, $deal->stage);
        $this->assertSame(today()->addDays(10)->toDateString(), $deal->expected_close_on->toDateString(), 'The forecast gets a close date.');
        $this->assertSame((float) $proposal->total, (float) $deal->expected_value);
        $this->assertStringContainsString('Follow up on proposal', (string) $deal->next_step);

        auth()->logout();
        $this->get(URL::temporarySignedRoute('proposals.shared', now()->addDay(), ['proposal' => $proposal->id]))
            ->assertOk()
            ->assertSee($proposal->title);
        $this->get("/p/{$proposal->id}")->assertForbidden();
    }

    public function test_the_answer_is_recorded(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $proposal = $this->proposal($this->deal($executive), $executive, 0);
        $proposal->forceFill(['status' => ProposalStatus::Sent])->save();

        $this->actingAs($executive)->post("/proposals/{$proposal->id}/respond/accepted");

        $this->assertSame(ProposalStatus::Accepted, $proposal->fresh()->status);
        $this->assertNotNull($proposal->fresh()->responded_at);
    }

    // ── Incentives, leaderboard, team ──────────────────────────────────────

    public function test_incentives_appear_only_once_a_rate_is_set(): void
    {
        $manager = $this->userWithRole(Roles::SALES_MANAGER);
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE, ['name' => 'Vikram Shetty']);
        $this->deal($executive, ['stage' => PipelineStage::Won, 'outcome' => 'won', 'actual_value' => 200000, 'closed_at' => now()]);

        $this->actingAs($manager)->get('/targets')->assertInertia(fn (AssertableInertia $page) => $page->where('commission', 0));

        Settings::put('sales.commission_percent', 5);

        $this->actingAs($manager)->get('/targets')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('commission', 5)
            ->where('rows', fn ($rows) => collect($rows)->contains(fn ($row) => $row['user']['name'] === 'Vikram Shetty' && (float) $row['incentive'] === 10000.0)));
    }

    public function test_the_leaderboard_ranks_by_value_won(): void
    {
        $first = $this->userWithRole(Roles::SALES_EXECUTIVE, ['name' => 'Big winner']);
        $second = $this->userWithRole(Roles::SALES_EXECUTIVE, ['name' => 'Small winner']);
        $this->deal($first, ['stage' => PipelineStage::Won, 'outcome' => 'won', 'actual_value' => 300000, 'closed_at' => now()]);
        $this->deal($second, ['stage' => PipelineStage::Won, 'outcome' => 'won', 'actual_value' => 50000, 'closed_at' => now()]);

        $this->actingAs($second)->get('/sales/leaderboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows.0.name', 'Big winner')->where('rows.1.name', 'Small winner'));
    }

    public function test_the_team_view_is_for_managers_and_flags_stuck_deals(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $manager = $this->userWithRole(Roles::SALES_MANAGER);
        $this->deal($executive, ['title' => 'Gone cold', 'next_step' => 'Call them', 'next_step_on' => today()->subDays(4)]);

        $this->actingAs($executive)->get('/sales/team')->assertForbidden();

        $this->actingAs($manager)->get('/sales/team')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.stuck', 1)
                ->where('stuck.0.title', 'Gone cold')
                ->where('stuck.0.reason', 'Next step overdue by 4 days'));
    }

    public function test_handing_a_city_moves_only_leads_nobody_has_spoken_to(): void
    {
        $manager = $this->userWithRole(Roles::SALES_MANAGER);
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        $telecaller = $this->userWithRole(Roles::TELECALLER);

        $untouched = $this->lead($telecaller, 'madurai ', LeadStatus::New);
        $inConversation = $this->lead($telecaller, 'Madurai', LeadStatus::Contacted);
        $elsewhere = $this->lead($telecaller, 'Chennai', LeadStatus::New);

        $this->actingAs($manager)->post('/sales/team/assign-city', ['city' => 'Madurai', 'user_id' => $executive->id])
            ->assertSessionHas('success', "1 new lead in Madurai handed to {$executive->name}.");

        $this->assertSame($executive->id, $untouched->fresh()->owner_id);
        $this->assertSame($telecaller->id, $inConversation->fresh()->owner_id);
        $this->assertSame($telecaller->id, $elsewhere->fresh()->owner_id);

        $this->actingAs($executive)->post('/sales/team/assign-city', ['city' => 'Chennai', 'user_id' => $executive->id])->assertForbidden();
    }

    public function test_a_sales_executive_dashboard_shows_deals_and_proposals(): void
    {
        $executive = $this->userWithRole(Roles::SALES_EXECUTIVE);
        Target::create(['user_id' => $executive->id, 'period' => Target::currentPeriod(), 'deals_target' => 4]);
        $deal = $this->deal($executive, ['next_step' => 'Send the proposal', 'next_step_on' => today()->addDay()]);
        $this->proposal($deal, $executive, 0)->forceFill(['status' => ProposalStatus::Approved])->save();

        $this->actingAs($executive)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('gauge.kind', 'deals')
                ->where('gauge.max', 4)
                ->where('gauge.items.0.meta', 'Send the proposal')
                ->where('proposals.ready', 1)
                ->where('proposals.items.0.action', 'Send'));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function deal(User $owner, array $overrides = []): Deal
    {
        $client = Client::create([
            'name' => 'Client '.fake()->unique()->word(),
            'status' => 'active',
            'phone' => '98765 43210',
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
        ]);

        return Deal::create([
            'code' => app(CodeGenerator::class)->next('DEA'),
            'client_id' => $client->id,
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
            'created_by' => $by->id,
        ]);
        $proposal->fillAmounts([['description' => 'Story', 'quantity' => 3, 'rate' => 20000]], $discount);
        $proposal->save();

        return $proposal;
    }

    private function lead(User $owner, string $city, LeadStatus $status): Lead
    {
        return Lead::create([
            'code' => app(CodeGenerator::class)->next('LED'),
            'name' => 'Lead '.fake()->unique()->word(),
            'city' => $city,
            'source' => LeadSource::Call,
            'status' => $status,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
        ]);
    }
}
