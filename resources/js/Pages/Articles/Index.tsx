import { Head, Link, router } from '@inertiajs/react';
import { Eye, ExternalLink, FileText, Globe, PenLine, Search } from 'lucide-react';
import { useState } from 'react';
import { StatCard, StatGrid } from '@/Components/data/StatCard';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge, StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Select } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { Pagination } from '@/Components/ui/pagination';
import { CellStack, Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { adminLayout } from '@/Layouts/AdminLayout';
import { due } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';
import type { ArticleRow } from './Queue';

interface IndexProps {
    articles: Paginated<ArticleRow>;
    filters: { search: string; stage: string; writer: string };
    stages: Array<{ value: string; label: string }>;
    writers: Array<{ id: number; name: string }>;
    counts: { awaitingReview: number; mine: number; publishedThisMonth: number; open: number };
}

export default function ArticlesIndex({ articles, filters, stages, writers, counts }: IndexProps) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<IndexProps['filters']>) {
        router.get(
            '/articles',
            { ...filters, search, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <div className="space-y-6">
            <Head title="Articles" />

            <PageHeader
                title="Articles"
                subtitle="Everything the content team has written or is writing. An article is created automatically when a business interview is handed over."
                actions={
                    <Button asChild variant="outline">
                        <Link href="/articles/queue">Queue view</Link>
                    </Button>
                }
            />

            <StatGrid count={4}>
                <StatCard label="In review" value={counts.awaitingReview} icon={Eye} tone="warning" />
                <StatCard label="Assigned to me" value={counts.mine} icon={PenLine} tone="primary" />
                <StatCard
                    label="Published this month"
                    value={counts.publishedThisMonth}
                    icon={Globe}
                    tone="success"
                    sub="Live on the website"
                />
                <StatCard label="Open" value={counts.open} icon={FileText} tone="violet" />
            </StatGrid>

            <Card>
                <div className="flex flex-wrap items-center gap-3 border-b border-border p-4">
                    <form
                        className="min-w-0 flex-1"
                        onSubmit={(event) => {
                            event.preventDefault();
                            applyFilters({});
                        }}
                    >
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search title or code…"
                            icon={<Search />}
                            className="max-w-md"
                            aria-label="Search articles"
                        />
                    </form>

                    <Select
                        value={filters.stage}
                        onChange={(event) => applyFilters({ stage: event.target.value })}
                        className="w-auto min-w-40"
                        aria-label="Filter by stage"
                    >
                        <option value="">All stages</option>
                        {stages.map((stage) => (
                            <option key={stage.value} value={stage.value}>
                                {stage.label}
                            </option>
                        ))}
                    </Select>

                    {writers.length > 0 && (
                        <Select
                            value={filters.writer}
                            onChange={(event) => applyFilters({ writer: event.target.value })}
                            className="w-auto min-w-40"
                            aria-label="Filter by writer"
                        >
                            <option value="">Any writer</option>
                            {writers.map((writer) => (
                                <option key={writer.id} value={writer.id}>
                                    {writer.name}
                                </option>
                            ))}
                        </Select>
                    )}
                </div>

                {articles.data.length === 0 ? (
                    <EmptyState
                        icon={FileText}
                        title="No articles match those filters"
                        description="Articles appear here when a business interview is handed over to the content team."
                    />
                ) : (
                    <>
                        <TableWrap className="hidden md:block">
                            <Table>
                                <THead>
                                    <TR className="hover:bg-transparent">
                                        <TH>Article</TH>
                                        <TH>Writer</TH>
                                        <TH>Deadline</TH>
                                        <TH>Stage</TH>
                                        <TH />
                                    </TR>
                                </THead>
                                <TBody>
                                    {articles.data.map((article) => {
                                        const deadline = due(article.due_on);

                                        return (
                                            <TR key={article.id} href={`/articles/${article.id}`}>
                                                <TD>
                                                    <Link
                                                        href={`/articles/${article.id}`}
                                                        className="block hover:text-primary"
                                                    >
                                                        <CellStack
                                                            primary={article.title}
                                                            secondary={
                                                                <>
                                                                    <span className="tabular">{article.code}</span>
                                                                    {article.interview_code
                                                                        ? ` · from ${article.interview_code}`
                                                                        : ''}
                                                                    {article.category ? ` · ${article.category}` : ''}
                                                                </>
                                                            }
                                                        />
                                                    </Link>
                                                </TD>

                                                <TD>
                                                    {article.writer ? (
                                                        <div className="flex items-center gap-2">
                                                            <Avatar name={article.writer.name} size="sm" />
                                                            <span className="truncate text-sm">
                                                                {article.writer.name}
                                                            </span>
                                                        </div>
                                                    ) : (
                                                        <Badge tone="warning">Unassigned</Badge>
                                                    )}
                                                </TD>

                                                <TD>
                                                    <span
                                                        className={cn(
                                                            'whitespace-nowrap text-sm',
                                                            deadline.tone === 'danger' && 'font-medium text-destructive',
                                                            deadline.tone === 'warning' && 'font-medium text-warning',
                                                            deadline.tone === 'muted' && 'text-muted-foreground',
                                                        )}
                                                    >
                                                        {deadline.label}
                                                    </span>
                                                </TD>

                                                <TD>
                                                    <StageBadge family="article" stage={article.stage} dot />
                                                </TD>

                                                <TD>
                                                    {article.published_url && (
                                                        <a
                                                            href={article.published_url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                                                            title="View it live"
                                                        >
                                                            Live
                                                            <ExternalLink className="size-3" />
                                                        </a>
                                                    )}
                                                </TD>
                                            </TR>
                                        );
                                    })}
                                </TBody>
                            </Table>
                        </TableWrap>

                        <ul className="divide-y divide-border md:hidden">
                            {articles.data.map((article) => (
                                <li key={article.id}>
                                    <Link href={`/articles/${article.id}`} className="block space-y-2 p-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="min-w-0 font-medium">{article.title}</span>
                                            <StageBadge family="article" stage={article.stage} />
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            <span className="tabular">{article.code}</span>
                                            {article.writer ? ` · ${article.writer.name}` : ' · unassigned'}
                                        </p>
                                    </Link>
                                </li>
                            ))}
                        </ul>

                        <Pagination paginator={articles} />
                    </>
                )}
            </Card>
        </div>
    );
}

ArticlesIndex.layout = adminLayout;
