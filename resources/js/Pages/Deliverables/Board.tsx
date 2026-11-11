import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Clapperboard,
    Download,
    ExternalLink,
    Eye,
    Film,
    Globe,
    Image as ImageIcon,
    Inbox,
    Search,
    Send,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Pill } from '@/Components/dashboard/parts';
import { TopbarHeading } from '@/Components/layout/TopbarSlot';
import { MediaThumb, MediaViewer, type MediaInfo, type ViewerItem } from '@/Components/work/MediaViewer';
import { ViewSwitch } from '@/Components/work/ViewSwitch';
import { Card } from '@/Components/ui/card';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { stageMeta } from '@/config/stages';
import { usePermissions } from '@/hooks/usePermissions';
import { adminLayout } from '@/Layouts/AdminLayout';
import { deliverableTiming } from '@/lib/deliverables';
import { duration as formatDuration } from '@/lib/format';
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
    media: MediaInfo | null;
    social_thumbnail: string | null;
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
 * The lanes wrap (two across, then four) instead of scrolling
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

    // The viewer steps through what is on screen, in board order.
    const [viewing, setViewing] = useState<number | null>(null);
    const viewerItems: ViewerItem[] = [...sentBack, ...lanes.flatMap((lane) => lane.items)]
        .filter((item) => item.media !== null)
        .map((item) => ({
            key: item.id,
            media: item.media as MediaInfo,
            title: `${item.title || item.kind_label}${item.slot_number > 1 ? ` ${item.slot_number}` : ''}`,
            subtitle: `${item.code} · ${item.stage_label}`,
            href: `/deliverables/${item.id}`,
        }));
    const view = (id: number) => {
        const index = viewerItems.findIndex((item) => item.key === id);
        setViewing(index === -1 ? null : index);
    };

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
                        description="Request posters and reels from an interview or a published article."
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

                    {sentBack.length > 0 && <SentBack items={sentBack} onView={view} />}

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {lanes.map((lane) => (
                            <Lane key={lane.stage} stage={lane.stage} label={lane.label} items={lane.items} onView={view} />
                        ))}
                    </div>
                </>
            )}

            <MediaViewer items={viewerItems} index={viewing} onIndexChange={setViewing} onClose={() => setViewing(null)} />
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
 * DeliverableStage::boardColumns()). A strip above keeps it unmissable.
 */
function SentBack({ items, onView }: { items: DeliverableRow[]; onView: (id: number) => void }) {
    return (
        <section className="rounded-2xl border border-destructive/20 bg-destructive/[0.03] p-3" aria-label="Sent back">
            <header className="flex items-center gap-2 px-1 pb-3">
                <span className="flex size-7 items-center justify-center rounded-lg bg-destructive/10 text-destructive">
                    <AlertTriangle className="size-4" aria-hidden="true" />
                </span>
                <h2 className="text-sm font-semibold">Sent back for changes</h2>
                <Pill tone="danger">{items.length}</Pill>
                <p className="ml-auto hidden text-xs text-muted-foreground sm:block">
                    Fix and resubmit from the deliverable page.
                </p>
            </header>

            {/* The same grid as the lanes, so a sent-back card lines up with them. */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {items.map((item) => (
                    <DeliverableCard key={item.id} deliverable={item} onView={() => onView(item.id)} />
                ))}
            </div>
        </section>
    );
}

/* ─── Lanes and cards ───────────────────────────────────────────────────── */

function Lane({
    stage,
    label,
    items,
    onView,
}: {
    stage: string;
    label: string;
    items: DeliverableRow[];
    onView: (id: number) => void;
}) {
    const meta = stageMeta('deliverable', stage);

    return (
        <section className="flex min-w-0 flex-col gap-2.5 rounded-2xl bg-muted/60 p-2.5 ring-1 ring-inset ring-border/70" aria-label={label}>
            <header className="flex items-center gap-2 px-1.5 pt-0.5">
                <span className={cn('size-2 shrink-0 rounded-full', meta.dot)} aria-hidden="true" />
                <h2 className="truncate text-sm font-semibold">{label}</h2>
                <span className="ml-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-card px-1.5 text-xs font-medium text-muted-foreground shadow-xs tabular">
                    {items.length}
                </span>
            </header>

            {items.length === 0 ? (
                <div className="flex flex-col items-center gap-1.5 rounded-xl border border-dashed border-border px-3 py-8 text-center">
                    <Inbox className="size-5 text-muted-foreground/60" aria-hidden="true" />
                    <p className="text-xs text-muted-foreground">Nothing here</p>
                </div>
            ) : (
                items.map((item) => <DeliverableCard key={item.id} deliverable={item} onView={() => onView(item.id)} />)
            )}
        </section>
    );
}

/**
 * One piece of work, picture first.
 *
 * The picture opens the viewer; everything else on the card opens the
 * deliverable. The title's link is stretched over the whole card (the
 * `after:` overlay) instead of wrapping it, because the card also holds other
 * links — the download and the live post — and links cannot nest.
 */
function DeliverableCard({ deliverable, onView }: { deliverable: DeliverableRow; onView: () => void }) {
    const { media } = deliverable;
    const when = deliverableTiming(deliverable);
    const WhenIcon = when.icon;
    const KindIcon = deliverable.is_video ? Film : ImageIcon;
    const title = deliverable.title || deliverable.kind_label;
    const emptyLabel = deliverable.stage === 'pending' ? 'Not started' : 'No upload yet';
    const hasPicture =
        media?.type === 'image' || media?.type === 'video' || Boolean(media?.thumbnail_url ?? deliverable.social_thumbnail);

    const overlays = (
        <>
            {hasPicture && (
                <span className="pointer-events-none absolute inset-x-0 top-0 h-14 bg-gradient-to-b from-black/45 to-transparent" />
            )}
            <span className="pointer-events-none absolute left-2 top-2 inline-flex h-6 items-center gap-1 rounded-full bg-black/55 px-2 text-[11px] font-medium text-white backdrop-blur-sm">
                <KindIcon className="size-3" aria-hidden="true" />
                {deliverable.kind_label}
                {deliverable.slot_number > 1 && <span className="text-white/70">{deliverable.slot_number}</span>}
            </span>
            {media && (
                <span className="pointer-events-none absolute right-2 top-2 inline-flex h-6 items-center rounded-full bg-black/55 px-2 text-[11px] font-semibold text-white backdrop-blur-sm tabular">
                    v{media.version}
                </span>
            )}
            {media?.duration_seconds ? (
                <span className="pointer-events-none absolute bottom-2 left-2 rounded bg-black/65 px-1.5 py-0.5 text-[11px] font-medium text-white tabular">
                    {formatDuration(media.duration_seconds)}
                </span>
            ) : null}
        </>
    );

    return (
        <article className="group/card relative flex min-w-0 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-xs transition-[border-color,box-shadow,transform] hover:-translate-y-px hover:border-primary/30 hover:shadow-card-hover">
            <div className="relative">
                {media ? (
                    <button
                        type="button"
                        onClick={onView}
                        className="group/thumb relative z-10 block w-full text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
                        aria-label={`View ${title}, version ${media.version}`}
                    >
                        <MediaThumb
                            media={media}
                            fallbackThumbnail={deliverable.social_thumbnail}
                            isVideo={deliverable.is_video}
                            emptyLabel={emptyLabel}
                            className="aspect-[4/3]"
                        >
                            {overlays}
                            <span className="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 opacity-0 transition group-hover/thumb:bg-black/25 group-hover/thumb:opacity-100">
                                {media.type !== 'video' && (
                                    <span className="inline-flex h-8 items-center gap-1.5 rounded-full bg-white px-3 text-xs font-semibold text-black shadow">
                                        <Eye className="size-3.5" aria-hidden="true" />
                                        View
                                    </span>
                                )}
                            </span>
                        </MediaThumb>
                    </button>
                ) : (
                    <MediaThumb
                        media={null}
                        fallbackThumbnail={deliverable.social_thumbnail}
                        isVideo={deliverable.is_video}
                        emptyLabel={emptyLabel}
                        className="aspect-[4/3]"
                    >
                        {overlays}
                    </MediaThumb>
                )}

                {media && (
                    <a
                        href={media.download_url}
                        {...(media.type === 'link'
                            ? { target: '_blank', rel: 'noreferrer' }
                            : { download: media.filename ?? true })}
                        className="absolute bottom-2 right-2 z-20 inline-flex size-8 items-center justify-center rounded-full bg-white/95 text-foreground opacity-0 shadow transition hover:bg-white hover:text-primary focus-visible:opacity-100 group-hover/card:opacity-100 [@media(hover:none)]:opacity-100"
                        aria-label={media.type === 'link' ? 'Open the linked file' : `Download version ${media.version}`}
                        title={media.type === 'link' ? 'Open link' : 'Download'}
                    >
                        {media.type === 'link' ? <ExternalLink className="size-3.5" /> : <Download className="size-3.5" />}
                    </a>
                )}
            </div>

            <div className="flex flex-1 flex-col gap-1.5 p-3">
                <div className="flex items-start gap-2">
                    <Link
                        href={`/deliverables/${deliverable.id}`}
                        className="min-w-0 flex-1 truncate text-sm font-semibold after:absolute after:inset-0 after:content-[''] group-hover/card:text-primary"
                    >
                        {title}
                    </Link>
                    {deliverable.priority === 'high' && <Pill tone="danger">High</Pill>}
                </div>

                <p className="line-clamp-2 text-xs leading-4 text-muted-foreground">
                    <span className="font-medium text-foreground/70">
                        {deliverable.parent_kind === 'interview' ? 'Interview' : 'Article'}
                    </span>{' '}
                    · {deliverable.parent_label}
                </p>

                <p className="text-[11px] text-muted-foreground tabular">{deliverable.code}</p>

                <div className="mt-auto flex items-center gap-1.5 pt-2">
                    <Pill tone={when.tone} className="min-w-0 shrink">
                        <WhenIcon className="size-3 shrink-0" aria-hidden="true" />
                        <span className="truncate">{when.label}</span>
                    </Pill>

                    {/* Published with no link recorded is worth flagging: nobody can find the post again. */}
                    {deliverable.stage === 'published' && !deliverable.has_social_link && <Pill tone="warning">No link</Pill>}

                    {deliverable.social_url && (
                        <a
                            href={deliverable.social_url}
                            target="_blank"
                            rel="noreferrer"
                            className="relative z-10 inline-flex shrink-0 items-center gap-0.5 rounded-full px-1 text-[11px] font-medium text-success hover:underline"
                        >
                            <ExternalLink className="size-3" aria-hidden="true" />
                            Live
                        </a>
                    )}

                    <span className="ml-auto shrink-0">
                        {deliverable.assignee ? (
                            <span title={deliverable.assignee.name}>
                                <Avatar name={deliverable.assignee.name} size="sm" className="size-6 text-[10px]" />
                            </span>
                        ) : (
                            <Pill tone="warning">Unassigned</Pill>
                        )}
                    </span>
                </div>
            </div>
        </article>
    );
}

DeliverableBoard.layout = adminLayout;
