import { Head, Link, router } from '@inertiajs/react';
import { Clapperboard, ExternalLink, Film, Globe, Image as ImageIcon, Search, Send, X } from 'lucide-react';
import { useState } from 'react';
import { Pill } from '@/Components/dashboard/parts';
import { StatCard, StatGrid } from '@/Components/data/StatCard';
import { PageHeader } from '@/Components/layout/PageHeader';
import { StageBadge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { Input, Select } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { Pagination } from '@/Components/ui/pagination';
import { Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { ViewSwitch } from '@/Components/work/ViewSwitch';
import { adminLayout } from '@/Layouts/AdminLayout';
import { deliverableTiming } from '@/lib/deliverables';
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

export default function DeliverablesIndex({ deliverables, filters, kinds, stages, creatives, counts }: IndexProps) {
    const [search, setSearch] = useState(filters.search);
    const filtering = Object.values(filters).some((value) => value !== '');

    function applyFilters(next: Partial<IndexProps['filters']>) {
        router.get('/deliverables', { ...filters, search, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    }

    function clearFilters() {
        setSearch('');
        router.get('/deliverables', {}, { preserveState: true, preserveScroll: true, replace: true });
    }

    return (
        <div className="space-y-6">
            <Head title="Deliverables" />

            <PageHeader
                title="Deliverables"
                subtitle="Posters, reels and edits. Each one hangs off an interview or a finished article."
                actions={<ViewSwitch current="list" boardHref="/deliverables/board" listHref="/deliverables" />}
            />

            <StatGrid count={4}>
                <StatCard label="Assigned to me" value={counts.mine} icon={Clapperboard} tone="primary" sub="Open, in your hands" />
                <StatCard label="Awaiting approval" value={counts.inReview} icon={Send} tone="warning" sub="Waiting on a reviewer" />
                <StatCard label="Approved, not posted" value={counts.awaitingPublish} icon={Globe} tone="info" sub="Cleared but not out yet" />
                <StatCard label="Published this month" value={counts.publishedThisMonth} icon={ExternalLink} tone="success" sub="Live on social" />
            </StatGrid>

            <Card className="overflow-hidden">
                {/* Toolbar */}
                <div className="flex flex-wrap items-center gap-3 p-4">
                    <form
                        className="w-full sm:w-80"
                        onSubmit={(event) => {
                            event.preventDefault();
                            applyFilters({});
                        }}
                    >
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search title or code…"
                            icon={<Search />}
                            aria-label="Search deliverables"
                        />
                    </form>

                    <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
                        <Select
                            value={filters.kind}
                            onChange={(event) => applyFilters({ kind: event.target.value })}
                            className="w-auto min-w-32"
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
                            className="w-auto min-w-32"
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
                                className="w-auto min-w-36"
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

                        {filtering && (
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-sm font-medium text-primary hover:bg-primary/5"
                            >
                                <X className="size-4" aria-hidden="true" />
                                Clear
                            </button>
                        )}
                    </div>
                </div>

                {deliverables.data.length === 0 ? (
                    <div className="border-t border-border">
                        <EmptyState
                            icon={Clapperboard}
                            title={filtering ? 'No deliverables match those filters' : 'No deliverables yet'}
                            description="Work is requested from an interview or a published article."
                        />
                    </div>
                ) : (
                    <>
                        <TableWrap className="hidden border-t border-border md:block">
                            <Table>
                                <THead>
                                    <TR className="hover:bg-transparent">
                                        <TH>Deliverable</TH>
                                        <TH>From</TH>
                                        <TH>Making it</TH>
                                        <TH>Timing</TH>
                                        <TH>Stage</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {deliverables.data.map((item) => (
                                        <TR key={item.id} href={`/deliverables/${item.id}`} className="group">
                                            <TD>
                                                <div className="flex min-w-0 items-center gap-3">
                                                    <KindIcon video={item.is_video} />
                                                    <div className="min-w-0">
                                                        <Link
                                                            href={`/deliverables/${item.id}`}
                                                            className="block truncate font-medium group-hover:text-primary"
                                                        >
                                                            {item.title || `${item.kind_label} ${item.slot_number}`}
                                                        </Link>
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            <span className="tabular">{item.code}</span> · {item.kind_label}
                                                            {item.file_count > 0 ? (
                                                                <span className="ml-1.5 rounded border border-border px-1 font-medium tabular">
                                                                    v{item.current_version}
                                                                </span>
                                                            ) : (
                                                                ' · no file yet'
                                                            )}
                                                        </p>
                                                    </div>
                                                </div>
                                            </TD>

                                            <TD className="max-w-72">
                                                <p className="text-xs text-muted-foreground">
                                                    {item.parent_kind === 'interview' ? 'Interview' : 'Article'}
                                                </p>
                                                <Link
                                                    href={item.parent_kind === 'interview' ? `/interviews/${item.parent_id}` : `/articles/${item.parent_id}`}
                                                    className="block truncate text-sm hover:text-primary hover:underline"
                                                >
                                                    {item.parent_label}
                                                </Link>
                                            </TD>

                                            <TD>
                                                {item.assignee ? (
                                                    <div className="flex items-center gap-2">
                                                        <Avatar name={item.assignee.name} size="sm" />
                                                        <span className="truncate text-sm">{item.assignee.name}</span>
                                                    </div>
                                                ) : (
                                                    <Pill tone="warning">Unassigned</Pill>
                                                )}
                                            </TD>

                                            <TD>
                                                <Timing item={item} />
                                            </TD>

                                            <TD>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <StageBadge family="deliverable" stage={item.stage} dot />
                                                    {item.stage === 'published' && !item.has_social_link && <Pill tone="warning">No link</Pill>}
                                                    {item.social_url && (
                                                        <a
                                                            href={item.social_url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                                        >
                                                            Live
                                                            <ExternalLink className="size-3" aria-hidden="true" />
                                                        </a>
                                                    )}
                                                </div>
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </TableWrap>

                        {/* Phone: a card per deliverable, the whole card a link. */}
                        <ul className="divide-y divide-border border-t border-border md:hidden">
                            {deliverables.data.map((item) => (
                                <li key={item.id}>
                                    <Link href={`/deliverables/${item.id}`} className="flex gap-3 p-4 active:bg-muted/50">
                                        <KindIcon video={item.is_video} />
                                        <div className="min-w-0 flex-1 space-y-1.5">
                                            <div className="flex items-start justify-between gap-2">
                                                <span className="min-w-0 truncate font-medium">
                                                    {item.title || `${item.kind_label} ${item.slot_number}`}
                                                </span>
                                                <StageBadge family="deliverable" stage={item.stage} />
                                            </div>
                                            <p className="truncate text-xs text-muted-foreground">
                                                <span className="tabular">{item.code}</span> · {item.parent_label}
                                            </p>
                                            <div className="flex items-center justify-between gap-2">
                                                <Timing item={item} />
                                                {item.assignee && <Avatar name={item.assignee.name} size="sm" className="size-6 text-[10px]" />}
                                            </div>
                                        </div>
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

function KindIcon({ video }: { video: boolean }) {
    const Icon = video ? Film : ImageIcon;

    return (
        <span
            className={cn(
                'inline-flex size-9 shrink-0 items-center justify-center rounded-lg',
                video ? 'bg-stage-in-progress/10 text-stage-in-progress' : 'bg-primary/10 text-primary',
            )}
            aria-hidden="true"
        >
            <Icon className="size-4" />
        </span>
    );
}

function Timing({ item }: { item: DeliverableRow }) {
    const when = deliverableTiming(item);
    const Icon = when.icon;

    return (
        <Pill tone={when.tone}>
            <Icon className="size-3" aria-hidden="true" />
            {when.label}
        </Pill>
    );
}

DeliverablesIndex.layout = adminLayout;
