<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Interview;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\ArticleStage;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * WhatsApp messages to the business, and the impact report.
 */
class ImpactAndMessagesTest extends TestCase
{
    use RefreshDatabase;

    // ── Messages to the business ───────────────────────────────────────────

    public function test_whoever_booked_the_interview_records_a_confirmation(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER, ['name' => 'Divya Krishnan']);
        $interview = $this->interview($telecaller);

        $this->actingAs($telecaller)
            ->post("/interviews/{$interview->id}/messages/confirmation")
            ->assertRedirect();

        $interview->refresh();
        $this->assertNotNull($interview->confirmation_sent_at);
        $this->assertSame($telecaller->id, $interview->confirmation_sent_by);
        $this->assertNull($interview->reminder_sent_at);

        $this->actingAs($telecaller)->get("/interviews/{$interview->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('interview.messages.confirmation.by', 'Divya Krishnan')
                ->where('interview.messages.reminder', null)
                ->where('can.message', true));
    }

    public function test_an_unknown_message_or_an_outsider_is_refused(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $interview = $this->interview($telecaller);

        $this->actingAs($telecaller)->post("/interviews/{$interview->id}/messages/spam")->assertNotFound();
        $this->actingAs($this->userWithRole(Roles::TELECALLER))
            ->post("/interviews/{$interview->id}/messages/reminder")
            ->assertForbidden();
    }

    public function test_the_live_story_link_is_offered_once_published(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $interview = $this->interview($telecaller, InterviewStage::Published);

        Article::create([
            'code' => app(CodeGenerator::class)->next('ART'),
            'interview_id' => $interview->id,
            'title' => 'Jasmine from Madurai',
            'stage' => ArticleStage::Published,
            'stage_entered_at' => now(),
            'published_at' => now(),
            'published_url' => 'https://takecareconnect.com/stories/jasmine',
        ]);

        $this->actingAs($telecaller)->get("/interviews/{$interview->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('interview.story_url', 'https://takecareconnect.com/stories/jasmine'));
    }

    public function test_tomorrows_unreminded_interviews_reach_the_morning_digest(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $this->interview($telecaller);

        $this->artisan('tcc:reminders')->assertSuccessful();

        $this->assertStringContainsString('reminding', (string) json_encode($telecaller->fresh()->notifications()->latest()->first()?->data));
    }

    // ── Impact ─────────────────────────────────────────────────────────────

    public function test_the_impact_report_counts_businesses_featured(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $this->interview($telecaller, InterviewStage::Conducted, ['conducted_at' => now()]);

        $this->actingAs($this->userWithRole(Roles::ADMIN))->get('/reports/impact')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Impact')
                ->where('totals.featured', 1)
                ->has('trend', 12)
                ->where('journey.4.value', 1));
    }

    public function test_the_impact_sheet_downloads(): void
    {
        $telecaller = $this->userWithRole(Roles::TELECALLER);
        $this->interview($telecaller, InterviewStage::Conducted, ['conducted_at' => now(), 'subject_company' => 'Madurai Jasmine Growers']);

        $response = $this->actingAs($this->userWithRole(Roles::ADMIN))->get('/reports/impact/export?months=1');

        $response->assertOk();
        $this->assertStringContainsString('Madurai Jasmine Growers', $response->streamedContent());
    }

    public function test_impact_is_organisation_wide_so_it_is_gated_like_performance(): void
    {
        $this->actingAs($this->userWithRole(Roles::TELECALLER))->get('/reports/impact')->assertForbidden();
        $this->actingAs($this->userWithRole(Roles::CONTENT))->get('/reports/impact')->assertOk();
    }

    /** @param array<string, mixed> $attributes */
    private function interview(User $booker, InterviewStage $stage = InterviewStage::Scheduled, array $attributes = []): Interview
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);

        return Interview::create([
            'code' => app(CodeGenerator::class)->next('INT'),
            'kind' => InterviewKind::BusinessArticle,
            'title' => 'Interview '.fake()->unique()->word(),
            'subject_name' => 'Selvi Murugan',
            'subject_phone' => '98765 43210',
            'anchor_id' => $anchor->id,
            'scheduled_by' => $booker->id,
            'scheduled_at' => now()->addDay(),
            'mode' => InterviewKind::BusinessArticle->defaultMode(),
            'stage' => $stage,
            'stage_entered_at' => now(),
            'priority' => 'normal',
            ...$attributes,
        ]);
    }
}
