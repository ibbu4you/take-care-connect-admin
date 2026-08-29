<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Deliverable;
use App\Models\DeliverableFile;
use App\Models\Interview;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\ArticleStage;
use App\Support\Workflow\DeliverableKind;
use App\Support\Workflow\DeliverableStage;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The creative desk.
 *
 * The rule this phase exists to protect: **whoever makes a piece of work does
 * not approve it, and whoever approves it does not post it.** A poster goes out
 * under the organisation's name with a client's phone number on it, so the
 * client-facing manager approves and the creative with the platform account
 * open posts. Neither can do both halves alone.
 */
class DeliverableWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The relationship manager who books every interview in this file.
     *
     * Memoised deliberately. A creative approval is scoped to people who can
     * see the deliverable, and for a manager that means the interview they
     * booked — so a fresh manager per call would be a stranger to the work and
     * correctly refused.
     */
    private ?User $manager = null;

    protected function setUp(): void
    {
        parent::setUp();

    }

    private function manager(): User
    {
        return $this->manager ??= $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
    }

    // ── Commissioning ──────────────────────────────────────────────────────

    /**
     * The whole reason the parent is polymorphic: two of the three interview
     * kinds never produce an article, so a deliverable keyed to an article
     * could not represent their output at all.
     */
    public function test_work_can_be_commissioned_from_a_social_interview(): void
    {
        $interview = $this->interview(InterviewKind::CelebritySocial, InterviewStage::Conducted);
        $anchor = User::find($interview->anchor_id);
        $creative = $this->userWithRole(Roles::CREATIVE);

        $this->actingAs($anchor)
            ->post('/deliverables', $this->commission($interview, ['assigned_to' => $creative->id]))
            ->assertRedirect();

        $deliverable = Deliverable::firstOrFail();

        $this->assertSame(Interview::class, $deliverable->deliverable_of_type);
        $this->assertSame($interview->id, $deliverable->deliverable_of_id);
        $this->assertSame($creative->id, $deliverable->assigned_to);
        $this->assertSame(DeliverableStage::Pending, $deliverable->stage);
        $this->assertStringStartsWith('DLV-', $deliverable->code);
    }

    public function test_work_can_be_commissioned_from_an_article(): void
    {
        $article = $this->article();
        $writer = User::find($article->writer_id);

        $this->actingAs($writer)
            ->post('/deliverables', [
                'parent_type' => 'article',
                'parent_id' => $article->id,
                'kind' => DeliverableKind::Poster->value,
                'quantity' => 1,
                'priority' => 'normal',
            ])
            ->assertRedirect();

        $deliverable = Deliverable::firstOrFail();

        $this->assertSame(Article::class, $deliverable->deliverable_of_type);
        $this->assertSame($article->title, $deliverable->parentLabel());
    }

    /** "Three reels" is one decision, not three trips through the form. */
    public function test_several_can_be_commissioned_at_once_and_are_numbered(): void
    {
        $interview = $this->interview();
        $anchor = User::find($interview->anchor_id);

        $this->actingAs($anchor)->post('/deliverables', $this->commission($interview, ['quantity' => 3]));

        $slots = Deliverable::orderBy('slot_number')->pluck('slot_number')->all();

        $this->assertSame([1, 2, 3], $slots);
    }

    /** Slot numbers run per kind, so "reel 1" and "poster 1" can both exist. */
    public function test_slot_numbers_are_counted_per_kind(): void
    {
        $interview = $this->interview();
        $anchor = User::find($interview->anchor_id);

        $this->actingAs($anchor)->post('/deliverables', $this->commission($interview, [
            'kind' => DeliverableKind::Reel->value,
            'quantity' => 2,
        ]));

        $this->actingAs($anchor)->post('/deliverables', $this->commission($interview, [
            'kind' => DeliverableKind::Poster->value,
            'quantity' => 1,
        ]));

        $this->assertSame([1, 2], Deliverable::where('kind', 'reel')->orderBy('slot_number')->pluck('slot_number')->all());
        $this->assertSame([1], Deliverable::where('kind', 'poster')->pluck('slot_number')->all());
    }

    public function test_commissioning_more_of_the_same_kind_continues_the_numbering(): void
    {
        $interview = $this->interview();
        $anchor = User::find($interview->anchor_id);

        $this->actingAs($anchor)->post('/deliverables', $this->commission($interview, ['quantity' => 2]));
        $this->actingAs($anchor)->post('/deliverables', $this->commission($interview, ['quantity' => 1]));

        $this->assertSame([1, 2, 3], Deliverable::orderBy('slot_number')->pluck('slot_number')->all());
    }

    /** A creative makes work; commissioning it is somebody else's decision. */
    public function test_a_creative_cannot_commission_work(): void
    {
        $interview = $this->interview();

        $this->actingAs($this->userWithRole(Roles::CREATIVE))
            ->post('/deliverables', $this->commission($interview))
            ->assertForbidden();
    }

    /** The default platforms come from the kind, so the form need not ask. */
    public function test_the_kind_sets_the_default_platforms(): void
    {
        $interview = $this->interview();

        $this->actingAs(User::find($interview->anchor_id))
            ->post('/deliverables', $this->commission($interview, ['kind' => DeliverableKind::Reel->value]));

        $this->assertSame(['instagram', 'facebook'], Deliverable::firstOrFail()->platforms);
    }

    // ── Making it ──────────────────────────────────────────────────────────

    public function test_uploading_the_first_cut_starts_the_work(): void
    {
        [$deliverable, $creative] = $this->assigned();

        $this->actingAs($creative)
            ->post("/deliverables/{$deliverable->id}/files", [
                'url' => 'https://drive.google.com/file/d/cut-1/view',
            ])
            ->assertRedirect();

        $this->assertSame(DeliverableStage::InProgress, $deliverable->fresh()->stage);
        $this->assertSame(1, $deliverable->files()->count());
    }

    /** "Send me the previous cut" is a request that will be made. */
    public function test_each_upload_is_a_new_version(): void
    {
        [$deliverable, $creative] = $this->assigned();

        foreach (['cut-1', 'cut-2', 'cut-3'] as $cut) {
            $this->actingAs($creative)->post("/deliverables/{$deliverable->id}/files", [
                'url' => "https://drive.google.com/file/d/{$cut}/view",
            ]);
        }

        $this->assertSame([3, 2, 1], $deliverable->files()->pluck('version')->all());
    }

    /** Doing the work on somebody else's behalf is a reassignment, not a shortcut. */
    public function test_another_creative_cannot_upload_to_somebody_elses_work(): void
    {
        [$deliverable] = $this->assigned();

        $this->actingAs($this->userWithRole(Roles::CREATIVE))
            ->post("/deliverables/{$deliverable->id}/files", ['url' => 'https://drive.google.com/x'])
            ->assertForbidden();
    }

    // ── Approval ───────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool}>
     */
    public static function submissionCombinations(): array
    {
        // [has a file, has a caption, may submit]
        return [
            'nothing' => [false, false, false],
            'file only' => [true, false, false],
            'caption only' => [false, true, false],
            'both' => [true, true, true],
        ];
    }

    #[DataProvider('submissionCombinations')]
    public function test_submitting_needs_both_the_file_and_the_caption(bool $file, bool $caption, bool $allowed): void
    {
        [$deliverable, $creative] = $this->assigned();

        if ($file) {
            DeliverableFile::create([
                'deliverable_id' => $deliverable->id,
                'version' => 1,
                'url' => 'https://drive.google.com/file/d/x/view',
                'uploaded_by' => $creative->id,
            ]);
        }

        if ($caption) {
            $deliverable->update(['caption' => 'A minute with the loom.']);
        }

        // Uploading a file moves it to in_progress; without one it is still
        // pending, and both are stages a creative may submit from.
        $deliverable->update(['stage' => $file ? DeliverableStage::InProgress : DeliverableStage::Pending]);

        $response = $this->actingAs($creative)->post("/deliverables/{$deliverable->id}/submit");

        if ($allowed) {
            $response->assertSessionHasNoErrors();
            $this->assertSame(DeliverableStage::Review, $deliverable->fresh()->stage);
        } else {
            $response->assertSessionHasErrors('review');
            $this->assertNotSame(DeliverableStage::Review, $deliverable->fresh()->stage);
        }
    }

    /** The rule this phase exists to protect. */
    public function test_a_creative_cannot_approve_their_own_work(): void
    {
        $deliverable = $this->inReview();

        $this->actingAs(User::find($deliverable->assigned_to))
            ->post("/deliverables/{$deliverable->id}/approve")
            ->assertForbidden();

        $this->assertSame(DeliverableStage::Review, $deliverable->fresh()->stage);
    }

    /** The client-facing manager is the one who hears about a wrong phone number. */
    public function test_a_relationship_manager_can_approve(): void
    {
        $deliverable = $this->inReview();
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post("/deliverables/{$deliverable->id}/approve")
            ->assertRedirect();

        $deliverable->refresh();

        $this->assertSame(DeliverableStage::Approved, $deliverable->stage);
        $this->assertSame($manager->id, $deliverable->approved_by);
    }

    public function test_the_content_team_can_also_approve(): void
    {
        $deliverable = $this->inReview();

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->post("/deliverables/{$deliverable->id}/approve")
            ->assertRedirect();

        $this->assertSame(DeliverableStage::Approved, $deliverable->fresh()->stage);
    }

    /**
     * Approval is scoped, not blanket.
     *
     * A manager approves the creative work for the clients they look after —
     * they are the one who will hear about a wrong phone number on a poster. A
     * manager with no connection to the interview holds the permission but not
     * the standing, and is refused.
     */
    public function test_a_manager_who_did_not_book_the_interview_cannot_approve(): void
    {
        $deliverable = $this->inReview();
        $stranger = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->actingAs($stranger)
            ->post("/deliverables/{$deliverable->id}/approve")
            ->assertForbidden();
    }

    public function test_sending_back_requires_a_note(): void
    {
        $deliverable = $this->inReview();

        $this->actingAs($this->manager())
            ->post("/deliverables/{$deliverable->id}/reject", ['note' => ''])
            ->assertSessionHasErrors('note');
    }

    public function test_sending_back_puts_the_note_on_the_deliverable(): void
    {
        $deliverable = $this->inReview();

        $this->actingAs($this->manager())
            ->post("/deliverables/{$deliverable->id}/reject", [
                'note' => 'The phone number on frame two is wrong.',
            ]);

        $deliverable->refresh();

        $this->assertSame(DeliverableStage::Rejected, $deliverable->stage);
        $this->assertSame('The phone number on frame two is wrong.', $deliverable->rejection_note);
    }

    /**
     * The lesson from the article workflow, applied here before it could bite:
     * without a direct rejected → review edge, every round of feedback after
     * the first would dead-end.
     */
    public function test_rejected_work_can_be_resubmitted(): void
    {
        $deliverable = $this->inReview();
        $creative = User::find($deliverable->assigned_to);
        $manager = $this->manager();

        $this->actingAs($manager)->post("/deliverables/{$deliverable->id}/reject", ['note' => 'Round one.']);
        $this->actingAs($creative)->post("/deliverables/{$deliverable->id}/submit");
        $this->actingAs($manager)->post("/deliverables/{$deliverable->id}/reject", ['note' => 'Round two.']);
        $this->actingAs($creative)->post("/deliverables/{$deliverable->id}/submit");
        $this->actingAs($manager)->post("/deliverables/{$deliverable->id}/approve");

        $this->assertSame(DeliverableStage::Approved, $deliverable->fresh()->stage);
    }

    /** Stale notes at the top of the page read as current ones. */
    public function test_resubmitting_clears_the_previous_note(): void
    {
        $deliverable = $this->inReview();

        $this->actingAs($this->manager())
            ->post("/deliverables/{$deliverable->id}/reject", ['note' => 'Needs work.']);

        $this->actingAs(User::find($deliverable->assigned_to))
            ->post("/deliverables/{$deliverable->id}/submit");

        $this->assertNull($deliverable->fresh()->rejection_note);
    }

    // ── Posting ────────────────────────────────────────────────────────────

    /** Posting something that never passed approval makes approval optional. */
    public function test_unapproved_work_cannot_be_posted(): void
    {
        $deliverable = $this->inReview();

        $this->actingAs(User::find($deliverable->assigned_to))
            ->post("/deliverables/{$deliverable->id}/publish", [
                'platform' => 'instagram',
                'url' => 'https://www.instagram.com/p/abc/',
            ])
            ->assertForbidden();
    }

    public function test_posting_requires_the_link(): void
    {
        $deliverable = $this->approved();

        $this->actingAs(User::find($deliverable->assigned_to))
            ->post("/deliverables/{$deliverable->id}/publish", ['platform' => 'instagram', 'url' => ''])
            ->assertSessionHasErrors('url');

        $this->assertSame(DeliverableStage::Approved, $deliverable->fresh()->stage);
    }

    public function test_posting_records_the_link_and_finishes_the_work(): void
    {
        $deliverable = $this->approved();

        $this->actingAs(User::find($deliverable->assigned_to))
            ->post("/deliverables/{$deliverable->id}/publish", [
                'platform' => 'instagram',
                'url' => 'https://www.instagram.com/p/abc123/',
            ])
            ->assertRedirect();

        $deliverable->refresh();

        $this->assertSame(DeliverableStage::Published, $deliverable->stage);
        $this->assertNotNull($deliverable->published_at);
        $this->assertTrue($deliverable->hasSocialLink());
        $this->assertSame('https://www.instagram.com/p/abc123/', $deliverable->socialPosts()->value('url'));
    }

    /** The same link against two deliverables means one of them is wrong. */
    public function test_the_same_link_cannot_be_recorded_twice(): void
    {
        $first = $this->approved();
        $second = $this->approved();

        $url = 'https://www.instagram.com/p/duplicate/';

        $this->actingAs(User::find($first->assigned_to))
            ->post("/deliverables/{$first->id}/publish", ['platform' => 'instagram', 'url' => $url]);

        $this->actingAs(User::find($second->assigned_to))
            ->post("/deliverables/{$second->id}/publish", ['platform' => 'instagram', 'url' => $url])
            ->assertSessionHasErrors('url');

        $this->assertSame(1, SocialPost::count());
    }

    /** The same reel cross-posted is a second link, not a second deliverable. */
    public function test_an_extra_link_can_be_recorded_after_posting(): void
    {
        $deliverable = $this->published();
        $creative = User::find($deliverable->assigned_to);

        $this->actingAs($creative)
            ->post("/deliverables/{$deliverable->id}/links", [
                'platform' => 'facebook',
                'url' => 'https://www.facebook.com/reel/xyz/',
            ])
            ->assertRedirect();

        $this->assertSame(2, $deliverable->fresh()->socialPosts()->count());
    }

    public function test_a_published_deliverable_cannot_be_edited(): void
    {
        $deliverable = $this->published();

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->patch("/deliverables/{$deliverable->id}", ['priority' => 'high'])
            ->assertForbidden();
    }

    // ── Scoping ────────────────────────────────────────────────────────────

    /** A creative holds no `.all`, so this is the whole mechanism. */
    public function test_a_creative_sees_only_their_own_work(): void
    {
        [$mine, $creative] = $this->assigned();
        [$theirs] = $this->assigned();

        $this->actingAs($creative)->get("/deliverables/{$mine->id}")->assertOk();
        $this->actingAs($creative)->get("/deliverables/{$theirs->id}")->assertForbidden();
    }

    /**
     * The anchor should be able to see whether their own interview ever went
     * out, without being assigned the editing work.
     */
    public function test_the_anchor_can_see_deliverables_cut_from_their_interview(): void
    {
        [$deliverable] = $this->assigned();
        $anchor = User::find($deliverable->deliverableOf->anchor_id);

        $this->actingAs($anchor)->get("/deliverables/{$deliverable->id}")->assertOk();
    }

    /**
     * The list must show what the policy allows.
     *
     * A manager is neither the assignee nor the assigner of the work they
     * commissioned — they booked the interview it came from. The policy lets
     * them open it, so if the list did not show it they could reach a record by
     * URL that their own board never offered.
     */
    public function test_a_manager_sees_the_work_from_interviews_they_booked(): void
    {
        [$deliverable] = $this->assigned();

        $this->actingAs($this->manager())
            ->get('/deliverables')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($deliverable) {
                $ids = collect($page->toArray()['props']['deliverables']['data'])->pluck('id');

                $this->assertContains($deliverable->id, $ids);
            });

        // And the two agree: the same record opens.
        $this->actingAs($this->manager())->get("/deliverables/{$deliverable->id}")->assertOk();
    }

    /** The converse: a stranger's work stays off the board. */
    public function test_a_manager_does_not_see_work_from_interviews_they_did_not_book(): void
    {
        [$deliverable] = $this->assigned();
        $stranger = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);

        $this->actingAs($stranger)
            ->get('/deliverables')
            ->assertInertia(function (AssertableInertia $page) use ($deliverable) {
                $ids = collect($page->toArray()['props']['deliverables']['data'])->pluck('id');

                $this->assertNotContains($deliverable->id, $ids);
            });
    }

    public function test_the_content_team_sees_every_deliverable(): void
    {
        [$deliverable] = $this->assigned();

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->get("/deliverables/{$deliverable->id}")
            ->assertOk();
    }

    /**
     * Eager loading must resolve the parent, not silently drop it.
     *
     * Every list eager-loads `deliverableOf`. When the relation was renamed to
     * fix the column names, eager loading stored the parent under one key while
     * the accessor read another — so every card on the board read "Unattached"
     * with no error anywhere. Asserting both paths agree is what stops that
     * coming back.
     */
    public function test_the_parent_resolves_whether_eager_loaded_or_not(): void
    {
        [$deliverable] = $this->assigned();
        $expected = $deliverable->deliverableOf->title;

        $lazy = Deliverable::find($deliverable->id);
        $eager = Deliverable::with('deliverableOf')->find($deliverable->id);

        $this->assertSame($expected, $lazy->parentLabel());
        $this->assertSame($expected, $eager->parentLabel(), 'Eager loading must not lose the parent.');
        $this->assertNotSame('Unattached', $eager->parentLabel());
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commission(Interview $interview, array $overrides = []): array
    {
        return [
            'parent_type' => 'interview',
            'parent_id' => $interview->id,
            'kind' => DeliverableKind::Reel->value,
            'quantity' => 1,
            'priority' => 'normal',
            ...$overrides,
        ];
    }

    private function interview(
        InterviewKind $kind = InterviewKind::CelebritySocial,
        InterviewStage $stage = InterviewStage::Conducted,
    ): Interview {
        return Interview::create([
            'code' => app(CodeGenerator::class)->next('INT'),
            'kind' => $kind,
            'title' => 'Interview '.fake()->unique()->words(3, true),
            'subject_name' => fake()->name(),
            'anchor_id' => $this->userWithRole(Roles::ANCHOR)->id,
            'scheduled_by' => $this->manager()->id,
            'scheduled_at' => now()->subDay(),
            'mode' => $kind->defaultMode(),
            'stage' => $stage,
            'stage_entered_at' => now(),
            'priority' => 'normal',
        ]);
    }

    private function article(): Article
    {
        return Article::create([
            'code' => app(CodeGenerator::class)->next('ART'),
            'title' => 'An article '.fake()->unique()->words(3, true),
            'writer_id' => $this->userWithRole(Roles::CONTENT)->id,
            'stage' => ArticleStage::Drafting,
            'stage_entered_at' => now(),
            'priority' => 'normal',
        ]);
    }

    /**
     * A deliverable assigned to a creative, at pending.
     *
     * @return array{0: Deliverable, 1: User}
     */
    private function assigned(): array
    {
        $interview = $this->interview();
        $creative = $this->userWithRole(Roles::CREATIVE);

        $this->actingAs(User::find($interview->anchor_id))
            ->post('/deliverables', $this->commission($interview, ['assigned_to' => $creative->id]));

        return [Deliverable::latest('id')->first(), $creative];
    }

    private function inReview(): Deliverable
    {
        [$deliverable, $creative] = $this->assigned();

        $this->actingAs($creative)->post("/deliverables/{$deliverable->id}/files", [
            'url' => 'https://drive.google.com/file/d/cut/view',
        ]);

        $deliverable->update(['caption' => 'A minute with the loom.']);

        $this->actingAs($creative)->post("/deliverables/{$deliverable->id}/submit");

        return $deliverable->fresh();
    }

    private function approved(): Deliverable
    {
        $deliverable = $this->inReview();

        $this->actingAs($this->manager())
            ->post("/deliverables/{$deliverable->id}/approve");

        return $deliverable->fresh();
    }

    private function published(): Deliverable
    {
        $deliverable = $this->approved();

        $this->actingAs(User::find($deliverable->assigned_to))
            ->post("/deliverables/{$deliverable->id}/publish", [
                'platform' => 'instagram',
                'url' => 'https://www.instagram.com/p/'.fake()->unique()->lexify('??????').'/',
            ]);

        return $deliverable->fresh();
    }
}
