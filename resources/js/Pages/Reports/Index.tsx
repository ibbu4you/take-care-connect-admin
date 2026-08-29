import { Head, router } from '@inertiajs/react';
import { BarChart3, Clapperboard, FileText, Handshake, Mic, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { StageBadge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { adminLayout } from '@/Layouts/AdminLayout';
import { money, moneyCompact, number } from '@/lib/format';
import { cn } from '@/lib/utils';

interface Bar {
    label: string;
    stage?: string;
    count: number;
    value?: number;
}

interface ReportsProps {
    window: { months: number; from: string; to: string; label: string };
    options: number[];
    pipeline: {
        won_count: number;
        lost_count: number;
        won_value: number;
        lost_value: number;
        win_rate: number | null;
        average_won: number;
        by_stage: Bar[];
        lost_reasons: Array<{ reason: string; count: number }>;
    };
    interviews: {
        booked: number;
        conducted: number;
        cancelled: number;
        by_kind: Bar[];
        median_days_to_handover: number | null;
    };
    content: {
        published: number;
        words_published: number;
        open: number;
        overdue: number;
        by_stage: Bar[];
        median_days_to_publish: number | null;
    };
    creative: {
        published: number;
        open: number;
        rejected: number;
        awaiting_publish: number;
        by_kind: Bar[];
        by_platform: Bar[];
    };
    people: Array<{
        name: string;
        initials: string;
        interviews: number;
        articles: number;
        deliverables: number;
        won_value: number;
    }> | null;
}

export default function Reports({ window: period, options, pipeline, interviews, content, creative, people }: ReportsProps) {
    return (
        <div className="space-y-6">
            <Head title="Reports" />

            <PageHeader
                title="Reports"
                subtitle="Where the work went. Everything here is scoped exactly as its module is, so these are your figures — not somebody else's."
                actions={
                    <Select
                        value={String(period.months)}
                        onChange={(event) =>
                            router.get('/reports', { months: event.target.value }, { preserveState: true })
                        }
                        className="w-auto min-w-40"
                        aria-label="Choose a window"
                    >
                        {options.map((months) => (
                            <option key={months} value={months}>
                                {months === 1 ? 'This month' : `Last ${months} months`}
                            </option>
                        ))}
                    </Select>
                }
            />

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel title="Pipeline" icon={Handshake}>
                    <Figures>
                        <Figure
                            label="Win rate"
                            value={pipeline.win_rate !== null ? `${pipeline.win_rate}%` : '—'}
                            hint={
                                pipeline.win_rate !== null
                                    ? `${pipeline.won_count} won, ${pipeline.lost_count} lost`
                                    : 'Nothing closed yet'
                            }
                        />
                        <Figure label="Won" value={moneyCompact(pipeline.won_value)} hint="Actually signed" />
                        <Figure
                            label="Average deal"
                            value={moneyCompact(pipeline.average_won)}
                            hint="Across wins"
                        />
                    </Figures>

                    <BarList title="Open by stage" bars={pipeline.by_stage} family="pipeline" showValue />

                    {pipeline.lost_reasons.length > 0 && (
                        <>
                            <h4 className="mt-5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Why deals were lost
                            </h4>
                            <ul className="mt-2 space-y-1.5">
                                {pipeline.lost_reasons.map((reason) => (
                                    <li key={reason.reason} className="flex items-baseline justify-between gap-3 text-sm">
                                        <span className="min-w-0 truncate text-muted-foreground">{reason.reason}</span>
                                        <span className="shrink-0 font-medium tabular">{reason.count}</span>
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </Panel>

                <Panel title="Interviews" icon={Mic}>
                    <Figures>
                        <Figure label="Conducted" value={number(interviews.conducted)} hint={`${interviews.booked} booked`} />
                        <Figure
                            label="Cancelled"
                            value={number(interviews.cancelled)}
                            hint="Or no-shows"
                        />
                        <Figure
                            label="Days to handover"
                            value={interviews.median_days_to_handover !== null ? String(interviews.median_days_to_handover) : '—'}
                            hint="Median, conducted → handed over"
                        />
                    </Figures>

                    <BarList title="Conducted by kind" bars={interviews.by_kind} />
                </Panel>

                <Panel title="Content" icon={FileText}>
                    <Figures>
                        <Figure label="Published" value={number(content.published)} hint="Live on the website" />
                        <Figure label="Words" value={number(content.words_published)} hint="Across published pieces" />
                        <Figure
                            label="Days to publish"
                            value={content.median_days_to_publish !== null ? String(content.median_days_to_publish) : '—'}
                            hint="Median, handover → live"
                        />
                    </Figures>

                    <BarList title="Open by stage" bars={content.by_stage} family="article" />

                    {content.overdue > 0 && (
                        <p className="mt-4 rounded-control bg-destructive/10 px-3 py-2 text-sm text-destructive">
                            {content.overdue} {content.overdue === 1 ? 'article is' : 'articles are'} past deadline.
                        </p>
                    )}
                </Panel>

                <Panel title="Creative" icon={Clapperboard}>
                    <Figures>
                        <Figure label="Posted" value={number(creative.published)} hint="With links recorded" />
                        <Figure label="In flight" value={number(creative.open)} hint={`${creative.rejected} sent back`} />
                        <Figure
                            label="Ready, not out"
                            value={number(creative.awaiting_publish)}
                            hint="Approved but unposted"
                        />
                    </Figures>

                    <BarList title="Posted by kind" bars={creative.by_kind} />
                    {creative.by_platform.length > 0 && (
                        <BarList title="Posted by platform" bars={creative.by_platform} />
                    )}
                </Panel>
            </div>

            {people !== null && (
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Users className="size-4" aria-hidden="true" />
                            By person · {period.label.toLowerCase()}
                        </CardTitle>
                    </CardHeader>

                    {people.length === 0 ? (
                        <EmptyState icon={BarChart3} title="No output in this window" />
                    ) : (
                        <TableWrap>
                            <Table>
                                <THead>
                                    <TR className="hover:bg-transparent">
                                        <TH>Person</TH>
                                        <TH>Interviews</TH>
                                        <TH>Articles</TH>
                                        <TH>Deliverables</TH>
                                        <TH>Revenue won</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {people.map((person) => (
                                        <TR key={person.name}>
                                            <TD>
                                                <div className="flex items-center gap-2">
                                                    <Avatar name={person.name} initials={person.initials} size="sm" />
                                                    <span className="truncate text-sm font-medium">{person.name}</span>
                                                </div>
                                            </TD>
                                            <TD className="tabular">{person.interviews || '—'}</TD>
                                            <TD className="tabular">{person.articles || '—'}</TD>
                                            <TD className="tabular">{person.deliverables || '—'}</TD>
                                            <TD className="tabular">
                                                {person.won_value ? money(person.won_value) : '—'}
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </TableWrap>
                    )}
                </Card>
            )}
        </div>
    );
}

function Panel({ title, icon: Icon, children }: { title: string; icon: typeof Mic; children: ReactNode }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Icon className="size-4 text-muted-foreground" aria-hidden="true" />
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

function Figures({ children }: { children: ReactNode }) {
    return <div className="grid grid-cols-3 gap-4">{children}</div>;
}

function Figure({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <div className="min-w-0">
            <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{label}</p>
            <p className="truncate text-xl font-bold tabular">{value}</p>
            {hint && <p className="truncate text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

/**
 * A horizontal bar list.
 *
 * Bars rather than a chart library: these are always a handful of labelled
 * categories, where a bar list is easier to read, needs no legend, and works in
 * both themes without a single hard-coded colour.
 */
function BarList({
    title,
    bars,
    family,
    showValue = false,
}: {
    title: string;
    bars: Bar[];
    family?: 'pipeline' | 'article';
    showValue?: boolean;
}) {
    const max = Math.max(...bars.map((bar) => bar.count), 1);
    const total = bars.reduce((sum, bar) => sum + bar.count, 0);

    if (total === 0) {
        return (
            <div className="mt-5">
                <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{title}</h4>
                <p className="mt-2 text-sm text-muted-foreground">Nothing in this window.</p>
            </div>
        );
    }

    return (
        <div className="mt-5">
            <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{title}</h4>

            <ul className="mt-2 space-y-2">
                {bars.map((bar) => (
                    <li key={bar.label}>
                        <div className="mb-1 flex items-baseline justify-between gap-2">
                            <span className="flex min-w-0 items-center gap-2">
                                {family && bar.stage ? (
                                    <StageBadge family={family} stage={bar.stage} dot />
                                ) : (
                                    <span className="truncate text-sm">{bar.label}</span>
                                )}
                            </span>
                            <span className="shrink-0 text-sm tabular">
                                {showValue && bar.value ? (
                                    <span className="text-muted-foreground">{moneyCompact(bar.value)} · </span>
                                ) : null}
                                <span className="font-medium">{bar.count}</span>
                            </span>
                        </div>

                        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn('h-full rounded-full bg-primary/70')}
                                style={{ width: `${(bar.count / max) * 100}%` }}
                            />
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}

Reports.layout = adminLayout;
