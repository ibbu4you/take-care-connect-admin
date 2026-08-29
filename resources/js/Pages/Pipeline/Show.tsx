import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Building2,
    CheckCircle2,
    Mail,
    MessageSquare,
    Phone,
    Send,
    XCircle,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
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
    can: { update: boolean; move: boolean; close: boolean };
}

export default function DealShow({ deal, channels, can }: ShowProps) {
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

            {deal.is_idle && !closed && (
                <Card className="border-warning/30 bg-warning/5">
                    <CardContent className="flex items-start gap-2 py-3 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                        <p>
                            Nothing has happened here for {deal.idle_days} days. Add a note or set a follow-up.
                        </p>
                    </CardContent>
                </Card>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <TimelineCard deal={deal} canPost={can.update} />
                    <FollowUpCard deal={deal} channels={channels} canAdd={can.update} />
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
    const { data, setData, patch, processing, isDirty } = useForm({
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
                    <Field label="Title" htmlFor="title">
                        <Input
                            id="title"
                            value={data.title}
                            onChange={(event) => setData('title', event.target.value)}
                            disabled={readOnly}
                        />
                    </Field>

                    <Field label="Expected value" htmlFor="expected_value" hint="In rupees.">
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

                    <Field label="Expected close" htmlFor="expected_close_on">
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

/**
 * Notes on the deal.
 *
 * Posting one also counts as activity, which is what keeps a deal somebody is
 * actively working off the "gone quiet" list.
 */
function TimelineCard({ deal, canPost }: { deal: ShowProps['deal']; canPost: boolean }) {
    const { data, setData, post, processing, reset, errors } = useForm({ body: '' });

    function submit(event: FormEvent) {
        event.preventDefault();

        post(`/deals/${deal.id}/notes`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>What has happened</CardTitle>
            </CardHeader>

            <CardContent className="space-y-4">
                {canPost && (
                    <form onSubmit={submit} className="space-y-2">
                        <Textarea
                            value={data.body}
                            onChange={(event) => setData('body', event.target.value)}
                            rows={2}
                            placeholder="Called Suresh — wants to see a sample reel before committing."
                            invalid={Boolean(errors.body)}
                        />
                        <Button type="submit" size="sm" loading={processing} disabled={!data.body.trim()}>
                            <Send />
                            Add note
                        </Button>
                    </form>
                )}

                {deal.timeline.length === 0 ? (
                    <EmptyState icon={MessageSquare} title="Nothing recorded yet" />
                ) : (
                    <ol className="space-y-3">
                        {deal.timeline.map((entry) => (
                            <li key={entry.id} className="rounded-control border border-border p-3">
                                <p className="whitespace-pre-wrap text-sm">{entry.body}</p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {entry.author} · {relative(entry.at)}
                                </p>
                            </li>
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}

function FollowUpCard({
    deal,
    channels,
    canAdd,
}: {
    deal: ShowProps['deal'];
    channels: string[];
    canAdd: boolean;
}) {
    const { data, setData, post, processing, reset } = useForm({
        deal_id: deal.id,
        client_id: deal.client?.id ?? null,
        due_on: '',
        channel: 'call',
        note: '',
    });

    return (
        <Card>
            <CardHeader>
                <CardTitle>Follow-ups</CardTitle>
            </CardHeader>

            <CardContent className="space-y-4">
                {canAdd && (
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            post('/follow-ups', { preserveScroll: true, onSuccess: () => reset('due_on', 'note') });
                        }}
                    >
                        <Field label="When" htmlFor="due_on" className="w-40">
                            <Input
                                id="due_on"
                                type="date"
                                value={data.due_on}
                                onChange={(event) => setData('due_on', event.target.value)}
                            />
                        </Field>

                        <Field label="How" htmlFor="channel" className="w-32">
                            <Select
                                id="channel"
                                value={data.channel}
                                onChange={(event) => setData('channel', event.target.value)}
                            >
                                {channels.map((channel) => (
                                    <option key={channel} value={channel} className="capitalize">
                                        {channel}
                                    </option>
                                ))}
                            </Select>
                        </Field>

                        <Field label="Note" htmlFor="fu_note" className="min-w-40 flex-1">
                            <Input
                                id="fu_note"
                                value={data.note}
                                onChange={(event) => setData('note', event.target.value)}
                            />
                        </Field>

                        <Button type="submit" loading={processing} disabled={!data.due_on}>
                            Add
                        </Button>
                    </form>
                )}

                {deal.follow_ups.length === 0 ? (
                    <EmptyState icon={Phone} title="Nothing scheduled" />
                ) : (
                    <ul className="space-y-2">
                        {deal.follow_ups.map((followUp) => (
                            <li
                                key={followUp.id}
                                className="flex items-center gap-3 rounded-control border border-border p-3"
                            >
                                <Badge tone={followUp.is_overdue ? 'danger' : 'default'} className="capitalize">
                                    {followUp.channel}
                                </Badge>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm">{followUp.note || 'Follow up'}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {followUp.due_on ? date(followUp.due_on) : ''}
                                    </p>
                                </div>
                                {followUp.state === 'pending' && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.post(
                                                `/follow-ups/${followUp.id}/complete`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Done
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
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
