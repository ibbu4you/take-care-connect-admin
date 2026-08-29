import {
    DndContext,
    DragOverlay,
    PointerSensor,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
    type DragEndEvent,
    type DragStartEvent,
} from '@dnd-kit/core';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Building2, Handshake, LayoutList, Plus, Target as TargetIcon, TrendingUp } from 'lucide-react';
import { useMemo, useState } from 'react';
import { StatCard, StatGrid } from '@/Components/data/StatCard';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge, StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Dialog } from '@/Components/ui/dialog';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { adminLayout } from '@/Layouts/AdminLayout';
import { date, moneyCompact, relative } from '@/lib/format';
import { cn } from '@/lib/utils';
import { NewDealDialog } from '@/Components/work/NewDealDialog';

export interface DealRow {
    id: number;
    code: string;
    title: string;
    stage: string;
    stage_label: string;
    client: { id: number; name: string; city: string | null } | null;
    owner: { id: number; name: string } | null;
    expected_value: number;
    actual_value: number | null;
    weighted_value: number;
    probability: number | null;
    expected_close_on: string | null;
    closed_at: string | null;
    outcome: string | null;
    board_order: number;
    idle_days: number | null;
    is_idle: boolean;
    last_activity_at: string | null;
}

interface Column {
    stage: string;
    label: string;
    items: DealRow[];
    total: number;
    weighted: number;
}

interface BoardProps {
    columns: Column[];
    summary: {
        open_count: number;
        pipeline_value: number;
        weighted_value: number;
        won_this_month: number;
        revenue_target: number | null;
        attainment: number | null;
        idle_count: number;
    };
    stages: Array<{ value: string; label: string; probability: number }>;
    clients: Array<{ id: number; name: string }>;
    owners: Array<{ id: number; name: string }>;
    can: { create: boolean; move: boolean; close: boolean };
}

export default function PipelineBoard({ columns, summary, stages, clients, can }: BoardProps) {
    const [adding, setAdding] = useState(false);
    const [dragging, setDragging] = useState<DealRow | null>(null);

    /*
     | Optimistic columns.
     |
     | The server is the source of truth, but waiting for a round trip before
     | the card appears in its new column makes the board feel broken. This
     | holds the moved state until Inertia brings the real data back.
     */
    const [optimistic, setOptimistic] = useState<Record<number, string>>({});

    const shown = useMemo(
        () =>
            columns.map((column) => ({
                ...column,
                items: columns
                    .flatMap((c) => c.items)
                    .filter((deal) => (optimistic[deal.id] ?? deal.stage) === column.stage),
            })),
        [columns, optimistic],
    );

    /*
     | An 8px activation distance.
     |
     | Without it every click on a card starts a drag, and the card then never
     | opens — the pointer moves a pixel between mousedown and mouseup and dnd
     | swallows it as a gesture.
     */
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 8 } }));

    function onDragStart(event: DragStartEvent) {
        const deal = columns.flatMap((column) => column.items).find((d) => d.id === event.active.id);
        setDragging(deal ?? null);
    }

    function onDragEnd(event: DragEndEvent) {
        setDragging(null);

        const dealId = Number(event.active.id);
        const target = event.over?.id ? String(event.over.id) : null;

        if (!target) {
            return;
        }

        const deal = columns.flatMap((column) => column.items).find((d) => d.id === dealId);

        if (!deal || deal.stage === target) {
            return;
        }

        setOptimistic((current) => ({ ...current, [dealId]: target }));

        router.post(
            `/deals/${dealId}/move`,
            { stage: target },
            {
                preserveScroll: true,
                // Cleared either way: on success the server data supersedes it,
                // and on failure the card must snap back rather than sit in a
                // column the server never accepted.
                onFinish: () => setOptimistic((current) => {
                    const next = { ...current };
                    delete next[dealId];

                    return next;
                }),
            },
        );
    }

    const empty = columns.every((column) => column.items.length === 0);

    return (
        <div className="space-y-6">
            <Head title="Pipeline" />

            <PageHeader
                title="Pipeline"
                subtitle="Open deals, by where they have got to. Drag a card to move it; closing one is a separate step because it writes revenue."
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href="/deals">
                                <LayoutList />
                                List view
                            </Link>
                        </Button>
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
                    sub={
                        summary.revenue_target
                            ? `${summary.attainment}% of ${moneyCompact(summary.revenue_target)}`
                            : 'No target set'
                    }
                />
                <StatCard
                    label="Gone quiet"
                    value={summary.idle_count}
                    icon={AlertTriangle}
                    tone={summary.idle_count > 0 ? 'warning' : 'primary'}
                    sub="No activity for 14 days"
                />
            </StatGrid>

            {summary.revenue_target !== null && summary.attainment !== null && (
                <AttainmentBar attainment={summary.attainment} />
            )}

            {empty ? (
                <Card>
                    <EmptyState
                        icon={Handshake}
                        title="No open deals"
                        description="Add a deal against a client to start tracking it."
                        action={
                            can.create ? (
                                <Button onClick={() => setAdding(true)}>
                                    <Plus />
                                    New deal
                                </Button>
                            ) : undefined
                        }
                    />
                </Card>
            ) : (
                <DndContext sensors={sensors} onDragStart={onDragStart} onDragEnd={onDragEnd}>
                    <div className="-mx-4 overflow-x-auto px-4 pb-3 lg:-mx-6 lg:px-6">
                        {/*
                          | Five equal lanes on a wide screen, a scrolling rail
                          | below it.
                          |
                          | Fixed-width columns always overflowed: five at 18rem
                          | is wider than the content area of a laptop, so the
                          | last stage was permanently off the right-hand edge
                          | and the board looked broken rather than scrollable.
                          | The grid takes over at xl, where the whole pipeline
                          | genuinely fits, and the flex rail handles everything
                          | narrower.
                          |
                          | items-stretch and a floor on the height keep every
                          | lane the same size, so a nearly-empty one is still a
                          | target big enough to drop a card into.
                        */}
                        <div className="flex min-h-[20rem] min-w-max items-stretch gap-3 xl:grid xl:min-w-0 xl:grid-cols-5">
                            {shown.map((column) => (
                                <PipelineColumn key={column.stage} column={column} draggable={can.move} />
                            ))}
                        </div>
                    </div>

                    {/* Follows the cursor so the card being moved stays visible
                        over the column it is heading for. */}
                    <DragOverlay>{dragging && <DealCard deal={dragging} dragging />}</DragOverlay>
                </DndContext>
            )}

            <NewDealDialog open={adding} onClose={() => setAdding(false)} clients={clients} stages={stages} />
        </div>
    );
}

function AttainmentBar({ attainment }: { attainment: number }) {
    const capped = Math.min(attainment, 100);

    return (
        <Card>
            <CardContent className="py-4">
                <div className="mb-2 flex items-baseline justify-between gap-2">
                    <span className="text-sm font-medium">Revenue against target</span>
                    <span
                        className={cn(
                            'text-sm font-bold tabular',
                            attainment >= 100 ? 'text-success' : attainment >= 60 ? 'text-warning' : 'text-destructive',
                        )}
                    >
                        {attainment}%
                    </span>
                </div>

                <div className="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        className={cn(
                            'h-full rounded-full transition-all',
                            attainment >= 100 ? 'bg-success' : attainment >= 60 ? 'bg-warning' : 'bg-destructive',
                        )}
                        style={{ width: `${capped}%` }}
                    />
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * One stage, as a lane.
 *
 * ── Why the header is inside the lane ──────────────────────────────────────
 * It used to sit above it, outside the droppable box. With the board scrolling
 * horizontally that put a scrollbar between each heading and the cards it
 * described, and the headings read as a separate strip of pills floating over
 * an unrelated row of cards.
 *
 * One bordered column with the heading attached to the top of it is the whole
 * fix: the lane is visibly a thing, and the count and the value obviously
 * belong to what is underneath them.
 */
function PipelineColumn({ column, draggable }: { column: Column; draggable: boolean }) {
    const { setNodeRef, isOver } = useDroppable({ id: column.stage });

    // Recomputed from the shown items rather than trusting the server total,
    // so an optimistic move updates the column figure immediately.
    const total = column.items.reduce((sum, deal) => sum + deal.expected_value, 0);

    return (
        <section
            ref={setNodeRef}
            className={cn(
                // 16rem rather than 18: five stages then fit a laptop without
                // horizontal scrolling, which is the difference between seeing
                // the pipeline and seeing most of it.
                'flex w-64 shrink-0 flex-col overflow-hidden rounded-xl border transition-colors xl:w-auto',
                isOver ? 'border-primary bg-primary/5' : 'border-border bg-muted/30',
            )}
        >
            <header className="flex items-center gap-2 border-b border-border/70 bg-card/60 px-3 py-2.5">
                <StageBadge family="pipeline" stage={column.stage} dot />
                <span className="text-xs tabular text-muted-foreground">{column.items.length}</span>
                <span className="ml-auto text-xs font-semibold tabular">{moneyCompact(total)}</span>
            </header>

            <div className="flex-1 space-y-2.5 p-2.5">
                {column.items.length === 0 ? (
                    <p
                        className={cn(
                            'rounded-control border border-dashed border-border py-10 text-center text-xs transition-colors',
                            isOver ? 'border-primary/50 text-primary' : 'text-muted-foreground',
                        )}
                    >
                        {isOver ? 'Drop here' : 'Nothing here'}
                    </p>
                ) : (
                    column.items.map((deal) => (
                        <DraggableDeal key={deal.id} deal={deal} draggable={draggable} />
                    ))
                )}
            </div>
        </section>
    );
}

function DraggableDeal({ deal, draggable }: { deal: DealRow; draggable: boolean }) {
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
        id: deal.id,
        disabled: !draggable,
    });

    return (
        <div
            ref={setNodeRef}
            {...listeners}
            {...attributes}
            className={cn(draggable && 'cursor-grab active:cursor-grabbing', isDragging && 'opacity-40')}
        >
            <DealCard deal={deal} />
        </div>
    );
}

function DealCard({ deal, dragging = false }: { deal: DealRow; dragging?: boolean }) {
    const body = (
        <div
            className={cn(
                'rounded-control border border-border bg-card p-3 shadow-sm transition-colors',
                dragging ? 'shadow-card-hover' : 'hover:border-primary/40',
            )}
        >
            <p className="truncate text-sm font-medium">{deal.title}</p>

            {deal.client && (
                <p className="mt-0.5 flex items-center gap-1 truncate text-xs text-muted-foreground">
                    <Building2 className="size-3 shrink-0" aria-hidden="true" />
                    {deal.client.name}
                </p>
            )}

            <div className="mt-2 flex items-center justify-between gap-2">
                <span className="text-sm font-semibold tabular">{moneyCompact(deal.expected_value)}</span>
                {deal.probability !== null && (
                    <span className="text-[11px] text-muted-foreground tabular">{deal.probability}%</span>
                )}
            </div>

            <div className="mt-2 flex items-center justify-between gap-2">
                <span className="text-[11px] text-muted-foreground">
                    {deal.expected_close_on ? date(deal.expected_close_on) : 'No close date'}
                </span>
                {deal.owner && <Avatar name={deal.owner.name} size="sm" />}
            </div>

            {/* Only shown when it changes what somebody should do. */}
            {deal.is_idle && (
                <p className="mt-2 flex items-center gap-1 text-[11px] text-warning">
                    <AlertTriangle className="size-3" aria-hidden="true" />
                    Quiet for {deal.idle_days}d
                </p>
            )}
        </div>
    );

    // A dragging overlay must not be a link, or the drop registers as a click
    // and navigates away mid-gesture.
    return dragging ? body : <Link href={`/deals/${deal.id}`}>{body}</Link>;
}

PipelineBoard.layout = adminLayout;
