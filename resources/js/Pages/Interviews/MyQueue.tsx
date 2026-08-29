import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, CheckCircle2, ExternalLink, Mic, Video } from 'lucide-react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge, StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { EmptyState } from '@/Components/ui/misc';
import { adminLayout } from '@/Layouts/AdminLayout';
import { date, relative, time } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { InterviewRow } from './Index';

interface MyQueueProps {
    upcoming: InterviewRow[];
    /** Conducted, but not yet moved on. The list that actually needs chasing. */
    toFinish: InterviewRow[];
}

/**
 * The anchor's own diary.
 *
 * Two lists rather than one, because they demand different things. "Upcoming"
 * is a diary — read it, turn up. "To finish" is a debt: each row is an
 * interview that already happened and is now blocking somebody else.
 * The debt goes first.
 */
export default function MyQueue({ upcoming, toFinish }: MyQueueProps) {
    return (
        <div className="space-y-6">
            <Head title="My queue" />

            <PageHeader
                title="My queue"
                subtitle="Interviews you are the anchor on. Anything conducted but not handed over is holding up the content team."
                actions={
                    <Button asChild variant="outline">
                        <Link href="/interviews">All interviews</Link>
                    </Button>
                }
            />

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        Waiting on you
                        {toFinish.length > 0 && <Badge tone="warning">{toFinish.length}</Badge>}
                    </CardTitle>
                </CardHeader>

                {toFinish.length === 0 ? (
                    <EmptyState
                        icon={CheckCircle2}
                        title="Nothing outstanding"
                        description="Every interview you have conducted has moved on."
                    />
                ) : (
                    <ul className="divide-y divide-border">
                        {toFinish.map((interview) => (
                            <QueueRow key={interview.id} interview={interview} emphasis />
                        ))}
                    </ul>
                )}
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        Coming up
                        {upcoming.length > 0 && <Badge tone="default">{upcoming.length}</Badge>}
                    </CardTitle>
                </CardHeader>

                {upcoming.length === 0 ? (
                    <EmptyState
                        icon={Mic}
                        title="Nothing scheduled"
                        description="Interviews booked for you will appear here."
                    />
                ) : (
                    <ul className="divide-y divide-border">
                        {upcoming.map((interview) => (
                            <QueueRow key={interview.id} interview={interview} />
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}

function QueueRow({ interview, emphasis = false }: { interview: InterviewRow; emphasis?: boolean }) {
    const incomplete = interview.is_ready_for_handover === false;

    return (
        <li>
            <Link
                href={`/interviews/${interview.id}`}
                className="flex items-start gap-4 p-4 transition-colors hover:bg-muted/40"
            >
                <span
                    className={cn(
                        'inline-flex size-10 shrink-0 items-center justify-center rounded-xl',
                        emphasis ? 'bg-warning/15 text-warning' : 'bg-primary/10 text-primary',
                    )}
                    aria-hidden="true"
                >
                    {emphasis ? <AlertTriangle className="size-5" /> : <CalendarClock className="size-5" />}
                </span>

                <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{interview.title}</p>
                    <p className="truncate text-xs text-muted-foreground">
                        <span className="tabular">{interview.code}</span> · {interview.subject_name}
                        {interview.subject_company ? ` · ${interview.subject_company}` : ''}
                    </p>

                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <StageBadge family="interview" stage={interview.stage} dot />

                        {interview.scheduled_at && (
                            <span className="text-xs text-muted-foreground">
                                {date(interview.scheduled_at)} · {time(interview.scheduled_at)}
                                {' · '}
                                {relative(interview.scheduled_at)}
                            </span>
                        )}

                        {!interview.requires_article && (
                            <Badge tone="default">
                                <Video className="size-3" />
                                Social only
                            </Badge>
                        )}
                    </div>

                    {incomplete && (
                        <p className="mt-2 flex items-center gap-1 text-xs text-warning">
                            <AlertTriangle className="size-3" aria-hidden="true" />
                            Handover incomplete — add what is missing before content can start.
                        </p>
                    )}

                    {interview.is_ready_for_handover === true && (
                        <p className="mt-2 flex items-center gap-1 text-xs text-success">
                            <CheckCircle2 className="size-3" aria-hidden="true" />
                            Ready to hand over.
                        </p>
                    )}
                </div>

                <ExternalLink className="mt-1 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            </Link>
        </li>
    );
}

MyQueue.layout = adminLayout;
