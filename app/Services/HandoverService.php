<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Assignment;
use App\Models\Interview;
use App\Models\User;
use App\Support\Notifier;
use App\Support\Settings;
use App\Support\Workflow\ArticleStage;
use App\Support\Workflow\InterviewStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Passing an interview to the content team.
 *
 * ── What a handover has to carry ───────────────────────────────────────────
 * The transcript, and a recording (uploaded or linked). A business interview
 * cannot reach the content team without both, because a writer who opens the
 * queue and finds a title and nothing else has been given a job they cannot
 * start.
 *
 * ── Why the article is created here ────────────────────────────────────────
 * Left to be created by hand, a handover becomes an item in somebody's head:
 * the interview claims to be with the content team and there is nothing in the
 * queue to show for it. Creating it inside the same transaction means the
 * handover either produces a real piece of work or does not happen at all.
 */
class HandoverService
{
    public function __construct(
        private readonly CodeGenerator $codes,
        private readonly StageMachine $stages,
    ) {}

    /**
     * Hand an interview to the content team, creating the article it becomes.
     *
     * @throws ValidationException when a required piece is missing
     */
    public function handOver(
        Interview $interview,
        User $by,
        ?User $writer = null,
        ?string $note = null,
    ): Article {
        $missing = $interview->missingForHandover();

        if ($missing !== []) {
            /*
             | Refused under `handover` rather than a field name.
             |
             | Nothing on the handover form is wrong — the interview is
             | incomplete — so the message belongs to the action, and the page
             | shows it above the dialog rather than under an input.
             */
            throw ValidationException::withMessages([
                'handover' => 'Still missing '.$this->readableList($missing).'.',
            ]);
        }

        return DB::transaction(function () use ($interview, $by, $writer, $note): Article {
            $this->stages->transition($interview, InterviewStage::HandedOver, $by, $note);

            $interview->forceFill([
                'handed_over_at' => now(),
                'content_owner_id' => $writer?->getKey() ?? $interview->content_owner_id,
            ])->save();

            $article = $this->createArticle($interview, $by, $writer);

            if ($writer !== null) {
                $this->assign($interview, $writer, $by, 'content', $note);

                Notifier::send(
                    [$writer],
                    'handover',
                    "{$interview->code} is yours to write up",
                    $note,
                    "/articles/{$article->id}",
                    $by,
                );
            } else {
                /*
                 | Unassigned work still has to reach somebody.
                 |
                 | Sent to whoever can review rather than to a named person, or
                 | it sits in a shared queue nobody is watching.
                 */
                Notifier::toPermission(
                    'articles.approve',
                    'handover',
                    "{$interview->code} is ready to write up",
                    $note,
                    "/articles/{$article->id}",
                    $by,
                );
            }

            return $article;
        });
    }

    /**
     * Record who is holding one part of an interview.
     *
     * Kept alongside the denormalised `anchor_id` / `content_owner_id` columns:
     * those answer "whose desk is it on now" in one index, this is the history
     * of how it got there.
     */
    public function assign(
        Interview $interview,
        User $to,
        User $by,
        string $role,
        ?string $brief = null,
    ): Assignment {
        return DB::transaction(function () use ($interview, $to, $by, $role, $brief): Assignment {
            $column = match ($role) {
                'anchor' => 'anchor_id',
                'content' => 'content_owner_id',
                'creative' => 'creative_owner_id',
                default => throw new \InvalidArgumentException("Unknown assignment role [{$role}]."),
            };

            $interview->forceFill([$column => $to->getKey()])->save();

            return $interview->assignments()->create([
                'assigned_to' => $to->getKey(),
                'assigned_by' => $by->getKey(),
                'role' => $role,
                'state' => 'open',
                'due_on' => $interview->due_on,
                'brief' => $brief,
            ]);
        });
    }

    /**
     * The article the content team will write.
     *
     * ── Why the draft is empty ─────────────────────────────────────────────
     * It used to be seeded with the anchor's Q&A folded into prose. The
     * transcript arrives as an uploaded file now and the writer works in Word,
     * so there is nothing to seed and nowhere to put it. The anchor's brief
     * travels as source material instead — visible beside the transcript, where
     * a writer looks for it.
     */
    private function createArticle(Interview $interview, User $by, ?User $writer): Article
    {
        $article = Article::create([
            'code' => $this->codes->next('ART'),
            'interview_id' => $interview->getKey(),
            'title' => $interview->title,
            'writer_id' => $writer?->getKey(),
            'stage' => ArticleStage::Assigned,
            'stage_entered_at' => now(),

            /*
             | The interview's deadline if it has one, otherwise one from
             | settings.
             |
             | An article with no due date never appears on an overdue list and
             | never reaches the morning digest, so it can sit for a month with
             | nothing in the system noticing.
             */
            'due_on' => $interview->due_on
                ?? now()->addDays(Settings::int('articles.turnaround_days'))->startOfDay(),

            'priority' => $interview->priority,
            'city' => $interview->client?->city,

            // A craftsman profile when there is a business behind it, an
            // ordinary blog post otherwise. The writer can change it.
            'target_type' => $interview->client_id !== null ? 'business' : 'post',
            // No `status`: an article has one stage, and the public site's
            // status is derived from it on export. A `'status' => 'draft'` here
            // was silently dropped in the app (not fillable) but crashed
            // DemoSeeder on a fresh install, where seeding runs unguarded.
        ]);

        $this->stages->recordCreation($article, $by, "Created from {$interview->code}");

        return $article;
    }

    /**
     * "the transcript and the recording link"
     *
     * @param  list<string>  $items
     */
    private function readableList(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
