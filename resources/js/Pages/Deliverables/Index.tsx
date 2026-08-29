import { Head, Link, router } from '@inertiajs/react';
import { Clapperboard, ExternalLink, Globe, LayoutGrid, Search, Send } from 'lucide-react';
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
import type { DeliverableRow } from './Board';

interface IndexProps {
    deliverables: Paginated<DeliverableRow>;
    filters: { search: string; kind: string; stage: string; assignee: string };
    kinds: Array<{ value: string; label: string }>;
    stages: Array<{ value: string; label: string }>;
    creatives: Array<{ id: number; name: string }>;
    counts: { mine: number; inReview: number; awaitingPublish: number; publishedThisMonth: number };
}

export default function DeliverablesIndex({
    deliverables,
    filters,
    kinds,
    stages,
    creatives,
    counts,
}: IndexProps) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<IndexProps['filters']>) {
        router.get(
            '/deliverables',
            { ...filters, search, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <div className="space-y-6">
            <Head title="Deliverables" />

            <PageHeader
                title="Deliverables"
                subtitle="Posters, reels and edits. Each one hangs off an interview or a finished article."
                actions={
                    <Button asChild variant="outline">
                        <Link href="/deliverables/board">
                            <LayoutGrid />
                            Board view
                        </Link>
                    </Button>
                }
            />

            <StatGrid count={4}>
                <StatCard label="Assigned to me" value={counts.mine} icon={Clapperboard} tone="primary" />
                <StatCard label="Awaiting approval" value={counts.inReview} icon={Send} tone="warning" />
                <StatCard
                    label="Approved, not posted"
                    value={counts.awaitingPublish}
                    icon={Globe}
                    tone="info"
                    sub="Cleared but not out yet"
                />
                <StatCard
                    label="Published this month"
                    value={counts.publishedThisMonth}
                    icon={ExternalLink}
                    tone="success"
                />
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
                            aria-label="Search deliverables"
                        />
                    </form>

                    <Select
                        value={filters.kind}
                        onChange={(event) => applyFilters({ kind: event.target.value })}
                        className="w-auto min-w-36"
                        aria-label="Filter by kind"
                    >
                        <option value="">All kinds</option>
                        {kinds.map((kind) => (
                            <option key={kind.value} value={kind.value}>
                                {kind.label}
                            </option>
                        ))}
                    </Select>

                    <Select
                        value={filters.stage}
                        onChange={(event) => applyFilters({ stage: event.target.value })}
                        className="w-auto min-w-36"
                        aria-label="Filter by stage"
                    >
                        <option value="">All stages</option>
                        {stages.map((stage) => (
                            <option key={stage.value} value={stage.value}>
                                {stage.label}
                            </option>
                        ))}
                    </Select>

                    {creatives.length > 0 && (
                        <Select
                            value={filters.assignee}
                            onChange={(event) => applyFilters({ assignee: event.target.value })}
                            className="w-auto min-w-40"
                            aria-label="Filter by assignee"
                        >
                            <option value="">Anyone</option>
                            {creatives.map((creative) => (
                                <option key={creative.id} value={creative.id}>
                                    {creative.name}
                                </option>
                            ))}
                        </Select>
                    )}
                </div>

                {deliverables.data.length === 0 ? (
                    <EmptyState
                        icon={Clapperboard}
                        title="No deliverables match those filters"
                        description="Work is commissioned from an interview or a published article."
                    />
                ) : (
                    <>
                        <TableWrap className="hidden md:block">
                            <Table>
                                <THead>
                                    <TR className="hover:bg-transparent">
                                        <TH>Deliverable</TH>
                                        <TH>From</TH>
                                        <TH>Making it</TH>
                                        <TH>Due</TH>
                                        <TH>Stage</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {deliverables.data.map((item) => {
                                        const deadline = due(item.due_on);

                                        return (
                                            <TR key={item.id}>
                                                <TD>
                                                    <Link
                                                        href={`/deliverables/${item.id}`}
                                                        className="block hover:text-primary"
                                                    >
                                                        <CellStack
                                                            primary={
                                                                item.title ||
                                                                `${item.kind_label} ${item.slot_number}`
                                                            }
                                                            secondary={
                                                                <>
                                                                    <span className="tabular">{item.code}</span> ·{' '}
                                                                    {item.kind_label}
                                                                    {item.file_count > 0
                                                                        ? ` · v${item.current_version}`
                                                                        : ' · no file yet'}
                                                                </>
                                                            }
                                                        />
                                                    </Link>
                                                </TD>

                                                <TD>
                                                    <Link
                                                        href={
                                                            item.parent_kind === 'interview'
                                                                ? `/interviews/${item.parent_id}`
                                                                : `/articles/${item.parent_id}`
                                                        }
                                                        className="block max-w-56 truncate text-sm text-muted-foreground hover:text-primary"
                                                    >
                                                        {item.parent_label}
                                                    </Link>
                                                </TD>

                                                <TD>
                                                    {item.assignee ? (
                                                        <div className="flex items-center gap-2">
                                                            <Avatar name={item.assignee.name} size="sm" />
                                                            <span className="truncate text-sm">
                                                                {item.assignee.name}
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
                                                            deadline.tone === 'danger' &&
                                                                'font-medium text-destructive',
                                                            deadline.tone === 'warning' && 'font-medium text-warning',
                                                            deadline.tone === 'muted' && 'text-muted-foreground',
                                                        )}
                                                    >
                                                        {deadline.label}
                                                    </span>
                                                </TD>

                                                <TD>
                                                    <div className="flex flex-col items-start gap-1">
                                                        <StageBadge family="deliverable" stage={item.stage} dot />
                                                        {item.stage === 'published' && !item.has_social_link && (
                                                            <span className="text-xs text-warning">No link</span>
                                                        )}
                                                        {item.social_url && (
                                                            <a
                                                                href={item.social_url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                                                            >
                                                                Live
                                                                <ExternalLink className="size-3" />
                                                            </a>
                                                        )}
                                                    </div>
                                                </TD>
                                            </TR>
                                        );
                                    })}
                                </TBody>
                            </Table>
                        </TableWrap>

                        <ul className="divide-y divide-border md:hidden">
                            {deliverables.data.map((item) => (
                                <li key={item.id}>
                                    <Link href={`/deliverables/${item.id}`} className="block space-y-2 p-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="min-w-0 font-medium">
                                                {item.title || `${item.kind_label} ${item.slot_number}`}
                                            </span>
                                            <StageBadge family="deliverable" stage={item.stage} />
                                        </div>
                                        <p className="truncate text-xs text-muted-foreground">
                                            <span className="tabular">{item.code}</span> · {item.parent_label}
                                        </p>
                                    </Link>
                                </li>
                            ))}
                        </ul>

                        <Pagination paginator={deliverables} />
                    </>
                )}
            </Card>
        </div>
    );
}

DeliverablesIndex.layout = adminLayout;
