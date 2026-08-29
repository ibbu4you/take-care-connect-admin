import { Head, Link, router } from '@inertiajs/react';
import { BarChart3, Clapperboard, FileText, Handshake, Mic, Users } from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { adminLayout } from '@/Layouts/AdminLayout';
import { money, moneyCompact, number } from '@/lib/format';
import { cn } from '@/lib/utils';

interface Person {
    id: number;
    name: string;
    initials: string;
    avatar_url: string | null;
    done: number;
    open: number;
    turnaround: number | null;
    [key: string]: unknown;
}

interface Group {
    rows: Person[];
    median: number | null;
}

interface PerformanceProps {
    window: { months: number; label: string };
    options: number[];
    anchors: Group;
    writers: Group;
    creatives: Group;
    managers: Group;
}

export default function Performance({
    window: period,
    options,
    anchors,
    writers,
    creatives,
    managers,
}: PerformanceProps) {
    return (
        <div className="space-y-6">
            <Head title="Performance" />

            <PageHeader
                title="Performance"
                subtitle="Who is carrying what, and where work is sitting. Sorted by what is open now, not by what was finished."
                actions={
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline">
                            <Link href="/reports">
                                <BarChart3 />
                                Reports
                            </Link>
                        </Button>

                        <div className="flex rounded-control border border-input p-0.5">
                            {options.map((months) => (
                                <button
                                    key={months}
                                    type="button"
                                    onClick={() =>
                                        router.get(
                                            '/reports/performance',
                                            { months },
                                            { preserveState: true, preserveScroll: true, replace: true },
                                        )
                                    }
                                    className={cn(
                                        'rounded-[calc(var(--radius-control)-2px)] px-3 py-1 text-sm transition-colors',
                                        period.months === months
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {months === 1 ? '1m' : `${months}m`}
                                </button>
                            ))}
                        </div>
                    </div>
                }
            />

            {/*
              | Say what this page is for, at the top, once.
              |
              | A grid of people with numbers next to them reads as a ranking
              | whatever the columns say, so the framing has to be explicit and
              | it has to come before the tables rather than in a footnote
              | underneath them.
            */}
            <Card>
                <CardContent className="py-4">
                    <p className="text-sm text-muted-foreground">
                        <span className="font-medium text-foreground">This is a workload view, not a ranking.</span>{' '}
                        Three long interviews are not less than nine short ones, and a 4,000-word feature is not
                        less than four profiles. Read <span className="font-medium">Open</span> to see who is
                        swamped and <span className="font-medium">Turnaround</span> to see where work is sitting —
                        each against the team median, not against each other.
                    </p>
                </CardContent>
            </Card>

            <Section
                title="Anchors"
                icon={Mic}
                group={anchors}
                unit="days to hand over"
                columns={[
                    { key: 'done', label: 'Conducted', align: 'right' },
                    { key: 'handed_over', label: 'Handed over', align: 'right' },
                    { key: 'waiting', label: 'Waiting', align: 'right', alarm: true },
                ]}
                windowLabel={period.label}
            />

            <Section
                title="Content"
                icon={FileText}
                group={writers}
                unit="days to publish"
                columns={[
                    { key: 'done', label: 'Published', align: 'right' },
                    { key: 'words', label: 'Words', align: 'right', format: (v) => number(v as number) },
                    { key: 'overdue', label: 'Overdue', align: 'right', alarm: true },
                ]}
                windowLabel={period.label}
            />

            <Section
                title="Creative"
                icon={Clapperboard}
                group={creatives}
                unit="days to publish"
                columns={[
                    { key: 'done', label: 'Published', align: 'right' },
                    { key: 'rework', label: 'Rework', align: 'right', alarm: true },
                ]}
                windowLabel={period.label}
                note="Rework counts work sent back. A high number is more often a vague brief than a careless editor."
            />

            <Section
                title="Sales"
                icon={Handshake}
                group={managers}
                unit={null}
                columns={[
                    { key: 'done', label: 'Won', align: 'right' },
                    {
                        key: 'won_value',
                        label: 'Value',
                        align: 'right',
                        format: (v) => money(v as number),
                    },
                    { key: 'attainment', label: 'Attainment', align: 'right', render: attainment },
                    { key: 'quiet', label: 'Gone quiet', align: 'right', alarm: true },
                ]}
                windowLabel={period.label}
            />
        </div>
    );
}

/* ───────────────────────────────────────────────────────────────────────── */

interface Column {
    key: string;
    label: string;
    align?: 'left' | 'right';
    alarm?: boolean;
    format?: (value: unknown) => string;
    render?: (row: Person) => ReactNode;
}

function Section({
    title,
    icon: Icon,
    group,
    columns,
    unit,
    windowLabel,
    note,
}: {
    title: string;
    icon: ComponentType<{ className?: string }>;
    group: Group;
    columns: Column[];
    unit: string | null;
    windowLabel: string;
    note?: string;
}) {
    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
                <CardTitle className="flex items-center gap-2">
                    <Icon className="size-4" aria-hidden="true" />
                    {title}
                </CardTitle>

                <span className="text-xs text-muted-foreground">
                    {windowLabel}
                    {group.median !== null && unit && (
                        <>
                            {' · '}
                            team median <span className="font-medium text-foreground">{group.median}</span> {unit}
                        </>
                    )}
                </span>
            </CardHeader>

            {group.rows.length === 0 ? (
                <EmptyState
                    icon={Users}
                    title={`Nobody in ${title.toLowerCase()} yet`}
                    description="Accounts with this role will appear here once they have work against them."
                />
            ) : (
                <>
                    <TableWrap>
                        <Table>
                            <THead>
                                <TR className="hover:bg-transparent">
                                    <TH>Person</TH>
                                    <TH className="text-right">Open</TH>
                                    {columns.map((column) => (
                                        <TH key={column.key} className="text-right">
                                            {column.label}
                                        </TH>
                                    ))}
                                    {unit && <TH className="text-right">Turnaround</TH>}
                                </TR>
                            </THead>

                            <TBody>
                                {group.rows.map((person) => (
                                    <TR key={person.id}>
                                        <TD>
                                            <div className="flex items-center gap-2">
                                                <Avatar
                                                    name={person.name}
                                                    initials={person.initials}
                                                    src={person.avatar_url}
                                                    size="sm"
                                                />
                                                <span className="truncate text-sm font-medium">{person.name}</span>
                                            </div>
                                        </TD>

                                        <TD className="text-right text-sm font-medium tabular">{person.open}</TD>

                                        {columns.map((column) => (
                                            <TD key={column.key} className="text-right text-sm tabular">
                                                <Cell person={person} column={column} />
                                            </TD>
                                        ))}

                                        {unit && (
                                            <TD className="text-right text-sm tabular">
                                                <Turnaround value={person.turnaround} median={group.median} />
                                            </TD>
                                        )}
                                    </TR>
                                ))}
                            </TBody>
                        </Table>
                    </TableWrap>

                    {note && (
                        <div className="border-t border-border px-5 py-3">
                            <p className="text-xs text-muted-foreground">{note}</p>
                        </div>
                    )}
                </>
            )}
        </Card>
    );
}

function Cell({ person, column }: { person: Person; column: Column }) {
    if (column.render) {
        return <>{column.render(person)}</>;
    }

    const value = person[column.key];

    if (value === null || value === undefined) {
        return <span className="text-muted-foreground">—</span>;
    }

    const text = column.format ? column.format(value) : String(value);

    // Alarm columns are counts of something wrong. Zero is the good case, so it
    // stays quiet rather than being coloured green — a wall of green ticks makes
    // the one red number harder to find, not easier.
    if (column.alarm) {
        return Number(value) > 0 ? (
            <span className="font-medium text-destructive">{text}</span>
        ) : (
            <span className="text-muted-foreground">{text}</span>
        );
    }

    return <>{text}</>;
}

/**
 * A turnaround, against the team median.
 *
 * Compared to the median rather than to the fastest person, because the fastest
 * person is not the target — and a column where everybody but one reads red is
 * a column nobody looks at twice.
 */
function Turnaround({ value, median }: { value: number | null; median: number | null }) {
    if (value === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    const slow = median !== null && value > median * 1.5 && value - median >= 2;

    return (
        <span className={cn(slow ? 'font-medium text-warning' : undefined)}>
            {value}d
        </span>
    );
}

function attainment(person: Person): ReactNode {
    const value = person.attainment as number | null;

    if (value === null) {
        // No target is not 0% — a red zero against somebody nobody set a target
        // for is a lie about them.
        return <span className="text-muted-foreground">No target</span>;
    }

    const tone = value >= 100 ? 'text-success' : value >= 60 ? 'text-warning' : 'text-destructive';

    return (
        <span className={cn('font-medium', tone)}>
            {value}%
            <span className="ml-1 text-xs font-normal text-muted-foreground">
                of {moneyCompact(person.target as number)}
            </span>
        </span>
    );
}

Performance.layout = adminLayout;
