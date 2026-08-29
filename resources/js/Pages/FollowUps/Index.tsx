import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, CheckCircle2, Inbox, Phone, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog } from '@/Components/ui/dialog';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { EmptyState } from '@/Components/ui/misc';
import { adminLayout } from '@/Layouts/AdminLayout';
import { date, relative } from '@/lib/format';
import { cn } from '@/lib/utils';

interface FollowUpRow {
    id: number;
    due_on: string | null;
    channel: string;
    note: string | null;
    state: string;
    outcome_note: string | null;
    completed_at: string | null;
    client: { id: number; name: string } | null;
    deal: { id: number; code: string; title: string } | null;
}

interface IndexProps {
    overdue: FollowUpRow[];
    today: FollowUpRow[];
    upcoming: FollowUpRow[];
    recentlyDone: FollowUpRow[];
    channels: string[];
}

/**
 * The chase list.
 *
 * Three groups rather than one sorted list, because they are three different
 * kinds of thing: overdue is a failure, today is a plan, and next week is
 * context. A single date-sorted table makes the first two look like the third.
 */
export default function FollowUpsIndex({ overdue, today, upcoming, recentlyDone, channels }: IndexProps) {
    const [adding, setAdding] = useState(false);
    const [completing, setCompleting] = useState<FollowUpRow | null>(null);

    const nothing = overdue.length === 0 && today.length === 0 && upcoming.length === 0;

    return (
        <div className="space-y-6">
            <Head title="Follow-ups" />

            <PageHeader
                title="Follow-ups"
                subtitle="Who you owe a call. Kept here rather than in a notebook so somebody can pick it up when you are away."
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <Plus />
                        Add follow-up
                    </Button>
                }
            />

            {nothing ? (
                <Card>
                    <EmptyState
                        icon={CheckCircle2}
                        title="Nothing outstanding"
                        description="Add a follow-up here, or from a deal, and it will show up on the day."
                    />
                </Card>
            ) : (
                <>
                    <Group
                        title="Overdue"
                        icon={AlertTriangle}
                        tone="danger"
                        items={overdue}
                        onComplete={setCompleting}
                    />
                    <Group title="Today" icon={CalendarClock} tone="warning" items={today} onComplete={setCompleting} />
                    <Group title="Coming up" icon={Inbox} tone="info" items={upcoming} onComplete={setCompleting} />
                </>
            )}

            {recentlyDone.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <CheckCircle2 className="size-4 text-success" aria-hidden="true" />
                            Recently done
                        </CardTitle>
                    </CardHeader>
                    <ul className="divide-y divide-border">
                        {recentlyDone.map((item) => (
                            <li key={item.id} className="px-5 py-3">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="min-w-0 truncate text-sm">
                                        {item.client?.name ?? 'No client'} — {item.note || item.channel}
                                    </span>
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {relative(item.completed_at)}
                                    </span>
                                </div>
                                {item.outcome_note && (
                                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                        {item.outcome_note}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <AddDialog open={adding} onClose={() => setAdding(false)} channels={channels} />

            {completing && (
                <CompleteDialog followUp={completing} onClose={() => setCompleting(null)} />
            )}
        </div>
    );
}

const TONES = {
    danger: 'bg-destructive/15 text-destructive',
    warning: 'bg-warning/15 text-warning',
    info: 'bg-info/15 text-info',
} as const;

function Group({
    title,
    icon: Icon,
    tone,
    items,
    onComplete,
}: {
    title: string;
    icon: typeof Inbox;
    tone: keyof typeof TONES;
    items: FollowUpRow[];
    onComplete: (item: FollowUpRow) => void;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <span
                        className={cn('inline-flex size-6 items-center justify-center rounded-control', TONES[tone])}
                        aria-hidden="true"
                    >
                        <Icon className="size-3.5" />
                    </span>
                    {title}
                    <Badge tone="default">{items.length}</Badge>
                </CardTitle>
            </CardHeader>

            <ul className="divide-y divide-border">
                {items.map((item) => (
                    <li key={item.id} className="flex items-center gap-3 px-5 py-3">
                        <Badge tone="default" className="capitalize">
                            {item.channel}
                        </Badge>

                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium">
                                {item.client ? (
                                    <Link href={`/clients/${item.client.id}`} className="hover:text-primary">
                                        {item.client.name}
                                    </Link>
                                ) : (
                                    'No client'
                                )}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {item.note || 'Follow up'}
                                {item.deal ? ` · ${item.deal.code}` : ''}
                            </p>
                        </div>

                        <span className="shrink-0 text-xs text-muted-foreground">
                            {item.due_on ? date(item.due_on) : ''}
                        </span>

                        <Button size="sm" variant="outline" onClick={() => onComplete(item)}>
                            Done
                        </Button>

                        <Button
                            variant="ghost"
                            size="icon"
                            className="text-destructive hover:bg-destructive/10"
                            aria-label="Remove follow-up"
                            onClick={() => router.delete(`/follow-ups/${item.id}`, { preserveScroll: true })}
                        >
                            <Trash2 />
                        </Button>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function AddDialog({ open, onClose, channels }: { open: boolean; onClose: () => void; channels: string[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        client_id: '',
        due_on: '',
        channel: 'call',
        note: '',
    });

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Add a follow-up"
            description="A reminder to get back to somebody."
            size="sm"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() =>
                            post('/follow-ups', {
                                preserveScroll: true,
                                onSuccess: () => {
                                    reset();
                                    onClose();
                                },
                            })
                        }
                    >
                        Add
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="When" htmlFor="due_on" error={errors.due_on} required>
                    <Input
                        id="due_on"
                        type="date"
                        value={data.due_on}
                        onChange={(event) => setData('due_on', event.target.value)}
                        invalid={Boolean(errors.due_on)}
                    />
                </Field>

                <Field label="How" htmlFor="channel" error={errors.channel} required>
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

                <Field label="What about?" htmlFor="fu_note" error={errors.note}>
                    <Textarea
                        id="fu_note"
                        value={data.note}
                        onChange={(event) => setData('note', event.target.value)}
                        rows={3}
                        placeholder="Chase the signed quote."
                    />
                </Field>
            </div>
        </Dialog>
    );
}

function CompleteDialog({ followUp, onClose }: { followUp: FollowUpRow; onClose: () => void }) {
    const { data, setData, post, processing } = useForm({ outcome_note: '' });

    return (
        <Dialog
            open
            onClose={onClose}
            title="Mark it done"
            description="What came of it? Optional, but the next person to pick this up will thank you."
            size="sm"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() =>
                            post(`/follow-ups/${followUp.id}/complete`, {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        Done
                    </Button>
                </>
            }
        >
            <Field label="Outcome" htmlFor="outcome_note">
                <Textarea
                    id="outcome_note"
                    value={data.outcome_note}
                    onChange={(event) => setData('outcome_note', event.target.value)}
                    rows={3}
                    placeholder="Spoke to Suresh — wants to revisit in March."
                />
            </Field>
        </Dialog>
    );
}

FollowUpsIndex.layout = adminLayout;
