import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Building2,
    ExternalLink,
    FolderKanban,
    Globe,
    Handshake,
    Mail,
    MapPin,
    Mic,
    Pencil,
    Phone,
    Plus,
} from 'lucide-react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge, StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Avatar, EmptyState, SectionDivider } from '@/Components/ui/misc';
import { adminLayout } from '@/Layouts/AdminLayout';
import { date, moneyCompact } from '@/lib/format';

interface ShowProps {
    client: {
        id: number;
        name: string;
        contact_person: string | null;
        email: string | null;
        phone: string | null;
        whatsapp: string | null;
        website: string | null;
        instagram: string | null;
        city: string | null;
        state: string | null;
        trade: string | null;
        address: string | null;
        status: string;
        source: string | null;
        notes: string | null;
        owner: { id: number; name: string } | null;
        projects: Array<{ id: number; code: string; title: string; status: string; due_on: string | null }>;
        interviews: Array<{
            id: number;
            code: string;
            title: string;
            kind: string;
            stage: string;
            scheduled_at: string | null;
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
    can: { update: boolean; createDeal: boolean; bookInterview: boolean; createProject: boolean };
}

export default function ClientShow({ client, can }: ShowProps) {
    // What this account has actually been worth, rather than what was hoped for.
    const won = client.deals
        .filter((deal) => deal.outcome === 'won')
        .reduce((sum, deal) => sum + (deal.actual_value ?? 0), 0);

    return (
        <div className="space-y-6">
            <Head title={client.name} />

            <PageHeader
                title={client.name}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <Badge tone={client.status === 'active' ? 'success' : 'default'}>{client.status}</Badge>
                        {client.trade && <span className="text-sm text-muted-foreground">{client.trade}</span>}
                        {client.city && (
                            <span className="text-sm text-muted-foreground">
                                {[client.city, client.state].filter(Boolean).join(', ')}
                            </span>
                        )}
                    </span>
                }
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href="/clients">
                                <ArrowLeft />
                                Clients
                            </Link>
                        </Button>
                        {can.bookInterview && (
                            <Button asChild variant="outline">
                                {/* Carries the client, so the form opens with it chosen. */}
                                <Link href={`/interviews/create?client=${client.id}`}>
                                    <Mic />
                                    Book an interview
                                </Link>
                            </Button>
                        )}
                        {can.update && (
                            <Button asChild>
                                <Link href={`/clients/${client.id}/edit`}>
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Handshake className="size-4" aria-hidden="true" />
                                Deals
                                {won > 0 && <Badge tone="success">{moneyCompact(won)} won</Badge>}
                            </CardTitle>
                        </CardHeader>

                        {client.deals.length === 0 ? (
                            <EmptyState icon={Handshake} title="No deals yet" />
                        ) : (
                            <ul className="divide-y divide-border">
                                {client.deals.map((deal) => (
                                    <li key={deal.id}>
                                        <Link
                                            href={`/deals/${deal.id}`}
                                            className="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/40"
                                        >
                                            <StageBadge family="pipeline" stage={deal.stage} dot />
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">{deal.title}</p>
                                                <p className="truncate text-xs text-muted-foreground tabular">
                                                    {deal.code}
                                                </p>
                                            </div>
                                            <span className="shrink-0 text-sm font-semibold tabular">
                                                {moneyCompact(deal.actual_value ?? deal.expected_value)}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Mic className="size-4" aria-hidden="true" />
                                Interviews
                            </CardTitle>
                        </CardHeader>

                        {client.interviews.length === 0 ? (
                            <EmptyState
                                icon={Mic}
                                title="No interviews yet"
                                description="Book one to start producing work for this client."
                            />
                        ) : (
                            <ul className="divide-y divide-border">
                                {client.interviews.map((interview) => (
                                    <li key={interview.id}>
                                        <Link
                                            href={`/interviews/${interview.id}`}
                                            className="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/40"
                                        >
                                            <StageBadge family="interview" stage={interview.stage} dot />
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">{interview.title}</p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    <span className="tabular">{interview.code}</span>
                                                    {interview.scheduled_at
                                                        ? ` · ${date(interview.scheduled_at)}`
                                                        : ''}
                                                </p>
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    {(client.projects.length > 0 || can.createProject) && (
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between gap-3">
                                <CardTitle className="flex items-center gap-2">
                                    <FolderKanban className="size-4" aria-hidden="true" />
                                    Projects
                                </CardTitle>

                                {can.createProject && (
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={`/projects/create?client=${client.id}`}>
                                            <Plus />
                                            New project
                                        </Link>
                                    </Button>
                                )}
                            </CardHeader>

                            {client.projects.length === 0 ? (
                                <CardContent>
                                    <p className="text-sm text-muted-foreground">
                                        Nothing sold to them yet. A project is the package of work a won deal
                                        turns into.
                                    </p>
                                </CardContent>
                            ) : (
                                <ul className="divide-y divide-border">
                                    {client.projects.map((project) => (
                                        <li key={project.id}>
                                            <Link
                                                href={`/projects/${project.id}`}
                                                className="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/50"
                                            >
                                                <StageBadge family="project" stage={project.status} />
                                                <span className="min-w-0 flex-1 truncate text-sm">
                                                    {project.title}
                                                </span>
                                                <span className="shrink-0 text-xs tabular text-muted-foreground">
                                                    {project.code}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    )}
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Contact</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            {client.contact_person && (
                                <p className="font-medium">{client.contact_person}</p>
                            )}

                            {client.phone && <Detail icon={Phone}>{client.phone}</Detail>}
                            {client.whatsapp && client.whatsapp !== client.phone && (
                                <Detail icon={Phone}>{client.whatsapp} (WhatsApp)</Detail>
                            )}
                            {client.email && <Detail icon={Mail}>{client.email}</Detail>}

                            {client.website && (
                                <Detail icon={Globe}>
                                    <a
                                        href={client.website}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex items-center gap-1 text-primary hover:underline"
                                    >
                                        Website
                                        <ExternalLink className="size-3" />
                                    </a>
                                </Detail>
                            )}

                            {client.address && (
                                <>
                                    <SectionDivider label="Address" />
                                    <Detail icon={MapPin}>{client.address}</Detail>
                                </>
                            )}

                            <SectionDivider label="Owner" />
                            {client.owner ? (
                                <span className="flex items-center gap-2">
                                    <Avatar name={client.owner.name} size="sm" />
                                    <span className="truncate">{client.owner.name}</span>
                                </span>
                            ) : (
                                <span className="text-muted-foreground">Unassigned</span>
                            )}
                        </CardContent>
                    </Card>

                    {client.notes && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Notes</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="whitespace-pre-wrap text-sm text-muted-foreground">{client.notes}</p>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </div>
    );
}

function Detail({ icon: Icon, children }: { icon: typeof Phone; children: React.ReactNode }) {
    return (
        <div className="flex items-start gap-2">
            <Icon className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span className="min-w-0 break-words">{children}</span>
        </div>
    );
}

ClientShow.layout = adminLayout;
