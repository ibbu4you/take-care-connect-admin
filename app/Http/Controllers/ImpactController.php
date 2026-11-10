<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Interview;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\SocialPost;
use App\Support\Workflow\CallOutcome;
use App\Support\Workflow\InterviewKind;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the work added up to — for the trustees, the donors and the annual report.
 *
 * Every other report here measures the team: who is swamped, where work sits.
 * This one measures the mission: how many small businesses were reached, how
 * many had their story told, and where they are. There is no money on it
 * because none changes hands — the interviews are free.
 *
 * A business is "featured" when its interview is conducted: that is the moment
 * the team has given it something, whether the result is an article or a reel.
 */
class ImpactController extends Controller
{
    public function __invoke(Request $request): Response
    {
        [$from, $to, $months] = $this->window($request);

        $featured = $this->featured($from, $to);

        return Inertia::render('Reports/Impact', [
            'window' => [
                'months' => $months,
                'label' => $months === 1 ? 'This month' : "Last {$months} months",
            ],
            'options' => [1, 3, 6, 12],

            'totals' => [
                'reached' => Lead::whereBetween('created_at', [$from, $to])->count(),
                'spoke_to' => LeadCall::connected()->whereBetween('called_at', [$from, $to])->distinct()->count('lead_id'),
                'featured' => $featured->count(),
                'stories' => Article::whereBetween('published_at', [$from, $to])->count(),
                'posts' => SocialPost::whereBetween('posted_at', [$from, $to])->count(),
                'cities' => $featured->pluck('city')->filter()->unique()->count(),
            ],

            // Each step counted in the window on its own terms — these are not
            // the same businesses moving down a funnel, which a three-month
            // window would only show for the ones that happened to start in it.
            'journey' => [
                ['label' => 'Businesses added', 'value' => Lead::whereBetween('created_at', [$from, $to])->count()],
                ['label' => 'Spoken to', 'value' => LeadCall::connected()->whereBetween('called_at', [$from, $to])->distinct()->count('lead_id')],
                ['label' => 'Said yes', 'value' => LeadCall::where('outcome', CallOutcome::Interested->value)->whereBetween('called_at', [$from, $to])->distinct()->count('lead_id')],
                ['label' => 'Interviews booked', 'value' => Interview::whereBetween('created_at', [$from, $to])->count()],
                ['label' => 'Interviewed', 'value' => $featured->count()],
                ['label' => 'Stories published', 'value' => Article::whereBetween('published_at', [$from, $to])->count()],
            ],

            'trend' => $this->trend(),
            'cities' => $this->tally($featured, 'city'),
            'trades' => $this->tally($featured, 'trade'),
            'kinds' => collect(InterviewKind::cases())
                ->map(fn (InterviewKind $kind) => [
                    'label' => $kind->label(),
                    'value' => $featured->where('kind', $kind->value)->count(),
                ])
                ->values(),

            'stories' => Article::with('interview:id,subject_name,subject_company,client_id', 'interview.client:id,name,city')
                ->whereNotNull('published_at')
                ->latest('published_at')
                ->limit(8)
                ->get()
                ->map(fn (Article $article) => [
                    'id' => $article->id,
                    'title' => $article->title,
                    'business' => $article->interview?->subject_company ?? $article->interview?->client?->name,
                    'city' => $article->city ?? $article->interview?->client?->city,
                    'published_at' => $article->published_at?->toIso8601String(),
                    'url' => $article->published_url,
                ]),
        ]);
    }

    /** The featured businesses in the window, one row each — the report's raw material. */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to, $months] = $this->window($request);
        $rows = $this->featured($from, $to);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // So Excel opens it as UTF-8.
            fputcsv($out, ['Interviewed on', 'Business', 'Person', 'City', 'Trade', 'Kind', 'Story link']);

            foreach ($rows as $row) {
                fputcsv($out, [$row['date'], $row['business'], $row['person'], $row['city'], $row['trade'], $row['kind_label'], $row['url']]);
            }

            fclose($out);
        }, "impact-last-{$months}-months.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Interviews conducted in the window, with where and what each business is.
     *
     * City and trade come from the client record where there is one, and from
     * the article otherwise — a walk-in interview often has no client at all.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function featured(Carbon $from, Carbon $to): Collection
    {
        $interviews = Interview::with(['client:id,name,city,trade'])
            ->whereBetween('conducted_at', [$from, $to])
            ->orderBy('conducted_at')
            ->get();

        // Loaded once and looked up, rather than one query per interview.
        $articles = Article::with('category:id,name')
            ->whereIn('interview_id', $interviews->pluck('id'))
            ->get()
            ->keyBy('interview_id');

        return $interviews
            ->map(function (Interview $interview) use ($articles) {
                $article = $articles->get($interview->id);

                return [
                    'date' => $interview->conducted_at?->toDateString(),
                    'business' => $interview->subject_company ?? $interview->client?->name ?? $interview->subject_name,
                    'person' => $interview->subject_name,
                    'city' => $this->clean($interview->client?->city ?? $article?->city),
                    'trade' => $this->clean($interview->client?->trade ?? $article?->category?->name),
                    'kind' => $interview->kind->value,
                    'kind_label' => $interview->kind->label(),
                    'url' => $article?->published_url,
                ];
            });
    }

    /**
     * Interviews and stories per month, for the last twelve months whatever the
     * window — a trend needs a year to mean anything.
     *
     * @return list<array{month: string, featured: int, stories: int}>
     */
    private function trend(): array
    {
        $months = [];

        for ($i = 11; $i >= 0; $i--) {
            $start = now()->startOfMonth()->subMonthsNoOverflow($i);
            $end = $start->copy()->endOfMonth();

            $months[] = [
                'month' => $start->format('M'),
                'featured' => Interview::whereBetween('conducted_at', [$start, $end])->count(),
                'stories' => Article::whereBetween('published_at', [$start, $end])->count(),
            ];
        }

        return $months;
    }

    /**
     * The top values of one field, largest first, with the rest folded in.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{label: string, value: int}>
     */
    private function tally(Collection $rows, string $field): array
    {
        $counts = $rows->groupBy(fn (array $row) => $row[$field] ?? 'Not recorded')
            ->map->count()
            ->sortDesc();

        $top = $counts->take(8);
        $rest = $counts->slice(8)->sum();

        return $top->map(fn (int $value, string $label) => ['label' => $label, 'value' => $value])
            ->values()
            ->when($rest > 0, fn (Collection $list) => $list->push(['label' => 'Others', 'value' => $rest]))
            ->all();
    }

    /** "madurai " and "Madurai" are the same city on a chart. */
    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_convert_case($value, MB_CASE_TITLE);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: int} */
    private function window(Request $request): array
    {
        $months = (int) $request->query('months', 12);
        $months = in_array($months, [1, 3, 6, 12], true) ? $months : 12;

        return [now()->startOfMonth()->subMonthsNoOverflow($months - 1), now()->endOfDay(), $months];
    }
}
