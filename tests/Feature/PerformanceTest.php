<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Interview;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Stats;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Per-person load and turnaround.
 *
 * ── The bug this file exists to stop coming back ───────────────────────────
 * Carbon 3 returns a *signed* float from diffInDays, so a record published
 * before its created_at produced "-2d" on the page and dragged the team median
 * below zero — a report claiming work finished before it started. It rendered
 * as a slightly odd number rather than an error, which is the only reason it
 * survived to be noticed by eye.
 */
class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    // ── Durations ──────────────────────────────────────────────────────────

    public function test_a_normal_duration_is_whole_days(): void
    {
        $this->assertSame(3, Stats::daysBetween(now()->subDays(3), now()));
        $this->assertSame(0, Stats::daysBetween(now()->subHours(2), now()));
    }

    /**
     * Backfilled data, an import, a clock that slipped — the duration is not
     * negative, it is unknown, and a median must not be built from it.
     */
    public function test_a_backwards_duration_is_unknown_rather_than_negative(): void
    {
        $this->assertNull(Stats::daysBetween(now(), now()->subDays(3)));
    }

    public function test_a_missing_end_is_unknown(): void
    {
        $this->assertNull(Stats::daysBetween(now()->subDays(3), null));
        $this->assertNull(Stats::daysBetween(null, now()));
    }

    /** abs() would report the backwards record as having taken two days. */
    public function test_an_unmeasurable_duration_is_left_out_of_the_median(): void
    {
        $this->assertSame(4.0, Stats::median([2, 4, 6]));
        $this->assertNull(Stats::median([]));
    }

    // ── The page ───────────────────────────────────────────────────────────

    public function test_the_page_needs_the_organisation_wide_scope(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->get('/reports/performance')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Performance')
                ->has('anchors.rows')
                ->has('writers.rows')
                ->has('creatives.rows')
                ->has('managers.rows'));
    }

    /**
     * An anchor holds `reports.view` but not `.all`. This page is everybody's
     * work, so their own scope is not enough to open it.
     */
    public function test_somebody_with_only_their_own_scope_cannot_open_it(): void
    {
        $this->actingAs($this->userWithRole(Roles::ANCHOR))
            ->get('/reports/performance')
            ->assertForbidden();

        $this->actingAs($this->userWithRole(Roles::RELATIONSHIP_MANAGER))
            ->get('/reports/performance')
            ->assertForbidden();
    }

    /** Content holds `reports.view.all`, so the throughput view is theirs too. */
    public function test_content_can_open_it(): void
    {
        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->get('/reports/performance')
            ->assertOk();
    }

    public function test_a_turnaround_is_reported_in_whole_days(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->conductedInterview($anchor, conducted: now()->subDays(5), handedOver: now()->subDays(3));

        $this->actingAs($admin)
            ->get('/reports/performance')
            // 2, not 2.0: a whole-number float serialises to JSON without
            // its decimal, and the page reads it back as an integer.
            ->assertInertia(fn (AssertableInertia $page) => $page->where('anchors.median', 2));
    }

    /** The whole point of the guard, asserted end to end. */
    public function test_a_backwards_record_does_not_produce_a_negative_median(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        // Handed over "before" it was conducted, as imported data can be.
        $this->conductedInterview($anchor, conducted: now()->subDays(2), handedOver: now()->subDays(6));

        $this->actingAs($admin)
            ->get('/reports/performance')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('anchors.median', null));
    }

    /**
     * Load is present tense: somebody swamped today is swamped today, whatever
     * they finished three months ago.
     */
    public function test_open_work_is_counted_now_not_within_the_window(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->interview($anchor, InterviewStage::Scheduled, now()->addMonths(6));

        $this->actingAs($admin)
            ->get('/reports/performance?months=1')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('anchors.rows.0.open', 1));
    }

    /** A red 0% against somebody nobody set a target for is a lie about them. */
    public function test_no_target_reads_as_no_target_rather_than_zero(): void
    {
        $admin = $this->userWithRole(Roles::ADMIN);
        $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->actingAs($admin)
            ->get('/reports/performance')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('managers.rows.0.attainment', null));
    }

    /** The window is a fixed set, so a hand-typed one cannot widen it. */
    public function test_an_unknown_window_falls_back_to_the_default(): void
    {
        $this->actingAs($this->userWithRole(Roles::ADMIN))
            ->get('/reports/performance?months=999')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('window.months', 3));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function conductedInterview(User $anchor, $conducted, $handedOver): Interview
    {
        $interview = $this->interview($anchor, InterviewStage::HandedOver, $conducted);

        $interview->forceFill([
            'conducted_at' => $conducted,
            'handed_over_at' => $handedOver,
        ])->save();

        return $interview;
    }

    private function interview(User $anchor, InterviewStage $stage, $scheduledAt): Interview
    {
        return Interview::create([
            'code' => app(CodeGenerator::class)->next('INT'),
            'kind' => InterviewKind::BusinessArticle,
            'title' => 'Interview '.fake()->unique()->words(2, true),
            'subject_name' => fake()->name(),
            'anchor_id' => $anchor->id,
            'scheduled_at' => $scheduledAt,
            'mode' => 'google_meet',
            'stage' => $stage,
            'stage_entered_at' => now(),
            'priority' => 'normal',
        ]);
    }
}
