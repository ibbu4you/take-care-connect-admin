<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Category;
use App\Models\User;
use App\Services\StageMachine;
use App\Support\Notifier;
use App\Support\Roles;
use App\Support\Scope;
use App\Support\Workflow\ArticleStage;
use App\Support\Workflow\InterviewStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArticleController extends Controller
{
    public function __construct(
        private readonly StageMachine $stages,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Article::class);

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'stage' => (string) $request->query('stage', ''),
            'writer' => (string) $request->query('writer', ''),
        ];

        $articles = $this->scopedQuery($request->user())
            ->with(['writer:id,name', 'category:id,name', 'interview:id,code'])
            ->when($filters['search'] !== '', function (Builder $query) use ($filters) {
                $term = "%{$filters['search']}%";

                $query->where(function (Builder $scoped) use ($term) {
                    $scoped->where('title', 'like', $term)->orWhere('code', 'like', $term);
                });
            })
            ->when($filters['stage'] !== '', fn (Builder $query) => $query->where('stage', $filters['stage']))
            ->when($filters['writer'] !== '', fn (Builder $query) => $query->where('writer_id', $filters['writer']))
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Article $article) => $this->row($article));

        return Inertia::render('Articles/Index', [
            'articles' => $articles,
            'filters' => $filters,
            'stages' => ArticleStage::options(),
            'writers' => $this->writerOptions(),
            'counts' => $this->counts($request->user()),
        ]);
    }

    /**
     * The content desk.
     *
     * Grouped by who has to act rather than listed by date, because that is the
     * question the team actually asks each morning: what is waiting on a writer,
     * what is waiting on a reviewer, and what is cleared to publish.
     */
    public function queue(Request $request): Response
    {
        $this->authorize('viewAny', Article::class);

        $user = $request->user();

        $articles = $this->scopedQuery($user)
            ->with(['writer:id,name', 'interview:id,code'])
            ->open()
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->get()
            ->map(fn (Article $article) => $this->row($article));

        return Inertia::render('Articles/Queue', [
            'mine' => $articles->where('writer.id', $user->id)
                ->whereIn('stage', [ArticleStage::Assigned->value, ArticleStage::Drafting->value, ArticleStage::Revisions->value])
                ->values(),
            'awaitingReview' => $articles->where('stage', ArticleStage::InternalReview->value)->values(),
            'approved' => $articles->where('stage', ArticleStage::Approved->value)->values(),
            'unassigned' => $articles->whereNull('writer')->values(),
        ]);
    }

    /** The editor. One page for reading and writing — see the note on QaCard. */
    public function edit(Request $request, Article $article): Response
    {
        $this->authorize('view', $article);

        $article->load([
            'writer:id,name',
            'reviewer:id,name',
            'category:id,name',
            'interview:id,code,subject_name,recording_url,qa_summary',
            'interview.assets',
            'assets.uploader:id,name',
            'stageEvents.changedBy:id,name',
        ]);

        return Inertia::render('Articles/Editor', [
            'article' => [
                ...$this->row($article),
                'city' => $article->city,
                'category_id' => $article->category_id,
                'review_note' => $article->review_note,
                'published_url' => $article->published_url,
                'published_at' => $article->published_at?->toIso8601String(),
                'missing_for_review' => $article->missingForReview(),

                // The anchor's material, so the writer does not have to open the
                // interview in another tab to check a quote.
                'source' => $article->interview ? [
                    'code' => $article->interview->code,
                    'id' => $article->interview_id,
                    'subject_name' => $article->interview->subject_name,
                    'recording_url' => $article->interview->recording_url,

                    /*
                     | The anchor's brief.
                     |
                     | It used to be copied into the article body as a starting
                     | paragraph. With no editor, a body is a field nobody can
                     | read — so it travels as what it always was: a note from
                     | the person who was in the room.
                     */
                    'brief' => $article->interview->qa_summary,

                    /*
                     | The transcript, rather than typed Q&A.
                     |
                     | The anchor runs the recording through Turboscript and
                     | uploads the export, so what the writer needs from here is
                     | a way to open that file — not a copy of it pasted into a
                     | dialog.
                     */
                    'transcripts' => $article->interview->assets
                        ->whereIn('kind', ['transcript', 'document'])
                        ->map(fn ($asset) => [
                            'id' => $asset->id,
                            'label' => $asset->label ?: $asset->original_filename ?: 'Transcript',
                            'url' => $asset->target(),
                        ])
                        ->values(),
                ] : null,

                'assets' => $article->assets->map(fn ($asset) => [
                    'id' => $asset->id,
                    'kind' => $asset->kind,
                    'label' => $asset->label,
                    'alt_text' => $asset->alt_text,
                    'is_link' => $asset->isLink(),
                    'url' => $asset->target(),
                    'original_filename' => $asset->original_filename,
                    'size_bytes' => $asset->size_bytes,
                    'uploader' => $asset->uploader?->name,
                    'created_at' => $asset->created_at?->toIso8601String(),
                ]),


                'history' => $article->stageEvents->map(fn ($event) => [
                    'id' => $event->id,
                    'from' => $event->from_stage,
                    'to' => $event->to_stage,
                    'note' => $event->note,
                    'by' => $event->changedBy?->name,
                    'at' => $event->changed_at?->toIso8601String(),
                ]),
            ],

            'categories' => Category::where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name']),
            'writers' => $this->writerOptions(),

            'can' => [
                'update' => $request->user()->can('update', $article),
                'submit' => $request->user()->can('submit', $article),
                'review' => $request->user()->can('review', $article),
                'approve' => $request->user()->can('approve', $article),
                'publish' => $request->user()->can('publish', $article),
                'assign' => $request->user()->can('assign', $article),
                'upload' => $request->user()->can('uploadAssets', $article),
            ],
        ]);
    }

    public function update(Request $request, Article $article): RedirectResponse
    {
        $this->authorize('update', $article);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'city' => ['nullable', 'string', 'max:255'],
            'target_type' => ['required', Rule::in(['post', 'business'])],
            'due_on' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($request, $article, $validated) {
            $article->fill($validated);
            $article->save();

            /*
             | Saving is the moment work actually starts — or restarts after a
             | round of notes — so the stage follows rather than waiting for
             | somebody to remember to move it.
             |
             | Covering `revisions` as well as `assigned` is what makes the
             | history read as revisions -> drafting -> review on every round,
             | rather than jumping straight back to review from the notes.
             */
            if (in_array($article->stage, [ArticleStage::Assigned, ArticleStage::Revisions], true)) {
                $this->startDrafting($article, $request->user());
            }
        });

        return back()->with('success', 'Saved.');
    }

    /** Send it for review, snapshotting what the reviewer is being shown. */
    public function submit(Request $request, Article $article): RedirectResponse
    {
        $this->authorize('submit', $article);

        $missing = $article->missingForReview();

        if ($missing !== []) {
            return back()->withErrors([
                'review' => 'Still missing '.$this->sentenceList($missing).'.',
            ]);
        }

        DB::transaction(function () use ($request, $article) {

            $article->forceFill([
                'submitted_at' => now(),
                // Cleared on submission: the previous round's notes have been
                // acted on, and leaving them at the top of the editor makes the
                // next reviewer think they are current.
                'review_note' => null,
            ])->save();

            $this->stages->transition($article, ArticleStage::InternalReview, $request->user());
        });

        Notifier::toPermission(
            'articles.approve',
            'review',
            "{$article->code} is ready to review",
            $article->title,
            "/articles/{$article->id}",
            $request->user(),
        );

        return back()->with('success', 'Sent for review.');
    }

    public function approve(Request $request, Article $article): RedirectResponse
    {
        $this->authorize('approve', $article);

        DB::transaction(function () use ($request, $article) {
            $article->forceFill([
                'reviewer_id' => $request->user()->id,
                'approved_at' => now(),
            ])->save();

            $this->stages->transition($article, ArticleStage::Approved, $request->user());
        });

        Notifier::send(
            [$article->writer],
            'approved',
            "{$article->code} approved",
            'Ready to publish on the website.',
            "/articles/{$article->id}",
            $request->user(),
        );

        return back()->with('success', 'Approved. Export the bundle to publish it on the website.');
    }

    /** Send it back with notes. */
    public function requestRevisions(Request $request, Article $article): RedirectResponse
    {
        $this->authorize('review', $article);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ], [
            'note.required' => 'Say what needs changing — sending it back with no notes helps nobody.',
        ]);

        DB::transaction(function () use ($request, $article, $validated) {
            $article->forceFill([
                'reviewer_id' => $request->user()->id,
                'review_note' => $validated['note'],
            ])->save();

            $this->stages->transition($article, ArticleStage::Revisions, $request->user(), $validated['note']);
        });

        Notifier::send(
            [$article->writer],
            'revisions',
            "{$article->code} needs changes",
            $validated['note'],
            "/articles/{$article->id}",
            $request->user(),
        );

        return back()->with('success', 'Sent back to the writer.');
    }

    /**
     * Mark it live, and record where.
     *
     * The URL is required. Without it this system cannot tell a published
     * article from an approved one that nobody got round to — and "is it live?"
     * becomes a question somebody has to answer by searching the website.
     */
    public function publish(Request $request, Article $article): RedirectResponse
    {
        $this->authorize('publish', $article);

        $validated = $request->validate([
            'published_url' => ['required', 'url', 'max:512'],
            'published_at' => ['nullable', 'date'],
        ], [
            'published_url.required' => 'Paste the live URL — that is what marks this finished.',
        ]);

        DB::transaction(function () use ($request, $article, $validated) {
            $article->forceFill([
                'published_url' => $validated['published_url'],
                'published_at' => $validated['published_at'] ?? now(),
            ])->save();

            $this->stages->transition($article, ArticleStage::Published, $request->user(), $validated['published_url']);

            /*
             | The interview follows the article to published.
             |
             | Without this the interview sits at "with content" forever, and the
             | anchor's own queue keeps showing finished work as outstanding.
             */
            $interview = $article->interview;

            if ($interview && ! $interview->stage->isTerminal()) {
                $this->stages->transition(
                    $interview,
                    InterviewStage::Published,
                    $request->user(),
                    "Article {$article->code} published",
                    $interview->allowedNextStages(),
                );
            }
        });

        // The anchor whose interview this was should hear that it went live —
        // it is the only moment they find out their work reached the public.
        Notifier::send(
            [$article->writer, $article->interview?->anchor],
            'published',
            "{$article->code} is live",
            $article->title,
            "/articles/{$article->id}",
            $request->user(),
        );

        return back()->with('success', 'Published. Nice work.');
    }

    public function assign(Request $request, Article $article): RedirectResponse
    {
        $this->authorize('assign', $article);

        $validated = $request->validate([
            'writer_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $article->update(['writer_id' => $validated['writer_id']]);

        return back()->with('success', 'Assigned.');
    }


    /**
     * Move the article to drafting, and the interview alongside it.
     *
     * The two stay in step so the anchor can see, from their own queue, that
     * somebody has actually picked the work up.
     */
    private function startDrafting(Article $article, User $by): void
    {
        $this->stages->transition($article, ArticleStage::Drafting, $by);

        $interview = $article->interview;

        if ($interview && $interview->stage === InterviewStage::HandedOver) {
            $this->stages->transition(
                $interview,
                InterviewStage::InContent,
                $by,
                "Writing started on {$article->code}",
                $interview->allowedNextStages(),
            );
        }
    }

    /**
     * @return Builder<Article>
     */
    private function scopedQuery(User $user): Builder
    {
        return Scope::apply(Article::query(), $user, 'articles', ['writer_id', 'reviewer_id']);
    }

    /**
     * @return array<string, int>
     */
    private function counts(User $user): array
    {
        return [
            'awaitingReview' => (clone $this->scopedQuery($user))->awaitingReview()->count(),
            'mine' => (clone $this->scopedQuery($user))
                ->where('writer_id', $user->id)
                ->open()
                ->count(),
            'publishedThisMonth' => (clone $this->scopedQuery($user))
                ->whereBetween('published_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'open' => (clone $this->scopedQuery($user))->open()->count(),
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    private function writerOptions(): Collection
    {
        return User::query()
            ->active()
            ->role(Roles::CONTENT)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name]);
    }

    /**
     * @param  list<string>  $items
     */
    private function sentenceList(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Article $article): array
    {
        return [
            'id' => $article->id,
            'code' => $article->code,
            'title' => $article->title,
            'stage' => $article->stage->value,
            'stage_label' => $article->stage->label(),
            'target_type' => $article->target_type,
            'writer' => $article->writer?->only(['id', 'name']),
            'reviewer' => $article->reviewer?->only(['id', 'name']),
            'category' => $article->category?->name,
            'due_on' => $article->due_on?->toDateString(),
            'priority' => $article->priority,
            'interview_code' => $article->interview?->code,
            'published_url' => $article->published_url,
            'updated_at' => $article->updated_at?->toIso8601String(),
        ];
    }
}
