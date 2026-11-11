import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Building2,
    Globe,
    Mail,
    MapPin,
    MessageCircle,
    MessageSquare,
    Pencil,
    Phone,
    PhoneCall,
    StickyNote,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Pill, type Tone } from '@/Components/dashboard/parts';
import { PageHeader } from '@/Components/layout/PageHeader';
import { StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { ConfirmDialog, Dialog } from '@/Components/ui/dialog';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { usePermissions } from '@/hooks/usePermissions';
import { adminLayout } from '@/Layouts/AdminLayout';
import { dateTime, money, relative } from '@/lib/format';
import { cn } from '@/lib/utils';
import { introMessage, whatsappLink } from '@/lib/whatsapp';

interface Option {
    value: string;
    label: string;
    description: string;
}

interface ShowProps {
    lead: {
        id: number;
        code: string;
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
        status: string;
        status_label: string;
        source_label: string;
        owner: { id: number; name: string } | null;
        creator: { id: number; name: string } | null;
        next_action_on: string | null;
        days_since_contact: number | null;
        last_contacted_at: string | null;
        estimated_value: number | null;
        notes: string | null;
        lost_reason: string | null;
        converted_at: string | null;
        converted_client: { id: number; name: string } | null;
        is_overdue: boolean;
        timeline: Array<{ id: number; body: string; author: string | null; created_at: string | null }>;
        calls: Array<{
            id: number;
            outcome: string;
            outcome_label: string;
            connected: boolean;
            note: string | null;
            caller: string | null;
            called_at: string | null;
        }>;
    };
    statuses: Option[];
    outcomes: Outcome[];
    can: { update: boolean; convert: boolean; delete: boolean };
}

interface Outcome {
    value: string;
    label: string;
    connected: boolean;
    retry_days: number | null;
}

export default function LeadShow({ lead, statuses, outcomes, can }: ShowProps) {
    const { user } = usePermissions();
    const [converting, setConverting] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const whatsapp = whatsappLink(
        lead.whatsapp ?? lead.phone,
        introMessage({ contact: lead.contact_person, business: lead.name, sender: user?.name ?? 'the team' }),
    );
    const connectedCalls = lead.calls.filter((call) => call.connected).length;

    return (
        <div className="space-y-6">
            <Head title={lead.name} />

            <PageHeader
                title={lead.name}
                subtitle={`${lead.code} · ${lead.source_label}${lead.trade ? ` · ${lead.trade}` : ''}`}
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href="/leads">
                                <ArrowLeft />
                                All leads
                            </Link>
                        </Button>

                        {can.update && (
                            <Button asChild variant="outline">
                                <Link href={`/leads/${lead.id}/edit`}>
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                        )}

                        {can.convert && (
                            <Button onClick={() => setConverting(true)}>
                                <ArrowRight />
                                Convert to client
                            </Button>
                        )}
                    </>
                }
            />

            {lead.converted_client && (
                <Card className="border-success/30 bg-success/5">
                    <CardContent className="flex flex-wrap items-center gap-2 py-3 text-sm">
                        <span>
                            Converted {lead.converted_at ? relative(lead.converted_at) : ''} —{' '}
                            <Link
                                href={`/clients/${lead.converted_client.id}`}
                                className="font-medium text-primary hover:underline"
                            >
                                {lead.converted_client.name}
                            </Link>
                        </span>
                    </CardContent>
                </Card>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    {can.update && <LogCard leadId={lead.id} nextActionOn={lead.next_action_on} outcomes={outcomes} />}

                    <History lead={lead} />

                    {lead.notes && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Background</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="whitespace-pre-wrap text-sm">{lead.notes}</p>
                            </CardContent>
                        </Card>
                    )}
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-3">
                            <StageBadge family="lead" stage={lead.status} dot />

                            {lead.lost_reason && (
                                <p className="text-sm text-muted-foreground">{lead.lost_reason}</p>
                            )}

                            {can.update && lead.status !== 'converted' && (
                                <StatusPicker leadId={lead.id} current={lead.status} statuses={statuses} />
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Contact</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            {lead.contact_person && (
                                <p className="font-medium">{lead.contact_person}</p>
                            )}

                            {(lead.phone || whatsapp) && (
                                <div className="grid grid-cols-2 gap-2">
                                    {lead.phone && (
                                        <Button asChild variant="outline" size="sm" className="rounded-lg">
                                            <a href={`tel:${lead.phone}`}>
                                                <Phone />
                                                Call
                                            </a>
                                        </Button>
                                    )}
                                    {whatsapp && (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                            className="rounded-lg border-success/30 text-success hover:bg-success/10 hover:text-success"
                                        >
                                            <a href={whatsapp} target="_blank" rel="noreferrer">
                                                <MessageCircle />
                                                WhatsApp
                                            </a>
                                        </Button>
                                    )}
                                </div>
                            )}
                            {lead.phone && (
                                <ContactLine icon={Phone}>
                                    <a href={`tel:${lead.phone}`} className="hover:text-primary">
                                        {lead.phone}
                                    </a>
                                </ContactLine>
                            )}
                            {lead.email && (
                                <ContactLine icon={Mail}>
                                    <a href={`mailto:${lead.email}`} className="break-all hover:text-primary">
                                        {lead.email}
                                    </a>
                                </ContactLine>
                            )}
                            {lead.website && (
                                <ContactLine icon={Globe}>
                                    <a
                                        href={lead.website}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="break-all hover:text-primary"
                                    >
                                        {lead.website}
                                    </a>
                                </ContactLine>
                            )}
                            {(lead.city || lead.state) && (
                                <ContactLine icon={MapPin}>
                                    {[lead.city, lead.state].filter(Boolean).join(', ')}
                                </ContactLine>
                            )}
                            {lead.trade && <ContactLine icon={Building2}>{lead.trade}</ContactLine>}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <Detail label="Owner">
                                {lead.owner ? (
                                    <span className="flex items-center justify-end gap-2">
                                        <Avatar name={lead.owner.name} size="sm" />
                                        {lead.owner.name}
                                    </span>
                                ) : (
                                    'Unowned'
                                )}
                            </Detail>
                            <Detail label="Worth">
                                {lead.estimated_value !== null ? money(lead.estimated_value) : '—'}
                            </Detail>
                            <Detail label="Calls">
                                <span className="tabular">
                                    {lead.calls.length} made · {connectedCalls} connected
                                </span>
                            </Detail>
                            <Detail label="Last spoke">
                                {lead.last_contacted_at ? relative(lead.last_contacted_at) : 'Never'}
                            </Detail>
                            <Detail label="Added by">{lead.creator?.name ?? '—'}</Detail>
                        </CardContent>
                    </Card>

                    {can.delete && (
                        <Button
                            variant="ghost"
                            className="w-full text-destructive hover:bg-destructive/10"
                            onClick={() => setConfirmDelete(true)}
                        >
                            <Trash2 />
                            Remove lead
                        </Button>
                    )}
                </div>
            </div>

            <ConvertDialog open={converting} onClose={() => setConverting(false)} lead={lead} />

            <ConfirmDialog
                open={confirmDelete}
                onClose={() => setConfirmDelete(false)}
                onConfirm={() => router.delete(`/leads/${lead.id}`, { onFinish: () => setConfirmDelete(false) })}
                title={`Remove ${lead.name}?`}
                description="This is for a lead entered twice. If they said no, mark them “Not a fit” instead — that keeps the record, which is the point of writing prospects down."
                confirmLabel="Remove lead"
            />
        </div>
    );
}

/* ───────────────────────────────────────────────────────────────────────── */

/**
 * Logging a call, or a note.
 *
 * ── Why the outcome is a button, not a sentence ───────────────────────────
 * "How many calls got through today" can only be answered if the outcome is a
 * value. One tap records it; the note is optional, for when there is something
 * worth writing.
 *
 * ── Why the next call is on the same form ──────────────────────────────────
 * The moment somebody puts the phone down is the only moment they know when to
 * ring back, and the moment they are least willing to open a second form. So
 * choosing an outcome fills in its usual retry — tomorrow for no answer, two
 * days for "call back" — and the caller only has to change it if they know
 * better. "Not interested" clears it: chasing them is the wrong default.
 */
function LogCard({
    leadId,
    nextActionOn,
    outcomes,
}: {
    leadId: number;
    nextActionOn: string | null;
    outcomes: Outcome[];
}) {
    const [mode, setMode] = useState<'call' | 'note'>('call');

    const call = useForm({ outcome: '', note: '', next_action_on: nextActionOn ?? '' });
    const note = useForm({ body: '', next_action_on: nextActionOn ?? '' });

    function choose(outcome: Outcome) {
        call.setData((data) => ({
            ...data,
            outcome: outcome.value,
            next_action_on: outcome.retry_days === null ? '' : daysFromToday(outcome.retry_days),
        }));
    }

    const connected = outcomes.filter((outcome) => outcome.connected);
    const missed = outcomes.filter((outcome) => !outcome.connected);

    return (
        <Card>
            <CardHeader>
                <CardTitle>Log</CardTitle>
                <div className="inline-flex h-8 items-center rounded-lg bg-muted p-0.5" role="radiogroup" aria-label="What to log">
                    {(
                        [
                            ['call', 'A call', PhoneCall],
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
                {mode === 'call' ? (
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            call.post(`/leads/${leadId}/calls`, {
                                preserveScroll: true,
                                onSuccess: () => call.setData((data) => ({ ...data, outcome: '', note: '' })),
                            });
                        }}
                    >
                        <fieldset>
                            <legend className="mb-2 text-sm font-medium">What happened?</legend>
                            <OutcomeGroup label="Spoke to them" outcomes={connected} value={call.data.outcome} onChoose={choose} />
                            <OutcomeGroup label="Did not get through" outcomes={missed} value={call.data.outcome} onChoose={choose} />
                            {call.errors.outcome && <p className="mt-1 text-xs font-medium text-destructive">{call.errors.outcome}</p>}
                        </fieldset>

                        <Field label="Anything worth writing down?" htmlFor="call_note" error={call.errors.note} hint="Optional.">
                            <Textarea
                                id="call_note"
                                rows={2}
                                value={call.data.note}
                                onChange={(event) => call.setData('note', event.target.value)}
                                placeholder="Owner is keen. Wants to be interviewed after the festival rush."
                            />
                        </Field>

                        <div className="flex flex-wrap items-end gap-3">
                            <Field
                                label="Next call"
                                htmlFor="call_next"
                                error={call.errors.next_action_on}
                                hint={call.data.next_action_on ? undefined : 'No next call.'}
                                className="w-44"
                            >
                                <Input
                                    id="call_next"
                                    type="date"
                                    value={call.data.next_action_on}
                                    onChange={(event) => call.setData('next_action_on', event.target.value)}
                                />
                            </Field>

                            <Button type="submit" loading={call.processing} disabled={!call.data.outcome}>
                                Log call
                            </Button>
                        </div>
                    </form>
                ) : (
                    <form
                        className="space-y-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            note.post(`/leads/${leadId}/notes`, {
                                preserveScroll: true,
                                onSuccess: () => note.reset('body'),
                            });
                        }}
                    >
                        <Field label="Note" htmlFor="body" error={note.errors.body}>
                            <Textarea
                                id="body"
                                rows={3}
                                value={note.data.body}
                                onChange={(event) => note.setData('body', event.target.value)}
                                placeholder="Met them at the Pongal market. Runs the shop with her two sons."
                            />
                        </Field>

                        <div className="flex flex-wrap items-end gap-3">
                            <Field label="Call back on" htmlFor="note_next" error={note.errors.next_action_on} className="w-44">
                                <Input
                                    id="note_next"
                                    type="date"
                                    value={note.data.next_action_on}
                                    onChange={(event) => note.setData('next_action_on', event.target.value)}
                                />
                            </Field>

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

function OutcomeGroup({
    label,
    outcomes,
    value,
    onChoose,
}: {
    label: string;
    outcomes: Outcome[];
    value: string;
    onChoose: (outcome: Outcome) => void;
}) {
    return (
        <div className="mb-3">
            <p className="mb-1.5 text-xs text-muted-foreground">{label}</p>
            <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={label}>
                {outcomes.map((outcome) => (
                    <button
                        key={outcome.value}
                        type="button"
                        role="radio"
                        aria-checked={value === outcome.value}
                        onClick={() => onChoose(outcome)}
                        className={cn(
                            'h-8 rounded-full border px-3 text-xs font-medium transition-colors',
                            value === outcome.value
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-card text-foreground hover:border-primary/40 hover:bg-primary/5',
                        )}
                    >
                        {outcome.label}
                    </button>
                ))}
            </div>
        </div>
    );
}

/** YYYY-MM-DD, in the browser's own day — what a date input expects. */
function daysFromToday(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
}

function outcomeTone(outcome: string): Tone {
    if (outcome === 'interested') return 'success';
    if (outcome === 'call_back') return 'info';
    if (outcome === 'not_interested' || outcome === 'wrong_number') return 'danger';
    return 'neutral';
}

/**
 * Calls and notes in one history, newest first.
 *
 * Two lists would make the reader interleave them in their head to answer
 * "what happened last time" — which is the only question the history exists for.
 */
function History({ lead }: { lead: ShowProps['lead'] }) {
    const entries = useMemo(
        () =>
            [
                ...lead.calls.map((call) => ({ kind: 'call' as const, key: `call-${call.id}`, at: call.called_at, call })),
                ...lead.timeline.map((entry) => ({ kind: 'note' as const, key: `note-${entry.id}`, at: entry.created_at, entry })),
            ].sort((a, b) => (b.at ?? '').localeCompare(a.at ?? '')),
        [lead.calls, lead.timeline],
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle>History</CardTitle>
            </CardHeader>

            {entries.length === 0 ? (
                <EmptyState
                    icon={MessageSquare}
                    title="Nothing logged yet"
                    description="Every call, message and visit goes here. It is what makes a lead worth keeping."
                />
            ) : (
                <ol className="divide-y divide-border">
                    {entries.map((item) => (
                        <li key={item.key} className="flex gap-3 px-5 py-3">
                            <span
                                className={cn(
                                    'mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded-full',
                                    item.kind === 'call' ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground',
                                )}
                                aria-hidden="true"
                            >
                                {item.kind === 'call' ? <PhoneCall className="size-3.5" /> : <StickyNote className="size-3.5" />}
                            </span>

                            <div className="min-w-0 flex-1">
                                {item.kind === 'call' ? (
                                    <>
                                        <Pill tone={outcomeTone(item.call.outcome)}>{item.call.outcome_label}</Pill>
                                        {item.call.note && <p className="mt-1.5 whitespace-pre-wrap text-sm">{item.call.note}</p>}
                                    </>
                                ) : (
                                    <p className="whitespace-pre-wrap text-sm">{item.entry.body}</p>
                                )}

                                <p className="mt-1 text-xs text-muted-foreground">
                                    {(item.kind === 'call' ? item.call.caller : item.entry.author) ?? 'Someone'}
                                    {item.at && (
                                        <span title={dateTime(item.at)}>
                                            {' · '}
                                            {relative(item.at)}
                                        </span>
                                    )}
                                </p>
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </Card>
    );
}

function StatusPicker({
    leadId,
    current,
    statuses,
}: {
    leadId: number;
    current: string;
    statuses: Option[];
}) {
    const [reason, setReason] = useState('');
    const [pending, setPending] = useState<string | null>(null);

    function apply(status: string, lostReason?: string) {
        router.patch(
            `/leads/${leadId}/status`,
            { status, lost_reason: lostReason ?? null },
            { preserveScroll: true, onFinish: () => setPending(null) },
        );
    }

    return (
        <div className="space-y-2">
            <Select
                value={current}
                onChange={(event) => {
                    const next = event.target.value;

                    // "Not a fit" is the one that wants a sentence with it, so it
                    // asks before saving rather than losing the reason.
                    if (next === 'unqualified') {
                        setPending(next);
                    } else {
                        apply(next);
                    }
                }}
                aria-label="Change status"
            >
                {statuses.map((status) => (
                    <option key={status.value} value={status.value}>
                        {status.label}
                    </option>
                ))}
            </Select>

            <p className="text-xs text-muted-foreground">
                {statuses.find((status) => status.value === current)?.description}
            </p>

            <Dialog
                open={pending === 'unqualified'}
                onClose={() => setPending(null)}
                title="Not a fit — why?"
                size="sm"
                description="One sentence. It is what makes the next hundred calls better."
                footer={
                    <>
                        <Button variant="outline" onClick={() => setPending(null)}>
                            Cancel
                        </Button>
                        <Button onClick={() => apply('unqualified', reason)}>Mark not a fit</Button>
                    </>
                }
            >
                <Field label="Reason" htmlFor="lost_reason">
                    <Input
                        id="lost_reason"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder="Too small a budget for now."
                        autoFocus
                    />
                </Field>
            </Dialog>
        </div>
    );
}

/**
 * Converting.
 *
 * The first deal is offered here because the moment somebody agrees to become a
 * client is exactly when the value is known. Left as a second job it becomes a
 * client with nothing in the pipeline — a name in a directory.
 */
function ConvertDialog({
    open,
    onClose,
    lead,
}: {
    open: boolean;
    onClose: () => void;
    lead: ShowProps['lead'];
}) {
    const { data, setData, post, processing, errors } = useForm({
        deal_title: '',
        deal_value: lead.estimated_value != null ? String(lead.estimated_value) : '',
    });

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={`Convert ${lead.name} to a client`}
            description="Creates the client record. The lead stays as it is, so you keep the record of how they were found."
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() => post(`/leads/${lead.id}/convert`, { onSuccess: onClose })}
                    >
                        Convert
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <p className="rounded-control bg-muted/50 p-3 text-xs text-muted-foreground">
                    Their contact details, trade and source carry across. You keep the account, so nothing goes
                    quiet in week one.
                </p>

                <Field
                    label="Open a deal at the same time?"
                    htmlFor="deal_title"
                    error={errors.deal_title}
                    hint="Optional. Leave blank to convert without one."
                >
                    <Input
                        id="deal_title"
                        value={data.deal_title}
                        onChange={(event) => setData('deal_title', event.target.value)}
                        placeholder="Three-interview feature package"
                    />
                </Field>

                {data.deal_title.trim() !== '' && (
                    <Field label="Expected value" htmlFor="deal_value" error={errors.deal_value} hint="In rupees.">
                        <Input
                            id="deal_value"
                            type="number"
                            min={0}
                            value={data.deal_value}
                            onChange={(event) => setData('deal_value', event.target.value)}
                        />
                    </Field>
                )}
            </div>
        </Dialog>
    );
}

function ContactLine({
    icon: Icon,
    children,
}: {
    icon: typeof Phone;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-start gap-2">
            <Icon className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span className="min-w-0 break-words">{children}</span>
        </div>
    );
}

function Detail({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <span className="shrink-0 text-xs uppercase tracking-wide text-muted-foreground">{label}</span>
            <span className="min-w-0 text-right">{children}</span>
        </div>
    );
}

LeadShow.layout = adminLayout;
