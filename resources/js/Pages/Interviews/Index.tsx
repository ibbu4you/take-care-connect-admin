import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, CheckCircle2, FileText, Mic, Plus, Search, Video } from 'lucide-react';
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
import { date, dateTime, relative } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

export interface InterviewRow {
    id: number;
    code: string;
    kind: string;
    kind_label: string;
    requires_article: boolean;
    title: string;
    subject_name: string;
    subject_company: string | null;
    client: { id: number; name: string } | null;
    anchor: { id: number; name: string } | null;
    stage: string;
    stage_label: string;
    scheduled_at: string | null;
    due_on: string | null;
    priority: string;
    /** null for a social interview — it never goes to the content team. */
    is_ready_for_handover: boolean | null;
}

interface Option {
    value: string;
    label: string;
}

interface IndexProps {
    interviews: Paginated<InterviewRow>;
    filters: { search: string; kind: string; stage: string; anchor: string; mine: boolean };
    kinds: Array<Option & { description: string; requires_article: boolean }>;
    stages: Option[];
    anchors: Array<{ id: number; name: string }>;
    counts: { thisWeek: number; awaitingHandover: number; conductedThisMonth: number; open: number };
    can: { create: boolean };
}

export default function InterviewsIndex({ interviews, filters, kinds, stages, anchors, counts, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<IndexProps['filters']>) {
        router.get(
            '/interviews',
            { ...filters, search, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <div className="space-y-6">
            <Head title="Interviews" />

            <PageHeader
                title="Interviews"
                subtitle="Every interview the team is running. Business interviews go to the content team; celebrity and Meet interviews go straight to creative."
                actions={
                    can.create && (
                        <Button asChild>
                            <Link href="/interviews/create">
                                <Plus />
                                Book interview
                            </Link>
                        </Button>
                    )
                }
            />

            <StatGrid count={4}>
                <StatCard label="Scheduled this week" value={counts.thisWeek} icon={CalendarClock} tone="primary" />
                <StatCard
                    label="Awaiting handover"
                    value={counts.awaitingHandover}
                    icon={FileText}
                    tone="warning"
                    sub="Conducted, not yet with content"
                />
                <StatCard
                    label="Conducted this month"
                    value={counts.conductedThisMonth}
                    icon={CheckCircle2}
                    tone="success"
                />
                <StatCard label="Open" value={counts.open} icon={Mic} tone="violet" sub="Not published or cancelled" />
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
                            placeholder="Search title, person, company or code…"
                            icon={<Search />}
                            className="max-w-md"
                            aria-label="Search interviews"
                        />
                    </form>

                    <Select
                        value={filters.kind}
                        onChange={(event) => applyFilters({ kind: event.target.value })}
                        className="w-auto min-w-44"
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

                    {anchors.length > 0 && (
                        <Select
                            value={filters.anchor}
                            onChange={(event) => applyFilters({ anchor: event.target.value })}
                            className="w-auto min-w-40"
                            aria-label="Filter by anchor"
                        >
                            <option value="">Any anchor</option>
                            {anchors.map((anchor) => (
                                <option key={anchor.id} value={anchor.id}>
                                    {anchor.name}
                                </option>
                            ))}
                        </Select>
                    )}
                </div>

                {interviews.data.length === 0 ? (
                    <EmptyState
                        icon={Mic}
                        title="No interviews match those filters"
                        description="Try a different search, or clear the filters to see everything you have access to."
                    />
                ) : (
                    <>
                        <TableWrap className="hidden md:block">
                            <Table>
                                <THead>
                                    <TR className="hover:bg-transparent">
                                        <TH>Interview</TH>
                                        <TH>Kind</TH>
                                        <TH>Anchor</TH>
                                        <TH>Scheduled</TH>
                                        <TH>Stage</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {interviews.data.map((interview) => (
                                        <TR key={interview.id} href={`/interviews/${interview.id}`}>
                                            <TD>
                                                <Link
                                                    href={`/interviews/${interview.id}`}
                                                    className="block hover:text-primary"
                                                >
                                                    <CellStack
                                                        primary={interview.title}
                                                        secondary={
                                                            <>
                                                                <span className="tabular">{interview.code}</span> ·{' '}
                                                                {interview.subject_name}
                                                                {interview.subject_company
                                                                    ? ` · ${interview.subject_company}`
                                                                    : ''}
                                                            </>
                                                        }
                                                    />
                                                </Link>
                                            </TD>

                                            <TD>
                                                <KindChip
                                                    label={interview.kind_label}
                                                    requiresArticle={interview.requires_article}
                                                />
                                            </TD>

                                            <TD>
                                                {interview.anchor ? (
                                                    <div className="flex items-center gap-2">
                                                        <Avatar name={interview.anchor.name} size="sm" />
                                                        <span className="truncate text-sm">
                                                            {interview.anchor.name}
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">Unassigned</span>
                                                )}
                                            </TD>

                                            <TD>
                                                {interview.scheduled_at ? (
                                                    <span
                                                        className="whitespace-nowrap text-sm"
                                                        title={dateTime(interview.scheduled_at)}
                                                    >
                                                        {date(interview.scheduled_at)}
                                                        <span className="block text-xs text-muted-foreground">
                                                            {relative(interview.scheduled_at)}
                                                        </span>
                                                    </span>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">—</span>
                                                )}
                                            </TD>

                                            <TD>
                                                <div className="flex flex-col items-start gap-1">
                                                    <StageBadge family="interview" stage={interview.stage} dot />
                                                    <HandoverHint interview={interview} />
                                                </div>
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </TableWrap>

                        <ul className="divide-y divide-border md:hidden">
                            {interviews.data.map((interview) => (
                                <li key={interview.id}>
                                    <Link href={`/interviews/${interview.id}`} className="block space-y-2 p-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="min-w-0 font-medium">{interview.title}</span>
                                            <StageBadge family="interview" stage={interview.stage} />
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            <span className="tabular">{interview.code}</span> · {interview.subject_name}
                                        </p>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <KindChip
                                                label={interview.kind_label}
                                                requiresArticle={interview.requires_article}
                                            />
                                            {interview.scheduled_at && (
                                                <span className="text-xs text-muted-foreground">
                                                    {date(interview.scheduled_at)}
                                                </span>
                                            )}
                                        </div>
                                        <HandoverHint interview={interview} />
                                    </Link>
                                </li>
                            ))}
                        </ul>

                        <Pagination paginator={interviews} />
                    </>
                )}
            </Card>
        </div>
    );
}

/**
 * The kind, and whether it produces an article.
 *
 * The article/social distinction drives everything downstream — who picks it up
 * next, what the handover requires, whether the website is involved at all — so
 * it earns a visual difference rather than only living in the label text.
 */
function KindChip({ label, requiresArticle }: { label: string; requiresArticle: boolean }) {
    return (
        <Badge tone={requiresArticle ? 'info' : 'default'} className="whitespace-nowrap">
            {requiresArticle ? <FileText className="size-3" /> : <Video className="size-3" />}
            {label}
        </Badge>
    );
}

/** Only shown where it changes what somebody should do next. */
function HandoverHint({ interview }: { interview: InterviewRow }) {
    if (interview.stage !== 'conducted' || interview.is_ready_for_handover === null) {
        return null;
    }

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 text-xs',
                interview.is_ready_for_handover ? 'text-success' : 'text-warning',
            )}
        >
            {interview.is_ready_for_handover ? (
                <>
                    <CheckCircle2 className="size-3" aria-hidden="true" />
                    Ready to hand over
                </>
            ) : (
                <>
                    <AlertTriangle className="size-3" aria-hidden="true" />
                    Handover incomplete
                </>
            )}
        </span>
    );
}

InterviewsIndex.layout = adminLayout;
