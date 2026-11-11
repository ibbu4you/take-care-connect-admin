<?php

namespace Tests\Feature;

use App\Models\Deliverable;
use App\Models\DeliverableFile;
use App\Models\Interview;
use App\Models\User;
use App\Services\CodeGenerator;
use App\Support\Roles;
use App\Support\Workflow\InterviewKind;
use App\Support\Workflow\InterviewStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Seeing the work itself: thumbnails on the board, the full-size viewer, and
 * the download.
 *
 * The preview serves uploaded files inline from the app's own origin, so the
 * guard that matters is the type check — an SVG or HTML file shown inline
 * would run script as whoever opened it.
 */
class DeliverableMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('workfiles');
    }

    public function test_an_uploaded_image_is_previewed_inline_and_downloaded_as_a_file(): void
    {
        [$deliverable, $creative] = $this->assigned();

        $this->actingAs($creative)
            ->post("/deliverables/{$deliverable->id}/files", ['file' => UploadedFile::fake()->image('poster.jpg', 800, 1000)])
            ->assertRedirect();

        $file = DeliverableFile::firstOrFail();
        $this->assertSame('image', $file->mediaType());

        $preview = $this->actingAs($creative)->get("/deliverable-files/{$file->id}/preview");
        $preview->assertOk();
        $this->assertSame('image/jpeg', $preview->headers->get('Content-Type'));
        $this->assertSame('inline', $preview->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $preview->headers->get('X-Content-Type-Options'));

        $download = $this->actingAs($creative)->get("/deliverable-files/{$file->id}");
        $download->assertOk();
        $this->assertStringStartsWith('attachment', (string) $download->headers->get('Content-Disposition'));
    }

    public function test_the_board_carries_each_cards_newest_cut(): void
    {
        [$deliverable, $creative] = $this->assigned();

        $this->actingAs($creative)->post("/deliverables/{$deliverable->id}/files", ['file' => UploadedFile::fake()->image('v1.png')]);
        $this->actingAs($creative)->post("/deliverables/{$deliverable->id}/files", ['file' => UploadedFile::fake()->image('v2.jpg')]);

        $newest = DeliverableFile::where('version', 2)->firstOrFail();

        $this->actingAs($creative)
            ->get('/deliverables/board')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('columns.1.items.0.media.version', 2)
                ->where('columns.1.items.0.media.type', 'image')
                ->where('columns.1.items.0.media.preview_url', route('deliverables.files.preview', $newest))
                ->where('columns.1.items.0.media.thumbnail_url', route('deliverables.files.preview', $newest))
                ->where('columns.1.items.0.media.download_url', route('deliverables.files.download', $newest))
            );
    }

    /** Named .png and claimed as image/png — but the bytes are SVG, and SVG can carry script. */
    public function test_a_file_that_is_not_a_safe_image_is_never_shown_inline(): void
    {
        [$deliverable, $creative] = $this->assigned();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

        $this->actingAs($creative)->post("/deliverables/{$deliverable->id}/files", [
            'file' => UploadedFile::fake()->createWithContent('poster.png', $svg)->mimeType('image/png'),
        ]);

        $file = DeliverableFile::firstOrFail();

        $this->actingAs($creative)->get("/deliverable-files/{$file->id}/preview")->assertStatus(415);

        // Still downloadable — as a download, not rendered.
        $download = $this->actingAs($creative)->get("/deliverable-files/{$file->id}");
        $download->assertOk();
        $this->assertStringStartsWith('attachment', (string) $download->headers->get('Content-Disposition'));
    }

    public function test_an_svg_or_document_is_classed_as_a_plain_file(): void
    {
        $this->assertSame('file', (new DeliverableFile(['path' => 'x/a.svg', 'original_filename' => 'a.svg', 'mime_type' => 'image/svg+xml']))->mediaType());
        $this->assertSame('file', (new DeliverableFile(['path' => 'x/a.pdf', 'original_filename' => 'a.pdf', 'mime_type' => 'application/pdf']))->mediaType());
        $this->assertSame('video', (new DeliverableFile(['path' => 'x/a.mp4', 'original_filename' => 'cut.MP4', 'mime_type' => null]))->mediaType());
    }

    public function test_somebody_who_cannot_see_the_work_cannot_preview_it(): void
    {
        [$deliverable, $creative] = $this->assigned();

        $this->actingAs($creative)->post("/deliverables/{$deliverable->id}/files", ['file' => UploadedFile::fake()->image('poster.jpg')]);

        $file = DeliverableFile::firstOrFail();
        $stranger = $this->userWithRole(Roles::CREATIVE);

        $this->actingAs($stranger)->get("/deliverable-files/{$file->id}/preview")->assertForbidden();
    }

    public function test_a_linked_cut_has_no_inline_preview_but_offers_the_hosts_still(): void
    {
        [$deliverable, $creative] = $this->assigned();

        $this->actingAs($creative)->post("/deliverables/{$deliverable->id}/files", [
            'url' => 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz012345/view?usp=sharing',
        ]);

        $file = DeliverableFile::firstOrFail();
        $media = $file->media();

        $this->assertSame('link', $media['type']);
        $this->assertNull($media['preview_url']);
        $this->assertSame('https://drive.google.com/thumbnail?id=1AbCdEfGhIjKlMnOpQrStUvWxYz012345&sz=w800', $media['thumbnail_url']);
        $this->assertSame($file->url, $media['download_url']);

        $this->actingAs($creative)->get("/deliverable-files/{$file->id}/preview")->assertNotFound();
    }

    public function test_youtube_links_get_their_video_still(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
        ] as $url) {
            $this->assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', DeliverableFile::thumbnailForUrl($url), $url);
        }

        $this->assertNull(DeliverableFile::thumbnailForUrl('https://www.instagram.com/p/abc/'));
        $this->assertNull(DeliverableFile::thumbnailForUrl(null));
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** @return array{0: Deliverable, 1: User} */
    private function assigned(): array
    {
        $manager = $this->userWithRole(Roles::RELATIONSHIP_MANAGER);
        $anchor = $this->userWithRole(Roles::ANCHOR);
        $creative = $this->userWithRole(Roles::CREATIVE);

        $interview = Interview::create([
            'code' => app(CodeGenerator::class)->next('INT'),
            'kind' => InterviewKind::CelebritySocial,
            'title' => 'Interview '.fake()->unique()->words(3, true),
            'subject_name' => fake()->name(),
            'anchor_id' => $anchor->id,
            'scheduled_by' => $manager->id,
            'scheduled_at' => now()->subDay(),
            'mode' => InterviewKind::CelebritySocial->defaultMode(),
            'stage' => InterviewStage::Conducted,
            'stage_entered_at' => now(),
            'priority' => 'normal',
        ]);

        $this->actingAs($anchor)->post('/deliverables', [
            'parent_type' => 'interview',
            'parent_id' => $interview->id,
            'kind' => 'poster',
            'quantity' => 1,
            'assigned_to' => $creative->id,
            'due_on' => now()->addWeek()->toDateString(),
            'priority' => 'normal',
        ])->assertRedirect();

        return [Deliverable::latest('id')->firstOrFail(), $creative];
    }
}
