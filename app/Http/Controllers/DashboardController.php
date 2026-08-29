<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Deal;
use App\Models\Deliverable;
use App\Models\FollowUp;
use App\Models\Interview;
use App\Models\Lead;
use App\Models\Target;
use App\Models\User;
use App\Support\Scope;
use App\Support\Workflow\ArticleStage;
use App\Support\Workflow\DeliverableStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The landing screen.
 *
 * ── Composed from permissions, not from role names ──────────────────────────
 * Each panel appears because the viewer holds the permission that panel is
 * about, never because they "are an Anchor". Somebody holding two roles — common
 * in a small team, where the person booking interviews also cuts the reels —
 * gets both sets of tiles rather than whichever role happened to be checked
 * first.
 *
 * ── Every figure is scoped the same way its module is ───────────────────────
 * A tile that counted rows the viewer cannot open would be worse than no tile:
 * they would click it, land on an empty list, and stop trusting the numbers.
 * So each query runs through the same {@see Scope} the module's own list uses.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $panels = [
            'sales' => $user->canAny(['deals.view', 'deals.view.all']),
            'leads' => $user->canAny(['leads.view', 'leads.view.all']),
            'interviews' => $user->canAny(['interviews.view', 'interviews.view.all']),
            'content' => $user->canAny(['articles.view', 'articles.view.all']),
            'creative' => $user->canAny(['deliverables.view', 'deliverables.view.all']),
            'organisation' => $user->can('reports.view.all'),
        ];

        return Inertia::render('Dashboard', [
            'greeting' => $this->greeting(),
            'panels' => $panels,

            // Only the sections this person can see are computed at all — an
            // anchor's dashboard should not be running five sales aggregates
            // whose results are then thrown away.
            'sales' => $panels['sales'] ? $this->sales($user) : null,
            'interviews' => $panels['interviews'] ? $this->interviews($user) : null,
            'content' => $panels['content'] ? $this->content($user) : null,
            'creative' => $panels['creative'] ? $this->creative($user) : null,

            'attention' => $this->attention($user, $panels),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sales(User $user): array
    {
        $open = $this->scope(Deal::query(), $user, 'deals', ['owner_id'])->open()->get();

        $won = (float) $this->scope(Deal::query(), $user, 'deals', ['owner_id'])
            ->won()
            ->whereBetween('closed_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('actual_value');

        $target = Target::where('user_id', $user->id)
            ->where('period', Target::currentPeriod())
            ->value('revenue_target');

        return [
            'open_count' => $open->count(),
            'pipeline_value' => round($open->sum(fn (Deal $deal) => (float) $deal->expected_value), 2),
            'weighted_value' => round($open->sum(fn (Deal $deal) => $deal->weightedValue()), 2),
            'won_this_month' => $won,
            'revenue_target' => $target !== null ? (float) $target : null,
            'attainment' => $target > 0 ? (int) round(($won / (float) $target) * 100) : null,
            'idle_count' => $this->scope(Deal::query(), $user, 'deals', ['owner_id'])->idle()->count(),
            'follow_ups_due' => FollowUp::where('user_id', $user->id)->due()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function interviews(User $user): array
    {
        $columns = ['anchor_id', 'scheduled_by', 'content_owner_id', 'creative_owner_id'];

        return [
            'this_week' => $this->scope(Interview::query(), $user, 'interviews', $columns)
                ->whereBetween('scheduled_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->open()
                ->count(),
            'awaiting_handover' => $this->scope(Interview::query(), $user, 'interviews', $columns)
                ->awaitingHandover()
                ->count(),
            'conducted_this_month' => $this->scope(Interview::query(), $user, 'interviews', $columns)
                ->whereBetween('conducted_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'open' => $this->scope(Interview::query(), $user, 'interviews', $columns)->open()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function content(User $user): array
    {
        $columns = ['writer_id', 'reviewer_id'];

        return [
            'handovers_waiting' => $this->scope(Article::query(), $user, 'articles', $columns)
                ->where('stage', ArticleStage::Assigned->value)
                ->count(),
            'my_drafts' => $this->scope(Article::query(), $user, 'articles', $columns)
                ->where('writer_id', $user->id)
                ->whereIn('stage', [ArticleStage::Drafting->value, ArticleStage::Revisions->value])
                ->count(),
            'in_review' => $this->scope(Article::query(), $user, 'articles', $columns)
                ->awaitingReview()
                ->count(),
            'published_this_month' => $this->scope(Article::query(), $user, 'articles', $columns)
                ->whereBetween('published_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function creative(User $user): array
    {
        /*
         | Deliverables are scoped through the model, not through Scope directly.
         |
         | Their visibility includes "somebody on the parent interview", which is
         | a relationship rather than a column — so the rule lives in one place
         | and the dashboard, the board and the policy all read it. Duplicating
         | the column list here is exactly how a tile ends up reading zero while
         | the board it links to has six cards on it.
         */
        return [
            'assigned_to_me' => Deliverable::visibleTo($user)
                ->where('assigned_to', $user->id)
                ->open()
                ->count(),
            'in_review' => Deliverable::visibleTo($user)
                ->where('stage', DeliverableStage::Review->value)
                ->count(),
            'awaiting_publish' => Deliverable::visibleTo($user)->awaitingPublish()->count(),
            'published_this_month' => Deliverable::visibleTo($user)
                ->whereBetween('published_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
        ];
    }

    /**
     * The one list on this page that is not a number: things actually blocked.
     *
     * A dashboard of counts tells somebody how they are doing. This tells them
     * what to open next, which is the only reason to look at it before ten in
     * the morning.
     *
     * @param  array<string, bool>  $panels
     * @return list<array<string, mixed>>
     */
    private function attention(User $user, array $panels): array
    {
        $items = [];

        if ($panels['interviews']) {
            $stuck = $this->scope(Interview::query(), $user, 'interviews', [
                'anchor_id', 'scheduled_by', 'content_owner_id', 'creative_owner_id',
            ])->awaitingHandover()->with('anchor:id,name')->limit(5)->get();

            foreach ($stuck as $interview) {
                // The reason it is stuck, not just that it is — otherwise the
                // reader has to open it to find out what to do.
                $missing = $interview->missingForHandover();

                $items[] = [
                    'kind' => 'interview',
                    'title' => $interview->title,
                    'detail' => $missing === []
                        ? 'Ready to hand over'
                        : 'Missing '.$this->sentenceList($missing),
                    'tone' => $missing === [] ? 'success' : 'warning',
                    'url' => "/interviews/{$interview->id}",
                    'code' => $interview->code,
                ];
            }
        }

        if ($panels['leads']) {
            /*
             | A call somebody promised to make and has not.
             |
             | This belongs beside a stuck interview rather than in a tile: a
             | count of overdue calls tells a manager how they are doing, and a
             | list of who is waiting tells them what to do in the next ten
             | minutes. The second is the only reason to open a dashboard first
             | thing.
             */
            $waiting = Lead::visibleTo($user)
                ->open()
                ->whereNotNull('next_action_on')
                ->whereDate('next_action_on', '<', now())
                ->orderBy('next_action_on')
                ->limit(5)
                ->get();

            foreach ($waiting as $lead) {
                $days = (int) round($lead->next_action_on->diffInDays(now()));

                $items[] = [
                    'kind' => 'lead',
                    'title' => $lead->name,
                    'detail' => $days === 1
                        ? 'You said you would call yesterday'
                        : "You said you would call {$days} days ago",
                    'tone' => 'warning',
                    'url' => "/leads/{$lead->id}",
                    'code' => $lead->code,
                ];
            }
        }

        if ($panels['content']) {
            $overdue = $this->scope(Article::query(), $user, 'articles', ['writer_id', 'reviewer_id'])
                ->open()
                ->whereNotNull('due_on')
                ->whereDate('due_on', '<', now())
                ->limit(5)
                ->get();

            foreach ($overdue as $article) {
                $items[] = [
                    'kind' => 'article',
                    'title' => $article->title,
                    'detail' => 'Past its deadline · '.$article->stage->label(),
                    'tone' => 'danger',
                    'url' => "/articles/{$article->id}",
                    'code' => $article->code,
                ];
            }
        }

        if ($panels['creative']) {
            $rejected = Deliverable::visibleTo($user)
                ->where('stage', DeliverableStage::Rejected->value)
                ->limit(5)
                ->get();

            foreach ($rejected as $deliverable) {
                $items[] = [
                    'kind' => 'deliverable',
                    'title' => $deliverable->title ?: $deliverable->kind->label(),
                    'detail' => 'Sent back — needs changes',
                    'tone' => 'danger',
                    'url' => "/deliverables/{$deliverable->id}",
                    'code' => $deliverable->code,
                ];
            }
        }

        if ($panels['sales']) {
            $quiet = $this->scope(Deal::query(), $user, 'deals', ['owner_id'])
                ->idle()
                ->with('client:id,name')
                ->limit(5)
                ->get();

            foreach ($quiet as $deal) {
                $items[] = [
                    'kind' => 'deal',
                    'title' => $deal->title,
                    'detail' => $deal->idleDays() !== null
                        ? "Nothing for {$deal->idleDays()} days · {$deal->client?->name}"
                        : 'No activity recorded',
                    'tone' => 'warning',
                    'url' => "/deals/{$deal->id}",
                    'code' => $deal->code,
                ];
            }
        }

        // Capped so the panel stays a to-do list rather than a second inbox.
        return array_slice($items, 0, 8);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    private function scope(Builder $query, User $user, string $module, array $columns): Builder
    {
        return Scope::apply($query, $user, $module, $columns);
    }

    /**
     * "a and b", "a, b and c" — the same phrasing the interview page uses, so
     * the dashboard and the record it links to read alike.
     *
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

    private function greeting(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}
