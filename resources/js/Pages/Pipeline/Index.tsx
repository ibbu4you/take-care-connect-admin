import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Handshake, Plus, Search, Target as TargetIcon, TrendingUp } from 'lucide-react';
import { useState } from 'react';
import { StatCard, StatGrid } from '@/Components/data/StatCard';
import { ViewSwitch } from '@/Components/work/ViewSwitch';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge, StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Select } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { Pagination } from '@/Components/ui/pagination';
import { CellStack, Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { adminLayout } from '@/Layouts/AdminLayout';
import { date, money, moneyCompact } from '@/lib/format';
import type { Paginated } from '@/types';
import type { DealRow } from './Board';
import { NewDealDialog } from '@/Components/work/NewDealDialog';

interface IndexProps {
    deals: Paginated<DealRow>;
    filters: { search: string; stage: string; owner: string; outcome: string };
    stages: Array<{ value: string; label: string }>;
    owners: Array<{ id: number; name: string }>;
    clients: Array<{ id: number; name: string }>;
    can: { create: boolean };
    summary: {
        open_count: number;
        pipeline_value: number;
        weighted_value: number;
        won_this_month: number;
        revenue_target: number | null;
        attainment: number | null;
        idle_count: number;
    };
}

export default function DealsIndex({ deals, filters, stages, owners, clients, summary, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search);
    const [adding, setAdding] = useState(false);

    function applyFilters(next: Partial<IndexProps['filters']>) {
        router.get(
            '/deals',
            { ...filters, search, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <div className="space-y-6">
            <Head title="Deals" />

            <PageHeader
                title="Deals"
                subtitle="Every deal, open and closed. The board shows only what is still live."
                actions={
                    <>
                        <ViewSwitch current="list" boardHref="/pipeline" listHref="/deals" />

                        {can.create && (
                            <Button onClick={() => setAdding(true)}>
                                <Plus />
                                New deal
                            </Button>
                        )}
                    </>
                }
            />

            <StatGrid count={4}>
                <StatCard label="Open deals" value={summary.open_count} icon={Handshake} tone="primary" />
                <StatCard
                    label="Pipeline value"
                    value={moneyCompact(summary.pipeline_value)}
                    icon={TrendingUp}
                    tone="info"
                    sub={`${moneyCompact(summary.weighted_value)} weighted`}
                />
                <StatCard
                    label="Won this month"
                    value={moneyCompact(summary.won_this_month)}
                    icon={TargetIcon}
                    tone="success"
                    sub={summary.attainment !== null ? `${summary.attainment}% of target` : 'No target set'}
                />
                <StatCard
                    label="Gone quiet"
                    value={summary.idle_count}
                    icon={AlertTriangle}
                    tone={summary.idle_count > 0 ? 'warning' : 'primary'}
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
                            placeholder="Search title, code or client…"
                            icon={<Search />}
                            className="max-w-md"
                            aria-label="Search deals"
                        />
                    </form>

                    <Select
                        value={filters.outcome}
                        onChange={(event) => applyFilters({ outcome: event.target.value })}
                        className="w-auto min-w-32"
                        aria-label="Filter by outcome"
                    >
                        <option value="">All</option>
                        <option value="open">Open</option>
                        <option value="won">Won</option>
                        <option value="lost">Lost</option>
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

                {deals.data.length === 0 ? (
                    <EmptyState
                        icon={Handshake}
                        title="No deals match those filters"
                        description="Open a deal from the pipeline board or a client's page."
                    />
                ) : (
                    <>
                        <TableWrap className="hidden md:block">
                            <Table>
                                <THead>
                                    <TR className="hover:bg-transparent">
                                        <TH>Deal</TH>
                                        <TH>Owner</TH>
                                        <TH>Value</TH>
                                        <TH>Close</TH>
                                        <TH>Stage</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {deals.data.map((deal) => (
                                        <TR key={deal.id} href={`/deals/${deal.id}`}>
                                            <TD>
                                                <Link href={`/deals/${deal.id}`} className="block hover:text-primary">
                                                    <CellStack
                                                        primary={deal.title}
                                                        secondary={
                                                            <>
                                                                <span className="tabular">{deal.code}</span>
                                                                {deal.client ? ` · ${deal.client.name}` : ''}
                                                            </>
                                                        }
                                                    />
                                                </Link>
                                            </TD>

                                            <TD>
                                                {deal.owner ? (
                                                    <div className="flex items-center gap-2">
                                                        <Avatar name={deal.owner.name} size="sm" />
                                                        <span className="truncate text-sm">{deal.owner.name}</span>
                                                    </div>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">—</span>
                                                )}
                                            </TD>

                                            <TD>
                                                <span className="whitespace-nowrap text-sm font-medium tabular">
                                                    {money(deal.actual_value ?? deal.expected_value)}
                                                </span>
                                                {deal.outcome === null && (
                                                    <span className="block text-xs text-muted-foreground tabular">
                                                        {money(deal.weighted_value)} weighted
                                                    </span>
                                                )}
                                            </TD>

                                            <TD>
                                                <span className="whitespace-nowrap text-sm text-muted-foreground">
                                                    {deal.closed_at
                                                        ? date(deal.closed_at)
                                                        : deal.expected_close_on
                                                          ? date(deal.expected_close_on)
                                                          : '—'}
                                                </span>
                                            </TD>

                                            <TD>
                                                <div className="flex flex-col items-start gap-1">
                                                    <StageBadge family="pipeline" stage={deal.stage} dot />
                                                    {deal.is_idle && (
                                                        <span className="text-xs text-warning">
                                                            quiet {deal.idle_days}d
                                                        </span>
                                                    )}
                                                </div>
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </TableWrap>

                        <ul className="divide-y divide-border md:hidden">
                            {deals.data.map((deal) => (
                                <li key={deal.id}>
                                    <Link href={`/deals/${deal.id}`} className="block space-y-1 p-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="min-w-0 font-medium">{deal.title}</span>
                                            <StageBadge family="pipeline" stage={deal.stage} />
                                        </div>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {deal.client?.name} · {money(deal.actual_value ?? deal.expected_value)}
                                        </p>
                                    </Link>
                                </li>
                            ))}
                        </ul>

                        <Pagination paginator={deals} />
                    </>
                )}
            </Card>

            <NewDealDialog
                open={adding}
                onClose={() => setAdding(false)}
                clients={clients}
                stages={stages}
            />
        </div>
    );
}

DealsIndex.layout = adminLayout;
