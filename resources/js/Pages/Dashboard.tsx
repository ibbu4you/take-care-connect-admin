import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    BarChart3,
    BellRing,
    CalendarCheck2,
    CalendarDays,
    ChevronDown,
    Clapperboard,
    Clock3,
    FileText,
    Handshake,
    Mic,
    PartyPopper,
    PhoneCall,
    Plus,
    StickyNote,
    Target as TargetIcon,
    UserSearch,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import {
    AvatarStack,
    CardEmpty,
    DashCard,
    Gauge,
    Pill,
    ProgressRing,
    StatusIcon,
    statusDotClass,
    type Person,
    type Tone,
} from '@/Components/dashboard/parts';
import { TopbarHeading } from '@/Components/layout/TopbarSlot';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { EmptyState } from '@/Components/ui/misc';
import { usePermissions } from '@/hooks/usePermissions';
import { adminLayout } from '@/Layouts/AdminLayout';
import { moneyCompact } from '@/lib/format';
import { cn } from '@/lib/utils';

interface AttentionItem {
    kind: string;
    title: string;
    detail: string;
    tone: 'warning' | 'danger' | 'success';
    url: string;
    code: string;
}

type ModuleKind = 'deals' | 'interviews' | 'articles' | 'deliverables';

interface DashboardProps {
    greeting: string;
    panels: Record<'sales' | 'leads' | 'interviews' | 'content' | 'creative' | 'organisation', boolean>;
    attention: AttentionItem[];
    progress: { kind: 'revenue' | 'interviews' | 'articles' | 'deliverables'; done: number; total: number | null } | null;
    breakdown: {
        kind: ModuleKind;
        total: number;
        bars: Array<{ label: string; value: number; open: boolean }>;
        people: Person[];
        people_count: number;
    } | null;
    gauge: {
        kind: Exclude<ModuleKind, 'deals'>;
        value: number;
        max: number;
        items: Array<{ id: number; date: string; meta: string; status: string; tone: Tone; url: string }>;
    } | null;
    activity: { scope: 'team' | 'mine'; end: string; days: number[] };
    todos: {
        kind: 'followups' | 'notes';
        items: Array<{
            id: number;
            title: string;
            body: string;
            tags: Array<{ label: string; tone: Tone }>;
            date: string | null;
            done: boolean;
            url: string | null;
        }>;
    };
    agenda: Partial<Record<AgendaTab, AgendaItem[]>>;
}

type AgendaTab = 'interviews' | 'calls' | 'deadlines';

interface AgendaItem {
    id: string;
    title: string;
    when: string;
    people: Person[];
    place: string;
    tag: string;
    url: string;
}

export default function Dashboard({ greeting, panels, attention, progress, breakdown, gauge, activity, todos, agenda }: DashboardProps) {
    const { user, can } = usePermissions();
    const hasAnyPanel = Object.values(panels).some(Boolean);

    const actions = (
        <>
            {can('interviews.create') && (
                <Button asChild variant="outline" className="h-10 rounded-lg px-3 shadow-xs">
                    <Link href="/interviews/create">
                        <CalendarDays aria-hidden="true" />
                        Schedule
                    </Link>
                </Button>
            )}
            {can('leads.create') && (
                <Button asChild className="h-10 rounded-lg px-3">
                    <Link href="/leads/create">
                        <Plus aria-hidden="true" />
                        New lead
                    </Link>
                </Button>
            )}
        </>
    );

    return (
        <>
            <Head title="Dashboard" />

            <TopbarHeading
                title="Dashboard"
                description={`${greeting}, ${user?.name.split(' ')[0] ?? ''}. Here is where your work stands on ${today()}.`}
                actions={actions}
            />

            {/* The top bar hides its buttons on a phone; they live here instead. */}
            <div className="mb-4 flex flex-wrap gap-3 empty:hidden sm:hidden">{actions}</div>

            {!hasAnyPanel ? (
                <Card>
                    <EmptyState
                        icon={Users}
                        title="Nothing assigned to you yet"
                        description="Your account has no modules enabled. An administrator can grant you access from People → Roles."
                    />
                </Card>
            ) : (
                <div className="grid items-start gap-6 lg:grid-cols-2 xl:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-6">
                        {progress && <ProgressCard progress={progress} />}
                        {breakdown && <BreakdownCard breakdown={breakdown} />}
                        <NeedsYouCard items={attention} />
                    </div>

                    <div className="flex min-w-0 flex-col gap-6">
                        {gauge && <GaugeCard gauge={gauge} />}
                        <ActivityCard activity={activity} canOpenReports={can(['reports.view', 'reports.view.all'])} />
                    </div>

                    <div className="grid min-w-0 gap-6 lg:col-span-2 lg:grid-cols-2 xl:col-span-1 xl:grid-cols-1">
                        <TodoCard todos={todos} />
                        <AgendaPanel agenda={agenda} />
                    </div>
                </div>
            )}
        </>
    );
}

/** "Monday, 9 November" — so a screenshot of this page says when it was taken. */
function today(): string {
    return new Intl.DateTimeFormat('en-IN', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());
}

function monthName(): string {
    return new Intl.DateTimeFormat('en-IN', { month: 'long' }).format(new Date());
}

/* ─── Progress ring ─────────────────────────────────────────────────────── */

function ProgressCard({ progress }: { progress: NonNullable<DashboardProps['progress']> }) {
    const { can } = usePermissions();
    const percent = progress.total ? (progress.done / progress.total) * 100 : 0;
    const month = monthName();

    const copy = {
        revenue: {
            title: 'Revenue target',
            heading: 'Won this month',
            text:
                progress.total !== null
                    ? `${moneyCompact(progress.done)} won of a ${moneyCompact(progress.total)} target for ${month}.`
                    : `No revenue target is set for ${month} yet.`,
            link: can(['targets.view', 'targets.view.all', 'targets.manage'])
                ? { label: progress.total !== null ? 'Open targets' : 'Set a target', href: '/targets' }
                : null,
        },
        interviews: {
            title: 'Interview progress',
            heading: 'Conducted this month',
            text: `${progress.done} of ${progress.total ?? 0} interviews conducted in ${month}.`,
            link: { label: 'Open my queue', href: '/interviews/my-queue' },
        },
        articles: {
            title: 'Article progress',
            heading: 'Published this month',
            text: `${progress.done} published, ${(progress.total ?? 0) - progress.done} still moving through the queue.`,
            link: { label: 'Open article queue', href: '/articles/queue' },
        },
        deliverables: {
            title: 'Creative progress',
            heading: 'Published this month',
            text: `${progress.done} posted, ${(progress.total ?? 0) - progress.done} still in production.`,
            link: { label: 'Open creative board', href: '/deliverables/board' },
        },
    }[progress.kind];

    return (
        <DashCard icon={TargetIcon} title={copy.title} action={copy.link ? { label: 'See All', href: copy.link.href } : null}>
            <div className="flex items-center gap-4">
                <ProgressRing percent={percent} />
                <div className="min-w-0">
                    <p className="text-sm font-medium leading-5">{copy.heading}</p>
                    <p className="mt-1 text-xs leading-4 text-muted-foreground">{copy.text}</p>
                    {copy.link && (
                        <Link
                            href={copy.link.href}
                            className="mt-1.5 inline-block text-xs font-medium text-primary underline underline-offset-2 hover:text-primary-dark"
                        >
                            {copy.link.label}
                        </Link>
                    )}
                </div>
            </div>
        </DashCard>
    );
}

/* ─── Bar chart ─────────────────────────────────────────────────────────── */

const BREAKDOWN_COPY: Record<ModuleKind, { title: string; noun: string; sub: string; people: string; href: string }> = {
    deals: { title: 'Pipeline analysis', noun: 'open deals', sub: 'Across the pipeline right now', people: 'owners', href: '/pipeline' },
    interviews: { title: 'Interview analysis', noun: 'open interviews', sub: 'Between booking and publishing', people: 'anchors', href: '/interviews' },
    articles: { title: 'Article analysis', noun: 'articles in progress', sub: 'Between handover and publishing', people: 'writers', href: '/articles' },
    deliverables: { title: 'Creative analysis', noun: 'deliverables open', sub: 'Between brief and posting', people: 'designers', href: '/deliverables/board' },
};

function BreakdownCard({ breakdown }: { breakdown: NonNullable<DashboardProps['breakdown']> }) {
    const copy = BREAKDOWN_COPY[breakdown.kind];
    const peak = Math.max(1, ...breakdown.bars.map((bar) => bar.value));

    return (
        <DashCard icon={BarChart3} title={copy.title} action={{ label: 'Details', href: copy.href }}>
            <div className="flex items-end justify-between gap-4">
                <div className="min-w-0">
                    <p className="text-lg font-medium leading-6 tabular">
                        {breakdown.total} {copy.noun}
                    </p>
                    <p className="text-xs leading-4 text-muted-foreground">{copy.sub}</p>

                    <div className="mt-4 flex items-center gap-2">
                        {breakdown.people.length > 0 && (
                            <AvatarStack people={breakdown.people} />
                        )}
                        <span className="text-xs text-muted-foreground tabular">
                            {breakdown.people_count}{' '}
                            {breakdown.people_count === 1 ? copy.people.replace(/s$/, '') : copy.people}
                        </span>
                    </div>
                </div>

                {/* Solid bars are open stages; pale ones closed this month. */}
                <ul className="flex h-14 shrink-0 items-end gap-1.5" aria-label={`${copy.title} by stage`}>
                    {breakdown.bars.map((bar) => (
                        <li
                            key={bar.label}
                            title={`${bar.label}: ${bar.value}`}
                            className={cn('w-2.5 rounded-t-[3px]', bar.open ? 'bg-primary' : 'bg-primary/30')}
                            style={{ height: `${Math.max(8, (bar.value / peak) * 100)}%` }}
                        >
                            <span className="sr-only">
                                {bar.label}: {bar.value}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </DashCard>
    );
}

/* ─── Needs you (status tracker) ────────────────────────────────────────── */

const KIND_ICONS: Record<string, LucideIcon> = {
    lead: UserSearch,
    interview: Mic,
    article: FileText,
    deliverable: Clapperboard,
    deal: Handshake,
};

const ATTENTION_GROUPS: Array<{ tone: AttentionItem['tone']; label: string; pill: Tone }> = [
    { tone: 'danger', label: 'Overdue', pill: 'danger' },
    { tone: 'warning', label: 'Waiting on you', pill: 'warning' },
    { tone: 'success', label: 'Ready to move', pill: 'success' },
];

function NeedsYouCard({ items }: { items: AttentionItem[] }) {
    return (
        <DashCard icon={BellRing} title="Needs you" action={{ label: 'See All', href: '/notifications' }}>
            {items.length === 0 ? (
                <CardEmpty icon={CalendarCheck2}>Nothing is waiting on you. Everything is moving.</CardEmpty>
            ) : (
                <div className="space-y-4">
                    {ATTENTION_GROUPS.map((group) => {
                        const rows = items.filter((item) => item.tone === group.tone);

                        if (rows.length === 0) {
                            return null;
                        }

                        return (
                            <div key={group.tone}>
                                <p className="pb-2 text-xs text-muted-foreground">{group.label}</p>
                                <ul className="space-y-3">
                                    {rows.map((item) => {
                                        const Icon = KIND_ICONS[item.kind] ?? BellRing;

                                        return (
                                            <li key={`${item.kind}-${item.code}`}>
                                                <Link href={item.url} className="group flex items-center gap-3">
                                                    <span className="relative shrink-0">
                                                        <span className="inline-flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground transition-colors group-hover:text-foreground">
                                                            <Icon className="size-5" aria-hidden="true" />
                                                        </span>
                                                        <span
                                                            className={cn(
                                                                'absolute bottom-0 right-0 size-2.5 rounded-full ring-2 ring-card',
                                                                statusDotClass(group.pill),
                                                            )}
                                                            aria-hidden="true"
                                                        />
                                                    </span>

                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-sm font-medium leading-5 group-hover:text-primary">
                                                            {item.title}
                                                        </span>
                                                        <span className="block truncate text-xs leading-4 text-muted-foreground">
                                                            {item.detail}
                                                        </span>
                                                    </span>

                                                    <Pill tone={group.pill} className="capitalize">
                                                        {item.kind}
                                                    </Pill>
                                                </Link>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        );
                    })}
                </div>
            )}
        </DashCard>
    );
}

/* ─── Gauge ─────────────────────────────────────────────────────────────── */

const GAUGE_COPY: Record<Exclude<ModuleKind, 'deals'>, { title: string; icon: LucideIcon; href: string; empty: string }> = {
    interviews: { title: 'Interviews', icon: Mic, href: '/interviews', empty: 'No interviews booked yet.' },
    articles: { title: 'Articles', icon: FileText, href: '/articles/queue', empty: 'No articles in the queue.' },
    deliverables: { title: 'Deliverables', icon: Clapperboard, href: '/deliverables', empty: 'No deliverables open.' },
};

function GaugeCard({ gauge }: { gauge: NonNullable<DashboardProps['gauge']> }) {
    const copy = GAUGE_COPY[gauge.kind];

    return (
        <DashCard icon={copy.icon} title={copy.title} action={{ label: 'See All', href: copy.href }}>
            <Gauge value={gauge.value} max={gauge.max} caption={`out of ${gauge.max} this month`} />

            {gauge.items.length === 0 ? (
                <p className="pt-4 text-center text-sm text-muted-foreground">{copy.empty}</p>
            ) : (
                <ul className="mt-4 divide-y divide-border border-t border-border">
                    {gauge.items.map((item) => (
                        <li key={item.id}>
                            <Link href={item.url} className="group flex items-center gap-2 py-3 last:pb-0">
                                <StatusIcon tone={item.tone} />
                                <span className="min-w-0 flex-1 truncate">
                                    <span className="text-sm font-medium group-hover:text-primary">{item.date}</span>{' '}
                                    <span className="text-xs text-muted-foreground">({item.meta})</span>
                                </span>
                                <Pill tone={item.tone}>{item.status}</Pill>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </DashCard>
    );
}

/* ─── Activity line chart ───────────────────────────────────────────────── */

const RANGES = ['5D', '2W', '1M', '6M', '1Y'] as const;
type Range = (typeof RANGES)[number];

interface Point {
    label: string;
    full: string;
    value: number;
}

const weekday = new Intl.DateTimeFormat('en-IN', { weekday: 'long' });
const shortDay = new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short' });
const monthLabel = new Intl.DateTimeFormat('en-IN', { month: 'short' });
const monthYear = new Intl.DateTimeFormat('en-IN', { month: 'long', year: 'numeric' });

/** Daily counts, newest last, folded into the buckets a range draws. */
function bucket(days: number[], end: string, range: Range): Point[] {
    const last = new Date(`${end}T00:00:00`);
    const dateAt = (index: number) => {
        const date = new Date(last);
        date.setDate(last.getDate() - (days.length - 1 - index));
        return date;
    };

    if (range === '5D' || range === '2W' || range === '1M') {
        const span = range === '5D' ? 5 : range === '2W' ? 14 : 30;

        return days.slice(-span).map((value, offset) => {
            const date = dateAt(days.length - span + offset);
            return {
                label: span === 5 ? weekday.format(date).slice(0, 3) : shortDay.format(date),
                full: span === 5 ? weekday.format(date) : `${weekday.format(date)}, ${shortDay.format(date)}`,
                value,
            };
        });
    }

    if (range === '6M') {
        const points: Point[] = [];

        for (let endIndex = days.length - 1, week = 0; week < 26; week++, endIndex -= 7) {
            const startIndex = Math.max(0, endIndex - 6);
            const value = days.slice(startIndex, endIndex + 1).reduce((sum, count) => sum + count, 0);
            points.unshift({ label: shortDay.format(dateAt(startIndex)), full: `Week of ${shortDay.format(dateAt(startIndex))}`, value });
        }

        return points;
    }

    const months = new Map<string, Point>();

    days.forEach((value, index) => {
        const date = dateAt(index);
        const key = `${date.getFullYear()}-${date.getMonth()}`;
        const point = months.get(key) ?? { label: monthLabel.format(date), full: monthYear.format(date), value: 0 };
        point.value += value;
        months.set(key, point);
    });

    return [...months.values()].slice(-12);
}

function ActivityCard({ activity, canOpenReports }: { activity: DashboardProps['activity']; canOpenReports: boolean }) {
    const [range, setRange] = useState<Range>('5D');
    const points = useMemo(() => bucket(activity.days, activity.end, range), [activity, range]);
    const total = points.reduce((sum, point) => sum + point.value, 0);
    const todayCount = activity.days[activity.days.length - 1] ?? 0;

    return (
        <DashCard
            icon={Activity}
            title="Workflow activity"
            action={canOpenReports ? { label: 'Details', href: '/reports' } : null}
        >
            <div className="flex items-center gap-3">
                <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-full border border-border">
                    <Clock3 className="size-5 text-primary" aria-hidden="true" />
                </span>
                <div>
                    <p className="text-[11px] font-medium uppercase tracking-[0.04em] text-muted-foreground">
                        Total stage moves
                    </p>
                    <p className="text-lg font-medium leading-6 tabular">
                        {total} moves <span className="text-muted-foreground">·</span> {todayCount} today
                    </p>
                </div>
            </div>

            <div className="mt-4 grid grid-cols-5 gap-1 rounded-lg bg-muted p-1" role="tablist" aria-label="Range">
                {RANGES.map((option) => (
                    <button
                        key={option}
                        type="button"
                        role="tab"
                        aria-selected={range === option}
                        onClick={() => setRange(option)}
                        className={cn(
                            'h-7 rounded-md text-xs font-medium transition-colors',
                            range === option
                                ? 'bg-card text-foreground shadow-xs'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {option}
                    </button>
                ))}
            </div>

            <div className="mt-4 h-40">
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={points} margin={{ top: 8, right: 4, bottom: 0, left: 4 }}>
                        <CartesianGrid vertical horizontal={false} strokeDasharray="4 4" stroke="hsl(var(--border))" />
                        <XAxis dataKey="label" hide />
                        <YAxis hide domain={[0, (max: number) => Math.max(4, Math.ceil(max * 1.25))]} />
                        <Tooltip
                            cursor={{ stroke: 'hsl(var(--border))', strokeDasharray: '4 4' }}
                            content={({ active, payload }) => {
                                const point = payload?.[0]?.payload as Point | undefined;

                                return active && point ? (
                                    <div className="rounded-md border border-border bg-popover px-2 py-1 text-xs font-medium text-popover-foreground shadow-card">
                                        {point.full}, {point.value} {point.value === 1 ? 'move' : 'moves'}
                                    </div>
                                ) : null;
                            }}
                        />
                        <Line
                            type="linear"
                            dataKey="value"
                            stroke="hsl(var(--primary))"
                            strokeWidth={2}
                            dot={false}
                            activeDot={{ r: 4, fill: 'hsl(var(--primary))', stroke: 'hsl(var(--card))', strokeWidth: 2 }}
                            isAnimationActive={false}
                        />
                    </LineChart>
                </ResponsiveContainer>
            </div>

            <p className="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground">
                <span className="size-1.5 rounded-full bg-muted-foreground/50" aria-hidden="true" />
                {activity.scope === 'team'
                    ? 'Every stage move across the team: deals, interviews, articles and creative.'
                    : 'Every stage move you made: deals, interviews, articles and creative.'}
            </p>
        </DashCard>
    );
}

/* ─── Checklist (notes) ─────────────────────────────────────────────────── */

function TodoCard({ todos }: { todos: DashboardProps['todos'] }) {
    const followUps = todos.kind === 'followups';

    return (
        <DashCard
            icon={StickyNote}
            title={followUps ? 'Follow-ups' : 'My notes'}
            action={followUps ? { label: <><Plus aria-hidden="true" />Add follow-up</>, href: '/follow-ups' } : null}
        >
            {todos.items.length === 0 ? (
                <CardEmpty icon={StickyNote}>
                    {followUps ? 'No follow-ups yet. Add one from a lead, client or deal.' : 'Notes you add to a record show up here.'}
                </CardEmpty>
            ) : (
                <ul className="space-y-4">
                    {todos.items.map((item) => {
                        const body = (
                            <>
                                <span className="flex items-start gap-2">
                                    {item.done ? (
                                        <StatusIcon tone="success" />
                                    ) : (
                                        <span className="mt-0.5 size-4 shrink-0 rounded-full border-[1.5px] border-muted-foreground/60" aria-hidden="true" />
                                    )}
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium leading-5 group-hover:text-primary">{item.title}</span>
                                        {item.body && (
                                            <span className="mt-1 block truncate text-sm leading-5 text-muted-foreground">{item.body}</span>
                                        )}
                                    </span>
                                </span>

                                <span className="mt-2 flex items-center gap-1.5 pl-6">
                                    {item.tags.map((tag) => (
                                        <Pill key={tag.label} tone={item.done ? 'neutral' : tag.tone}>
                                            {tag.label}
                                        </Pill>
                                    ))}
                                    {item.date && (
                                        <span className="ml-auto inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <CalendarDays className="size-3.5" aria-hidden="true" />
                                            {item.date}
                                        </span>
                                    )}
                                </span>
                            </>
                        );

                        return (
                            <li key={item.id}>
                                {item.url ? (
                                    <Link href={item.url} className="group block">
                                        {body}
                                    </Link>
                                ) : (
                                    <div>{body}</div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </DashCard>
    );
}

/* ─── Agenda (meetings / events / holidays) ─────────────────────────────── */

const TAB_COPY: Record<AgendaTab, { label: string; icon: LucideIcon; empty: string }> = {
    interviews: { label: 'Interviews', icon: Mic, empty: 'No interviews booked from today on.' },
    calls: { label: 'Calls', icon: PhoneCall, empty: 'No follow-ups due from today on.' },
    deadlines: { label: 'Deadlines', icon: PartyPopper, empty: 'Nothing due from today on.' },
};

/** Peach, blue, violet — in turn, like the reference cards. */
const AGENDA_SKINS = [
    { card: 'from-stage-revisions/20 to-stage-revisions/5', tag: 'border-stage-revisions text-stage-revisions' },
    { card: 'from-primary/20 to-primary/5', tag: 'border-primary text-primary' },
    { card: 'from-stage-conducted/20 to-stage-conducted/5', tag: 'border-stage-conducted text-stage-conducted' },
];

function AgendaPanel({ agenda }: { agenda: DashboardProps['agenda'] }) {
    const tabs = (Object.keys(TAB_COPY) as AgendaTab[]).filter((tab) => agenda[tab] !== undefined);
    const [active, setActive] = useState<AgendaTab | undefined>(tabs[0]);
    const [expanded, setExpanded] = useState<string | null>(null);

    if (tabs.length === 0 || !active) {
        return null;
    }

    const items = agenda[active] ?? [];

    return (
        <section aria-label="Agenda">
            <div className="flex gap-6 border-b border-border px-1" role="tablist">
                {tabs.map((tab) => {
                    const Icon = TAB_COPY[tab].icon;
                    const selected = tab === active;

                    return (
                        <button
                            key={tab}
                            type="button"
                            role="tab"
                            aria-selected={selected}
                            onClick={() => setActive(tab)}
                            className={cn(
                                '-mb-px flex items-center gap-1.5 border-b-2 pb-3.5 pt-1 text-sm font-medium transition-colors',
                                selected
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <Icon className={cn('size-[18px]', selected && 'text-primary')} aria-hidden="true" />
                            {TAB_COPY[tab].label}
                        </button>
                    );
                })}
            </div>

            {items.length === 0 ? (
                <div className="mt-4 rounded-xl border border-dashed border-border">
                    <CardEmpty icon={TAB_COPY[active].icon}>{TAB_COPY[active].empty}</CardEmpty>
                </div>
            ) : (
                <ul className="mt-4 space-y-3">
                    {items.map((item, index) => {
                        const skin = AGENDA_SKINS[index % AGENDA_SKINS.length];
                        const open = expanded === item.id;

                        return (
                            <li key={item.id} className={cn('rounded-xl bg-gradient-to-b p-4', skin.card)}>
                                <div className="flex items-start gap-2">
                                    <Link href={item.url} className="min-w-0 flex-1 hover:underline">
                                        <p className="truncate text-sm font-medium leading-5">{item.title}</p>
                                        <p className="mt-0.5 truncate text-xs leading-4 text-muted-foreground">{item.when}</p>
                                    </Link>
                                    <button
                                        type="button"
                                        onClick={() => setExpanded(open ? null : item.id)}
                                        className="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-card shadow-xs transition-transform"
                                        aria-expanded={open}
                                        aria-label={open ? 'Show less' : 'Show who is on it'}
                                    >
                                        <ChevronDown className={cn('size-4 transition-transform', open && 'rotate-180')} aria-hidden="true" />
                                    </button>
                                </div>

                                {item.people.length > 0 && (
                                    <div className="mt-3">
                                        <AvatarStack people={item.people.slice(0, 3)} extra={item.people.length - 3} size="md" />
                                        {open && (
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {item.people.map((person) => person.name).join(', ')}
                                            </p>
                                        )}
                                    </div>
                                )}

                                <div className="mt-3 flex items-center justify-between gap-2">
                                    <span className="truncate text-xs text-muted-foreground">{item.place}</span>
                                    <span
                                        className={cn(
                                            'shrink-0 rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.04em]',
                                            skin.tag,
                                        )}
                                    >
                                        {item.tag}
                                    </span>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}

Dashboard.layout = adminLayout;
