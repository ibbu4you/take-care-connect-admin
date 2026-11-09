import { Head, Link, router } from '@inertiajs/react';
import { Building2, Download, Pencil, Plus, Search, UserCheck, Users } from 'lucide-react';
import { useState } from 'react';
import { StatCard, StatGrid } from '@/Components/data/StatCard';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Select } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { Pagination } from '@/Components/ui/pagination';
import { CellStack, Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { adminLayout } from '@/Layouts/AdminLayout';
import type { Paginated } from '@/types';

export interface ClientRow {
    id: number;
    name: string;
    contact_person: string | null;
    email: string | null;
    phone: string | null;
    city: string | null;
    trade: string | null;
    status: string;
    owner: { id: number; name: string } | null;
    interviews_count: number | null;
    projects_count: number | null;
    created_at: string | null;
    can: { edit: boolean };
}

interface IndexProps {
    clients: Paginated<ClientRow>;
    filters: { search: string; status: string; owner: string };
    owners: Array<{ id: number; name: string }>;
    counts: { total: number; active: number; dormant: number };
    can: { create: boolean; export: boolean };
}

const STATUS_TONE: Record<string, 'success' | 'warning' | 'default'> = {
    active: 'success',
    dormant: 'warning',
    archived: 'default',
};

export default function ClientsIndex({ clients, filters, owners, counts, can }: IndexProps) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<IndexProps['filters']>) {
        router.get(
            '/clients',
            { ...filters, search, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <div className="space-y-6">
            <Head title="Clients" />

            <PageHeader
                title="Clients"
                subtitle="The businesses and people you look after. Interviews and deals are booked against them."
                actions={
                    <>
                        {can.export && (
                            <Button asChild variant="outline">
                                <a href="/clients/export">
                                    <Download />
                                    Export CSV
                                </a>
                            </Button>
                        )}
                        {can.create && (
                            <Button asChild>
                                <Link href="/clients/create">
                                    <Plus />
                                    Add client
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            <StatGrid count={3}>
                <StatCard label="Clients" value={counts.total} icon={Users} tone="primary" />
                <StatCard label="Active" value={counts.active} icon={UserCheck} tone="success" />
                <StatCard label="Dormant" value={counts.dormant} icon={Building2} tone="warning" sub="Worth a call" />
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
                            aria-label="Search clients"
                        />
                    </form>

                    <Select
                        value={filters.status}
                        onChange={(event) => applyFilters({ status: event.target.value })}
                        className="w-auto min-w-36"
                        aria-label="Filter by status"
                    >
                        <option value="">Any status</option>
                        <option value="active">Active</option>
                        <option value="dormant">Dormant</option>
                        <option value="archived">Archived</option>
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

                {clients.data.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No clients match those filters"
                        description="Add a client, or clear the filters to see everyone you look after."
                    />
                ) : (
                    <>
                        <TableWrap className="hidden md:block">
                            <Table>
                                <THead>
                                    <TR className="hover:bg-transparent">
                                        <TH>Client</TH>
                                        <TH>Contact</TH>
                                        <TH>Owner</TH>
                                        <TH>Work</TH>
                                        <TH>Status</TH>
                                        <TH />
                                    </TR>
                                </THead>
                                <TBody>
                                    {clients.data.map((client) => (
                                        <TR key={client.id} href={`/clients/${client.id}`}>
                                            <TD>
                                                <Link href={`/clients/${client.id}`} className="block hover:text-primary">
                                                    <CellStack
                                                        primary={client.name}
                                                        secondary={
                                                            [client.trade, client.city].filter(Boolean).join(' · ') ||
                                                            '—'
                                                        }
                                                    />
                                                </Link>
                                            </TD>

                                            <TD>
                                                <CellStack
                                                    primary={client.contact_person ?? '—'}
                                                    secondary={client.phone ?? client.email ?? ''}
                                                />
                                            </TD>

                                            <TD>
                                                {client.owner ? (
                                                    <div className="flex items-center gap-2">
                                                        <Avatar name={client.owner.name} size="sm" />
                                                        <span className="truncate text-sm">{client.owner.name}</span>
                                                    </div>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">—</span>
                                                )}
                                            </TD>

                                            <TD>
                                                <span className="whitespace-nowrap text-xs text-muted-foreground tabular">
                                                    {client.interviews_count ?? 0} interviews ·{' '}
                                                    {client.projects_count ?? 0} projects
                                                </span>
                                            </TD>

                                            <TD>
                                                <Badge tone={STATUS_TONE[client.status] ?? 'default'}>
                                                    {client.status}
                                                </Badge>
                                            </TD>

                                            <TD>
                                                {client.can.edit && (
                                                    <Button
                                                        asChild
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Edit ${client.name}`}
                                                    >
                                                        <Link href={`/clients/${client.id}/edit`}>
                                                            <Pencil />
                                                        </Link>
                                                    </Button>
                                                )}
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </TableWrap>

                        <ul className="divide-y divide-border md:hidden">
                            {clients.data.map((client) => (
                                <li key={client.id}>
                                    <Link href={`/clients/${client.id}`} className="block space-y-1 p-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <span className="min-w-0 font-medium">{client.name}</span>
                                            <Badge tone={STATUS_TONE[client.status] ?? 'default'}>
                                                {client.status}
                                            </Badge>
                                        </div>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {[client.contact_person, client.phone].filter(Boolean).join(' · ')}
                                        </p>
                                    </Link>
                                </li>
                            ))}
                        </ul>

                        <Pagination paginator={clients} />
                    </>
                )}
            </Card>
        </div>
    );
}

ClientsIndex.layout = adminLayout;
