import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    ChevronRight,
    Clapperboard,
    ExternalLink,
    FileText,
    Globe,
    Handshake,
    Mic,
    PenLine,
    Phone,
    Target as TargetIcon,
    TrendingUp,
    UserSearch,
    Users,
} from 'lucide-react';
import { StatCard, StatGrid, type StatTone } from '@/Components/data/StatCard';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
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

interface DashboardProps {
    greeting: string;
    panels: {
        sales: boolean;
        leads: boolean;
        interviews: boolean;
        content: boolean;
        creative: boolean;
        organisation: boolean;
    };
    sales: {
        open_count: number;
        pipeline_value: number;
        weighted_value: number;
        won_this_month: number;
        revenue_target: number | null;
        attainment: number | null;
        idle_count: number;
        follow_ups_due: number;
    } | null;
    interviews: {
        this_week: number;
        awaiting_handover: number;
        conducted_this_month: number;
        open: number;
    } | null;
    content: {
        handovers_waiting: number;
        my_drafts: number;
        in_review: number;
        published_this_month: number;
    } | null;
    creative: {
        assigned_to_me: number;
        in_review: number;
        awaiting_publish: number;
        published_this_month: number;
    } | null;
    attention: AttentionItem[];
}

export default function Dashboard({
    greeting,
    panels,
    sales,
    interviews,
    content,
    creative,
    attention,
}: DashboardProps) {
    const { user } = usePermissions();
    const hasAnyPanel = Object.values(panels).some(Boolean);

    return (
        <div className="space-y-6">
            <Head title="Dashboard" />

            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">
                        {greeting}, {user?.name.split(' ')[0]}
                    </h1>
                    <p className="mt-1 text-sm text-muted-foreground">Here is where your work stands today.</p>
                </div>

                {/* The date, so the greeting is not a lone sentence across a
                    very wide row — and so a screenshot of this page says when
                    it was taken. */}
                <p className="text-sm text-muted-foreground">{today()}</p>
            </header>

            {/*
              | What is blocked, before what is counted.
              |
              | Counts tell somebody how they are doing; this tells them what to
              | open next, which is the only reason to look at a dashboard
              | before ten in the morning. So it goes first.
            */}
            {attention.length > 0 && <AttentionPanel items={attention} />}

            {sales && (
                <section className="space-y-3">
                    <SectionHeading title="Sales" href="/deals" />

                    <StatGrid count={4}>
                        <StatCard
                            label="Open deals"
                            value={sales.open_count}
                            icon={Handshake}
                            tone="primary"
                            href="/deals"
                        />
                        <StatCard
                            label="Pipeline value"
                            value={moneyCompact(sales.pipeline_value)}
                            icon={TrendingUp}
                            tone="info"
                            sub={`${moneyCompact(sales.weighted_value)} weighted`}
                        />
                        <StatCard
                            label="Won this month"
                            value={moneyCompact(sales.won_this_month)}
                            icon={TargetIcon}
                            tone="success"
                            sub={
                                sales.attainment !== null
                                    ? `${sales.attainment}% of ${moneyCompact(sales.revenue_target ?? 0)}`
                                    : 'No target set'
                            }
                            href="/targets"
                        />
                        <StatCard
                            label="Follow-ups due"
                            value={sales.follow_ups_due}
                            icon={Phone}
                            tone={sales.follow_ups_due > 0 ? 'warning' : 'primary'}
                            href="/follow-ups"
                        />
                    </StatGrid>
                </section>
            )}

            {interviews && (
                <section className="space-y-3">
                    <SectionHeading title="Interviews" href="/interviews" />

                    <StatGrid count={4}>
                        <StatCard
                            label="Scheduled this week"
                            value={interviews.this_week}
                            icon={CalendarClock}
                            tone="primary"
                            href="/interviews/my-queue"
                        />
                        <StatCard
                            label="Awaiting handover"
                            value={interviews.awaiting_handover}
                            icon={FileText}
                            tone={interviews.awaiting_handover > 0 ? 'warning' : 'primary'}
                            sub="Conducted, not yet with content"
                        />
                        <StatCard
                            label="Conducted this month"
                            value={interviews.conducted_this_month}
                            icon={CheckCircle2}
                            tone="success"
                        />
                        <StatCard label="Open" value={interviews.open} icon={Mic} tone="violet" />
                    </StatGrid>
                </section>
            )}

            {content && (
                <section className="space-y-3">
                    <SectionHeading title="Content" href="/articles/queue" />

                    <StatGrid count={4}>
                        <StatCard
                            label="Handovers waiting"
                            value={content.handovers_waiting}
                            icon={FileText}
                            tone={content.handovers_waiting > 0 ? 'warning' : 'primary'}
                            href="/articles/queue"
                        />
                        <StatCard label="My drafts" value={content.my_drafts} icon={PenLine} tone="primary" />
                        <StatCard
                            label="In review"
                            value={content.in_review}
                            icon={CheckCircle2}
                            tone="info"
                            href="/articles/queue"
                        />
                        <StatCard
                            label="Published this month"
                            value={content.published_this_month}
                            icon={Globe}
                            tone="success"
                            sub="Live on the website"
                        />
                    </StatGrid>
                </section>
            )}

            {creative && (
                <section className="space-y-3">
                    <SectionHeading title="Creative" href="/deliverables" />

                    <StatGrid count={4}>
                        <StatCard
                            label="Assigned to me"
                            value={creative.assigned_to_me}
                            icon={Clapperboard}
                            tone="primary"
                            href="/deliverables"
                        />
                        <StatCard
                            label="Awaiting approval"
                            value={creative.in_review}
                            icon={CheckCircle2}
                            tone={creative.in_review > 0 ? 'warning' : 'primary'}
                        />
                        <StatCard
                            label="Approved, not posted"
                            value={creative.awaiting_publish}
                            icon={Globe}
                            tone="info"
                            sub="Cleared but not out yet"
                        />
                        <StatCard
                            label="Published this month"
                            value={creative.published_this_month}
                            icon={ExternalLink}
                            tone="success"
                        />
                    </StatGrid>
                </section>
            )}

            {!hasAnyPanel && (
                <Card>
                    <EmptyState
                        icon={Users}
                        title="Nothing assigned to you yet"
                        description="Your account has no modules enabled. An administrator can grant you access from People → Roles."
                    />
                </Card>
            )}
        </div>
    );
}

/**
 * The rule between one stack of tiles and the next.
 *
 * The heading used to be small grey uppercase, the same weight as the labels
 * inside the tiles underneath it — so four sections read as one undifferentiated
 * wall. It is foreground-coloured and a size up now, with a hairline running to
 * the link, which is what actually separates the groups.
 */
function SectionHeading({ title, href }: { title: string; href: string }) {
    return (
        <div className="flex items-center gap-3">
            <h2 className="shrink-0 text-sm font-semibold tracking-tight">{title}</h2>
            <span className="h-px flex-1 bg-gradient-to-r from-border to-transparent" aria-hidden="true" />
            <Link
                href={href}
                className="shrink-0 text-xs font-medium text-muted-foreground transition-colors hover:text-primary"
            >
                Open all
            </Link>
        </div>
    );
}

/** "Thursday, 27 August" — the long form, since there is room for it. */
function today(): string {
    return new Intl.DateTimeFormat('en-IN', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(new Date());
}

const TONES: Record<AttentionItem['tone'], { chip: string; text: string }> = {
    danger: { chip: 'bg-destructive/15 text-destructive', text: 'text-destructive' },
    warning: { chip: 'bg-warning/15 text-warning', text: 'text-warning' },
    success: { chip: 'bg-success/15 text-success', text: 'text-success' },
};

const KIND_ICONS: Record<string, typeof Mic> = {
    lead: UserSearch,
    interview: Mic,
    article: FileText,
    deliverable: Clapperboard,
    deal: Handshake,
};

function AttentionPanel({ items }: { items: AttentionItem[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <AlertTriangle className="size-4 text-warning" aria-hidden="true" />
                    Needs you
                    <Badge tone="warning">{items.length}</Badge>
                </CardTitle>
            </CardHeader>

            <ul className="divide-y divide-border">
                {items.map((item) => {
                    const Icon = KIND_ICONS[item.kind] ?? AlertTriangle;
                    const tone = TONES[item.tone];

                    return (
                        <li key={`${item.kind}-${item.code}`}>
                            <Link
                                href={item.url}
                                className="group flex items-center gap-3 px-5 py-2.5 transition-colors hover:bg-muted/40"
                            >
                                <span
                                    className={cn(
                                        'inline-flex size-8 shrink-0 items-center justify-center rounded-control',
                                        tone.chip,
                                    )}
                                    aria-hidden="true"
                                >
                                    <Icon className="size-4" />
                                </span>

                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{item.title}</p>

                                    {/*
                                      | The code sits with the detail rather than
                                      | pinned to the right-hand edge. Pinned, it
                                      | ended up a foot from the title it belongs
                                      | to on a wide screen, and read as a stray
                                      | column rather than as part of the row.
                                    */}
                                    <p className="truncate text-xs">
                                        <span className="tabular text-muted-foreground">{item.code}</span>
                                        <span className="text-muted-foreground"> · </span>
                                        <span className={tone.text}>{item.detail}</span>
                                    </p>
                                </div>

                                <ChevronRight
                                    className="size-4 shrink-0 text-muted-foreground/50 transition-colors group-hover:text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </Card>
    );
}

Dashboard.layout = adminLayout;
