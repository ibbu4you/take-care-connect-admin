<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleAsset;
use App\Models\Client;
use App\Models\Interview;
use App\Models\InterviewAsset;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Services\HandoverService;
use App\Support\Roles;
use App\Support\Workflow\ArticleStage;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The content pipeline: handover -> draft -> review -> published.
 *
 * ── The draft is a file ────────────────────────────────────────────────────
 * It used to be HTML typed into a rich-text editor, and most of the rules here
 * were about that HTML: sanitising it, counting its words, snapshotting it on
 * every submit. The team writes in Word and publishes on the website by hand,
 * so the draft is an uploaded document and the rules that remain are the ones
 * about the *work*: you cannot review nothing, you cannot approve your own
 * writing, and nothing counts as published without the live URL.
 */
class ArticleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    // ── The handover creates the article ───────────────────────────────────

    public function test_handing_over_an_interview_creates_the_article(): void
    {
        $interview = $this->readyInterview();
        $writer = $this->userWithRole(Roles::CONTENT);

        app(HandoverService::class)->handOver($interview, User::find($interview->anchor_id), $writer);

        $article = Article::firstOrFail();

        $this->assertSame($interview->id, $article->interview_id);
        $this->assertSame($writer->id, $article->writer_id);
        $this->assertSame(ArticleStage::Assigned, $article->stage);
        $this->assertStringStartsWith('ART-', $article->code);
    }

    /**
     * The brief reaches the writer as source material, not as a body.
     *
     * It used to be copied into the article as a starting paragraph. With no
     * editor, a body would be a field nobody could read — so the note from the
     * person who was actually in the room travels alongside the transcript,
     * where the writer looks for it.
     */
    public function test_the_anchors_brief_reaches_the_writer(): void
    {
        $interview = $this->readyInterview();
        $interview->update(['qa_summary' => 'A patient account of building a craft business.']);

        $writer = $this->userWithRole(Roles::CONTENT);
        app(HandoverService::class)->handOver($interview->refresh(), User::find($interview->anchor_id), $writer);

        $article = Article::firstOrFail();

        $this->actingAs($writer)
            ->get("/articles/{$article->id}")
            ->assertInertia(fn ($page) => $page->where(
                'article.source.brief',
                'A patient account of building a craft business.',
            ));
    }

    /** A business behind it makes it a craftsman profile, not a blog post. */
    public function test_an_interview_with_a_client_becomes_a_business_profile(): void
    {
        $interview = $this->readyInterview();
        $client = Client::create(['name' => 'Kamala Handlooms', 'city' => 'Kanchipuram']);
        $interview->update(['client_id' => $client->id]);

        app(HandoverService::class)->handOver($interview->refresh(), User::find($interview->anchor_id));

        $article = Article::firstOrFail();

        $this->assertSame('business', $article->target_type);
        $this->assertSame('Kanchipuram', $article->city);
    }

    /**
     * The handover and the article are one transaction: a handover that leaves
     * nothing in the content queue is the inconsistency this prevents.
     */
    public function test_a_refused_handover_creates_no_article(): void
    {
        $interview = $this->interview(stage: InterviewStage::Conducted);

        try {
            app(HandoverService::class)->handOver($interview, User::find($interview->anchor_id));
            $this->fail('An incomplete handover should have been refused.');
        } catch (ValidationException) {
            // Expected.
        }

        $this->assertSame(0, Article::count());
    }

    // ── Writing ────────────────────────────────────────────────────────────

    /**
     * Saving is the moment work actually starts, so the stage follows rather
     * than waiting for somebody to remember to move it.
     */
    public function test_saving_a_freshly_assigned_article_starts_drafting(): void
    {
        [$article, $writer] = $this->assignedArticle();

        $this->actingAs($writer)->patch("/articles/{$article->id}", $this->payload());

        $this->assertSame(ArticleStage::Drafting, $article->fresh()->stage);
    }

    public function test_starting_to_write_moves_the_interview_to_with_content(): void
    {
        [$article, $writer] = $this->assignedArticle();

        $this->actingAs($writer)->patch("/articles/{$article->id}", $this->payload());

        $this->assertSame(InterviewStage::InContent, $article->interview->fresh()->stage);
    }

    // ── Review ─────────────────────────────────────────────────────────────

    /** The one thing a reviewer cannot proceed without is the document. */
    public function test_submitting_is_refused_without_a_draft(): void
    {
        [$article, $writer] = $this->assignedArticle();

        $this->actingAs($writer)->patch("/articles/{$article->id}", $this->payload());

        $this->actingAs($writer)
            ->post("/articles/{$article->id}/submit")
            ->assertSessionHasErrors();

        $this->assertNotSame(ArticleStage::InternalReview, $article->fresh()->stage);
    }

    public function test_a_complete_article_can_be_submitted_for_review(): void
    {
        $article = $this->readyToSubmit();

        $this->actingAs(User::find($article->writer_id))
            ->post("/articles/{$article->id}/submit")
            ->assertSessionHasNoErrors();

        $this->assertSame(ArticleStage::InternalReview, $article->fresh()->stage);
    }

    /** A review you can grant yourself is not a review. */
    public function test_a_writer_cannot_approve_their_own_article(): void
    {
        $article = $this->inReview();

        $this->actingAs(User::find($article->writer_id))
            ->post("/articles/{$article->id}/approve")
            ->assertForbidden();

        $this->assertSame(ArticleStage::InternalReview, $article->fresh()->stage);
    }

    public function test_another_content_member_can_approve_it(): void
    {
        $article = $this->inReview();
        $reviewer = $this->userWithRole(Roles::CONTENT);

        $this->actingAs($reviewer)
            ->post("/articles/{$article->id}/approve")
            ->assertRedirect();

        $article->refresh();

        $this->assertSame(ArticleStage::Approved, $article->stage);
        $this->assertSame($reviewer->id, $article->reviewer_id);
    }

    public function test_sending_back_requires_a_note(): void
    {
        $article = $this->inReview();

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->post("/articles/{$article->id}/revisions", ['note' => ''])
            ->assertSessionHasErrors('note');
    }

    public function test_sending_back_puts_the_note_where_the_writer_will_see_it(): void
    {
        $article = $this->inReview();

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->post("/articles/{$article->id}/revisions", ['note' => 'The middle section needs a quote.']);

        $article->refresh();

        $this->assertSame(ArticleStage::Revisions, $article->stage);
        $this->assertSame('The middle section needs a quote.', $article->review_note);
    }

    public function test_resubmitting_clears_the_previous_review_note(): void
    {
        $article = $this->inReview();

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->post("/articles/{$article->id}/revisions", ['note' => 'Needs a quote.']);

        $writer = User::find($article->writer_id);

        $this->actingAs($writer)->patch("/articles/{$article->id}", $this->payload());
        $this->actingAs($writer)->post("/articles/{$article->id}/submit");

        $this->assertNull($article->fresh()->review_note);
    }

    /**
     * Every resubmission after notes has to be legal, or the second round of
     * review dead-ends in production.
     */
    public function test_an_article_can_go_round_the_review_loop_more_than_once(): void
    {
        $article = $this->inReview();
        $reviewer = $this->userWithRole(Roles::CONTENT);
        $writer = User::find($article->writer_id);

        foreach (['First pass.', 'Second pass.'] as $note) {
            $this->actingAs($reviewer)->post("/articles/{$article->id}/revisions", ['note' => $note]);
            $this->actingAs($writer)->patch("/articles/{$article->id}", $this->payload());
            $this->actingAs($writer)->post("/articles/{$article->id}/submit");
        }

        $this->assertSame(ArticleStage::InternalReview, $article->fresh()->stage);
    }

    // ── Publishing ─────────────────────────────────────────────────────────

    public function test_an_unapproved_article_cannot_be_published(): void
    {
        $article = $this->inReview();

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->post("/articles/{$article->id}/publish", [
                'published_url' => 'https://takecareconnect.com/x',
            ])
            ->assertForbidden();
    }

    /**
     * Until the URL is recorded, this system cannot tell a published article
     * from an approved one nobody got round to.
     */
    public function test_publishing_requires_the_live_url(): void
    {
        $article = $this->approved();

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->post("/articles/{$article->id}/publish", ['published_url' => ''])
            ->assertSessionHasErrors('published_url');

        $this->assertSame(ArticleStage::Approved, $article->fresh()->stage);
    }

    public function test_publishing_records_the_url_and_finishes_the_interview(): void
    {
        $article = $this->approved();
        $url = 'https://takecareconnect.com/stories/a-weaving-business';

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->post("/articles/{$article->id}/publish", ['published_url' => $url])
            ->assertRedirect();

        $article->refresh();

        $this->assertSame(ArticleStage::Published, $article->stage);
        $this->assertSame($url, $article->published_url);
        $this->assertNotNull($article->published_at);
        $this->assertSame(InterviewStage::Published, $article->interview->fresh()->stage);
    }

    public function test_a_published_article_can_no_longer_be_edited(): void
    {
        $article = $this->published();

        $this->actingAs(User::find($article->writer_id))
            ->patch("/articles/{$article->id}", $this->payload(['title' => 'Rewritten after the fact']))
            ->assertForbidden();
    }

    /** The slug is part of a live URL, so it stops moving once published. */
    public function test_the_slug_is_frozen_once_published(): void
    {
        $article = $this->published();
        $slug = $article->slug;

        $article->forceFill(['title' => 'A completely different headline'])->save();

        $this->assertSame($slug, $article->fresh()->slug);
    }

    public function test_two_articles_with_the_same_headline_get_different_slugs(): void
    {
        $first = Article::create([
            'code' => app(CodeGenerator::class)->next('ART'),
            'title' => 'A weaving business',
            'stage' => 'assigned',
            'status' => 'draft',
        ]);

        $second = Article::create([
            'code' => app(CodeGenerator::class)->next('ART'),
            'title' => 'A weaving business',
            'stage' => 'assigned',
            'status' => 'draft',
        ]);

        $this->assertNotSame($first->slug, $second->slug);
    }

    // ── Access ─────────────────────────────────────────────────────────────

    public function test_the_content_team_sees_every_article(): void
    {
        $this->assignedArticle();
        $this->assignedArticle();

        $this->actingAs($this->userWithRole(Roles::CONTENT))
            ->get('/articles')
            ->assertOk();
    }

    public function test_an_anchor_cannot_open_an_article_they_are_not_on(): void
    {
        [$article] = $this->assignedArticle();

        $this->actingAs($this->userWithRole(Roles::ANCHOR))
            ->get("/articles/{$article->id}")
            ->assertForbidden();
    }

    public function test_a_creative_cannot_reach_articles_at_all(): void
    {
        $this->actingAs($this->userWithRole(Roles::CREATIVE))
            ->get('/articles')
            ->assertForbidden();
    }


    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'A weaving business',
            'target_type' => 'post',
            ...$overrides,
        ];
    }

    /**
     * The draft, as the writer uploads it.
     *
     * The article body used to be HTML typed into the admin. The team writes in
     * Word and publishes on the website by hand, so what the reviewer reads is
     * a file — and attaching one is what makes an article ready.
     */
    private function attachDraft(Article $article, ?User $by = null): ArticleAsset
    {
        return ArticleAsset::create([
            'article_id' => $article->id,
            'kind' => 'document',
            'path' => 'articles/'.$article->id.'/draft.docx',
            'original_filename' => 'draft.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => 24_512,
            'uploaded_by' => ($by ?? User::find($article->writer_id))?->id,
        ]);
    }

    private function interview(
        InterviewKind $kind = InterviewKind::BusinessArticle,
        InterviewStage $stage = InterviewStage::Scheduled,
    ): Interview {
        return Interview::create([
            'code' => app(CodeGenerator::class)->next('INT'),
            'kind' => $kind,
            'title' => 'Interview '.fake()->unique()->word(),
            'subject_name' => fake()->name(),
            'anchor_id' => $this->userWithRole(Roles::ANCHOR)->id,
            'scheduled_by' => $this->userWithRole(Roles::RELATIONSHIP_MANAGER)->id,
            'scheduled_at' => now()->subDay(),
            'mode' => $kind->defaultMode(),
            'stage' => $stage,
            'stage_entered_at' => now(),
            'priority' => 'normal',
        ]);
    }

    /** A conducted business interview carrying all three required pieces. */
    private function readyInterview(): Interview
    {
        $interview = $this->interview(stage: InterviewStage::Conducted);

        InterviewAsset::create([
            'interview_id' => $interview->id,
            'kind' => 'document',
            'path' => 'interviews/1/write-up.docx',
            'uploaded_by' => $interview->anchor_id,
        ]);

        $interview->update([
            'recording_url' => 'https://drive.google.com/file/d/x/view',
            'qa_summary' => 'A patient account of building a craft business.',
        ]);

        return $interview->fresh();
    }

    /**
     * @return array{0: Article, 1: User}
     */
    private function assignedArticle(): array
    {
        $interview = $this->readyInterview();
        $writer = $this->userWithRole(Roles::CONTENT);

        app(HandoverService::class)->handOver($interview, User::find($interview->anchor_id), $writer);

        return [Article::latest('id')->first(), $writer];
    }

    private function readyToSubmit(): Article
    {
        [$article, $writer] = $this->assignedArticle();

        $this->actingAs($writer)->patch("/articles/{$article->id}", $this->payload());
        $this->attachDraft($article, $writer);

        return $article->fresh();
    }

    private function inReview(): Article
    {
        $article = $this->readyToSubmit();

        $this->actingAs(User::find($article->writer_id))->post("/articles/{$article->id}/submit");

        return $article->fresh();
    }

    private function approved(): Article
    {
        $article = $this->inReview();
        $reviewer = $this->userWithRole(Roles::CONTENT);

        $this->actingAs($reviewer)->post("/articles/{$article->id}/approve");

        return $article->fresh();
    }

    private function published(): Article
    {
        $article = $this->approved();

        $this->actingAs($article->reviewer)->post("/articles/{$article->id}/publish", [
            'published_url' => 'https://takecareconnect.com/blog/a-weaving-business',
        ]);

        return $article->fresh();
    }
}
