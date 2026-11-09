import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Clapperboard,
    ExternalLink,
    Film,
    Globe,
    Image as ImageIcon,
    Search,
    Send,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Pill } from '@/Components/dashboard/parts';
import { TopbarHeading } from '@/Components/layout/TopbarSlot';
import { ViewSwitch } from '@/Components/work/ViewSwitch';
import { Card } from '@/Components/ui/card';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { stageMeta } from '@/config/stages';
import { usePermissions } from '@/hooks/usePermissions';
import { adminLayout } from '@/Layouts/AdminLayout';
import { deliverableTiming } from '@/lib/deliverables';
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
 * The creative pipeline, as lanes.
 *
 * Read-only lanes rather than drag-and-drop: every move here has a
 * precondition the board cannot express — a cut needs uploading before it can
 * go for approval, an approval cannot be granted by the person who made it, and
 * publishing requires the link. Dragging a card would either bypass those or
 * fail silently halfway, so the moves live on the deliverable itself.
 *
 * The lanes wrap (two, then three, then all six across) instead of scrolling
 * sideways: a horizontal scrollbar under a board hides the right-hand half of
 * the pipeline — exactly the approved and published work a manager is looking
 * for — behind a gesture nobody finds on a desktop mouse.
 */
export default function DeliverableBoard({ columns, rejected, counts }: BoardProps) {
    const { user } = usePermissions();
    const [query, setQuery] = useState('');
    const [mine, setMine] = useState(false);
    const [kind, setKind] = useState<string>('all');

    // The kinds actually on the board, so the filter never offers an empty one.
    const kinds = useMemo(() => {
        const seen = new Map<string, string>();
        [...rejected, ...columns.flatMap((column) => column.items)].forEach((item) => seen.set(item.kind, item.kind_label));
        return [...seen.entries()];
    }, [columns, rejected]);

    const matches = (item: DeliverableRow) => {
        const needle = query.trim().toLowerCase();

        return (
            (!mine || item.assignee?.id === user?.id) &&
            (kind === 'all' || item.kind === kind) &&
            (needle === '' ||
                [item.kind_label, item.title, item.parent_label, item.code, item.assignee?.name]
                    .filter(Boolean)
                    .some((value) => String(value).toLowerCase().includes(needle)))
        );
    };

    const lanes = columns.map((column) => ({ ...column, items: column.items.filter(matches) }));
    const sentBack = rejected.filter(matches);
    const total = columns.reduce((sum, column) => sum + column.items.length, 0) + rejected.length;
    const shown = lanes.reduce((sum, lane) => sum + lane.items.length, 0) + sentBack.length;
    const filtering = query !== '' || mine || kind !== 'all';

    return (
        <div className="space-y-6">
            <Head title="Creative board" />

            <TopbarHeading
                title="Creative board"
                description="Posters, reels and edits, by where they have got to."
                actions={<ViewSwitch current="board" boardHref="/deliverables/board" listHref="/deliverables" />}
            />

            {/* The top bar hides its buttons on a phone; the view switch lives here instead. */}
            <div className="phone-only">
                <ViewSwitch current="board" boardHref="/deliverables/board" listHref="/deliverables" />
            </div>

            <Summary counts={counts} mine={mine} onToggleMine={() => setMine((value) => !value)} />

            {total === 0 ? (
                <Card>
                    <EmptyState
                        icon={Clapperboard}
                        title="No creative work yet"
                        description="Commission posters and reels from an interview or a published article."
                    />
                </Card>
            ) : (
                <>
                    {/* Toolbar: search, whose work, which kind. */}
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="relative w-full sm:w-72">
                            <span className="sr-only">Search the board</span>
                            <Search
                                className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <input
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Search title, interview or code…"
                                className="h-9 w-full rounded-lg border border-input bg-card pl-9 pr-3 text-sm shadow-xs placeholder:text-muted-foreground focus:border-primary/50 focus:outline-none focus:ring-2 focus:ring-primary/15"
                            />
                        </label>

                        <Segmented
                            label="Whose work"
                            value={mine ? 'mine' : 'all'}
                            onChange={(value) => setMine(value === 'mine')}
                            options={[
                                ['all', 'Everyone'],
                                ['mine', 'Assigned to me'],
                            ]}
                        />

                        {kinds.length > 1 && (
                            <Segmented
                                label="Kind"
                                value={kind}
                                onChange={setKind}
                                options={[['all', 'All kinds'], ...kinds]}
                            />
                        )}

                        <p className="ml-auto text-sm text-muted-foreground tabular" aria-live="polite">
                            {filtering ? `${shown} of ${total} shown` : `${total} deliverables`}
                            {filtering && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setQuery('');
                                        setMine(false);
                                        setKind('all');
                                    }}
                                    className="ml-2 inline-flex items-center gap-1 font-medium text-primary hover:text-primary-dark"
                                >
                                    <X className="size-3.5" aria-hidden="true" />
                                    Clear
                                </button>
                            )}
                        </p>
                    </div>

                    {sentBack.length > 0 && <SentBack items={sentBack} />}

                    <div className="grid items-start gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
                        {lanes.map((lane) => (
                            <Lane key={lane.stage} stage={lane.stage} label={lane.label} items={lane.items} />
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

/* ─── Header pieces ─────────────────────────────────────────────────────── */

function Summary({
    counts,
    mine,
    onToggleMine,
}: {
    counts: BoardProps['counts'];
    mine: boolean;
    onToggleMine: () => void;
}) {
    const metrics: Array<{ label: string; value: number; sub: string; icon: LucideIcon; tint: string; onClick?: () => void; active?: boolean }> = [
        {
            label: 'Assigned to me',
            value: counts.mine,
            sub: mine ? 'Showing only yours' : 'Open, in your hands',
            icon: Clapperboard,
            tint: 'bg-primary/10 text-primary',
            onClick: onToggleMine,
            active: mine,
        },
        { label: 'Awaiting approval', value: counts.inReview, sub: 'Waiting on a reviewer', icon: Send, tint: 'bg-stage-review/10 text-stage-review' },
        { label: 'Approved, not posted', value: counts.awaitingPublish, sub: 'Cleared but not out yet', icon: Globe, tint: 'bg-info/10 text-info' },
        { label: 'Published this month', value: counts.publishedThisMonth, sub: 'Live on social', icon: ExternalLink, tint: 'bg-success/10 text-success' },
    ];

    return (
        <section className="grid grid-cols-2 overflow-hidden rounded-xl border border-border bg-card shadow-xs xl:grid-cols-4" aria-label="Summary">
            {metrics.map((metric, index) => {
                const Icon = metric.icon;
                const body = (
                    <>
                        {/* Dropped on a phone, where two metrics share a row and the label needs the width. */}
                        <span className={cn('hidden size-10 shrink-0 items-center justify-center rounded-full sm:inline-flex', metric.tint)}>
                            <Icon className="size-5" aria-hidden="true" />
                        </span>
                        <span className="min-w-0 text-left">
                            <span className="block text-[11px] font-medium uppercase tracking-[0.04em] text-muted-foreground">
                                {metric.label}
                            </span>
                            <span className="block text-2xl font-medium leading-8 tabular">{metric.value}</span>
                            <span className="block truncate text-xs text-muted-foreground">{metric.sub}</span>
                        </span>
                    </>
                );

                const cell = cn(
                    'flex min-w-0 items-center gap-3 border-border p-3 sm:p-4',
                    index % 2 === 1 && 'border-l',
                    index >= 2 && 'border-t xl:border-t-0',
                    index === 2 && 'xl:border-l',
                );

                return metric.onClick ? (
                    <button
                        key={metric.label}
                        type="button"
                        onClick={metric.onClick}
                        aria-pressed={metric.active}
                        className={cn(cell, 'transition-colors hover:bg-muted/60', metric.active && 'bg-primary/5')}
                    >
                        {body}
                    </button>
                ) : (
                    <div key={metric.label} className={cell}>
                        {body}
                    </div>
                );
            })}
        </section>
    );
}

function Segmented({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Array<[string, string]>;
}) {
    return (
        <div className="inline-flex h-9 items-center rounded-lg bg-muted p-1" role="radiogroup" aria-label={label}>
            {options.map(([key, text]) => (
                <button
                    key={key}
                    type="button"
                    role="radio"
                    aria-checked={value === key}
                    onClick={() => onChange(key)}
                    className={cn(
                        'h-7 whitespace-nowrap rounded-md px-3 text-xs font-medium transition-colors',
                        value === key ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {text}
                </button>
            ))}
        </div>
    );
}

/* ─── Sent back ─────────────────────────────────────────────────────────── */

/**
 * Rejected work, above the lanes rather than in one.
 *
 * A rejected deliverable is work in somebody's hands, not a resting place —
 * a lane of its own would let things sit there looking filed (see
 * DeliverableStage::boardColumns()). A slim strip keeps it unmissable without
 * pushing the board half a screen down.
 */
function SentBack({ items }: { items: DeliverableRow[] }) {
    return (
        <section className="rounded-xl border border-destructive/20 bg-destructive/[0.03] p-3" aria-label="Sent back">
            <header className="flex items-center gap-2 px-1 pb-3">
                <AlertTriangle className="size-4 text-destructive" aria-hidden="true" />
                <h2 className="text-sm font-medium">Sent back for changes</h2>
                <Pill tone="danger">{items.length}</Pill>
                <p className="ml-auto hidden text-xs text-muted-foreground sm:block">
                    Fix and resubmit from the deliverable page.
                </p>
            </header>

            {/* The same grid as the lanes, so a sent-back card lines up with them. */}
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
                {items.map((item) => (
                    <DeliverableCard key={item.id} deliverable={item} />
                ))}
            </div>
        </section>
    );
}

/* ─── Lanes and cards ───────────────────────────────────────────────────── */

function Lane({ stage, label, items }: { stage: string; label: string; items: DeliverableRow[] }) {
    const meta = stageMeta('deliverable', stage);

    return (
        <section className="flex min-w-0 flex-col rounded-xl border border-border bg-muted/60 p-2" aria-label={label}>
            <header className="flex items-center gap-2 px-2 pb-2 pt-1">
                <span className={cn('size-2 shrink-0 rounded-full', meta.dot)} aria-hidden="true" />
                <h2 className="truncate text-sm font-medium">{label}</h2>
                <span className="ml-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full border border-border bg-card px-1.5 text-xs font-medium text-muted-foreground tabular">
                    {items.length}
                </span>
            </header>

            <div className="flex flex-col gap-2">
                {items.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-xs text-muted-foreground">
                        Nothing here
                    </p>
                ) : (
                    items.map((item) => <DeliverableCard key={item.id} deliverable={item} />)
                )}
            </div>
        </section>
    );
}

export function DeliverableCard({ deliverable }: { deliverable: DeliverableRow }) {
    const Icon = deliverable.is_video ? Film : ImageIcon;
    const when = deliverableTiming(deliverable);
    const WhenIcon = when.icon;

    return (
        <Link
            href={`/deliverables/${deliverable.id}`}
            className="group block rounded-lg border border-border bg-card p-3 shadow-xs transition-[border-color,box-shadow] hover:border-primary/40 hover:shadow-card-hover"
        >
            <div className="flex items-center gap-2">
                <span
                    className={cn(
                        'inline-flex size-7 shrink-0 items-center justify-center rounded-md',
                        deliverable.is_video ? 'bg-stage-in-progress/10 text-stage-in-progress' : 'bg-primary/10 text-primary',
                    )}
                    aria-hidden="true"
                >
                    <Icon className="size-4" />
                </span>

                <p className="min-w-0 flex-1 truncate text-sm font-medium group-hover:text-primary">
                    {deliverable.title || deliverable.kind_label}
                    {deliverable.slot_number > 1 && <span className="text-muted-foreground"> · {deliverable.slot_number}</span>}
                </p>

                {deliverable.priority === 'high' && <Pill tone="danger">High</Pill>}
            </div>

            <p className="mt-2 line-clamp-2 text-xs leading-4 text-muted-foreground">
                <span className="font-medium text-foreground/70">
                    {deliverable.parent_kind === 'interview' ? 'Interview' : 'Article'}
                </span>{' '}
                · {deliverable.parent_label}
            </p>

            <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[11px] text-muted-foreground">
                <span className="tabular">{deliverable.code}</span>
                {deliverable.title && <span>· {deliverable.kind_label}</span>}
                {deliverable.file_count > 0 && (
                    <span className="rounded border border-border px-1 font-medium tabular">v{deliverable.current_version}</span>
                )}
            </div>

            <div className="mt-3 flex items-center gap-2 border-t border-border pt-3">
                {/* Allowed to shrink and truncate, so the avatar never leaves the card. */}
                <Pill tone={when.tone} className="min-w-0 shrink">
                    <WhenIcon className="size-3 shrink-0" aria-hidden="true" />
                    <span className="truncate">{when.label}</span>
                </Pill>

                {/* Published with no link recorded is worth flagging: nobody can find the post again. */}
                {deliverable.stage === 'published' && !deliverable.has_social_link && <Pill tone="warning">No link</Pill>}

                {deliverable.social_url && (
                    <span className="inline-flex items-center gap-0.5 text-[11px] font-medium text-success">
                        <ExternalLink className="size-3" aria-hidden="true" />
                        Live
                    </span>
                )}

                <span className="ml-auto shrink-0">
                    {deliverable.assignee ? (
                        <Avatar name={deliverable.assignee.name} size="sm" className="size-6 text-[10px]" />
                    ) : (
                        <Pill tone="warning">Unassigned</Pill>
                    )}
                </span>
            </div>
        </Link>
    );
}

DeliverableBoard.layout = adminLayout;
