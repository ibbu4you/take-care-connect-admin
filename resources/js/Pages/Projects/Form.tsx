import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Info } from 'lucide-react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { adminLayout } from '@/Layouts/AdminLayout';

interface Option {
    value: string;
    label: string;
    description: string;
}

interface FormProps {
    project: {
        id: number;
        code: string;
        client_id: number;
        title: string;
        kind: string;
        status: string;
        starts_on: string | null;
        due_on: string | null;
        value_amount: number | null;
        notes: string | null;
        owner_id: number | null;
    } | null;
    preselected_client: string | null;
    clients: Array<{ id: number; name: string }>;
    owners: Array<{ id: number; name: string }>;
    default_owner_id: number;
    kinds: Option[];
    statuses: Option[];
}

export default function ProjectForm({
    project,
    preselected_client,
    clients,
    owners,
    default_owner_id,
    kinds,
    statuses,
}: FormProps) {
    const editing = project !== null;

    const { data, setData, post, patch, processing, errors } = useForm({
        client_id: String(project?.client_id ?? preselected_client ?? ''),
        title: project?.title ?? '',
        kind: project?.kind ?? kinds[0]?.value ?? 'interview_feature',
        status: project?.status ?? 'open',
        starts_on: project?.starts_on ?? '',
        due_on: project?.due_on ?? '',
        value_amount: project?.value_amount != null ? String(project.value_amount) : '',
        notes: project?.notes ?? '',
        owner_id: String(project?.owner_id ?? default_owner_id),
    });

    const chosenKind = kinds.find((kind) => kind.value === data.kind);

    function submit(event: React.FormEvent) {
        event.preventDefault();

        if (editing) {
            patch(`/projects/${project.id}`);
        } else {
            post('/projects');
        }
    }

    return (
        <div className="space-y-6">
            <Head title={editing ? `Edit ${project.code}` : 'New project'} />

            <PageHeader
                title={editing ? project.title : 'New project'}
                subtitle={
                    editing
                        ? `${project.code} — change what was sold, or where it stands.`
                        : 'The package of work a client has bought. Interviews get booked against it.'
                }
                actions={
                    <Button asChild variant="outline">
                        <Link href={editing ? `/projects/${project.id}` : '/projects'}>
                            <ArrowLeft />
                            {editing ? 'Back to project' : 'Back to projects'}
                        </Link>
                    </Button>
                }
            />

            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>What was sold</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-4">
                            <Field label="Client" htmlFor="client_id" error={errors.client_id} required>
                                <Select
                                    id="client_id"
                                    value={data.client_id}
                                    onChange={(event) => setData('client_id', event.target.value)}
                                    invalid={Boolean(errors.client_id)}
                                >
                                    <option value="">Choose a client…</option>
                                    {clients.map((client) => (
                                        <option key={client.id} value={client.id}>
                                            {client.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>

                            <Field
                                label="Title"
                                htmlFor="title"
                                error={errors.title}
                                required
                                hint="What the team will call it — “Diwali campaign”, “Six founder interviews”."
                            >
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(event) => setData('title', event.target.value)}
                                    invalid={Boolean(errors.title)}
                                    maxLength={255}
                                />
                            </Field>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Kind" htmlFor="kind" error={errors.kind} required>
                                    <Select
                                        id="kind"
                                        value={data.kind}
                                        onChange={(event) => setData('kind', event.target.value)}
                                    >
                                        {kinds.map((kind) => (
                                            <option key={kind.value} value={kind.value}>
                                                {kind.label}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>

                                <Field
                                    label="Value"
                                    htmlFor="value_amount"
                                    error={errors.value_amount}
                                    hint="What the client is paying, in rupees."
                                >
                                    <Input
                                        id="value_amount"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={data.value_amount}
                                        onChange={(event) => setData('value_amount', event.target.value)}
                                        invalid={Boolean(errors.value_amount)}
                                        placeholder="0"
                                    />
                                </Field>
                            </div>

                            {chosenKind && (
                                <p className="flex items-start gap-2 text-xs text-muted-foreground">
                                    <Info className="mt-0.5 size-3.5 shrink-0" />
                                    {chosenKind.description}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Dates and notes</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Starts on" htmlFor="starts_on" error={errors.starts_on}>
                                    <Input
                                        id="starts_on"
                                        type="date"
                                        value={data.starts_on}
                                        onChange={(event) => setData('starts_on', event.target.value)}
                                    />
                                </Field>

                                <Field
                                    label="Due on"
                                    htmlFor="due_on"
                                    error={errors.due_on}
                                    hint="What the client was promised."
                                >
                                    <Input
                                        id="due_on"
                                        type="date"
                                        // Cannot precede the start date, matching the
                                        // server rule — so the picker refuses it before
                                        // the form round-trips to be told the same thing.
                                        min={data.starts_on || undefined}
                                        value={data.due_on}
                                        onChange={(event) => setData('due_on', event.target.value)}
                                        invalid={Boolean(errors.due_on)}
                                    />
                                </Field>
                            </div>

                            <Field
                                label="Notes"
                                htmlFor="notes"
                                error={errors.notes}
                                hint="What was agreed that is not obvious from the title."
                            >
                                <Textarea
                                    id="notes"
                                    rows={5}
                                    value={data.notes}
                                    onChange={(event) => setData('notes', event.target.value)}
                                    maxLength={5000}
                                />
                            </Field>
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Status</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-3">
                            {statuses.map((status) => (
                                <label
                                    key={status.value}
                                    className="flex cursor-pointer items-start gap-3 rounded-control border border-border p-3 transition-colors hover:bg-muted/50 has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                                >
                                    <input
                                        type="radio"
                                        name="status"
                                        value={status.value}
                                        checked={data.status === status.value}
                                        onChange={(event) => setData('status', event.target.value)}
                                        className="mt-0.5 size-4 accent-primary"
                                    />
                                    <span className="min-w-0">
                                        <span className="block text-sm font-medium">{status.label}</span>
                                        <span className="block text-xs text-muted-foreground">
                                            {status.description}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Who runs it</CardTitle>
                        </CardHeader>

                        <CardContent>
                            <Field
                                label="Owner"
                                htmlFor="owner_id"
                                error={errors.owner_id}
                                hint="They answer for delivery. You keep sight of it either way — a project stays on the list of whoever set it up."
                            >
                                <Select
                                    id="owner_id"
                                    value={data.owner_id}
                                    onChange={(event) => setData('owner_id', event.target.value)}
                                >
                                    {owners.map((owner) => (
                                        <option key={owner.id} value={owner.id}>
                                            {owner.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </CardContent>
                    </Card>

                    <div className="flex gap-2">
                        <Button type="submit" loading={processing} className="flex-1">
                            {editing ? 'Save changes' : 'Create project'}
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={editing ? `/projects/${project.id}` : '/projects'}>Cancel</Link>
                        </Button>
                    </div>
                </div>
            </form>
        </div>
    );
}

ProjectForm.layout = adminLayout;
