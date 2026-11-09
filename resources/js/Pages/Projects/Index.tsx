import { Head, Link, router } from '@inertiajs/react';
import { CircleAlert, FolderKanban, IndianRupee, PackageCheck, Pencil, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { StatCard, StatGrid } from '@/Components/data/StatCard';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Select } from '@/Components/ui/input';
import { EmptyState } from '@/Components/ui/misc';
import { Pagination } from '@/Components/ui/pagination';
import { StageBadge } from '@/Components/ui/badge';
import { CellStack, Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { adminLayout } from '@/Layouts/AdminLayout';
import { date, due, money, moneyCompact } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

export interface ProjectRow {
    id: number;
    code: string;
    title: string;
    kind: string;
    kind_label: string;
    status: string;
    client: { id: number; name: string } | null;
    owner: { id: number; name: string } | null;
    due_on: string | null;
    value_amount: number | null;
    interviews_count: number;
    progress: { done: number; total: number; percent: number | null };
    is_overdue: boolean;
    can: { edit: boolean };
}

interface Option {
    value: string;
    label: string;
    description: string;
}

interface IndexProps {
    projects: Paginated<ProjectRow>;
    filters: { search: string; status: string; kind: string; owner: string };
    owners: Array<{ id: number; name: string }>;
    kinds: Option[];
    statuses: Option[];
    counts: { open: number; overdue: number; delivered: number; open_value: number };
    can: { create: boolean };
}

export default function ProjectsIndex({
    projects,
    filters,
    owners,
    kinds,
    statuses,
    counts,
    can,
}: IndexProps) {
    const [search, setSearch] = useState(filters.search);

    function applyFilters(next: Partial<IndexProps['filters']>) {
        router.get(
            '/projects',
            { ...filters, search, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <div className="space-y-6">
            <Head title="Projects" />

            <PageHeader
                title="Projects"
                subtitle="What a client bought, and how much of it has shipped. Interviews are booked against a project so a package of six reads as one job, not six."
                actions={
                    can.create && (
                        <Button asChild>
                            <Link href="/projects/create">
                                <Plus />
                                New project
                            </Link>
                        </Button>
                    )
                }
            />

            <StatGrid count={4}>
                <StatCard label="Open" value={counts.open} icon={FolderKanban} tone="primary" />
                <StatCard
                    label="Overdue"
                    value={counts.overdue}
                    icon={CircleAlert}
                    tone={counts.overdue > 0 ? 'danger' : 'success'}
                    sub={counts.overdue > 0 ? 'Past the promised date' : 'Nothing late'}
                />
                <StatCard
                    label="Delivered"
                    value={counts.delivered}
                    icon={PackageCheck}
                    tone="success"
                    sub="Delivered or closed"
                />
                <StatCard
                    label="Open value"
                    value={moneyCompact(counts.open_value)}
                    icon={IndianRupee}
                    tone="info"
                    sub="Sold, not yet delivered"
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
                            aria-label="Search projects"
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
                    </Select>

                    <Select
                        value={filters.kind}
                        onChange={(event) => applyFilters({ kind: event.target.value })}
                        className="w-auto min-w-40"
                        aria-label="Filter by kind"
                    >
                        <option value="">Any kind</option>
                        {kinds.map((kind) => (
                            <option key={kind.value} value={kind.value}>
                                {kind.label}
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

                {projects.data.length === 0 ? (
                    <EmptyState
                        icon={FolderKanban}
                        title="No projects match those filters"
                        description="A project is what a won deal becomes — the package of work you now owe a client."
                        action={
                            can.create ? (
                                <Button asChild>
                                    <Link href="/projects/create">
                                        <Plus />
                                        New project
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
                                        <TH>Project</TH>
                                        <TH>Client</TH>
                                        <TH>Progress</TH>
                                        <TH>Value</TH>
                                        <TH>Due</TH>
                                        <TH>Status</TH>
                                        <TH />
                                    </TR>
                                </THead>
                                <TBody>
                                    {projects.data.map((project) => (
                                        <TR key={project.id} href={`/projects/${project.id}`}>
                                            <TD>
                                                <Link
                                                    href={`/projects/${project.id}`}
                                                    className="block hover:text-primary"
                                                >
                                                    <CellStack
                                                        primary={project.title}
                                                        secondary={`${project.code} · ${project.kind_label}`}
                                                    />
                                                </Link>
                                            </TD>

                                            <TD>
                                                {project.client ? (
                                                    <Link
                                                        href={`/clients/${project.client.id}`}
                                                        className="text-sm hover:text-primary"
                                                    >
                                                        {project.client.name}
                                                    </Link>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">—</span>
                                                )}
                                            </TD>

                                            <TD>
                                                <Progress progress={project.progress} />
                                            </TD>

                                            <TD className="text-sm tabular">
                                                {project.value_amount !== null ? money(project.value_amount) : '—'}
                                            </TD>

                                            <TD>
                                                <Due dueOn={project.due_on} status={project.status} overdue={project.is_overdue} />
                                            </TD>

                                            <TD>
                                                <StageBadge family="project" stage={project.status} />
                                            </TD>

                                            <TD>
                                                <div className="flex justify-end">
                                                    {project.can.edit && (
                                                        <Button
                                                            asChild
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Edit ${project.title}`}
                                                        >
                                                            <Link href={`/projects/${project.id}/edit`}>
                                                                <Pencil />
                                                            </Link>
                                                        </Button>
                                                    )}
                                                </div>
                                            </TD>
                                        </TR>
                                    ))}
                                </TBody>
                            </Table>
                        </TableWrap>

                        <ul className="divide-y divide-border md:hidden">
                            {projects.data.map((project) => (
                                <li key={project.id} className="p-4">
                                    <Link href={`/projects/${project.id}`} className="block">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">{project.title}</p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {project.client?.name ?? project.code}
                                                </p>
                                            </div>
                                            <StageBadge family="project" stage={project.status} />
                                        </div>

                                        <div className="mt-3">
                                            <Progress progress={project.progress} />
                                        </div>

                                        <div className="mt-2 flex items-center justify-between text-xs">
                                            <Due dueOn={project.due_on} status={project.status} overdue={project.is_overdue} />
                                            <span className="tabular text-muted-foreground">
                                                {project.value_amount !== null
                                                    ? moneyCompact(project.value_amount)
                                                    : ''}
                                            </span>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>

                        <Pagination paginator={projects} />
                    </>
                )}
            </Card>
        </div>
    );
}

/**
 * "4 of 6" with a bar.
 *
 * A project with no bookings yet shows nothing rather than an empty bar at 0%:
 * a package sold this morning is not 0% delivered, it is not started, and a red
 * empty bar against it reads as a failure on the first day.
 */
function Progress({ progress }: { progress: ProjectRow['progress'] }) {
    if (progress.percent === null) {
        return <span className="text-sm text-muted-foreground">Not booked yet</span>;
    }

    return (
        <div className="min-w-28 max-w-40">
            <div className="mb-1 flex items-baseline justify-between gap-2 text-xs">
                <span className="tabular font-medium">
                    {progress.done} of {progress.total}
                </span>
                <span className="text-muted-foreground">{progress.percent}%</span>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    className={cn(
                        'h-full rounded-full transition-all',
                        progress.percent === 100 ? 'bg-success' : 'bg-primary',
                    )}
                    style={{ width: `${progress.percent}%` }}
                />
            </div>
        </div>
    );
}

/*
 | The due date, coloured by how close it is.
 |
 | ── Why a finished project shows only a date ───────────────────────────────
 | `due()` says "45d overdue" for any past date, which is right for open work
 | and wrong for finished work: a project delivered in July was due in July and
 | is not late, it is done. Showing it in red is a lie somebody could read over
 | a manager's shoulder, and it makes the overdue count on this page disagree
 | with the rows underneath it.
 |
 | So urgency language is reserved for projects still running. Everything else
 | just states the date.
 */
const DUE_TONE = {
    muted: 'text-muted-foreground',
    warning: 'text-warning',
    danger: 'font-medium text-destructive',
} as const;

function Due({ dueOn, status, overdue }: { dueOn: string | null; status: string; overdue: boolean }) {
    if (!dueOn) {
        return <span className="text-sm text-muted-foreground">No date</span>;
    }

    if (status !== 'open') {
        return <span className="text-sm text-muted-foreground">{date(dueOn)}</span>;
    }

    const { label, tone } = due(dueOn);

    return <span className={cn('text-sm', overdue ? DUE_TONE.danger : DUE_TONE[tone])}>{label}</span>;
}

ProjectsIndex.layout = adminLayout;
