import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { FormEvent } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { adminLayout } from '@/Layouts/AdminLayout';

interface FormProps {
    client: {
        id: number;
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
        address: string | null;
        status: string;
        notes: string | null;
        source: string | null;
    } | null;
}

export default function ClientForm({ client }: FormProps) {
    const editing = client !== null;

    const { data, setData, post, patch, processing, errors } = useForm({
        name: client?.name ?? '',
        contact_person: client?.contact_person ?? '',
        email: client?.email ?? '',
        phone: client?.phone ?? '',
        whatsapp: client?.whatsapp ?? '',
        website: client?.website ?? '',
        instagram: client?.instagram ?? '',
        city: client?.city ?? '',
        state: client?.state ?? '',
        trade: client?.trade ?? '',
        address: client?.address ?? '',
        status: client?.status ?? 'active',
        source: client?.source ?? 'manual',
        notes: client?.notes ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        if (editing) {
            patch(`/clients/${client.id}`);
        } else {
            post('/clients');
        }
    }

    return (
        <div className="space-y-6">
            <Head title={editing ? `Edit ${client.name}` : 'Add a client'} />

            <PageHeader
                title={editing ? client.name : 'Add a client'}
                subtitle="A business or person you work with. Interviews and deals are booked against them."
                actions={
                    <Button asChild variant="outline">
                        <Link href={editing ? `/clients/${client.id}` : '/clients'}>
                            <ArrowLeft />
                            Back
                        </Link>
                    </Button>
                }
            />

            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Who they are</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <Field label="Business name" htmlFor="name" error={errors.name} required className="sm:col-span-2">
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(event) => setData('name', event.target.value)}
                                    invalid={Boolean(errors.name)}
                                    autoFocus
                                />
                            </Field>

                            <Field label="Contact person" htmlFor="contact_person" error={errors.contact_person}>
                                <Input
                                    id="contact_person"
                                    value={data.contact_person}
                                    onChange={(event) => setData('contact_person', event.target.value)}
                                />
                            </Field>

                            <Field label="Trade" htmlFor="trade" error={errors.trade} hint="Handloom weaving, coffee roasting…">
                                <Input
                                    id="trade"
                                    value={data.trade}
                                    onChange={(event) => setData('trade', event.target.value)}
                                />
                            </Field>

                            <Field
                                label="Phone"
                                htmlFor="phone"
                                error={errors.phone}
                                hint="Stored as typed; searched on the digits alone."
                            >
                                <Input
                                    id="phone"
                                    value={data.phone}
                                    onChange={(event) => setData('phone', event.target.value)}
                                    invalid={Boolean(errors.phone)}
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
                                    value={data.website}
                                    onChange={(event) => setData('website', event.target.value)}
                                    placeholder="https://…"
                                    invalid={Boolean(errors.website)}
                                />
                            </Field>

                            <Field label="Instagram" htmlFor="instagram" error={errors.instagram}>
                                <Input
                                    id="instagram"
                                    value={data.instagram}
                                    onChange={(event) => setData('instagram', event.target.value)}
                                    placeholder="@handle"
                                />
                            </Field>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Where they are</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
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

                            <Field label="Address" htmlFor="address" error={errors.address} className="sm:col-span-2">
                                <Textarea
                                    id="address"
                                    value={data.address}
                                    onChange={(event) => setData('address', event.target.value)}
                                    rows={2}
                                />
                            </Field>
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Account</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Field label="Status" htmlFor="status" error={errors.status} required>
                                <Select
                                    id="status"
                                    value={data.status}
                                    onChange={(event) => setData('status', event.target.value)}
                                >
                                    <option value="active">Active</option>
                                    <option value="dormant">Dormant</option>
                                    <option value="archived">Archived</option>
                                </Select>
                            </Field>

                            <Field label="How did they come in?" htmlFor="source" error={errors.source}>
                                <Select
                                    id="source"
                                    value={data.source}
                                    onChange={(event) => setData('source', event.target.value)}
                                >
                                    <option value="manual">Added by hand</option>
                                    <option value="referral">Referral</option>
                                    <option value="field">Met in the field</option>
                                    <option value="website_form">Website form</option>
                                    <option value="enquiry">Enquiry</option>
                                    <option value="import">Imported</option>
                                </Select>
                            </Field>

                            <Field label="Notes" htmlFor="notes" error={errors.notes}>
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(event) => setData('notes', event.target.value)}
                                    rows={5}
                                    placeholder="Anything worth knowing before the next call."
                                />
                            </Field>
                        </CardContent>
                    </Card>

                    <div className="flex gap-2">
                        <Button type="submit" loading={processing} className="flex-1">
                            {editing ? 'Save changes' : 'Add client'}
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={editing ? `/clients/${client.id}` : '/clients'}>Cancel</Link>
                        </Button>
                    </div>
                </div>
            </form>
        </div>
    );
}

ClientForm.layout = adminLayout;
