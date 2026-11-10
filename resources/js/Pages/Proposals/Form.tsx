import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Field, Input, Textarea } from '@/Components/ui/input';
import { adminLayout } from '@/Layouts/AdminLayout';
import { money } from '@/lib/format';
import { cn } from '@/lib/utils';

interface Line {
    description: string;
    quantity: number | string;
    rate: number | string;
}

interface FormProps {
    proposal: { id: number; code: string; version: number } | null;
    deal: { id: number; code: string; title: string; client: { name: string } | null };
    defaults: {
        title: string;
        items: Line[];
        discount_percent: number;
        valid_until: string;
        terms: string;
        internal_note: string;
    };
    threshold: number;
}

/**
 * Writing a proposal.
 *
 * The running total is a convenience; the server recomputes every amount on
 * save, so what the buyer sees is never a figure the browser rounded.
 */
export default function ProposalForm({ proposal, deal, defaults, threshold }: FormProps) {
    const { data, setData, post, patch, processing, errors } = useForm({
        title: defaults.title,
        items: defaults.items.map((item) => ({ description: item.description, quantity: item.quantity, rate: item.rate })),
        discount_percent: String(defaults.discount_percent ?? 0),
        valid_until: defaults.valid_until ?? '',
        terms: defaults.terms ?? '',
        internal_note: defaults.internal_note ?? '',
    });

    const subtotal = data.items.reduce((sum, item) => sum + (Number(item.quantity) || 0) * (Number(item.rate) || 0), 0);
    const discount = (subtotal * (Number(data.discount_percent) || 0)) / 100;
    const total = subtotal - discount;
    const needsApproval = (Number(data.discount_percent) || 0) > threshold;

    function setLine(index: number, key: keyof Line, value: string) {
        setData(
            'items',
            data.items.map((item, i) => (i === index ? { ...item, [key]: value } : item)),
        );
    }

    const itemError = (index: number, key: string) => (errors as Record<string, string>)[`items.${index}.${key}`];

    return (
        <div className="space-y-6">
            <Head title={proposal ? `Edit ${proposal.code}` : 'New proposal'} />

            <PageHeader
                title={proposal ? `Edit ${proposal.code}` : 'New proposal'}
                subtitle={`For ${deal.client?.name ?? deal.title} · ${deal.code}`}
                actions={
                    <Button asChild variant="outline">
                        <Link href={proposal ? `/proposals/${proposal.id}` : `/deals/${deal.id}`}>
                            <ArrowLeft />
                            {proposal ? 'Back to proposal' : 'Back to deal'}
                        </Link>
                    </Button>
                }
            />

            <form
                className="grid items-start gap-6 lg:grid-cols-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (proposal) {
                        patch(`/proposals/${proposal.id}`);
                    } else {
                        post(`/deals/${deal.id}/proposals`);
                    }
                }}
            >
                <div className="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>What you are offering</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Field label="Title" htmlFor="title" error={errors.title} required>
                                <Input id="title" value={data.title} onChange={(event) => setData('title', event.target.value)} invalid={Boolean(errors.title)} />
                            </Field>

                            <div className="overflow-hidden rounded-lg border border-border">
                                <div className="hidden grid-cols-[1fr_6rem_8rem_8rem_2.5rem] gap-2 bg-muted/70 px-3 py-2 text-xs font-medium text-muted-foreground sm:grid">
                                    <span>Description</span>
                                    <span className="text-right">Qty</span>
                                    <span className="text-right">Rate (₹)</span>
                                    <span className="text-right">Amount</span>
                                    <span />
                                </div>

                                <ul className="divide-y divide-border">
                                    {data.items.map((item, index) => (
                                        <li key={index} className="grid gap-2 p-3 sm:grid-cols-[1fr_6rem_8rem_8rem_2.5rem] sm:items-start">
                                            <div>
                                                <Input
                                                    value={item.description}
                                                    onChange={(event) => setLine(index, 'description', event.target.value)}
                                                    placeholder="Three business stories with reels"
                                                    aria-label={`Line ${index + 1} description`}
                                                    invalid={Boolean(itemError(index, 'description'))}
                                                />
                                                {itemError(index, 'description') && (
                                                    <p className="mt-1 text-xs font-medium text-destructive">{itemError(index, 'description')}</p>
                                                )}
                                            </div>
                                            <Input
                                                type="number"
                                                min={0}
                                                step="any"
                                                value={item.quantity}
                                                onChange={(event) => setLine(index, 'quantity', event.target.value)}
                                                className="text-right"
                                                aria-label={`Line ${index + 1} quantity`}
                                                invalid={Boolean(itemError(index, 'quantity'))}
                                            />
                                            <Input
                                                type="number"
                                                min={0}
                                                step="any"
                                                value={item.rate}
                                                onChange={(event) => setLine(index, 'rate', event.target.value)}
                                                className="text-right"
                                                aria-label={`Line ${index + 1} rate`}
                                                invalid={Boolean(itemError(index, 'rate'))}
                                            />
                                            <span className="flex h-9 items-center justify-end text-sm font-medium tabular">
                                                {money((Number(item.quantity) || 0) * (Number(item.rate) || 0))}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => setData('items', data.items.filter((_, i) => i !== index))}
                                                disabled={data.items.length === 1}
                                                className="inline-flex size-9 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive disabled:opacity-30"
                                                aria-label={`Remove line ${index + 1}`}
                                            >
                                                <Trash2 className="size-4" />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            {errors.items && <p className="text-xs font-medium text-destructive">{errors.items}</p>}

                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="rounded-lg"
                                onClick={() => setData('items', [...data.items, { description: '', quantity: 1, rate: 0 }])}
                                disabled={data.items.length >= 30}
                            >
                                <Plus />
                                Add a line
                            </Button>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Terms</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Field label="Shown to the buyer" htmlFor="terms" error={errors.terms}>
                                <Textarea id="terms" rows={4} value={data.terms} onChange={(event) => setData('terms', event.target.value)} />
                            </Field>
                            <Field label="Internal note" htmlFor="internal_note" error={errors.internal_note} hint="Only the team sees this — useful context for whoever approves the discount.">
                                <Textarea id="internal_note" rows={2} value={data.internal_note} onChange={(event) => setData('internal_note', event.target.value)} />
                            </Field>
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-6 lg:sticky lg:top-28">
                    <Card>
                        <CardHeader>
                            <CardTitle>Total</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Field
                                label="Discount"
                                htmlFor="discount_percent"
                                error={errors.discount_percent}
                                hint={`Up to ${threshold}% goes out straight away; more needs a Sales Manager.`}
                            >
                                <div className="relative">
                                    <Input
                                        id="discount_percent"
                                        type="number"
                                        min={0}
                                        max={100}
                                        step="any"
                                        value={data.discount_percent}
                                        onChange={(event) => setData('discount_percent', event.target.value)}
                                        className="pr-8"
                                    />
                                    <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">%</span>
                                </div>
                            </Field>

                            <dl className="space-y-2 rounded-lg bg-muted/50 p-3 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">Subtotal</dt>
                                    <dd className="tabular">{money(subtotal)}</dd>
                                </div>
                                {discount > 0 && (
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">Discount</dt>
                                        <dd className="tabular">−{money(discount)}</dd>
                                    </div>
                                )}
                                <div className="flex justify-between border-t border-border pt-2 text-base font-semibold">
                                    <dt>Total</dt>
                                    <dd className="tabular">{money(total)}</dd>
                                </div>
                            </dl>

                            <div
                                className={cn(
                                    'flex items-start gap-2 rounded-lg p-3 text-xs',
                                    needsApproval ? 'bg-stage-review/10 text-stage-review' : 'bg-success/10 text-success',
                                )}
                            >
                                {needsApproval && <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />}
                                <span>
                                    {needsApproval
                                        ? 'This discount is above the limit, so a Sales Manager will approve it before it can be sent.'
                                        : 'Within the discount limit — it can be sent as soon as you submit it.'}
                                </span>
                            </div>

                            <Field label="Valid until" htmlFor="valid_until" error={errors.valid_until}>
                                <Input id="valid_until" type="date" value={data.valid_until} onChange={(event) => setData('valid_until', event.target.value)} />
                            </Field>

                            <Button type="submit" className="w-full" loading={processing}>
                                {proposal ? 'Save changes' : 'Save draft'}
                            </Button>
                            <p className="text-center text-xs text-muted-foreground">You can still review it before submitting.</p>
                        </CardContent>
                    </Card>
                </div>
            </form>
        </div>
    );
}

ProposalForm.layout = adminLayout;
