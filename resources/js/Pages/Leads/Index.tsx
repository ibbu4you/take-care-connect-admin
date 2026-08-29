import { Head, Link, router } from '@inertiajs/react';
import { CircleCheck, Download, PhoneCall, Plus, Search, UserSearch } from 'lucide-react';
import { useState } from 'react';
import { StatCard, StatGrid } from '@/Components/data/StatCard';
import { PageHeader } from '@/Components/layout/PageHeader';
import { StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Select } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { Pagination } from '@/Components/ui/pagination';
import { CellStack, Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { adminLayout } from '@/Layouts/AdminLayout';
import { date, due } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

export interface LeadRow {
    id: number;
    code: string;
    name: string;
    contact_person: string | null;
    phone: string | null;
    city: string | null;
    trade: string | null;
    status: string;
    status_label: string;
    source: string;
    source_label: string;
    owner: { id: number; name: string } | null;
    next_action_on: string | null;
    days_since_contact: number | null;
    is_overdue: boolean;
    created_at: string | null;
    can: { edit: boolean };
}

interface IndexProps {
    leads: Paginated<LeadRow>;
    filters: { search: string; status: string; source: string; owner: string };
    statuses: Array<{ value: string; label: string; description: string }>;
    sources: Array<{ value: string; label: string }>;
    owners: Array<{ id: number; name: string }>;
    counts: { open: number; due: number; qualified: number; converted_this_month: number };
    can: { create: boolean; export: boolean };
}

export default function LeadsIndex({ leads, filters, statuses, sources, owners, counts, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<IndexProps['filters']>) {
        router.get(
            '/leads',
            { ...filters, search, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <div className="space-y-6">
            <Head title="Leads" />

            <PageHeader
                title="Leads"
                subtitle="People you are talking to who are not clients yet. Convert one when they say yes — the lead stays, so you keep the record of how they were found."
                actions={
                    <>
                        {can.export && (
                            <Button asChild variant="outline">
                                <a href="/leads/export">
                                    <Download />
                                    Export CSV
                                </a>
                            </Button>
                        )}
                        {can.create && (
                            <Button asChild>
                                <Link href="/leads/create">
                                    <Plus />
                                    Add lead
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            <StatGrid count={4}>
                <StatCard label="Open" value={counts.open} icon={UserSearch} tone="primary" />
                <StatCard
                    label="Need a call"
                    value={counts.due}
                    icon={PhoneCall}
                    tone={counts.due > 0 ? 'warning' : 'success'}
                    sub={counts.due > 0 ? 'Due or overdue' : 'Nobody is waiting'}
                />
                <StatCard label="Qualified" value={counts.qualified} icon={CircleCheck} tone="info" sub="Ready to convert" />
                <StatCard
                    label="Converted"
                    value={counts.converted_this_month}
                    icon={CircleCheck}
                    tone="success"
                    sub="This month"
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
                            placeholder="Search name, contact, phone, trade or city…"
                            icon={<Search />}
                            className="max-w-md"
                            aria-label="Search leads"
                        />
                    </form>

                    <Select
                        value={filters.status}
                        onChange={(event) => applyFilters({ status: event.target.value })}
                        className="w-auto min-w-36"
                        aria-label="Filter by status"
                    >
                        <option value="">Any status</option>
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                        <option value="converted">Converted</option>
                    </Select>

                    <Select
                        value={filters.source}
                        onChange={(event) => applyFilters({ source: event.target.value })}
                        className="w-auto min-w-40"
                        aria-label="Filter by source"
                    >
                        <option value="">Any source</option>
                        {sources.map((source) => (
                            <option key={source.value} value={source.value}>
                                {source.label}
                            </option>
                        ))}
                    </Select>

                    {owners.length > 1 && (
                        <Select
                            value={filters.owner}
                            onChange={(event) => applyFilters({ owner: event.target.value })}
                            className="w-auto min-w-40"
                            aria-label="Filter by owner"
                        >
                            <option value="">Any owner</option>
                            {owners.map((owner) => (
                                <option key={owner.id} value={owner.id}>
                                    {owner.name}
                                </option>
                            ))}
                        </Select>
                    )}
                </div>

                {leads.data.length === 0 ? (
                    <EmptyState
                        icon={UserSearch}
                        title="No leads match those filters"
                        description="A lead is anybody worth calling back. They become a client the day they say yes."
                        action={
                            can.create ? (
                                <Button asChild>
                                    <Link href="/leads/create">
                                        <Plus />
                                        Add lead
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <>
                        <TableWrap className="hidden md:block">
                            <Table>
                                <THead>
                                    <TR className="hover:bg-transparent">
                                        <TH>Lead</TH>
                                        <TH>Contact</TH>
                                        <TH>Source</TH>
                                        <TH>Owner</TH>
                                        <TH>Next call</TH>
                                        <TH>Status</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {leads.data.map((lead) => (
                                        <TR key={lead.id}>
                                            <TD>
                                                <Link href={`/leads/${lead.id}`} className="block hover:text-primary">
                                                    <CellStack
                                                        primary={lead.name}
                                                        secondary={
                                                            [lead.trade, lead.city].filter(Boolean).join(' · ') ||
                                                            lead.code
                                                        }
                                                    />
                                                </Link>
                                            </TD>

                                            <TD>
                                                {lead.phone || lead.contact_person ? (
                                                    <CellStack
                                                        primary={lead.contact_person ?? '—'}
                                                        secondary={lead.phone ?? undefined}
                                                    />
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">—</span>
                                                )}
                                            </TD>

                                            <TD className="text-sm text-muted-foreground">{lead.source_label}</TD>

                                            <TD>
                                                {lead.owner ? (
                                                    <span className="flex items-center gap-2 text-sm">
                                                        <Avatar name={lead.owner.name} size="sm" />
                                                        <span className="truncate">{lead.owner.name}</span>
                                                    </span>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">Unowned</span>
                                                )}
                                            </TD>

                                            <TD>
                                                <NextCall lead={lead} />
                                            </TD>

                                            <TD>
                                                <StageBadge family="lead" stage={lead.status} />
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </TableWrap>

                        <ul className="divide-y divide-border md:hidden">
                            {leads.data.map((lead) => (
                                <li key={lead.id}>
                                    <Link href={`/leads/${lead.id}`} className="block p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">{lead.name}</p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {lead.phone ?? lead.contact_person ?? lead.code}
                                                </p>
                                            </div>
                                            <StageBadge family="lead" stage={lead.status} />
                                        </div>
                                        <div className="mt-2">
                                            <NextCall lead={lead} />
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>

                        <Pagination paginator={leads} />
                    </>
                )}
            </Card>
        </div>
    );
}

/**
 * When somebody should ring them, and how alarmed to look about it.
 *
 * `is_overdue` comes from the server rather than being recomputed here, because
 * it also knows whether the lead is still open — a converted lead past its date
 * is finished, not late, and colouring it red would be a lie.
 */
const DUE_TONE = {
    muted: 'text-muted-foreground',
    warning: 'text-warning',
    danger: 'font-medium text-destructive',
} as const;

function NextCall({ lead }: { lead: LeadRow }) {
    if (!lead.next_action_on) {
        return (
            <span className="text-sm text-muted-foreground">
                {lead.days_since_contact === null
                    ? 'Never contacted'
                    : `${lead.days_since_contact}d since contact`}
            </span>
        );
    }

    const { label, tone } = due(lead.next_action_on);

    return (
        <span className={cn('text-sm', lead.is_overdue ? DUE_TONE.danger : DUE_TONE[tone])}>
            {lead.status === 'converted' || lead.status === 'unqualified' ? date(lead.next_action_on) : label}
        </span>
    );
}

LeadsIndex.layout = adminLayout;
