import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Clapperboard,
    ExternalLink,
    Film,
    Globe,
    Image as ImageIcon,
    LayoutList,
    Send,
} from 'lucide-react';
import { StatCard, StatGrid } from '@/Components/data/StatCard';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge, StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { adminLayout } from '@/Layouts/AdminLayout';
import { due } from '@/lib/format';
import { cn } from '@/lib/utils';

export interface DeliverableRow {
    id: number;
    code: string;
    kind: string;
    kind_label: string;
    is_video: boolean;
    title: string | null;
    slot_number: number;
    stage: string;
    stage_label: string;
    assignee: { id: number; name: string } | null;
    due_on: string | null;
    priority: string;
    caption: string | null;
    platforms: string[];
    parent_label: string;
    parent_code: string | null;
    parent_kind: string;
    parent_id: number;
    file_count: number;
    current_version: number | null;
    has_social_link: boolean;
    social_url: string | null;
    published_at: string | null;
}

interface BoardProps {
    columns: Array<{ stage: string; label: string; items: DeliverableRow[] }>;
    rejected: DeliverableRow[];
    counts: { mine: number; inReview: number; awaitingPublish: number; publishedThisMonth: number };
}

/**
 * The creative pipeline, as columns.
 *
 * Read-only columns rather than drag-and-drop: every move here has a
 * precondition the board cannot express — a cut needs uploading before it can
 * go for approval, an approval cannot be granted by the person who made it, and
 * publishing requires the link. Dragging a card would either bypass those or
 * fail silently halfway, so the moves live on the card itself.
 */
export default function DeliverableBoard({ columns, rejected, counts }: BoardProps) {
    return (
        <div className="space-y-6">
            <Head title="Creative board" />

            <PageHeader
                title="Creative board"
                subtitle="Posters, reels and edits, by where they have got to. Work comes from an interview or a finished article."
                actions={
                    <Button asChild variant="outline">
                        <Link href="/deliverables">
                            <LayoutList />
                            List view
                        </Link>
                    </Button>
                }
            />

            <StatGrid count={4}>
                <StatCard label="Assigned to me" value={counts.mine} icon={Clapperboard} tone="primary" />
                <StatCard label="Awaiting approval" value={counts.inReview} icon={Send} tone="warning" />
                <StatCard
                    label="Approved, not posted"
                    value={counts.awaitingPublish}
                    icon={Globe}
                    tone="info"
                    sub="Cleared but not out yet"
                />
                <StatCard
                    label="Published this month"
                    value={counts.publishedThisMonth}
                    icon={ExternalLink}
                    tone="success"
                />
            </StatGrid>

            {rejected.length > 0 && (
                <Card className="border-destructive/30 bg-destructive/5">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <AlertTriangle className="size-4 text-destructive" aria-hidden="true" />
                            Sent back
                            <Badge tone="danger">{rejected.length}</Badge>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {rejected.map((item) => (
                            <DeliverableCard key={item.id} deliverable={item} />
                        ))}
                    </CardContent>
                </Card>
            )}

            {/*
              | Horizontally scrollable rather than wrapped.
              |
              | Five columns will not fit on a laptop at a readable card width,
              | and wrapping them breaks the left-to-right reading of a pipeline
              | — which is the only reason to draw it as columns at all.
            */}
            <div className="-mx-4 overflow-x-auto px-4 pb-2 lg:-mx-6 lg:px-6">
                <div className="flex min-w-max gap-4">
                    {columns.map((column) => (
                        <section key={column.stage} className="flex w-72 shrink-0 flex-col">
                            <header className="mb-3 flex items-center gap-2">
                                <StageBadge family="deliverable" stage={column.stage} dot />
                                <span className="text-xs text-muted-foreground tabular">
                                    {column.items.length}
                                </span>
                            </header>

                            <div className="flex-1 space-y-3 rounded-xl bg-muted/40 p-3">
                                {column.items.length === 0 ? (
                                    <p className="py-8 text-center text-xs text-muted-foreground">Nothing here</p>
                                ) : (
                                    column.items.map((item) => (
                                        <DeliverableCard key={item.id} deliverable={item} />
                                    ))
                                )}
                            </div>
                        </section>
                    ))}
                </div>
            </div>

            {columns.every((column) => column.items.length === 0) && rejected.length === 0 && (
                <Card>
                    <EmptyState
                        icon={Clapperboard}
                        title="No creative work yet"
                        description="Commission posters and reels from an interview or a published article."
                    />
                </Card>
            )}
        </div>
    );
}

export function DeliverableCard({ deliverable }: { deliverable: DeliverableRow }) {
    const deadline = due(deliverable.due_on);
    const Icon = deliverable.is_video ? Film : ImageIcon;

    return (
        <Link
            href={`/deliverables/${deliverable.id}`}
            className="block rounded-control border border-border bg-card p-3 shadow-sm transition-colors hover:border-primary/40"
        >
            <div className="flex items-start gap-2">
                <span
                    className="inline-flex size-8 shrink-0 items-center justify-center rounded-control bg-primary/10 text-primary"
                    aria-hidden="true"
                >
                    <Icon className="size-4" />
                </span>

                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">
                        {deliverable.kind_label} {deliverable.slot_number > 1 && `· ${deliverable.slot_number}`}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">{deliverable.parent_label}</p>
                </div>
            </div>

            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                <span className="text-[11px] text-muted-foreground tabular">{deliverable.code}</span>

                {deliverable.file_count > 0 && (
                    <Badge tone="default" className="text-[10px]">
                        v{deliverable.current_version}
                    </Badge>
                )}

                {/*
                  | Published with no link recorded is the state worth flagging:
                  | it means nobody can find the post again.
                */}
                {deliverable.stage === 'published' && !deliverable.has_social_link && (
                    <Badge tone="warning" className="text-[10px]">
                        No link
                    </Badge>
                )}

                {deliverable.social_url && (
                    <span className="inline-flex items-center gap-0.5 text-[11px] text-success">
                        <ExternalLink className="size-2.5" aria-hidden="true" />
                        live
                    </span>
                )}
            </div>

            <div className="mt-2 flex items-center justify-between gap-2">
                <span
                    className={cn(
                        'text-[11px]',
                        deadline.tone === 'danger' && 'font-medium text-destructive',
                        deadline.tone === 'warning' && 'font-medium text-warning',
                        deadline.tone === 'muted' && 'text-muted-foreground',
                    )}
                >
                    {deadline.label}
                </span>

                {deliverable.assignee ? (
                    <Avatar name={deliverable.assignee.name} size="sm" />
                ) : (
                    <Badge tone="warning" className="text-[10px]">
                        Unassigned
                    </Badge>
                )}
            </div>
        </Link>
    );
}

DeliverableBoard.layout = adminLayout;
