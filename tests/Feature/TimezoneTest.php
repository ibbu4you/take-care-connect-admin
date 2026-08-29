<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Interview;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clocks.
 *
 * ── The bug this exists to stop coming back ────────────────────────────────
 * With the application timezone left at Laravel's UTC default, a 10 am
 * interview was stored as 10:00, sent to the browser as 10:00+00:00, and
 * rendered by a browser in India as 3:30 pm. Nothing errored. Every time in
 * the system was simply wrong by five and a half hours, and the only way to
 * notice was to read a clock and know what it should have said.
 *
 * A test cannot read a clock, but it can assert the offset — which is the
 * thing that was actually missing.
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_runs_on_india_time(): void
    {
        $this->assertSame('Asia/Kolkata', config('app.timezone'));
        $this->assertSame('+05:30', now()->format('P'));
    }

    /**
     * A timestamp is a moment, so it must travel with its offset — otherwise
     * the browser applies its own and lands somewhere else.
     */
    public function test_a_scheduled_time_reaches_the_browser_with_its_offset(): void
    {
        $interview = $this->interviewAt('2026-08-18 10:00:00');

        $this->assertSame(
            '2026-08-18T10:00:00+05:30',
            $interview->scheduled_at->toIso8601String(),
        );
    }

    /**
     * A due date is a calendar date, not a moment. Serialising it as an instant
     * is what makes the 25th render as the 24th for some viewers, so the cast
     * is pinned to a bare date.
     */
    public function test_a_due_date_is_serialised_as_a_plain_date(): void
    {
        $article = Article::create([
            'code' => app(CodeGenerator::class)->next('ART'),
            'title' => 'A weaving business',
            'stage' => 'assigned',
            'status' => 'draft',
            'due_on' => '2026-08-25',
        ]);

        $this->assertSame('2026-08-25', $article->fresh()->toArray()['due_on']);
    }

    /**
     * "Today" has to mean today in India, or the morning digest reminds the
     * anchor about the wrong day's interviews for the first five and a half
     * hours of it.
     */
    public function test_today_is_today_in_india(): void
    {
        $this->travelTo(now()->setTime(0, 30));

        $interview = $this->interviewAt(now()->format('Y-m-d').' 09:00:00');

        $this->assertSame(
            1,
            Interview::whereDate('scheduled_at', now()->startOfDay())->count(),
            'An interview later today was not counted as today.',
        );

        $this->assertSame(now()->toDateString(), $interview->scheduled_at->toDateString());
    }

    private function interviewAt(string $when): Interview
    {
        return Interview::create([
            'code' => app(CodeGenerator::class)->next('INT'),
            'kind' => InterviewKind::BusinessArticle,
            'title' => 'Interview '.fake()->unique()->word(),
            'subject_name' => fake()->name(),
            'anchor_id' => $this->userWithRole(Roles::ANCHOR)->id,
            'scheduled_at' => $when,
            'mode' => 'google_meet',
            'stage' => InterviewStage::Scheduled,
            'stage_entered_at' => now(),
            'priority' => 'normal',
        ]);
    }
}
