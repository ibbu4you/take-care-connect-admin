<?php

namespace Tests\Feature;

use App\Models\Interview;
use App\Models\InterviewAsset;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The SOP itself.
 *
 * The rule these exist to protect: a business interview cannot reach the content
 * team without the write-up, the recording and the Q&A. A writer opening an
 * article days later with nothing to write from is the failure this whole phase
 * is built to prevent, so it is asserted from several directions.
 */
class InterviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    // ── Booking ────────────────────────────────────────────────────────────

    public function test_a_relationship_manager_can_book_an_interview(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($manager)
            ->post('/interviews', $this->bookingPayload($anchor))
            ->assertRedirect();

        $interview = Interview::firstWhere('title', 'A weaving business');

        $this->assertNotNull($interview);
        $this->assertSame($anchor->id, $interview->anchor_id);
        $this->assertSame($manager->id, $interview->scheduled_by);
        $this->assertSame(InterviewStage::Scheduled, $interview->stage);
        $this->assertStringStartsWith('INT-', $interview->code);
    }

    public function test_an_anchor_cannot_book_an_interview(): void
    {
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($anchor)
            ->post('/interviews', $this->bookingPayload($anchor))
            ->assertForbidden();
    }

    /** A diary entry with no way to join it is discovered at the appointment. */
    public function test_a_meet_interview_needs_a_meeting_link(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($manager)
            ->post('/interviews', [
                ...$this->bookingPayload($anchor),
                'mode' => 'google_meet',
                'meeting_url' => '',
            ])
            ->assertSessionHasErrors('meeting_url');
    }

    public function test_an_in_person_interview_needs_a_location(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($manager)
            ->post('/interviews', [
                ...$this->bookingPayload($anchor),
                'mode' => 'in_person',
                'meeting_url' => '',
                'location' => '',
            ])
            ->assertSessionHasErrors('location');
    }

    public function test_booking_records_the_opening_stage_in_the_history(): void
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $anchor = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($manager)->post('/interviews', $this->bookingPayload($anchor));

        $interview = Interview::firstWhere('title', 'A weaving business');

        $this->assertDatabaseHas('stage_events', [
            'subject_type' => Interview::class,
            'subject_id' => $interview->id,
            'from_stage' => null,
            'to_stage' => InterviewStage::Scheduled->value,
        ]);
    }

    // ── The kind decides everything downstream ─────────────────────────────

    public function test_only_a_business_interview_requires_an_article(): void
    {
        $this->assertTrue($this->interview(InterviewKind::BusinessArticle)->requires_article);
        $this->assertFalse($this->interview(InterviewKind::CelebritySocial)->requires_article);
        $this->assertFalse($this->interview(InterviewKind::MeetSocial)->requires_article);
    }

    /** Stored rather than derived, so it must follow a change of kind. */
    public function test_changing_the_kind_keeps_requires_article_in_step(): void
    {
        $interview = $this->interview(InterviewKind::BusinessArticle);
        $this->assertTrue($interview->requires_article);

        $interview->update(['kind' => InterviewKind::MeetSocial]);

        $this->assertFalse($interview->fresh()->requires_article);
    }

    public function test_a_social_interview_can_never_be_handed_over(): void
    {
        $interview = $this->interview(InterviewKind::CelebritySocial, InterviewStage::Conducted);

        $this->assertNotContains(InterviewStage::HandedOver, $interview->allowedNextStages());
    }

    // ── Conducting ─────────────────────────────────────────────────────────

    /**
     * Conducting records the context around the interview, not the interview.
     *
     * The transcript arrives as an uploaded Turboscript export; what is typed
     * here is where the recording lives and a brief for whoever writes it up.
     */
    public function test_the_anchor_can_mark_it_conducted(): void
    {
        $interview = $this->interview();
        $anchor = User::find($interview->anchor_id);

        $this->actingAs($anchor)
            ->post("/interviews/{$interview->id}/conduct", [
                'qa_summary' => 'The best material is in the second half.',
                'recording_url' => 'https://drive.google.com/file/d/x/view',
                'consent_to_publish' => true,
            ])
            ->assertRedirect();

        $interview->refresh();

        $this->assertSame(InterviewStage::Conducted, $interview->stage);
        $this->assertSame('The best material is in the second half.', $interview->qa_summary);
        $this->assertTrue($interview->consent_to_publish);
        $this->assertNotNull($interview->conducted_at);
    }

    /** Marking it conducted is a statement about what you personally did. */
    public function test_another_anchor_cannot_conduct_somebody_elses_interview(): void
    {
        $interview = $this->interview();
        $other = $this->userWithRole(Roles::ANCHOR);

        $this->actingAs($other)
            ->post("/interviews/{$interview->id}/conduct", ['qa_summary' => 'Mine now.'])
            ->assertForbidden();
    }

    public function test_saving_the_details_again_does_not_re_conduct_the_interview(): void
    {
        $interview = $this->interview(stage: InterviewStage::Conducted);
        $anchor = User::find($interview->anchor_id);

        $before = $interview->stageEvents()->count();

        $this->actingAs($anchor)->post("/interviews/{$interview->id}/conduct", [
            'qa_summary' => 'Edited.',
        ]);

        $this->assertSame($before, $interview->fresh()->stageEvents()->count());
    }

    // ── The handover rule ──────────────────────────────────────────────────

    public function test_handover_is_refused_when_everything_is_missing(): void
    {
        $interview = $this->interview(stage: InterviewStage::Conducted);
        $anchor = User::find($interview->anchor_id);

        $this->actingAs($anchor)
            ->post("/interviews/{$interview->id}/handover")
            ->assertSessionHasErrors('handover');

        $this->assertSame(InterviewStage::Conducted, $interview->fresh()->stage);
    }

    /**
     * Every combination of the two required pieces.
     *
     * `transcript` and `document` both satisfy the transcript requirement: they
     * are the same artefact as far as the content team is concerned, and
     * refusing a file over which of two labels somebody picked from a dropdown
     * would be a pointless argument to have.
     *
     * @return array<string, array{0: list<string>, 1: bool}>
     */
    public static function handoverCombinations(): array
    {
        // [assets present, should be allowed]
        return [
            'nothing at all' => [[], false],
            'transcript only' => [['transcript'], false],
            'recording only' => [['recording'], false],
            'transcript and recording' => [['transcript', 'recording'], true],
            'a document counts as the transcript' => [['document', 'recording'], true],
        ];
    }

    /**
     * @param  list<string>  $assets
     */
    #[DataProvider('handoverCombinations')]
    public function test_handover_requires_the_transcript_and_the_recording(array $assets, bool $allowed): void
    {
        $interview = $this->interview(stage: InterviewStage::Conducted);
        $anchor = User::find($interview->anchor_id);

        foreach ($assets as $kind) {
            InterviewAsset::create([
                'interview_id' => $interview->id,
                'kind' => $kind,
                'url' => $kind === 'recording' ? 'https://drive.google.com/x' : null,
                'path' => $kind === 'recording' ? null : 'interviews/1/transcript.pdf',
                'uploaded_by' => $anchor->id,
            ]);

            if ($kind === 'recording') {
                $interview->update(['recording_url' => 'https://drive.google.com/x']);
            }
        }

        $response = $this->actingAs($anchor)->post("/interviews/{$interview->id}/handover");

        $interview->refresh();

        if ($allowed) {
            $response->assertSessionHasNoErrors();
            $this->assertSame(InterviewStage::HandedOver, $interview->stage);
            $this->assertNotNull($interview->handed_over_at);
        } else {
            $response->assertSessionHasErrors('handover');
            $this->assertSame(InterviewStage::Conducted, $interview->stage);
        }
    }

    public function test_handover_assigns_the_writer_and_records_it(): void
    {
        $interview = $this->readyForHandover();
        $anchor = User::find($interview->anchor_id);
        $writer = $this->userWithRole(Roles::CONTENT);

        $this->actingAs($anchor)
            ->post("/interviews/{$interview->id}/handover", [
                'content_owner_id' => $writer->id,
                'note' => 'Best quotes are around 12 minutes in.',
            ])
            ->assertRedirect();

        $interview->refresh();

        $this->assertSame($writer->id, $interview->content_owner_id);
        $this->assertDatabaseHas('assignments', [
            'assignable_type' => Interview::class,
            'assignable_id' => $interview->id,
            'assigned_to' => $writer->id,
            'role' => 'content',
            'state' => 'open',
        ]);
    }

    public function test_a_social_interview_cannot_be_handed_over_through_the_route(): void
    {
        $interview = $this->interview(InterviewKind::CelebritySocial, InterviewStage::Conducted);
        $anchor = User::find($interview->anchor_id);

        $this->actingAs($anchor)
            ->post("/interviews/{$interview->id}/handover")
            ->assertForbidden();
    }

    /** Removing the recording must withdraw the readiness it granted. */
    public function test_deleting_the_recording_makes_it_unready_again(): void
    {
        $interview = $this->readyForHandover();
        $anchor = User::find($interview->anchor_id);

        $this->assertTrue($interview->isReadyForHandover());

        $recording = $interview->assets()->where('kind', 'recording')->firstOrFail();

        $this->actingAs($anchor)
            ->delete("/interview-assets/{$recording->id}")
            ->assertRedirect();

        $interview->refresh();

        $this->assertFalse($interview->isReadyForHandover());
        $this->assertNull($interview->recording_url);
    }

    // ── The stage machine ──────────────────────────────────────────────────

    public function test_an_illegal_stage_move_is_refused(): void
    {
        $interview = $this->interview();
        $anchor = User::find($interview->anchor_id);

        // scheduled → published skips the entire process. It is bad input, so
        // it comes back as a field error rather than as a 500.
        $this->actingAs($anchor)
            ->post("/interviews/{$interview->id}/stage", ['stage' => InterviewStage::Published->value])
            ->assertSessionHasErrors('stage');

        $this->assertSame(InterviewStage::Scheduled, $interview->fresh()->stage);
    }

    public function test_a_cancelled_interview_is_terminal(): void
    {
        $this->assertTrue(InterviewStage::Cancelled->isTerminal());
        $this->assertSame([], InterviewStage::Cancelled->allowedNext());
    }

    /** Somebody who did not turn up can be booked again; a cancellation cannot. */
    public function test_a_no_show_can_be_rescheduled(): void
    {
        $this->assertContains(InterviewStage::Rescheduled, InterviewStage::NoShow->allowedNext());
    }

    public function test_cancelling_records_the_reason_and_the_history(): void
    {
        $interview = $this->interview();
        $manager = User::find($interview->scheduled_by);

        $this->actingAs($manager)
            ->post("/interviews/{$interview->id}/cancel", [
                'stage' => InterviewStage::NoShow->value,
                'reason' => 'They did not join the call.',
            ])
            ->assertRedirect();

        $interview->refresh();

        $this->assertSame(InterviewStage::NoShow, $interview->stage);
        $this->assertSame('They did not join the call.', $interview->cancelled_reason);
        $this->assertDatabaseHas('stage_events', [
            'subject_id' => $interview->id,
            'to_stage' => InterviewStage::NoShow->value,
        ]);
    }

    // ── Scoping ────────────────────────────────────────────────────────────

    /** An anchor's list is their own diary, not the whole team's. */
    public function test_an_anchor_only_sees_interviews_they_are_on(): void
    {
        $mine = $this->interview();
        $anchor = User::find($mine->anchor_id);

        $theirs = $this->interview();

        $this->actingAs($anchor)
            ->get('/interviews')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($mine, $theirs) {
                $ids = collect($page->toArray()['props']['interviews']['data'])->pluck('id');

                $this->assertContains($mine->id, $ids);
                $this->assertNotContains($theirs->id, $ids);
            });
    }

    public function test_the_content_team_sees_every_interview(): void
    {
        $one = $this->interview();
        $two = $this->interview();

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->get('/interviews')
            ->assertInertia(function (AssertableInertia $page) use ($one, $two) {
                $ids = collect($page->toArray()['props']['interviews']['data'])->pluck('id');

                $this->assertContains($one->id, $ids);
                $this->assertContains($two->id, $ids);
            });
    }

    /**
     * A creative holds no `interviews.view.all` at all, so this is the whole
     * mechanism by which they can open the one interview they are cutting a
     * reel for.
     */
    public function test_a_creative_sees_only_the_interview_they_are_assigned_to(): void
    {
        $creative = $this->userWithRole(Roles::CREATIVE);

        $assigned = $this->interview();
        $assigned->update(['creative_owner_id' => $creative->id]);

        $other = $this->interview();

        $this->actingAs($creative)->get("/interviews/{$assigned->id}")->assertOk();
        $this->actingAs($creative)->get("/interviews/{$other->id}")->assertForbidden();
    }

    // ── Codes ──────────────────────────────────────────────────────────────

    /** Codes must never be reused, which is why they do not come from max(id). */
    public function test_codes_are_sequential_and_survive_deletion(): void
    {
        $codes = app(CodeGenerator::class);

        $this->assertSame('INT-000001', $codes->next('INT'));
        $this->assertSame('INT-000002', $codes->next('INT'));

        $interview = $this->interview();
        $interview->delete();

        $this->assertSame('INT-000004', $codes->next('INT'), 'A deleted record must not release its code.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(User $anchor): array
    {
        return [
            'kind' => InterviewKind::BusinessArticle->value,
            'title' => 'A weaving business',
            'subject_name' => 'Kamala Devi',
            'anchor_id' => $anchor->id,
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'mode' => 'google_meet',
            'meeting_url' => 'https://meet.google.com/abc-defg-hij',
            'priority' => 'normal',
        ];
    }

    private function interview(
        InterviewKind $kind = InterviewKind::BusinessArticle,
        InterviewStage $stage = InterviewStage::Scheduled,
    ): Interview {
        $anchor = $this->userWithRole(Roles::ANCHOR);
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        return Interview::create([
            'code' => app(CodeGenerator::class)->next('INT'),
            'kind' => $kind,
            'title' => 'Interview '.fake()->unique()->word(),
            'subject_name' => fake()->name(),
            'anchor_id' => $anchor->id,
            'scheduled_by' => $manager->id,
            'scheduled_at' => now()->addDay(),
            'mode' => $kind->defaultMode(),
            'stage' => $stage,
            'stage_entered_at' => now(),
            'priority' => 'normal',
        ]);
    }

    /** A business interview carrying all three required pieces. */
    private function readyForHandover(): Interview
    {
        $interview = $this->interview(stage: InterviewStage::Conducted);

        InterviewAsset::create([
            'interview_id' => $interview->id,
            'kind' => 'document',
            'path' => 'interviews/1/write-up.docx',
            'uploaded_by' => $interview->anchor_id,
        ]);

        InterviewAsset::create([
            'interview_id' => $interview->id,
            'kind' => 'recording',
            'url' => 'https://drive.google.com/file/d/x/view',
            'uploaded_by' => $interview->anchor_id,
        ]);

        $interview->update([
            'recording_url' => 'https://drive.google.com/file/d/x/view',
            'qa' => [['question' => 'How did you start?', 'answer' => 'With one loom.']],
        ]);

        return $interview->fresh();
    }
}
