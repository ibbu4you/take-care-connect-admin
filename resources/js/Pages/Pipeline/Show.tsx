import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Building2,
    CalendarClock,
    CheckCircle2,
    FileText,
    Mail,
    MapPin,
    MessageCircle,
    MessageSquare,
    Phone,
    PhoneCall,
    Plus,
    StickyNote,
    Users,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { Pill, type Tone } from '@/Components/dashboard/parts';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge, StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog } from '@/Components/ui/dialog';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { Avatar, EmptyState, SectionDivider } from '@/Components/ui/misc';
import { adminLayout } from '@/Layouts/AdminLayout';
import { date, dateTime, money, relative } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { DealRow } from './Board';

interface ShowProps {
    deal: DealRow & {
        lost_reason: string | null;
        source: string | null;
        project: { id: number; code: string; title: string } | null;
        client_detail: {
            id: number;
            name: string;
            city: string | null;
            contact_person: string | null;
            phone: string | null;
            email: string | null;
        } | null;
        follow_ups: Array<{
            id: number;
            due_on: string | null;
            channel: string;
            note: string | null;
            state: string;
            is_overdue: boolean;
            owner: string | null;
        }>;
        timeline: Array<{ id: number; body: string; author: string | null; at: string | null }>;
        activities: Array<{
            id: number;
            type: string;
            type_label: string;
            outcome: string | null;
            outcome_label: string | null;
            note: string | null;
            author: string | null;
            at: string | null;
        }>;
        proposals: Array<{
            id: number;
            code: string;
            version: number;
            title: string;
            status: string;
            status_label: string;
            status_tone: Tone;
            total: number;
            discount_percent: number;
            created_by: string | null;
            updated_at: string | null;
        }>;
        next_step: string | null;
        next_step_on: string | null;
        next_step_overdue: boolean;
        history: Array<{
            id: number;
            from: string | null;
            to: string;
            note: string | null;
            by: string | null;
            at: string | null;
        }>;
    };
    stages: Array<{ value: string; label: string; probability: number }>;
    channels: string[];
    activityTypes: ActivityTypeOption[];
    can: { update: boolean; move: boolean; close: boolean; propose: boolean };
}

interface ActivityTypeOption {
    value: string;
    label: string;
    outcomes: Array<{ value: string; label: string }>;
}

export default function DealShow({ deal, stages, activityTypes, can }: ShowProps) {
    const [closing, setClosing] = useState<'won' | 'lost' | null>(null);
    const closed = deal.outcome !== null;

    return (
        <div className="space-y-6">
            <Head title={deal.title} />

            <PageHeader
                title={deal.title}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="tabular font-medium text-foreground">{deal.code}</span>
                        <StageBadge family="pipeline" stage={deal.stage} dot />
                        {deal.client_detail && (
                            <Link
                                href={`/clients/${deal.client_detail.id}`}
                                className="inline-flex items-center gap-1 text-sm text-primary hover:underline"
                            >
                                <Building2 className="size-3" />
                                {deal.client_detail.name}
                            </Link>
                        )}
                    </span>
                }
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href="/pipeline">
                                <ArrowLeft />
                                Pipeline
                            </Link>
                        </Button>

                        {/* The keyboard's way to move a deal — the board only offers dragging. */}
                        {can.move && !closed && (
                            <Select
                                aria-label="Move to stage"
                                value={deal.stage}
                                className="w-auto"
                                onChange={(event) =>
                                    router.post(
                                        `/deals/${deal.id}/move`,
                                        { stage: event.target.value },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {stages
                                    .filter((stage) => stage.value !== 'won' && stage.value !== 'lost')
                                    .map((stage) => (
                                        <option key={stage.value} value={stage.value}>
                                            {stage.label}
                                        </option>
                                    ))}
                            </Select>
                        )}

                        {can.close && !closed && (
                            <>
                                <Button variant="outline" onClick={() => setClosing('lost')}>
                                    <XCircle />
                                    Lost
                                </Button>
                                <Button onClick={() => setClosing('won')}>
                                    <CheckCircle2 />
                                    Won
                                </Button>
                            </>
                        )}
                    </>
                }
            />

            {closed && (
                <Card
                    className={cn(
                        'border',
                        deal.outcome === 'won'
                            ? 'border-success/30 bg-success/5'
                            : 'border-destructive/30 bg-destructive/5',
                    )}
                >
                    <CardContent className="flex items-start gap-3 py-4">
                        {deal.outcome === 'won' ? (
                            <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-success" aria-hidden="true" />
                        ) : (
                            <XCircle className="mt-0.5 size-5 shrink-0 text-destructive" aria-hidden="true" />
                        )}
                        <div className="min-w-0">
                            <p className="text-sm font-semibold">
                                {deal.outcome === 'won'
                                    ? `Won for ${money(deal.actual_value ?? 0)}`
                                    : 'Closed as lost'}
                                {deal.closed_at ? ` · ${relative(deal.closed_at)}` : ''}
                            </p>
                            {deal.lost_reason && (
                                <p className="mt-0.5 text-sm text-muted-foreground">{deal.lost_reason}</p>
                            )}
                        </div>
                    </CardContent>
                </Card>
            )}

            {!closed && <NextStepCard deal={deal} />}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    {can.update && !closed && <LogCard deal={deal} types={activityTypes} />}
                    <ProposalsCard deal={deal} canPropose={can.propose && !closed} />
                    <ActivityHistory deal={deal} />
                    <HistoryCard history={deal.history} />
                </div>

                <div className="space-y-6">
                    <ValueCard deal={deal} readOnly={!can.update} />

                    {deal.client_detail && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Client</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <Link
                                    href={`/clients/${deal.client_detail.id}`}
                                    className="block font-medium hover:text-primary"
                                >
                                    {deal.client_detail.name}
                                </Link>
                                {deal.client_detail.contact_person && (
                                    <p className="text-muted-foreground">{deal.client_detail.contact_person}</p>
                                )}
                                {deal.client_detail.phone && (
                                    <p className="flex items-center gap-2">
                                        <Phone className="size-3.5 text-muted-foreground" aria-hidden="true" />
                                        {deal.client_detail.phone}
                                    </p>
                                )}
                                {deal.client_detail.email && (
                                    <p className="flex items-center gap-2">
                                        <Mail className="size-3.5 text-muted-foreground" aria-hidden="true" />
                                        {deal.client_detail.email}
                                    </p>
                                )}

                                <SectionDivider label="Owner" />
                                {deal.owner ? (
                                    <span className="flex items-center gap-2">
                                        <Avatar name={deal.owner.name} size="sm" />
                                        <span className="truncate">{deal.owner.name}</span>
                                    </span>
                                ) : (
                                    <span className="text-muted-foreground">Unassigned</span>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>

            <CloseDialog
                outcome={closing}
                onClose={() => setClosing(null)}
                dealId={deal.id}
                expected={deal.expected_value}
            />
        </div>
    );
}

function ValueCard({ deal, readOnly }: { deal: ShowProps['deal']; readOnly: boolean }) {
    const { data, setData, patch, processing, isDirty, errors } = useForm({
        title: deal.title,
        expected_value: String(deal.expected_value),
        expected_close_on: deal.expected_close_on ?? '',
        probability: deal.probability !== null ? String(deal.probability) : '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        patch(`/deals/${deal.id}`, { preserveScroll: true });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>The deal</CardTitle>
                {!readOnly && (
                    <Button size="sm" variant="outline" onClick={submit} loading={processing} disabled={!isDirty}>
                        Save
                    </Button>
                )}
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <Field label="Title" htmlFor="title" error={errors.title}>
                        <Input
                            id="title"
                            value={data.title}
                            onChange={(event) => setData('title', event.target.value)}
                            disabled={readOnly}
                        />
                    </Field>

                    <Field label="Expected value" htmlFor="expected_value" hint="In rupees." error={errors.expected_value}>
                        <Input
                            id="expected_value"
                            type="number"
                            min={0}
                            value={data.expected_value}
                            onChange={(event) => setData('expected_value', event.target.value)}
                            disabled={readOnly}
                        />
                    </Field>

                    <Field
                        label="Probability"
                        htmlFor="probability"
                        hint="Follows the stage unless you set it here."
                        error={errors.probability}
                    >
                        <Input
                            id="probability"
                            type="number"
                            min={0}
                            max={100}
                            value={data.probability}
                            onChange={(event) => setData('probability', event.target.value)}
                            disabled={readOnly}
                        />
                    </Field>

                    <Field label="Expected close" htmlFor="expected_close_on" error={errors.expected_close_on}>
                        <Input
                            id="expected_close_on"
                            type="date"
                            value={data.expected_close_on}
                            onChange={(event) => setData('expected_close_on', event.target.value)}
                            disabled={readOnly}
                        />
                    </Field>

                    <div className="rounded-control bg-muted/50 p-3 text-sm">
                        <span className="text-muted-foreground">Weighted: </span>
                        <span className="font-semibold tabular">{money(deal.weighted_value)}</span>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

/* ─── Next step ─────────────────────────────────────────────────────────── */

/**
 * The one thing to do next on this deal.
 *
 * Top of the page because it is the first question anybody opening a deal
 * has. Red when it is overdue, amber when there is none — a live deal with no
 * next step is a deal nobody is working.
 */
function NextStepCard({ deal }: { deal: ShowProps['deal'] }) {
    if (!deal.next_step) {
        return (
            <Card className="border-warning/30 bg-warning/5">
                <CardContent className="flex items-start gap-2 py-3 text-sm">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                    <p>
                        {deal.is_idle
                            ? `Nothing has happened here for ${deal.idle_days} days, and there is no next step.`
                            : 'No next step yet.'}{' '}
                        Log what happened last and say what happens next.
                    </p>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className={cn(deal.next_step_overdue && 'border-destructive/30 bg-destructive/5')}>
            <CardContent className="flex items-center gap-3 py-3">
                <span
                    className={cn(
                        'inline-flex size-9 shrink-0 items-center justify-center rounded-full',
                        deal.next_step_overdue ? 'bg-destructive/10 text-destructive' : 'bg-primary/10 text-primary',
                    )}
                    aria-hidden="true"
                >
                    <ArrowRight className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="text-xs font-medium uppercase tracking-[0.04em] text-muted-foreground">Next step</p>
                    <p className="truncate text-sm font-medium">{deal.next_step}</p>
                </div>
                {deal.next_step_on && (
                    <Pill tone={deal.next_step_overdue ? 'danger' : 'info'}>
                        <CalendarClock className="size-3" aria-hidden="true" />
                        {deal.next_step_overdue ? `Overdue since ${date(deal.next_step_on)}` : date(deal.next_step_on)}
                    </Pill>
                )}
            </CardContent>
        </Card>
    );
}

/* ─── Logging ───────────────────────────────────────────────────────────── */

const TYPE_ICONS: Record<string, LucideIcon> = {
    call: PhoneCall,
    meeting: Users,
    visit: MapPin,
    whatsapp: MessageCircle,
    email: Mail,
};

/** YYYY-MM-DD, in the browser's own day — what a date input expects. */
function inDays(days: number): string {
    const value = new Date();
    value.setDate(value.getDate() + days);

    return [value.getFullYear(), String(value.getMonth() + 1).padStart(2, '0'), String(value.getDate()).padStart(2, '0')].join('-');
}

/**
 * What happened, and what happens next — one form.
 *
 * The next step is required: it is the moment the salesperson knows it, and a
 * live deal without one is the deal that goes quiet. It replaces the deal's
 * pending follow-up, so there is always exactly one thing to do next.
 */
function LogCard({ deal, types }: { deal: ShowProps['deal']; types: ActivityTypeOption[] }) {
    const [mode, setMode] = useState<'activity' | 'note'>('activity');

    const activity = useForm({
        type: 'call',
        outcome: '',
        note: '',
        next_step: '',
        next_step_type: 'call',
        next_step_on: inDays(2),
    });
    const note = useForm({ body: '' });

    const current = types.find((type) => type.value === activity.data.type) ?? types[0];

    return (
        <Card>
            <CardHeader>
                <CardTitle>Log</CardTitle>
                <div className="inline-flex h-8 items-center rounded-lg bg-muted p-0.5" role="radiogroup" aria-label="What to log">
                    {(
                        [
                            ['activity', 'What happened', PhoneCall],
                            ['note', 'A note', StickyNote],
                        ] as const
                    ).map(([key, label, Icon]) => (
                        <button
                            key={key}
                            type="button"
                            role="radio"
                            aria-checked={mode === key}
                            onClick={() => setMode(key)}
                            className={cn(
                                'inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium transition-colors',
                                mode === key ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <Icon className="size-3.5" aria-hidden="true" />
                            {label}
                        </button>
                    ))}
                </div>
            </CardHeader>

            <CardContent>
                {mode === 'activity' ? (
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            activity.post(`/deals/${deal.id}/activities`, {
                                preserveScroll: true,
                                onSuccess: () => activity.setData((data) => ({ ...data, outcome: '', note: '', next_step: '' })),
                            });
                        }}
                    >
                        <div>
                            <p className="mb-2 text-sm font-medium">What did you do?</p>
                            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Activity">
                                {types.map((type) => {
                                    const Icon = TYPE_ICONS[type.value] ?? PhoneCall;
                                    const selected = activity.data.type === type.value;

                                    return (
                                        <button
                                            key={type.value}
                                            type="button"
                                            role="radio"
                                            aria-checked={selected}
                                            onClick={() => activity.setData((data) => ({ ...data, type: type.value, outcome: '' }))}
                                            className={cn(
                                                'inline-flex h-9 items-center gap-1.5 rounded-lg border px-3 text-sm font-medium transition-colors',
                                                selected ? 'border-primary bg-primary/5 text-primary' : 'border-border bg-card hover:border-primary/40',
                                            )}
                                        >
                                            <Icon className="size-4" aria-hidden="true" />
                                            {type.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        {current.outcomes.length > 0 && (
                            <div>
                                <p className="mb-2 text-sm font-medium">How did it go?</p>
                                <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Outcome">
                                    {current.outcomes.map((outcome) => (
                                        <button
                                            key={outcome.value}
                                            type="button"
                                            role="radio"
                                            aria-checked={activity.data.outcome === outcome.value}
                                            onClick={() => activity.setData('outcome', outcome.value)}
                                            className={cn(
                                                'h-8 rounded-full border px-3 text-xs font-medium transition-colors',
                                                activity.data.outcome === outcome.value
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'border-border bg-card hover:border-primary/40 hover:bg-primary/5',
                                            )}
                                        >
                                            {outcome.label}
                                        </button>
                                    ))}
                                </div>
                                {activity.errors.outcome && <p className="mt-1 text-xs font-medium text-destructive">{activity.errors.outcome}</p>}
                            </div>
                        )}

                        <Field label="Notes" htmlFor="activity_note" error={activity.errors.note} hint="Optional.">
                            <Textarea
                                id="activity_note"
                                rows={2}
                                value={activity.data.note}
                                onChange={(event) => activity.setData('note', event.target.value)}
                                placeholder="They liked the sample reels. Budget is approved for this quarter."
                            />
                        </Field>

                        <div className="rounded-lg border border-border bg-muted/40 p-3">
                            <p className="mb-2 text-sm font-medium">What happens next?</p>
                            <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto]">
                                <Field htmlFor="next_step" error={activity.errors.next_step}>
                                    <Input
                                        id="next_step"
                                        value={activity.data.next_step}
                                        onChange={(event) => activity.setData('next_step', event.target.value)}
                                        placeholder="Send the revised proposal"
                                        aria-label="Next step"
                                        invalid={Boolean(activity.errors.next_step)}
                                    />
                                </Field>
                                <Select
                                    value={activity.data.next_step_type}
                                    onChange={(event) => activity.setData('next_step_type', event.target.value)}
                                    aria-label="How"
                                    className="sm:w-36"
                                >
                                    {types.map((type) => (
                                        <option key={type.value} value={type.value}>
                                            {type.label}
                                        </option>
                                    ))}
                                </Select>
                                <Field htmlFor="next_step_on" error={activity.errors.next_step_on}>
                                    <Input
                                        id="next_step_on"
                                        type="date"
                                        value={activity.data.next_step_on}
                                        onChange={(event) => activity.setData('next_step_on', event.target.value)}
                                        aria-label="When"
                                        className="sm:w-44"
                                        invalid={Boolean(activity.errors.next_step_on)}
                                    />
                                </Field>
                            </div>
                        </div>

                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                loading={activity.processing}
                                disabled={(current.outcomes.length > 0 && !activity.data.outcome) || !activity.data.next_step.trim()}
                            >
                                Log {current.label.toLowerCase()}
                            </Button>
                        </div>
                    </form>
                ) : (
                    <form
                        className="space-y-3"
                        onSubmit={(event: FormEvent) => {
                            event.preventDefault();
                            note.post(`/deals/${deal.id}/notes`, { preserveScroll: true, onSuccess: () => note.reset() });
                        }}
                    >
                        <Textarea
                            value={note.data.body}
                            onChange={(event) => note.setData('body', event.target.value)}
                            rows={3}
                            placeholder="Their marketing head changes in January — worth knowing before renewal."
                            aria-label="Note"
                            invalid={Boolean(note.errors.body)}
                        />
                        <div className="flex justify-end">
                            <Button type="submit" loading={note.processing} disabled={!note.data.body.trim()}>
                                Save note
                            </Button>
                        </div>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

/* ─── Proposals ─────────────────────────────────────────────────────────── */

function ProposalsCard({ deal, canPropose }: { deal: ShowProps['deal']; canPropose: boolean }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Proposals</CardTitle>
                {canPropose && (
                    <Button asChild size="sm" variant="outline" className="rounded-lg">
                        <Link href={`/deals/${deal.id}/proposals/create`}>
                            <Plus />
                            {deal.proposals.length > 0 ? 'New version' : 'Write a proposal'}
                        </Link>
                    </Button>
                )}
            </CardHeader>

            {deal.proposals.length === 0 ? (
                <EmptyState
                    icon={FileText}
                    title="No proposal yet"
                    description="Write one once you know what they need. Sending it moves the deal to Proposal sent."
                />
            ) : (
                <ul className="divide-y divide-border">
                    {deal.proposals.map((proposal) => (
                        <li key={proposal.id}>
                            <Link href={`/proposals/${proposal.id}`} className="group flex items-center gap-3 px-5 py-3 hover:bg-muted/40">
                                <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                                    <FileText className="size-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium group-hover:text-primary">
                                        {proposal.title} <span className="text-muted-foreground">· v{proposal.version}</span>
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        <span className="tabular">{proposal.code}</span>
                                        {proposal.discount_percent > 0 && ` · ${proposal.discount_percent}% off`}
                                        {proposal.updated_at && ` · ${relative(proposal.updated_at)}`}
                                    </p>
                                </div>
                                <span className="shrink-0 text-sm font-medium tabular">{money(proposal.total)}</span>
                                <Pill tone={proposal.status_tone}>{proposal.status_label}</Pill>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/* ─── History ───────────────────────────────────────────────────────────── */

function outcomeTone(outcome: string | null): Tone {
    if (outcome === 'interested' || outcome === 'held') return 'success';
    if (outcome === 'call_back' || outcome === 'postponed') return 'info';
    if (outcome === 'not_interested' || outcome === 'wrong_number' || outcome === 'no_show') return 'danger';
    return 'neutral';
}

/** Activities and notes in one list, newest first. */
function ActivityHistory({ deal }: { deal: ShowProps['deal'] }) {
    const entries = useMemo(
        () =>
            [
                ...deal.activities.map((activity) => ({ key: `a-${activity.id}`, at: activity.at, activity, note: null })),
                ...deal.timeline.map((entry) => ({ key: `n-${entry.id}`, at: entry.at, activity: null, note: entry })),
            ].sort((a, b) => (b.at ?? '').localeCompare(a.at ?? '')),
        [deal.activities, deal.timeline],
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>What has happened</CardTitle>
            </CardHeader>

            {entries.length === 0 ? (
                <EmptyState icon={MessageSquare} title="Nothing recorded yet" description="Every call, meeting and visit goes here." />
            ) : (
                <ol className="divide-y divide-border">
                    {entries.map((entry) => {
                        const Icon = entry.activity ? (TYPE_ICONS[entry.activity.type] ?? PhoneCall) : StickyNote;

                        return (
                            <li key={entry.key} className="flex gap-3 px-5 py-3">
                                <span
                                    className={cn(
                                        'mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded-full',
                                        entry.activity ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground',
                                    )}
                                    aria-hidden="true"
                                >
                                    <Icon className="size-3.5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    {entry.activity ? (
                                        <>
                                            <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                                {entry.activity.type_label}
                                                {entry.activity.outcome_label && (
                                                    <Pill tone={outcomeTone(entry.activity.outcome)}>{entry.activity.outcome_label}</Pill>
                                                )}
                                            </p>
                                            {entry.activity.note && <p className="mt-1 whitespace-pre-wrap text-sm">{entry.activity.note}</p>}
                                        </>
                                    ) : (
                                        <p className="whitespace-pre-wrap text-sm">{entry.note?.body}</p>
                                    )}
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {(entry.activity?.author ?? entry.note?.author) || 'Someone'}
                                        {entry.at && <span title={dateTime(entry.at)}> · {relative(entry.at)}</span>}
                                    </p>
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
        </Card>
    );
}

function HistoryCard({ history }: { history: ShowProps['deal']['history'] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Stage history</CardTitle>
            </CardHeader>
            {history.length === 0 ? (
                <EmptyState icon={MessageSquare} title="Nothing yet" />
            ) : (
                <CardContent>
                    <ol className="space-y-3">
                        {history.map((entry) => (
                            <li key={entry.id} className="flex items-start gap-2">
                                <StageBadge family="pipeline" stage={entry.to} dot />
                                <div className="min-w-0 flex-1 text-xs">
                                    <p className="text-muted-foreground">{entry.by ?? 'System'}</p>
                                    {entry.note && <p className="mt-0.5 text-muted-foreground">{entry.note}</p>}
                                </div>
                                <span className="shrink-0 text-xs text-muted-foreground" title={dateTime(entry.at)}>
                                    {relative(entry.at)}
                                </span>
                            </li>
                        ))}
                    </ol>
                </CardContent>
            )}
        </Card>
    );
}

function CloseDialog({
    outcome,
    onClose,
    dealId,
    expected,
}: {
    outcome: 'won' | 'lost' | null;
    onClose: () => void;
    dealId: number;
    expected: number;
}) {
    const won = outcome === 'won';

    const { data, setData, post, processing, errors } = useForm({
        outcome: outcome ?? 'won',
        // Pre-filled with the expected value: most deals close at roughly what
        // was quoted, and retyping it is friction for no benefit.
        actual_value: String(expected),
        lost_reason: '',
        closed_at: '',
    });

    return (
        <Dialog
            open={outcome !== null}
            onClose={onClose}
            title={won ? 'Close as won' : 'Close as lost'}
            description={
                won
                    ? 'This writes the revenue every attainment figure is a sum of, so it happens once.'
                    : 'Say why — that is the part worth reading back in six months.'
            }
            size="sm"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant={won ? 'primary' : 'destructive'}
                        loading={processing}
                        onClick={() => {
                            setData('outcome', outcome ?? 'won');
                            post(`/deals/${dealId}/close`, { preserveScroll: true, onSuccess: onClose });
                        }}
                    >
                        {won ? 'Mark won' : 'Mark lost'}
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                {won ? (
                    <Field
                        label="What was actually signed?"
                        htmlFor="actual_value"
                        error={errors.actual_value}
                        required
                        hint="Not the quote — what they agreed to pay."
                    >
                        <Input
                            id="actual_value"
                            type="number"
                            min={0}
                            value={data.actual_value}
                            onChange={(event) => setData('actual_value', event.target.value)}
                            invalid={Boolean(errors.actual_value)}
                        />
                    </Field>
                ) : (
                    <Field label="Why was it lost?" htmlFor="lost_reason" error={errors.lost_reason} required>
                        <Textarea
                            id="lost_reason"
                            value={data.lost_reason}
                            onChange={(event) => setData('lost_reason', event.target.value)}
                            rows={3}
                            placeholder="Went with a cheaper local studio."
                            invalid={Boolean(errors.lost_reason)}
                        />
                    </Field>
                )}

                <Field label="Closed on" htmlFor="closed_at" error={errors.closed_at} hint="Leave blank for today.">
                    <Input
                        id="closed_at"
                        type="date"
                        value={data.closed_at}
                        onChange={(event) => setData('closed_at', event.target.value)}
                    />
                </Field>
            </div>
        </Dialog>
    );
}

DealShow.layout = adminLayout;
