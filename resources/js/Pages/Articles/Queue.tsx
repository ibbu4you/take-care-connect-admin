import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Eye, FileText, Globe, PenLine, UserPlus } from 'lucide-react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge, StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardHeader, CardTitle } from '@/Components/ui/card';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { adminLayout } from '@/Layouts/AdminLayout';
import { due, relative } from '@/lib/format';
import { cn } from '@/lib/utils';

export interface ArticleRow {
    id: number;
    code: string;
    title: string;
    slug: string | null;
    stage: string;
    stage_label: string;
    target_type: string;
    writer: { id: number; name: string } | null;
    reviewer: { id: number; name: string } | null;
    category: string | null;
    due_on: string | null;
    priority: string;
    word_count_target: number | null;
    interview_code: string | null;
    published_url: string | null;
    updated_at: string | null;
}

interface QueueProps {
    mine: ArticleRow[];
    awaitingReview: ArticleRow[];
    approved: ArticleRow[];
    unassigned: ArticleRow[];
}

/**
 * The content desk.
 *
 * Grouped by who has to act, not listed by date — because that is the question
 * the team asks each morning. A single sorted table hides the difference
 * between "I need to write this" and "somebody is waiting on me to read it",
 * which are the only two states that actually move work along.
 */
export default function ArticleQueue({ mine, awaitingReview, approved, unassigned }: QueueProps) {
    return (
        <div className="space-y-6">
            <Head title="Article queue" />

            <PageHeader
                title="Article queue"
                subtitle="Everything the content team has in hand, grouped by who has to act next."
                actions={
                    <Button asChild variant="outline">
                        <Link href="/articles">All articles</Link>
                    </Button>
                }
            />

            <Group
                title="Waiting on you"
                icon={PenLine}
                tone="warning"
                articles={mine}
                empty="Nothing assigned to you right now."
            />

            <Group
                title="Waiting on a reviewer"
                icon={Eye}
                tone="info"
                articles={awaitingReview}
                empty="Nothing is in review."
            />

            <Group
                title="Approved — ready to publish"
                icon={Globe}
                tone="success"
                articles={approved}
                empty="Nothing approved and waiting."
                hint="Export the bundle, publish it on the website, then paste the live URL back here."
            />

            {unassigned.length > 0 && (
                <Group
                    title="Nobody assigned"
                    icon={UserPlus}
                    tone="danger"
                    articles={unassigned}
                    empty=""
                    hint="These came from a handover with no writer chosen."
                />
            )}
        </div>
    );
}

const TONES = {
    warning: 'bg-warning/15 text-warning',
    info: 'bg-info/15 text-info',
    success: 'bg-success/15 text-success',
    danger: 'bg-destructive/15 text-destructive',
} as const;

function Group({
    title,
    icon: Icon,
    tone,
    articles,
    empty,
    hint,
}: {
    title: string;
    icon: typeof PenLine;
    tone: keyof typeof TONES;
    articles: ArticleRow[];
    empty: string;
    hint?: string;
}) {
    return (
        <Card>
            <CardHeader>
                <div className="min-w-0">
                    <CardTitle className="flex items-center gap-2">
                        <span
                            className={cn('inline-flex size-6 items-center justify-center rounded-control', TONES[tone])}
                            aria-hidden="true"
                        >
                            <Icon className="size-3.5" />
                        </span>
                        {title}
                        {articles.length > 0 && <Badge tone="default">{articles.length}</Badge>}
                    </CardTitle>
                    {hint && <p className="mt-0.5 text-sm text-muted-foreground">{hint}</p>}
                </div>
            </CardHeader>

            {articles.length === 0 ? (
                <EmptyState icon={CheckCircle2} title={empty} />
            ) : (
                <ul className="divide-y divide-border">
                    {articles.map((article) => (
                        <ArticleLine key={article.id} article={article} />
                    ))}
                </ul>
            )}
        </Card>
    );
}

function ArticleLine({ article }: { article: ArticleRow }) {
    const deadline = due(article.due_on);

    return (
        <li>
            <Link
                href={`/articles/${article.id}`}
                className="flex items-start gap-4 p-4 transition-colors hover:bg-muted/40"
            >
                <span
                    className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground"
                    aria-hidden="true"
                >
                    <FileText className="size-5" />
                </span>

                <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{article.title}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        <span className="tabular">{article.code}</span>
                        {article.interview_code ? ` · from ${article.interview_code}` : ''}
                        {article.category ? ` · ${article.category}` : ''}
                    </p>

                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <StageBadge family="article" stage={article.stage} dot />

                        <span
                            className={cn(
                                'text-xs',
                                deadline.tone === 'danger' && 'font-medium text-destructive',
                                deadline.tone === 'warning' && 'font-medium text-warning',
                                deadline.tone === 'muted' && 'text-muted-foreground',
                            )}
                        >
                            {deadline.label}
                        </span>

                        {article.updated_at && (
                            <span className="text-xs text-muted-foreground">
                                edited {relative(article.updated_at)}
                            </span>
                        )}
                    </div>
                </div>

                {article.writer ? (
                    <span className="flex shrink-0 items-center gap-2">
                        <Avatar name={article.writer.name} size="sm" />
                        <span className="hidden text-sm text-muted-foreground sm:inline">
                            {article.writer.name}
                        </span>
                    </span>
                ) : (
                    <Badge tone="warning">Unassigned</Badge>
                )}
            </Link>
        </li>
    );
}

ArticleQueue.layout = adminLayout;
