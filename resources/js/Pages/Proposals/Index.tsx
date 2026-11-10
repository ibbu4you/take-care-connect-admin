import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Clock3, FileText, Send } from 'lucide-react';
import { Pill, type Tone } from '@/Components/dashboard/parts';
import { StatCard, StatGrid } from '@/Components/data/StatCard';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Card } from '@/Components/ui/card';
import { Select } from '@/Components/ui/input';
import { EmptyState } from '@/Components/ui/misc';
import { Pagination } from '@/Components/ui/pagination';
import { CellStack, Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { adminLayout } from '@/Layouts/AdminLayout';
import { money, relative } from '@/lib/format';
import type { Paginated } from '@/types';

interface Row {
    id: number;
    code: string;
    version: number;
    title: string;
    status: string;
    status_label: string;
    status_tone: Tone;
    total: number;
    discount_percent: number;
    deal: { id: number; code: string; title: string } | null;
    client: string | null;
    created_by: string | null;
    updated_at: string | null;
}

interface IndexProps {
    proposals: Paginated<Row>;
    filters: { status: string };
    statuses: Array<{ value: string; label: string }>;
    counts: { pending: number; ready: number; sent: number; accepted: number };
    can: { approve: boolean };
}

export default function ProposalsIndex({ proposals, filters, statuses, counts, can }: IndexProps) {
    const filter = (status: string) => router.get('/proposals', status ? { status } : {}, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <div className="space-y-6">
            <Head title="Proposals" />

            <PageHeader
                title="Proposals"
                subtitle={
                    can.approve
                        ? 'Every proposal the team has written. The ones waiting for you are those over the discount limit.'
                        : 'Proposals on your deals. Write a new one from the deal it is for.'
                }
            />

            <StatGrid count={4}>
                <StatCard
                    label="Waiting for approval"
                    value={counts.pending}
                    icon={Clock3}
                    tone="warning"
                    sub={can.approve ? 'Need your decision' : 'With a Sales Manager'}
                    href="/proposals?status=pending_approval"
                />
                <StatCard label="Ready to send" value={counts.ready} icon={Send} tone="info" sub="Approved, not sent yet" href="/proposals?status=approved" />
                <StatCard label="Sent" value={counts.sent} icon={FileText} tone="primary" sub="Waiting for an answer" href="/proposals?status=sent" />
                <StatCard label="Accepted this month" value={counts.accepted} icon={CheckCircle2} tone="success" sub="Said yes" href="/proposals?status=accepted" />
            </StatGrid>

            <Card className="overflow-hidden">
                <div className="flex flex-wrap items-center gap-3 p-4">
                    <Select value={filters.status} onChange={(event) => filter(event.target.value)} className="w-auto min-w-48" aria-label="Filter by status">
                        <option value="">Every status</option>
                        {statuses.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                    </Select>
                    <p className="ml-auto text-sm text-muted-foreground tabular">{proposals.total} proposals</p>
                </div>

                {proposals.data.length === 0 ? (
                    <div className="border-t border-border">
                        <EmptyState
                            icon={FileText}
                            title={filters.status ? 'None with that status' : 'No proposals yet'}
                            description="Proposals are written from a deal: open the deal and choose “Write a proposal”."
                        />
                    </div>
                ) : (
                    <>
                        <TableWrap className="border-t border-border">
                            <Table>
                                <THead>
                                    <TR className="hover:bg-transparent">
                                        <TH>Proposal</TH>
                                        <TH>For</TH>
                                        <TH className="text-right">Total</TH>
                                        <TH>Status</TH>
                                        <TH>Updated</TH>
                                    </TR>
                                </THead>
                                <TBody>
                                    {proposals.data.map((proposal) => (
                                        <TR key={proposal.id} href={`/proposals/${proposal.id}`} className="group">
                                            <TD>
                                                <Link href={`/proposals/${proposal.id}`} className="block group-hover:text-primary">
                                                    <CellStack
                                                        primary={proposal.title}
                                                        secondary={
                                                            <>
                                                                <span className="tabular">{proposal.code}</span>
                                                                {proposal.version > 1 && ` · v${proposal.version}`}
                                                                {proposal.created_by && ` · ${proposal.created_by}`}
                                                            </>
                                                        }
                                                    />
                                                </Link>
                                            </TD>
                                            <TD>
                                                <CellStack primary={proposal.client ?? '—'} secondary={proposal.deal?.code} />
                                            </TD>
                                            <TD className="text-right">
                                                <span className="font-medium tabular">{money(proposal.total)}</span>
                                                {proposal.discount_percent > 0 && (
                                                    <span className="block text-xs text-muted-foreground tabular">{proposal.discount_percent}% off</span>
                                                )}
                                            </TD>
                                            <TD>
                                                <Pill tone={proposal.status_tone}>{proposal.status_label}</Pill>
                                            </TD>
                                            <TD className="text-sm text-muted-foreground">{proposal.updated_at ? relative(proposal.updated_at) : '—'}</TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </TableWrap>
                        <Pagination paginator={proposals} />
                    </>
                )}
            </Card>
        </div>
    );
}

ProposalsIndex.layout = adminLayout;
