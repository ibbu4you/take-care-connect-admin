import { Head, router } from '@inertiajs/react';
import { ScrollText, Search } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { Input, Select } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { Pagination } from '@/Components/ui/pagination';
import { Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { adminLayout } from '@/Layouts/AdminLayout';
import { dateTime, relative } from '@/lib/format';
import type { Paginated } from '@/types';

interface Change {
    field: string;
    from: string;
    to: string;
}

interface Entry {
    id: number;
    description: string;
    event: string | null;
    subject: string | null;
    subject_id: number | null;
    causer: { name: string; email: string } | null;
    changes: Change[];
    created_at: string | null;
}

interface AuditIndexProps {
    activities: Paginated<Entry>;
    filters: { search: string; event: string; causer: string };
    events: string[];
    causers: Array<{ id: number; name: string }>;
}

/** Colour by what the event did, not by which model it happened to. */
const EVENT_TONE: Record<string, 'success' | 'info' | 'danger' | 'default'> = {
    created: 'success',
    updated: 'info',
    deleted: 'danger',
    login: 'default',
    logout: 'default',
};

export default function AuditIndex({ activities, filters, events, causers }: AuditIndexProps) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<AuditIndexProps['filters']>) {
        router.get(
            '/audit',
            { ...filters, search, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <div className="space-y-6">
            <Head title="Audit log" />

            <PageHeader
                title="Audit log"
                subtitle="Every change to a record, and who made it. Read-only — entries cannot be edited or deleted by anyone."
            />

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
                            placeholder="Search descriptions…"
                            icon={<Search />}
                            className="max-w-md"
                            aria-label="Search the audit log"
                        />
                    </form>

                    <Select
                        value={filters.event}
                        onChange={(event) => applyFilters({ event: event.target.value })}
                        className="w-auto min-w-36"
                        aria-label="Filter by event"
                    >
                        <option value="">All events</option>
                        {events.map((event) => (
                            <option key={event} value={event}>
                                {event}
                            </option>
                        ))}
                    </Select>

                    <Select
                        value={filters.causer}
                        onChange={(event) => applyFilters({ causer: event.target.value })}
                        className="w-auto min-w-44"
                        aria-label="Filter by person"
                    >
                        <option value="">Anyone</option>
                        {causers.map((causer) => (
                            <option key={causer.id} value={causer.id}>
                                {causer.name}
                            </option>
                        ))}
                    </Select>
                </div>

                {activities.data.length === 0 ? (
                    <EmptyState
                        icon={ScrollText}
                        title="Nothing logged yet"
                        description="Changes to accounts, interviews, articles and deliverables will appear here as the team works."
                    />
                ) : (
                    <>
                        <TableWrap className="hidden md:block">
                            <Table>
                                <THead>
                                    <TR className="hover:bg-transparent">
                                        <TH>Who</TH>
                                        <TH>What</TH>
                                        <TH>Changed</TH>
                                        <TH>When</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {activities.data.map((entry) => (
                                        <TR key={entry.id} className="align-top">
                                            <TD>
                                                {entry.causer ? (
                                                    <div className="flex items-center gap-2">
                                                        <Avatar name={entry.causer.name} size="sm" />
                                                        <span className="truncate text-sm font-medium">
                                                            {entry.causer.name}
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">System</span>
                                                )}
                                            </TD>

                                            <TD>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {entry.event && (
                                                        <Badge tone={EVENT_TONE[entry.event] ?? 'default'}>
                                                            {entry.event}
                                                        </Badge>
                                                    )}
                                                    <span className="text-sm">{entry.description}</span>
                                                    {entry.subject && (
                                                        <span className="text-xs text-muted-foreground">
                                                            {entry.subject}
                                                            {entry.subject_id ? ` #${entry.subject_id}` : ''}
                                                        </span>
                                                    )}
                                                </div>
                                            </TD>

                                            <TD>
                                                <ChangeList changes={entry.changes} />
                                            </TD>

                                            <TD>
                                                <span
                                                    className="whitespace-nowrap text-xs text-muted-foreground"
                                                    title={dateTime(entry.created_at)}
                                                >
                                                    {relative(entry.created_at)}
                                                </span>
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </TableWrap>

                        <ul className="divide-y divide-border md:hidden">
                            {activities.data.map((entry) => (
                                <li key={entry.id} className="space-y-2 p-4">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="truncate text-sm font-medium">
                                            {entry.causer?.name ?? 'System'}
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {relative(entry.created_at)}
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {entry.event && (
                                            <Badge tone={EVENT_TONE[entry.event] ?? 'default'}>{entry.event}</Badge>
                                        )}
                                        <span className="text-sm">{entry.description}</span>
                                    </div>
                                    <ChangeList changes={entry.changes} />
                                </li>
                            ))}
                        </ul>

                        <Pagination paginator={activities} />
                    </>
                )}
            </Card>
        </div>
    );
}

/**
 * The before-and-after for one entry.
 *
 * Capped at three fields with a count for the rest: a bulk permission change
 * can touch dozens, and letting one row grow to fifty lines makes the log
 * unscannable — which is the only thing it is for.
 */
function ChangeList({ changes }: { changes: Change[] }) {
    if (changes.length === 0) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    const shown = changes.slice(0, 3);
    const hidden = changes.length - shown.length;

    return (
        <ul className="space-y-0.5 text-xs">
            {shown.map((change) => (
                <li key={change.field} className="flex flex-wrap items-baseline gap-1">
                    <span className="font-medium text-foreground">{change.field}</span>
                    <span className="text-muted-foreground line-through">{truncate(change.from)}</span>
                    <span className="text-muted-foreground" aria-label="changed to">
                        →
                    </span>
                    <span className="text-foreground">{truncate(change.to)}</span>
                </li>
            ))}
            {hidden > 0 && <li className="text-muted-foreground">+{hidden} more</li>}
        </ul>
    );
}

function truncate(value: string, max = 32): string {
    return value.length > max ? `${value.slice(0, max)}…` : value;
}

AuditIndex.layout = adminLayout;
