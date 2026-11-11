import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, FileText, Video } from 'lucide-react';
import { useEffect, type FormEvent } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { adminLayout } from '@/Layouts/AdminLayout';
import { cn } from '@/lib/utils';

interface KindOption {
    value: string;
    label: string;
    description: string;
    requires_article: boolean;
}

interface FormProps {
    interview: null;
    kinds: KindOption[];
    anchors: Array<{ id: number; name: string }>;
    clients: Array<{ id: number; name: string }>;
    projects: Array<{ id: number; code: string; title: string; client_id: number | null }>;
    preselected: { client_id: string | number | null; project_id: number | null };
}

export default function InterviewForm({ kinds, anchors, clients, projects, preselected }: FormProps) {
    const { data, setData, post, processing, errors } = useForm({
        kind: 'business_article',
        title: '',
        subject_name: '',
        subject_designation: '',
        subject_company: '',
        subject_phone: '',
        subject_email: '',
        client_id: preselected.client_id != null ? String(preselected.client_id) : '',
        project_id: preselected.project_id != null ? String(preselected.project_id) : '',
        anchor_id: '',
        scheduled_at: '',
        duration_minutes: '45',
        mode: 'google_meet',
        location: '',
        meeting_url: '',
        due_on: '',
        priority: 'normal',
        language: '',
        notes: '',
    });

    /*
     | The kind decides where it happens.
     |
     | A celebrity interview is in person; the two Meet kinds are not. Following
     | the kind rather than making somebody set both means the common case needs
     | no thought, and the field is still there to override.
     */
    useEffect(() => {
        setData('mode', data.kind === 'celebrity_social' ? 'in_person' : 'google_meet');
        // Intentionally keyed on `kind` alone: this should follow a kind change,
        // not fight the user every time they edit the mode itself.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.kind]);

    const selectedKind = kinds.find((kind) => kind.value === data.kind);

    /*
     | Only this client's projects.
     |
     | Offering all of them would let somebody file an interview against a
     | package sold to a different business, which the server rejects and which
     | would otherwise corrupt that project's delivery count.
     */
    const clientProjects = projects.filter(
        (project) => String(project.client_id ?? '') === data.client_id,
    );

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/interviews');
    }

    return (
        <div className="space-y-6">
            <Head title="Book an interview" />

            <PageHeader
                title="Book an interview"
                subtitle="Who is being interviewed, when, and which anchor is running it."
                actions={
                    <Button asChild variant="outline">
                        <Link href="/interviews">
                            <ArrowLeft />
                            Back
                        </Link>
                    </Button>
                }
            />

            <form onSubmit={submit} className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>What kind of interview?</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {kinds.map((kind) => (
                                <label
                                    key={kind.value}
                                    className={cn(
                                        'flex cursor-pointer items-start gap-3 rounded-control border p-3 transition-colors',
                                        data.kind === kind.value
                                            ? 'border-primary/40 bg-primary/5'
                                            : 'border-border hover:bg-muted/50',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="kind"
                                        value={kind.value}
                                        checked={data.kind === kind.value}
                                        onChange={(event) => setData('kind', event.target.value)}
                                        className="mt-1 size-4 border-input text-primary focus-visible:ring-2 focus-visible:ring-ring"
                                    />
                                    <span className="min-w-0">
                                        <span className="flex items-center gap-1.5 text-sm font-medium">
                                            {kind.requires_article ? (
                                                <FileText className="size-3.5 text-info" aria-hidden="true" />
                                            ) : (
                                                <Video className="size-3.5 text-muted-foreground" aria-hidden="true" />
                                            )}
                                            {kind.label}
                                        </span>
                                        <span className="mt-0.5 block text-xs text-muted-foreground">
                                            {kind.description}
                                        </span>
                                    </span>
                                </label>
                            ))}
                            {errors.kind && <p className="text-xs font-medium text-destructive">{errors.kind}</p>}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Who is being interviewed?</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <Field label="Interview title" htmlFor="title" error={errors.title} required className="sm:col-span-2">
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(event) => setData('title', event.target.value)}
                                    placeholder="How Lakshmi built a weaving business from one loom"
                                    invalid={Boolean(errors.title)}
                                    autoFocus
                                />
                            </Field>

                            <Field label="Their name" htmlFor="subject_name" error={errors.subject_name} required>
                                <Input
                                    id="subject_name"
                                    value={data.subject_name}
                                    onChange={(event) => setData('subject_name', event.target.value)}
                                    invalid={Boolean(errors.subject_name)}
                                />
                            </Field>

                            <Field label="Their role" htmlFor="subject_designation" error={errors.subject_designation}>
                                <Input
                                    id="subject_designation"
                                    value={data.subject_designation}
                                    onChange={(event) => setData('subject_designation', event.target.value)}
                                    placeholder="Founder"
                                />
                            </Field>

                            <Field label="Company" htmlFor="subject_company" error={errors.subject_company}>
                                <Input
                                    id="subject_company"
                                    value={data.subject_company}
                                    onChange={(event) => setData('subject_company', event.target.value)}
                                />
                            </Field>

                            <Field label="Phone" htmlFor="subject_phone" error={errors.subject_phone}>
                                <Input
                                    id="subject_phone"
                                    value={data.subject_phone}
                                    onChange={(event) => setData('subject_phone', event.target.value)}
                                />
                            </Field>

                            <Field label="Email" htmlFor="subject_email" error={errors.subject_email}>
                                <Input
                                    id="subject_email"
                                    type="email"
                                    value={data.subject_email}
                                    onChange={(event) => setData('subject_email', event.target.value)}
                                    invalid={Boolean(errors.subject_email)}
                                />
                            </Field>

                            {clients.length > 0 && (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="Client" htmlFor="client_id" error={errors.client_id}>
                                        <Select
                                            id="client_id"
                                            value={data.client_id}
                                            onChange={(event) => {
                                                /*
                                                 | Changing the client clears the project.
                                                 |
                                                 | The two are only valid together, and the
                                                 | server refuses a mismatch — so silently
                                                 | keeping the old project would send the
                                                 | form back with an error the person did
                                                 | not cause.
                                                 */
                                                setData((current) => ({
                                                    ...current,
                                                    client_id: event.target.value,
                                                    project_id: '',
                                                }));
                                            }}
                                        >
                                            <option value="">No client</option>
                                            {clients.map((client) => (
                                                <option key={client.id} value={client.id}>
                                                    {client.name}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>

                                    <Field
                                        label="Project"
                                        htmlFor="project_id"
                                        error={errors.project_id}
                                        hint={
                                            data.client_id
                                                ? 'Counts this booking towards a package.'
                                                : 'Choose a client first.'
                                        }
                                    >
                                        <Select
                                            id="project_id"
                                            value={data.project_id}
                                            onChange={(event) => setData('project_id', event.target.value)}
                                            disabled={!data.client_id || clientProjects.length === 0}
                                        >
                                            <option value="">
                                                {data.client_id && clientProjects.length === 0
                                                    ? 'No open projects for this client'
                                                    : 'No project'}
                                            </option>
                                            {clientProjects.map((project) => (
                                                <option key={project.id} value={project.id}>
                                                    {project.title} ({project.code})
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>When and where</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <Field label="Date and time" htmlFor="scheduled_at" error={errors.scheduled_at} required>
                                <Input
                                    id="scheduled_at"
                                    type="datetime-local"
                                    value={data.scheduled_at}
                                    onChange={(event) => setData('scheduled_at', event.target.value)}
                                    invalid={Boolean(errors.scheduled_at)}
                                />
                            </Field>

                            <Field label="Length" htmlFor="duration_minutes" error={errors.duration_minutes} hint="Minutes.">
                                <Input
                                    id="duration_minutes"
                                    type="number"
                                    min={5}
                                    max={600}
                                    value={data.duration_minutes}
                                    onChange={(event) => setData('duration_minutes', event.target.value)}
                                    invalid={Boolean(errors.duration_minutes)}
                                />
                            </Field>

                            <Field label="Where" htmlFor="mode" error={errors.mode} required>
                                <Select id="mode" value={data.mode} onChange={(event) => setData('mode', event.target.value)}>
                                    <option value="google_meet">Google Meet</option>
                                    <option value="in_person">In person</option>
                                </Select>
                            </Field>

                            {data.mode === 'google_meet' ? (
                                <Field
                                    label="Meet link"
                                    htmlFor="meeting_url"
                                    error={errors.meeting_url}
                                    required
                                    hint="So the anchor can just click through."
                                >
                                    <Input
                                        id="meeting_url"
                                        value={data.meeting_url}
                                        onChange={(event) => setData('meeting_url', event.target.value)}
                                        placeholder="https://meet.google.com/…"
                                        invalid={Boolean(errors.meeting_url)}
                                    />
                                </Field>
                            ) : (
                                <Field label="Address" htmlFor="location" error={errors.location} required>
                                    <Input
                                        id="location"
                                        value={data.location}
                                        onChange={(event) => setData('location', event.target.value)}
                                        invalid={Boolean(errors.location)}
                                    />
                                </Field>
                            )}

                            <Field label="Notes for the anchor" htmlFor="notes" error={errors.notes} className="sm:col-span-2">
                                <Textarea
                                    id="notes"
                                    value={data.notes}
                                    onChange={(event) => setData('notes', event.target.value)}
                                    rows={3}
                                    placeholder="Background, angles worth pushing, anything to avoid."
                                />
                            </Field>
                        </CardContent>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Assignment</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Field label="Anchor" htmlFor="anchor_id" error={errors.anchor_id} required>
                                <Select
                                    id="anchor_id"
                                    value={data.anchor_id}
                                    onChange={(event) => setData('anchor_id', event.target.value)}
                                    invalid={Boolean(errors.anchor_id)}
                                >
                                    <option value="">Choose an anchor…</option>
                                    {anchors.map((anchor) => (
                                        <option key={anchor.id} value={anchor.id}>
                                            {anchor.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>

                            <Field
                                label="Deadline"
                                htmlFor="due_on"
                                error={errors.due_on}
                                hint={
                                    selectedKind?.requires_article
                                        ? 'When the article should be published by.'
                                        : 'When the reels should be out by.'
                                }
                            >
                                <Input
                                    id="due_on"
                                    type="date"
                                    value={data.due_on}
                                    onChange={(event) => setData('due_on', event.target.value)}
                                    invalid={Boolean(errors.due_on)}
                                />
                            </Field>

                            <Field label="Priority" htmlFor="priority" error={errors.priority}>
                                <Select
                                    id="priority"
                                    value={data.priority}
                                    onChange={(event) => setData('priority', event.target.value)}
                                >
                                    <option value="low">Low</option>
                                    <option value="normal">Normal</option>
                                    <option value="high">High</option>
                                </Select>
                            </Field>

                            <Field label="Language" htmlFor="language" error={errors.language}>
                                <Input
                                    id="language"
                                    value={data.language}
                                    onChange={(event) => setData('language', event.target.value)}
                                    placeholder="English"
                                />
                            </Field>
                        </CardContent>
                    </Card>

                    {selectedKind && (
                        <Card className="border-info/30 bg-info/5">
                            <CardContent className="space-y-2 py-4 text-sm">
                                <p className="font-medium">What happens next</p>
                                <p className="text-muted-foreground">
                                    {selectedKind.requires_article
                                        ? 'The anchor conducts it, transcribes the recording in Turboscript, and hands over the exported PDF with the recording link. The content team writes the article from those two things — it cannot be handed over without them.'
                                        : 'The anchor conducts it and the creative team produce the reels and posters. Nothing goes to the content team, and no website article is created.'}
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    <div className="flex gap-2">
                        <Button type="submit" loading={processing} className="flex-1">
                            Book interview
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/interviews">Cancel</Link>
                        </Button>
                    </div>
                </div>
            </form>
        </div>
    );
}

InterviewForm.layout = adminLayout;
