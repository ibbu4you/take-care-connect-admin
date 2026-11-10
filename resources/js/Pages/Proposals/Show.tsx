import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, CheckCircle2, Copy, ExternalLink, MessageCircle, Pencil, Printer, Send, Undo2, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Pill, type Tone } from '@/Components/dashboard/parts';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog } from '@/Components/ui/dialog';
import { Field, Textarea } from '@/Components/ui/input';
import { usePermissions } from '@/hooks/usePermissions';
import { adminLayout } from '@/Layouts/AdminLayout';
import { date, dateTime, money, relative } from '@/lib/format';
import { cn } from '@/lib/utils';
import { proposalMessage, whatsappLink } from '@/lib/whatsapp';

interface ShowProps {
    proposal: {
        id: number;
        code: string;
        version: number;
        title: string;
        status: string;
        status_label: string;
        status_tone: Tone;
        total: number;
        subtotal: number;
        discount_percent: number;
        discount_amount: number;
        items: Array<{ description: string; quantity: number; rate: number; amount: number }>;
        valid_until: string | null;
        terms: string | null;
        internal_note: string | null;
        review_note: string | null;
        created_by: string | null;
        approver: string | null;
        approved_at: string | null;
        sender: string | null;
        sent_at: string | null;
        responded_at: string | null;
        updated_at: string | null;
        needs_approval: boolean;
        share_url: string | null;
    };
    deal: {
        id: number;
        code: string;
        title: string;
        client: { name: string; contact_person: string | null; phone: string | null } | null;
    };
    threshold: number;
    can: { edit: boolean; submit: boolean; approve: boolean; send: boolean; respond: boolean };
}

/** The path a proposal takes, drawn so everybody can see where this one is. */
const STEPS = [
    { key: 'draft', label: 'Draft' },
    { key: 'check', label: 'Checked' },
    { key: 'sent', label: 'Sent' },
    { key: 'answer', label: 'Answer' },
] as const;

function stepIndex(status: string): number {
    if (['draft', 'rejected'].includes(status)) return 0;
    if (['pending_approval', 'approved'].includes(status)) return 1;
    if (status === 'sent') return 2;
    return 3;
}

export default function ProposalShow({ proposal, deal, threshold, can }: ShowProps) {
    const { user } = usePermissions();
    const [sendingBack, setSendingBack] = useState(false);
    const [copied, setCopied] = useState(false);
    const current = stepIndex(proposal.status);

    const whatsapp = proposal.share_url
        ? whatsappLink(
              deal.client?.phone,
              proposalMessage({
                  contact: deal.client?.contact_person ?? null,
                  title: proposal.title,
                  url: proposal.share_url,
                  validUntil: proposal.valid_until ? date(proposal.valid_until) : null,
                  sender: user?.name ?? 'the Take Care Connect team',
              }),
          )
        : null;

    function sendOnWhatsapp() {
        if (whatsapp) {
            // Opened inside the click, before the request — a window opened
            // after an await is blocked as a popup.
            window.open(whatsapp, '_blank', 'noopener');
        }
        router.post(`/proposals/${proposal.id}/send`, {}, { preserveScroll: true });
    }

    async function copyLink() {
        if (!proposal.share_url) return;
        try {
            await navigator.clipboard.writeText(proposal.share_url);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            window.prompt('Copy this link', proposal.share_url);
        }
    }

    return (
        <div className="space-y-6">
            <Head title={`${proposal.code} · ${proposal.title}`} />

            <PageHeader
                title={proposal.title}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="tabular font-medium text-foreground">{proposal.code}</span>
                        {proposal.version > 1 && <span>v{proposal.version}</span>}
                        <Pill tone={proposal.status_tone}>{proposal.status_label}</Pill>
                        <span>
                            for{' '}
                            <Link href={`/deals/${deal.id}`} className="text-primary hover:underline">
                                {deal.client?.name ?? deal.title}
                            </Link>
                        </span>
                    </span>
                }
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href={`/deals/${deal.id}`}>
                                <ArrowLeft />
                                Deal
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <a href={`/proposals/${proposal.id}/print`} target="_blank" rel="noreferrer">
                                <Printer />
                                Print / PDF
                            </a>
                        </Button>
                    </>
                }
            />

            {/* Where it is, and the one thing to do next. */}
            <Card>
                <CardContent className="space-y-4 py-5">
                    <ol className="grid grid-cols-4 gap-2" aria-label="Progress">
                        {STEPS.map((step, index) => (
                            <li key={step.key} className="space-y-1.5">
                                <span className={cn('block h-1.5 rounded-full', index <= current ? 'bg-primary' : 'bg-border')} aria-hidden="true" />
                                <span className={cn('text-xs font-medium', index <= current ? 'text-foreground' : 'text-muted-foreground')}>
                                    {step.label}
                                </span>
                            </li>
                        ))}
                    </ol>

                    <NextAction proposal={proposal} can={can} threshold={threshold} />

                    <div className="flex flex-wrap gap-2">
                        {can.edit && (
                            <Button asChild variant="outline">
                                <Link href={`/proposals/${proposal.id}/edit`}>
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                        )}
                        {can.submit && (
                            <Button onClick={() => router.post(`/proposals/${proposal.id}/submit`, {}, { preserveScroll: true })}>
                                <Check />
                                {proposal.needs_approval ? 'Submit for approval' : 'Submit'}
                            </Button>
                        )}
                        {can.approve && (
                            <>
                                <Button variant="outline" onClick={() => setSendingBack(true)}>
                                    <Undo2 />
                                    Send back
                                </Button>
                                <Button onClick={() => router.post(`/proposals/${proposal.id}/approve`, {}, { preserveScroll: true })}>
                                    <CheckCircle2 />
                                    Approve
                                </Button>
                            </>
                        )}
                        {can.send && (
                            <>
                                <Button
                                    onClick={sendOnWhatsapp}
                                    className="bg-success text-success-foreground hover:bg-success/90"
                                    disabled={!whatsapp}
                                    title={whatsapp ? undefined : 'The client has no phone number — copy the link instead.'}
                                >
                                    <MessageCircle />
                                    Send on WhatsApp
                                </Button>
                                <Button variant="outline" onClick={() => router.post(`/proposals/${proposal.id}/send`, {}, { preserveScroll: true })}>
                                    <Send />
                                    I sent it another way
                                </Button>
                            </>
                        )}
                        {can.respond && (
                            <>
                                <Button variant="outline" onClick={() => router.post(`/proposals/${proposal.id}/respond/declined`, {}, { preserveScroll: true })}>
                                    <XCircle />
                                    They declined
                                </Button>
                                <Button onClick={() => router.post(`/proposals/${proposal.id}/respond/accepted`, {}, { preserveScroll: true })}>
                                    <CheckCircle2 />
                                    They accepted
                                </Button>
                            </>
                        )}
                        {proposal.share_url && (
                            <Button variant="ghost" onClick={copyLink}>
                                {copied ? <Check /> : <Copy />}
                                {copied ? 'Copied' : 'Copy client link'}
                            </Button>
                        )}
                    </div>
                </CardContent>
            </Card>

            <div className="grid items-start gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>What is offered</CardTitle>
                        {proposal.share_url && (
                            <a href={proposal.share_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                See it as the client does
                                <ExternalLink className="size-3" aria-hidden="true" />
                            </a>
                        )}
                    </CardHeader>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b border-border bg-muted/70">
                                <tr>
                                    <th className="h-10 px-5 text-left text-xs font-medium text-muted-foreground">Description</th>
                                    <th className="h-10 px-3 text-right text-xs font-medium text-muted-foreground">Qty</th>
                                    <th className="h-10 px-3 text-right text-xs font-medium text-muted-foreground">Rate</th>
                                    <th className="h-10 px-5 text-right text-xs font-medium text-muted-foreground">Amount</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {proposal.items.map((item, index) => (
                                    <tr key={index}>
                                        <td className="px-5 py-3">{item.description}</td>
                                        <td className="px-3 py-3 text-right tabular">{item.quantity}</td>
                                        <td className="px-3 py-3 text-right tabular">{money(item.rate)}</td>
                                        <td className="px-5 py-3 text-right font-medium tabular">{money(item.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <dl className="ml-auto w-full max-w-xs space-y-1.5 px-5 py-4 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-muted-foreground">Subtotal</dt>
                            <dd className="tabular">{money(proposal.subtotal)}</dd>
                        </div>
                        {proposal.discount_amount > 0 && (
                            <div className="flex justify-between">
                                <dt className="text-muted-foreground">Discount ({proposal.discount_percent}%)</dt>
                                <dd className="tabular">−{money(proposal.discount_amount)}</dd>
                            </div>
                        )}
                        <div className="flex justify-between border-t border-border pt-2 text-base font-semibold">
                            <dt>Total</dt>
                            <dd className="tabular">{money(proposal.total)}</dd>
                        </div>
                    </dl>
                    {proposal.terms && (
                        <div className="border-t border-border px-5 py-4">
                            <p className="mb-1 text-xs font-medium uppercase tracking-[0.04em] text-muted-foreground">Terms</p>
                            <p className="whitespace-pre-line text-sm">{proposal.terms}</p>
                        </div>
                    )}
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Trail</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <Row label="Written by" value={proposal.created_by} />
                        <Row label="Valid until" value={proposal.valid_until ? date(proposal.valid_until) : null} />
                        <Row
                            label="Approved"
                            value={proposal.approved_at ? `${proposal.approver ?? 'Within the limit'} · ${relative(proposal.approved_at)}` : null}
                            title={proposal.approved_at ? dateTime(proposal.approved_at) : undefined}
                        />
                        <Row
                            label="Sent"
                            value={proposal.sent_at ? `${proposal.sender ?? ''} · ${relative(proposal.sent_at)}` : null}
                            title={proposal.sent_at ? dateTime(proposal.sent_at) : undefined}
                        />
                        <Row label="Answered" value={proposal.responded_at ? relative(proposal.responded_at) : null} />
                        {proposal.internal_note && (
                            <div className="rounded-lg bg-muted/60 p-3 text-xs">
                                <p className="mb-1 font-medium">Internal note</p>
                                <p className="whitespace-pre-line text-muted-foreground">{proposal.internal_note}</p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <SendBackDialog open={sendingBack} onClose={() => setSendingBack(false)} proposalId={proposal.id} />
        </div>
    );
}

/** One sentence saying exactly what happens next, and who does it. */
function NextAction({ proposal, can, threshold }: Pick<ShowProps, 'proposal' | 'can' | 'threshold'>) {
    const text = (() => {
        switch (proposal.status) {
            case 'draft':
                return proposal.needs_approval
                    ? `Draft. The ${proposal.discount_percent}% discount is above the ${threshold}% limit, so submitting sends it to a Sales Manager first.`
                    : 'Draft. Check it, then submit — it can be sent straight after.';
            case 'rejected':
                return `Sent back for changes: “${proposal.review_note ?? 'see the note'}”. Edit it and submit again.`;
            case 'pending_approval':
                return can.approve
                    ? `Waiting for you: ${proposal.discount_percent}% off. Approve it, or send it back with what to change.`
                    : 'Waiting for a Sales Manager to approve the discount. You will be notified.';
            case 'approved':
                return 'Ready to send. Send it on WhatsApp — the client opens it from the link, no account needed.';
            case 'sent':
                return 'Sent. When the client answers, record it here. A follow-up call is on your list.';
            case 'accepted':
                return 'Accepted. Close the deal as won once it is signed.';
            default:
                return 'Declined. Write a new version from the deal, or close the deal as lost.';
        }
    })();

    return (
        <p
            className={cn(
                'rounded-lg px-3 py-2.5 text-sm',
                proposal.status === 'rejected' ? 'bg-destructive/10 text-destructive' : 'bg-muted/60',
            )}
        >
            {text}
        </p>
    );
}

function Row({ label, value, title }: { label: string; value: string | null; title?: string }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <span className="shrink-0 text-xs uppercase tracking-wide text-muted-foreground">{label}</span>
            <span className="min-w-0 truncate text-right" title={title}>
                {value ?? <span className="text-muted-foreground">—</span>}
            </span>
        </div>
    );
}

function SendBackDialog({ open, onClose, proposalId }: { open: boolean; onClose: () => void; proposalId: number }) {
    const { data, setData, post, processing, errors, reset } = useForm({ note: '' });

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Send back for changes"
            description="Say what to change. The salesperson is notified and sees this on the proposal."
            size="sm"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() =>
                            post(`/proposals/${proposalId}/send-back`, {
                                preserveScroll: true,
                                onSuccess: () => {
                                    reset();
                                    onClose();
                                },
                            })
                        }
                    >
                        Send back
                    </Button>
                </>
            }
        >
            <Field label="What should change?" htmlFor="review_note" error={errors.note} required>
                <Textarea
                    id="review_note"
                    rows={3}
                    value={data.note}
                    onChange={(event) => setData('note', event.target.value)}
                    placeholder="Keep it to 10% and add a second reel instead."
                    autoFocus
                />
            </Field>
        </Dialog>
    );
}

ProposalShow.layout = adminLayout;
