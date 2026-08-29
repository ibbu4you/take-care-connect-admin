import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    CalendarPlus,
    Handshake,
    Mic,
    Pencil,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge, StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { ConfirmDialog } from '@/Components/ui/dialog';
import { EmptyState } from '@/Components/ui/misc';
import { CellStack, Table, TableWrap, TBody, TD, TH, THead, TR } from '@/Components/ui/table';
import { adminLayout } from '@/Layouts/AdminLayout';
import { date, dateTime, due, money } from '@/lib/format';
import { cn } from '@/lib/utils';

interface Option {
    value: string;
    label: string;
    description: string;
}

interface ShowProps {
    project: {
        id: number;
        code: string;
        title: string;
        kind: string;
        kind_label: string;
        status: string;
        client: {
            id: number;
            name: string;
            contact_person: string | null;
            phone: string | null;
            city: string | null;
        } | null;
        owner: { id: number; name: string } | null;
        starts_on: string | null;
        due_on: string | null;
        value_amount: number | null;
        notes: string | null;
        progress: { done: number; total: number; percent: number | null };
        is_overdue: boolean;
        interviews: Array<{
            id: number;
            code: string;
            title: string;
            kind: string;
            stage: string;
            scheduled_at: string | null;
            anchor: { id: number; name: string } | null;
        }>;
        deals: Array<{
            id: number;
            code: string;
            title: string;
            stage: string;
            expected_value: number;
            actual_value: number | null;
            outcome: string | null;
        }>;
    };
    statuses: Option[];
    can: { update: boolean; delete: boolean; bookInterview: boolean };
}

export default function ProjectShow({ project, statuses, can }: ShowProps) {
    const [confirmDelete, setConfirmDelete] = useState(false);
    const dueMeta = due(project.due_on);

    return (
        <div className="space-y-6">
            <Head title={project.title} />

            <PageHeader
                title={project.title}
                subtitle={`${project.code} · ${project.kind_label}${project.client ? ` · ${project.client.name}` : ''}`}
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href="/projects">
                                <ArrowLeft />
                                All projects
                            </Link>
                        </Button>

                        {can.bookInterview && (
                            <Button asChild variant="outline">
                                <Link href={`/interviews/create?project=${project.id}`}>
                                    <CalendarPlus />
                                    Book an interview
                                </Link>
                            </Button>
                        )}

                        {can.update && (
                            <Button asChild>
                                <Link href={`/projects/${project.id}/edit`}>
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="space-y-6">
                    {/*
                      | Delivery first, because it is the one question this page
                      | exists to answer. Everything else here is reference.
                    */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Delivery</CardTitle>
                        </CardHeader>

                        <CardContent>
                            {project.progress.percent === null ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing booked against this project yet. Interviews scheduled against it will
                                    show up here and count towards delivery.
                                </p>
                            ) : (
                                <>
                                    <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                                        <span className="text-sm">
                                            <span className="font-bold tabular">
                                                {project.progress.done} of {project.progress.total}
                                            </span>
                                            <span className="text-muted-foreground"> handed over</span>
                                        </span>
                                        <span className="text-sm font-medium tabular">
                                            {project.progress.percent}%
                                        </span>
                                    </div>

                                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className={cn(
                                                'h-full rounded-full transition-all',
                                                project.progress.percent === 100 ? 'bg-success' : 'bg-primary',
                                            )}
                                            style={{ width: `${project.progress.percent}%` }}
                                        />
                                    </div>

                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Counted from the bookings underneath, never stored — so it cannot go stale.
                                        Cancelled bookings are left out entirely.
                                    </p>
                                </>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Interviews</CardTitle>
                        </CardHeader>

                        {project.interviews.length === 0 ? (
                            <EmptyState
                                icon={Mic}
                                title="No interviews yet"
                                description="Book one against this project and it will count towards delivery."
                                action={
                                    can.bookInterview ? (
                                        <Button asChild>
                                            <Link href={`/interviews/create?project=${project.id}`}>
                                                <CalendarPlus />
                                                Book an interview
                                            </Link>
                                        </Button>
                                    ) : undefined
                                }
                            />
                        ) : (
                            <TableWrap>
                                <Table>
                                    <THead>
                                        <TR className="hover:bg-transparent">
                                            <TH>Interview</TH>
                                            <TH>Anchor</TH>
                                            <TH>When</TH>
                                            <TH>Stage</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {project.interviews.map((interview) => (
                                            <TR key={interview.id}>
                                                <TD>
                                                    <Link
                                                        href={`/interviews/${interview.id}`}
                                                        className="block hover:text-primary"
                                                    >
                                                        <CellStack
                                                            primary={interview.title}
                                                            secondary={interview.code}
                                                        />
                                                    </Link>
                                                </TD>
                                                <TD className="text-sm">{interview.anchor?.name ?? '—'}</TD>
                                                <TD className="text-sm text-muted-foreground">
                                                    {interview.scheduled_at
                                                        ? dateTime(interview.scheduled_at)
                                                        : '—'}
                                                </TD>
                                                <TD>
                                                    <StageBadge family="interview" stage={interview.stage} />
                                                </TD>
                                            </TR>
                                        ))}
                                    </TBody>
                                </Table>
                            </TableWrap>
                        )}
                    </Card>

                    {project.deals.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Deals</CardTitle>
                            </CardHeader>

                            <TableWrap>
                                <Table>
                                    <THead>
                                        <TR className="hover:bg-transparent">
                                            <TH>Deal</TH>
                                            <TH>Value</TH>
                                            <TH>Stage</TH>
                                        </TR>
                                    </THead>
                                    <TBody>
                                        {project.deals.map((deal) => (
                                            <TR key={deal.id}>
                                                <TD>
                                                    <Link
                                                        href={`/deals/${deal.id}`}
                                                        className="block hover:text-primary"
                                                    >
                                                        <CellStack primary={deal.title} secondary={deal.code} />
                                                    </Link>
                                                </TD>
                                                <TD className="text-sm tabular">
                                                    {money(deal.actual_value ?? deal.expected_value)}
                                                    {deal.actual_value === null && (
                                                        <span className="ml-1 text-xs text-muted-foreground">
                                                            expected
                                                        </span>
                                                    )}
                                                </TD>
                                                <TD>
                                                    <StageBadge family="pipeline" stage={deal.stage} />
                                                </TD>
                                            </TR>
                                        ))}
                                    </TBody>
                                </Table>
                            </TableWrap>
                        </Card>
                    )}

                    {project.notes && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Notes</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="whitespace-pre-wrap text-sm">{project.notes}</p>
                            </CardContent>
                        </Card>
                    )}
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-3">
                            <StageBadge family="project" stage={project.status} dot />

                            {can.update ? (
                                <>
                                    <p className="text-xs text-muted-foreground">
                                        Delivered means the work is done. Closed means it is done and settled.
                                    </p>

                                    <div className="grid gap-2">
                                        {statuses
                                            .filter((status) => status.value !== project.status)
                                            .map((status) => (
                                                <Button
                                                    key={status.value}
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.patch(
                                                            `/projects/${project.id}/status`,
                                                            { status: status.value },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    Mark {status.label.toLowerCase()}
                                                </Button>
                                            ))}
                                    </div>
                                </>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    Only the sales side can change what was sold.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-3 text-sm">
                            <Detail label="Value">
                                {project.value_amount !== null ? money(project.value_amount) : '—'}
                            </Detail>
                            <Detail label="Starts">
                                {project.starts_on ? date(project.starts_on) : '—'}
                            </Detail>
                            <Detail label="Due">
                                {project.due_on ? (
                                    <span
                                        className={cn(
                                            project.is_overdue && 'font-medium text-destructive',
                                        )}
                                    >
                                        {date(project.due_on)}
                                        {project.is_overdue && (
                                            <span className="ml-1 text-xs">({dueMeta.label})</span>
                                        )}
                                    </span>
                                ) : (
                                    '—'
                                )}
                            </Detail>
                            <Detail label="Owner">{project.owner?.name ?? '—'}</Detail>
                            <Detail label="Kind">
                                <Badge>{project.kind_label}</Badge>
                            </Detail>
                        </CardContent>
                    </Card>

                    {project.client && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Client</CardTitle>
                            </CardHeader>

                            <CardContent className="space-y-2">
                                <Link
                                    href={`/clients/${project.client.id}`}
                                    className="flex items-center gap-2 text-sm font-medium hover:text-primary"
                                >
                                    <Building2 className="size-4 shrink-0 text-muted-foreground" />
                                    {project.client.name}
                                </Link>

                                {project.client.contact_person && (
                                    <p className="text-sm text-muted-foreground">
                                        {project.client.contact_person}
                                    </p>
                                )}
                                {project.client.phone && (
                                    <a
                                        href={`tel:${project.client.phone}`}
                                        className="block text-sm text-muted-foreground hover:text-primary"
                                    >
                                        {project.client.phone}
                                    </a>
                                )}
                                {project.client.city && (
                                    <p className="text-sm text-muted-foreground">{project.client.city}</p>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {can.delete && (
                        <Button
                            variant="ghost"
                            className="w-full text-destructive hover:bg-destructive/10"
                            onClick={() => setConfirmDelete(true)}
                        >
                            <Trash2 />
                            Remove project
                        </Button>
                    )}

                    {!can.delete && project.interviews.length > 0 && (
                        <p className="flex items-start gap-2 text-xs text-muted-foreground">
                            <Handshake className="mt-0.5 size-3.5 shrink-0" />
                            A project with interviews booked against it cannot be removed. Mark it cancelled
                            instead — that keeps the history.
                        </p>
                    )}
                </div>
            </div>

            <ConfirmDialog
                open={confirmDelete}
                onClose={() => setConfirmDelete(false)}
                onConfirm={() =>
                    router.delete(`/projects/${project.id}`, { onFinish: () => setConfirmDelete(false) })
                }
                title={`Remove ${project.code}?`}
                description="This is for a project created by mistake. If the work was called off, mark it cancelled instead so the record survives."
                confirmLabel="Remove project"
            />
        </div>
    );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <span className="shrink-0 text-xs uppercase tracking-wide text-muted-foreground">{label}</span>
            <span className="min-w-0 text-right">{children}</span>
        </div>
    );
}

ProjectShow.layout = adminLayout;
