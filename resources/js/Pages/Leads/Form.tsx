import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { adminLayout } from '@/Layouts/AdminLayout';

interface FormProps {
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
        notes: string | null;
        lost_reason: string | null;
        status: string;
        source: string;
        next_action_on: string | null;
        estimated_value: number | null;
    } | null;
    statuses: Array<{ value: string; label: string; description: string }>;
    sources: Array<{ value: string; label: string }>;
}

export default function LeadForm({ lead, statuses, sources }: FormProps) {
    const editing = lead !== null;

    const { data, setData, post, patch, processing, errors } = useForm({
        name: lead?.name ?? '',
        contact_person: lead?.contact_person ?? '',
        email: lead?.email ?? '',
        phone: lead?.phone ?? '',
        whatsapp: lead?.whatsapp ?? '',
        website: lead?.website ?? '',
        instagram: lead?.instagram ?? '',
        city: lead?.city ?? '',
        state: lead?.state ?? '',
        trade: lead?.trade ?? '',
        source: lead?.source ?? 'manual',
        status: lead?.status ?? 'new',
        next_action_on: lead?.next_action_on ?? '',
        estimated_value: lead?.estimated_value != null ? String(lead.estimated_value) : '',
        notes: lead?.notes ?? '',
        lost_reason: lead?.lost_reason ?? '',
    });

    function submit(event: React.FormEvent) {
        event.preventDefault();

        if (editing) {
            patch(`/leads/${lead.id}`);
        } else {
            post('/leads');
        }
    }

    return (
        <div className="space-y-6">
            <Head title={editing ? `Edit ${lead.name}` : 'New lead'} />

            <PageHeader
                title={editing ? lead.name : 'New lead'}
                subtitle={
                    editing
                        ? `${lead.code} — anybody worth calling back.`
                        : 'Somebody worth calling back. They become a client the day they say yes.'
                }
                actions={
                    <Button asChild variant="outline">
                        <Link href={editing ? `/leads/${lead.id}` : '/leads'}>
                            <ArrowLeft />
                            {editing ? 'Back to lead' : 'Back to leads'}
                        </Link>
                    </Button>
                }
            />

            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Who they are</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-4">
                            <Field label="Business or person" htmlFor="name" error={errors.name} required>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(event) => setData('name', event.target.value)}
                                    invalid={Boolean(errors.name)}
                                    placeholder="Madurai Jasmine Collective"
                                />
                            </Field>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Contact person" htmlFor="contact_person" error={errors.contact_person}>
                                    <Input
                                        id="contact_person"
                                        value={data.contact_person}
                                        onChange={(event) => setData('contact_person', event.target.value)}
                                    />
                                </Field>

                                <Field label="Trade" htmlFor="trade" error={errors.trade}>
                                    <Input
                                        id="trade"
                                        value={data.trade}
                                        onChange={(event) => setData('trade', event.target.value)}
                                        placeholder="Flower growing"
                                    />
                                </Field>

                                <Field label="Phone" htmlFor="phone" error={errors.phone}>
                                    <Input
                                        id="phone"
                                        value={data.phone}
                                        onChange={(event) => setData('phone', event.target.value)}
                                        placeholder="+91 98765 43210"
                                    />
                                </Field>

                                <Field label="WhatsApp" htmlFor="whatsapp" error={errors.whatsapp}>
                                    <Input
                                        id="whatsapp"
                                        value={data.whatsapp}
                                        onChange={(event) => setData('whatsapp', event.target.value)}
                                    />
                                </Field>

                                <Field label="Email" htmlFor="email" error={errors.email}>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        onChange={(event) => setData('email', event.target.value)}
                                        invalid={Boolean(errors.email)}
                                    />
                                </Field>

                                <Field label="Website" htmlFor="website" error={errors.website}>
                                    <Input
                                        id="website"
                                        type="url"
                                        value={data.website}
                                        onChange={(event) => setData('website', event.target.value)}
                                        placeholder="https://…"
                                        invalid={Boolean(errors.website)}
                                    />
                                </Field>

                                <Field label="City" htmlFor="city" error={errors.city}>
                                    <Input
                                        id="city"
                                        value={data.city}
                                        onChange={(event) => setData('city', event.target.value)}
                                    />
                                </Field>

                                <Field label="State" htmlFor="state" error={errors.state}>
                                    <Input
                                        id="state"
                                        value={data.state}
                                        onChange={(event) => setData('state', event.target.value)}
                                    />
                                </Field>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Field
                                label="What you know about them"
                                htmlFor="notes"
                                error={errors.notes}
                                hint="Background. Individual calls are logged on the lead itself."
                            >
                                <Textarea
                                    id="notes"
                                    rows={5}
                                    value={data.notes}
                                    onChange={(event) => setData('notes', event.target.value)}
                                />
                            </Field>
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Working it</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-4">
                            <Field
                                label="Where they came from"
                                htmlFor="source"
                                error={errors.source}
                                required
                                hint="The one field that changes what you do next quarter."
                            >
                                <Select
                                    id="source"
                                    value={data.source}
                                    onChange={(event) => setData('source', event.target.value)}
                                >
                                    {sources.map((source) => (
                                        <option key={source.value} value={source.value}>
                                            {source.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>

                            <Field label="Status" htmlFor="status" error={errors.status} required>
                                <Select
                                    id="status"
                                    value={data.status}
                                    onChange={(event) => setData('status', event.target.value)}
                                >
                                    {statuses.map((status) => (
                                        <option key={status.value} value={status.value}>
                                            {status.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>

                            <Field
                                label="Call them back on"
                                htmlFor="next_action_on"
                                error={errors.next_action_on}
                                hint="The list is ordered by this."
                            >
                                <Input
                                    id="next_action_on"
                                    type="date"
                                    value={data.next_action_on}
                                    onChange={(event) => setData('next_action_on', event.target.value)}
                                />
                            </Field>

                            <Field
                                label="Worth roughly"
                                htmlFor="estimated_value"
                                error={errors.estimated_value}
                                hint="A guess is fine. It seeds the first deal on conversion."
                            >
                                <Input
                                    id="estimated_value"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={data.estimated_value}
                                    onChange={(event) => setData('estimated_value', event.target.value)}
                                    placeholder="0"
                                />
                            </Field>

                            {data.status === 'unqualified' && (
                                <Field
                                    label="Why not a fit?"
                                    htmlFor="lost_reason"
                                    error={errors.lost_reason}
                                    hint="Worth a sentence — it is what makes the next hundred calls better."
                                >
                                    <Input
                                        id="lost_reason"
                                        value={data.lost_reason}
                                        onChange={(event) => setData('lost_reason', event.target.value)}
                                        placeholder="Too small a budget for now."
                                    />
                                </Field>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex gap-2">
                        <Button type="submit" loading={processing} className="flex-1">
                            {editing ? 'Save changes' : 'Add lead'}
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={editing ? `/leads/${lead.id}` : '/leads'}>Cancel</Link>
                        </Button>
                    </div>
                </div>
            </form>
        </div>
    );
}

LeadForm.layout = adminLayout;
