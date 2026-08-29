import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Building2,
    Globe,
    Mail,
    MapPin,
    MessageSquare,
    Pencil,
    Phone,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { ConfirmDialog, Dialog } from '@/Components/ui/dialog';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { Avatar, EmptyState } from '@/Components/ui/misc';
import { adminLayout } from '@/Layouts/AdminLayout';
import { dateTime, money, relative } from '@/lib/format';

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
    };
    statuses: Option[];
    can: { update: boolean; convert: boolean; delete: boolean };
}

export default function LeadShow({ lead, statuses, can }: ShowProps) {
    const [converting, setConverting] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

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

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    {can.update && <LogCard leadId={lead.id} nextActionOn={lead.next_action_on} />}

                    <Card>
                        <CardHeader>
                            <CardTitle>Contact log</CardTitle>
                        </CardHeader>

                        {lead.timeline.length === 0 ? (
                            <EmptyState
                                icon={MessageSquare}
                                title="Nothing logged yet"
                                description="Every call, message and visit goes here. It is what makes a lead worth keeping."
                            />
                        ) : (
                            <ol className="divide-y divide-border">
                                {lead.timeline.map((entry) => (
                                    <li key={entry.id} className="px-5 py-3">
                                        <p className="whitespace-pre-wrap text-sm">{entry.body}</p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {entry.author ?? 'Someone'}
                                            {entry.created_at && (
                                                <span title={dateTime(entry.created_at)}>
                                                    {' · '}
                                                    {relative(entry.created_at)}
                                                </span>
                                            )}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Card>

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
                            <Detail label="Last contact">
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
 * Logging a conversation.
 *
 * ── Why the next call is on the same form ──────────────────────────────────
 * The moment somebody finishes a call is the only moment they know when to ring
 * back, and it is the moment they are least willing to open a second form. Two
 * fields, one button: what happened, and when to try again.
 */
function LogCard({ leadId, nextActionOn }: { leadId: number; nextActionOn: string | null }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        body: '',
        next_action_on: nextActionOn ?? '',
    });

    return (
        <Card>
            <CardHeader>
                <CardTitle>Log a call</CardTitle>
            </CardHeader>

            <CardContent>
                <form
                    className="space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        post(`/leads/${leadId}/notes`, {
                            preserveScroll: true,
                            onSuccess: () => reset('body'),
                        });
                    }}
                >
                    <Field label="What happened?" htmlFor="body" error={errors.body}>
                        <Textarea
                            id="body"
                            rows={3}
                            value={data.body}
                            onChange={(event) => setData('body', event.target.value)}
                            placeholder="Spoke to the owner. Wants a quote for six reels before Diwali."
                        />
                    </Field>

                    <div className="flex flex-wrap items-end gap-3">
                        <Field
                            label="Call back on"
                            htmlFor="next_action_on"
                            error={errors.next_action_on}
                            className="w-44"
                        >
                            <Input
                                id="next_action_on"
                                type="date"
                                value={data.next_action_on}
                                onChange={(event) => setData('next_action_on', event.target.value)}
                            />
                        </Field>

                        <Button type="submit" loading={processing} disabled={!data.body.trim()}>
                            Log it
                        </Button>
                    </div>
                </form>
            </CardContent>
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
