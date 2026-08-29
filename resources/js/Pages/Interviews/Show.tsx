import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Building2,
    CalendarClock,
    CheckCircle2,
    ExternalLink,
    FileText,
    Link2,
    Mail,
    MapPin,
    Paperclip,
    Phone,
    Plus,
    Trash2,
    Video,
    X,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { CommissionDialog, type KindOption } from '@/Components/work/CommissionDialog';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge, StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { ConfirmDialog, Dialog } from '@/Components/ui/dialog';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { Avatar, EmptyState, SectionDivider } from '@/Components/ui/misc';
import { adminLayout } from '@/Layouts/AdminLayout';
import { bytes, dateTime, relative, sentenceList } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

interface Asset {
    id: number;
    kind: string;
    label: string | null;
    is_link: boolean;
    url: string;
    original_filename: string | null;
    size_bytes: number | null;
    uploader: string | null;
    created_at: string | null;
}

interface HistoryEntry {
    id: number;
    from: string | null;
    to: string;
    note: string | null;
    by: string | null;
    at: string | null;
}

interface ShowProps {
    interview: {
        id: number;
        code: string;
        kind: string;
        kind_label: string;
        requires_article: boolean;
        title: string;
        subject_name: string;
        subject_designation: string | null;
        subject_company: string | null;
        subject_phone: string | null;
        subject_email: string | null;
        client: { id: number; name: string } | null;
        project: { id: number; code: string; title: string } | null;
        anchor: { id: number; name: string } | null;
        scheduler: { id: number; name: string } | null;
        content_owner: { id: number; name: string } | null;
        creative_owner: { id: number; name: string } | null;
        stage: string;
        stage_label: string;
        mode: string;
        location: string | null;
        meeting_url: string | null;
        recording_url: string | null;
        scheduled_at: string | null;
        duration_minutes: number | null;
        conducted_at: string | null;
        handed_over_at: string | null;
        due_on: string | null;
        priority: string;
        qa_summary: string | null;
        language: string | null;
        consent_to_publish: boolean;
        notes: string | null;
        cancelled_reason: string | null;
        assets: Asset[];
        history: HistoryEntry[];
        missing_for_handover: string[];
    };
    writers: Array<{ id: number; name: string }>;
    deliverableKinds: KindOption[];
    creatives: Array<{ id: number; name: string }>;
    deliverables: Array<{
        id: number;
        code: string;
        kind_label: string;
        slot_number: number;
        stage: string;
        assignee: { id: number; name: string } | null;
        social_url: string | null;
    }>;
    can: {
        commission: boolean;
        update: boolean;
        conduct: boolean;
        handover: boolean;
        reassign: boolean;
        cancel: boolean;
        upload: boolean;
        deleteAsset: boolean;
    };
}

export default function InterviewShow({
    interview,
    writers,
    deliverableKinds,
    creatives,
    deliverables,
    can,
}: ShowProps) {
    const [attaching, setAttaching] = useState(false);
    const [handingOver, setHandingOver] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [removeAsset, setRemoveAsset] = useState<Asset | null>(null);
    const [commissioning, setCommissioning] = useState(false);

    const ready = interview.missing_for_handover.length === 0;

    return (
        <div className="space-y-6">
            <Head title={interview.title} />

            <PageHeader
                title={interview.title}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="tabular font-medium text-foreground">{interview.code}</span>
                        <StageBadge family="interview" stage={interview.stage} dot />
                        <Badge tone={interview.requires_article ? 'info' : 'default'}>
                            {interview.requires_article ? <FileText className="size-3" /> : <Video className="size-3" />}
                            {interview.kind_label}
                        </Badge>
                    </span>
                }
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href="/interviews">
                                <ArrowLeft />
                                All interviews
                            </Link>
                        </Button>

                        {can.handover && (
                            <Button onClick={() => setHandingOver(true)} disabled={!ready} title={ready ? undefined : 'Complete the handover first.'}>
                                <ArrowRight />
                                Hand to content
                            </Button>
                        )}
                    </>
                }
            />

            {/*
              | The single most useful thing on this page.
              |
              | An anchor who conducted the interview yesterday needs to know
              | what is still outstanding before it can move on — and they need
              | to know it here, not by pressing a button and reading an error.
            */}
            {interview.requires_article && interview.stage === 'conducted' && (
                <HandoverChecklist missing={interview.missing_for_handover} />
            )}

            {interview.cancelled_reason && (
                <Card className="border-destructive/30 bg-destructive/5">
                    <CardContent className="flex items-start gap-2 py-3 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden="true" />
                        <p>
                            <span className="font-medium">{interview.stage_label}.</span>{' '}
                            {interview.cancelled_reason}
                        </p>
                    </CardContent>
                </Card>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <ConductCard interview={interview} canEdit={can.conduct} />

                    <Card>
                        <CardHeader>
                            <div>
                                <CardTitle>Attachments</CardTitle>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    The transcript, the recording link, and any photos.
                                </p>
                            </div>
                            {can.upload && (
                                <Button size="sm" variant="outline" onClick={() => setAttaching(true)}>
                                    <Plus />
                                    Attach
                                </Button>
                            )}
                        </CardHeader>

                        {interview.assets.length === 0 ? (
                            <EmptyState
                                icon={Paperclip}
                                title="Nothing attached yet"
                                description={
                                    interview.requires_article
                                        ? 'A business interview needs the transcript and the recording link before it can be handed over.'
                                        : 'Attach the recording or any photos from the shoot.'
                                }
                            />
                        ) : (
                            <ul className="divide-y divide-border">
                                {interview.assets.map((asset) => (
                                    <li key={asset.id} className="flex items-center gap-3 px-5 py-3">
                                        <span
                                            className="inline-flex size-9 shrink-0 items-center justify-center rounded-control bg-muted text-muted-foreground"
                                            aria-hidden="true"
                                        >
                                            {asset.is_link ? <Link2 className="size-4" /> : <FileText className="size-4" />}
                                        </span>

                                        <div className="min-w-0 flex-1">
                                            <a
                                                href={asset.url}
                                                target={asset.is_link ? '_blank' : undefined}
                                                rel={asset.is_link ? 'noreferrer' : undefined}
                                                className="flex items-center gap-1 truncate text-sm font-medium hover:text-primary"
                                            >
                                                {asset.label || asset.original_filename || asset.url}
                                                {asset.is_link && <ExternalLink className="size-3 shrink-0" />}
                                            </a>
                                            <p className="truncate text-xs text-muted-foreground">
                                                <span className="capitalize">{asset.kind}</span>
                                                {asset.size_bytes ? ` · ${bytes(asset.size_bytes)}` : ''}
                                                {asset.uploader ? ` · ${asset.uploader}` : ''}
                                                {asset.created_at ? ` · ${relative(asset.created_at)}` : ''}
                                            </p>
                                        </div>

                                        {can.deleteAsset && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="text-destructive hover:bg-destructive/10"
                                                aria-label="Remove attachment"
                                                onClick={() => setRemoveAsset(asset)}
                                            >
                                                <Trash2 />
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <CreativeCard
                        deliverables={deliverables}
                        canCommission={can.commission}
                        onCommission={() => setCommissioning(true)}
                        requiresArticle={interview.requires_article}
                    />

                    <HistoryCard history={interview.history} />
                </div>

                <div className="space-y-6">
                    <DetailsCard interview={interview} />

                    <Card>
                        <CardHeader>
                            <CardTitle>People</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <PersonRow role="Anchor" person={interview.anchor} />
                            <PersonRow role="Booked by" person={interview.scheduler} />
                            {interview.requires_article && (
                                <PersonRow role="Content" person={interview.content_owner} />
                            )}
                            <PersonRow role="Creative" person={interview.creative_owner} />

                            {/*
                              | Where the writer actually gets chosen.
                              |
                              | The picker lives inside the handover dialog, and
                              | that button only exists once the interview is
                              | conducted — so an anchor looking at a scheduled
                              | one sees "Not assigned" with nothing anywhere on
                              | the page to explain how it stops saying that.
                              | This is the row they read when they go looking.
                            */}
                            {interview.requires_article && !interview.content_owner && (
                                <p className="border-t border-border pt-3 text-xs text-muted-foreground">
                                    {interview.stage === 'conducted'
                                        ? 'Choose a writer with “Hand to content”, above.'
                                        : 'You choose the writer when you hand over. Mark this conducted first, then attach the transcript and the recording.'}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {can.cancel && (
                        <Button variant="outline" className="w-full" onClick={() => setCancelling(true)}>
                            <X />
                            Cancel or mark no-show
                        </Button>
                    )}
                </div>
            </div>

            <AttachDialog
                open={attaching}
                onClose={() => setAttaching(false)}
                interviewId={interview.id}
                requiresArticle={interview.requires_article}
            />

            <HandoverDialog
                open={handingOver}
                onClose={() => setHandingOver(false)}
                interviewId={interview.id}
                writers={writers}
            />

            <CancelDialog open={cancelling} onClose={() => setCancelling(false)} interviewId={interview.id} />

            <CommissionDialog
                open={commissioning}
                onClose={() => setCommissioning(false)}
                parentKind="interview"
                parentId={interview.id}
                kinds={deliverableKinds}
                creatives={creatives}
                defaultDueOn={interview.due_on}
            />

            <ConfirmDialog
                open={removeAsset !== null}
                onClose={() => setRemoveAsset(null)}
                onConfirm={() => {
                    if (removeAsset) {
                        router.delete(`/interview-assets/${removeAsset.id}`, {
                            preserveScroll: true,
                            onFinish: () => setRemoveAsset(null),
                        });
                    }
                }}
                title="Remove this attachment?"
                description="The file is deleted permanently. If this was the recording link, the interview will no longer be ready to hand over."
                confirmLabel="Remove"
            />
        </div>
    );
}

function HandoverChecklist({ missing }: { missing: string[] }) {
    const ready = missing.length === 0;

    return (
        <Card className={cn('border', ready ? 'border-success/30 bg-success/5' : 'border-warning/30 bg-warning/5')}>
            <CardContent className="flex items-start gap-3 py-4">
                {ready ? (
                    <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-success" aria-hidden="true" />
                ) : (
                    <AlertTriangle className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden="true" />
                )}

                <div className="min-w-0">
                    <p className="text-sm font-semibold">
                        {ready ? 'Ready to hand over to the content team.' : 'Not ready to hand over yet.'}
                    </p>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {ready
                            ? 'The transcript and the recording are both here.'
                            : `A business interview needs both before a writer can start. Still missing ${sentenceList(missing)}.`}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * What was captured after the interview, and the form that records it.
 *
 * The same card in two states: read-only once somebody else owns the interview,
 * editable while the anchor still has it. Keeping them together rather than
 * splitting into a separate edit page means the anchor types the answers with
 * the questions they already asked in front of them.
 */
function ConductCard({ interview, canEdit }: { interview: ShowProps['interview']; canEdit: boolean }) {
    const [editing, setEditing] = useState(false);

    if (editing) {
        return <ConductForm interview={interview} onDone={() => setEditing(false)} />;
    }

    return (
        <Card>
            <CardHeader>
                <div>
                    <CardTitle>After the interview</CardTitle>
                    {interview.conducted_at && (
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            Conducted {dateTime(interview.conducted_at)}
                        </p>
                    )}
                </div>
                {canEdit && (
                    <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                        {interview.conducted_at ? 'Edit' : 'Mark conducted'}
                    </Button>
                )}
            </CardHeader>

            {interview.conducted_at ? (
                <CardContent className="space-y-4">
                    {interview.qa_summary ? (
                        <div>
                            <p className="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                For the writer
                            </p>
                            <p className="whitespace-pre-wrap rounded-control bg-muted/50 p-3 text-sm">
                                {interview.qa_summary}
                            </p>
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No brief written. The transcript and the recording are what the writer works from.
                        </p>
                    )}

                    <div className="grid gap-3 text-sm sm:grid-cols-2">
                        {interview.language && (
                            <p>
                                <span className="text-muted-foreground">Language · </span>
                                {interview.language}
                            </p>
                        )}
                        <p>
                            <span className="text-muted-foreground">Consent to publish · </span>
                            {interview.consent_to_publish ? 'Given' : 'Not recorded'}
                        </p>
                    </div>
                </CardContent>
            ) : (
                <EmptyState
                    icon={FileText}
                    title="Not conducted yet"
                    description="Mark it conducted once it has happened. Run the recording through Turboscript, export the PDF, and attach it below."
                />
            )}
        </Card>
    );
}

/**
 * What is captured after the interview.
 *
 * ── No questions and answers ───────────────────────────────────────────────
 * The anchor used to type the Q&A here, pair by pair. They run the recording
 * through Turboscript instead and upload the exported PDF, which is faster than
 * retyping an interview and a better record than anybody typing from memory an
 * hour later.
 *
 * What is left is the context a transcript cannot carry: where the recording
 * lives, what language it was in, whether the subject agreed to publication,
 * and a short brief for whoever writes it up.
 */
function ConductForm({ interview, onDone }: { interview: ShowProps['interview']; onDone: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        qa_summary: interview.qa_summary ?? '',
        recording_url: interview.recording_url ?? '',
        language: interview.language ?? '',
        consent_to_publish: interview.consent_to_publish,
        conducted_at: '',
        notes: interview.notes ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        post(`/interviews/${interview.id}/conduct`, {
            preserveScroll: true,
            onSuccess: onDone,
        });
    }

    return (
        <Card>
            <CardHeader>
                <div>
                    <CardTitle>After the interview</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        The transcript is a file — export it from Turboscript and attach it below. This is the
                        context around it.
                    </p>
                </div>
            </CardHeader>

            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Recording link"
                            htmlFor="recording_url"
                            error={errors.recording_url}
                            hint="The Drive or Meet URL. The writer checks quotes against it."
                            className="sm:col-span-2"
                        >
                            <Input
                                id="recording_url"
                                type="url"
                                value={data.recording_url}
                                onChange={(event) => setData('recording_url', event.target.value)}
                                placeholder="https://drive.google.com/…"
                                invalid={Boolean(errors.recording_url)}
                            />
                        </Field>

                        <Field label="Language" htmlFor="language" error={errors.language}>
                            <Input
                                id="language"
                                value={data.language}
                                onChange={(event) => setData('language', event.target.value)}
                                placeholder="Tamil"
                            />
                        </Field>

                        <Field
                            label="Conducted at"
                            htmlFor="conducted_at"
                            error={errors.conducted_at}
                            hint="Leave blank for now."
                        >
                            <Input
                                id="conducted_at"
                                type="datetime-local"
                                value={data.conducted_at}
                                onChange={(event) => setData('conducted_at', event.target.value)}
                            />
                        </Field>

                        <Field
                            label="Brief for the writer"
                            htmlFor="qa_summary"
                            error={errors.qa_summary}
                            className="sm:col-span-2"
                            hint="What the piece is about, and anything the transcript will not make obvious."
                        >
                            <Textarea
                                id="qa_summary"
                                rows={4}
                                value={data.qa_summary}
                                onChange={(event) => setData('qa_summary', event.target.value)}
                                placeholder="A few lines the writer can start from."
                            />
                        </Field>
                    </div>

                    <label className="flex cursor-pointer items-start gap-2.5">
                        <Checkbox
                            className="mt-0.5"
                            checked={data.consent_to_publish}
                            onChange={(event) => setData('consent_to_publish', event.target.checked)}
                        />
                        <span className="text-sm">
                            The subject agreed to this being published.
                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                Worth recording while the conversation is fresh.
                            </span>
                        </span>
                    </label>

                    <div className="flex gap-2">
                        <Button type="submit" loading={processing}>
                            Save
                        </Button>
                        <Button type="button" variant="ghost" onClick={onDone}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}


function DetailsCard({ interview }: { interview: ShowProps['interview'] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Details</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                <div>
                    <p className="font-medium">{interview.subject_name}</p>
                    {interview.subject_designation && (
                        <p className="text-xs text-muted-foreground">{interview.subject_designation}</p>
                    )}
                </div>

                {interview.subject_company && (
                    <Detail icon={Building2}>{interview.subject_company}</Detail>
                )}
                {interview.subject_phone && <Detail icon={Phone}>{interview.subject_phone}</Detail>}
                {interview.subject_email && <Detail icon={Mail}>{interview.subject_email}</Detail>}

                <SectionDivider label="When" />

                {interview.scheduled_at && (
                    <Detail icon={CalendarClock}>
                        {dateTime(interview.scheduled_at)}
                        {interview.duration_minutes ? ` · ${interview.duration_minutes} min` : ''}
                    </Detail>
                )}

                {interview.mode === 'google_meet' && interview.meeting_url ? (
                    <Detail icon={Video}>
                        <a
                            href={interview.meeting_url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-primary hover:underline"
                        >
                            Join Google Meet
                            <ExternalLink className="size-3" />
                        </a>
                    </Detail>
                ) : (
                    interview.location && <Detail icon={MapPin}>{interview.location}</Detail>
                )}

                {interview.client && (
                    <>
                        <SectionDivider label="Client" />
                        <Detail icon={Building2}>{interview.client.name}</Detail>
                    </>
                )}

                {interview.notes && (
                    <>
                        <SectionDivider label="Notes" />
                        <p className="whitespace-pre-wrap text-muted-foreground">{interview.notes}</p>
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function Detail({ icon: Icon, children }: { icon: typeof Phone; children: React.ReactNode }) {
    return (
        <div className="flex items-start gap-2">
            <Icon className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span className="min-w-0 break-words">{children}</span>
        </div>
    );
}

function PersonRow({ role, person }: { role: string; person: { id: number; name: string } | null }) {
    return (
        <div className="flex items-center gap-2">
            <span className="w-20 shrink-0 text-xs uppercase tracking-wide text-muted-foreground">{role}</span>
            {person ? (
                <span className="flex min-w-0 items-center gap-2">
                    <Avatar name={person.name} size="sm" />
                    <span className="truncate text-sm">{person.name}</span>
                </span>
            ) : (
                <span className="text-sm text-muted-foreground">Not assigned</span>
            )}
        </div>
    );
}

/**
 * The social work cut from this interview.
 *
 * Shown on every interview, not only the social ones: a business interview
 * produces an article AND the reels that promote it. For the two social kinds
 * this panel is the entire downstream — there is no article, so if the work is
 * not commissioned here it is not commissioned anywhere.
 */
function CreativeCard({
    deliverables,
    canCommission,
    onCommission,
    requiresArticle,
}: {
    deliverables: ShowProps['deliverables'];
    canCommission: boolean;
    onCommission: () => void;
    requiresArticle: boolean;
}) {
    return (
        <Card>
            <CardHeader>
                <div>
                    <CardTitle>Creative work</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {requiresArticle
                            ? 'Posters and reels promoting this interview.'
                            : 'This interview has no article — the reels and posters are its whole output.'}
                    </p>
                </div>
                {canCommission && (
                    <Button size="sm" variant="outline" onClick={onCommission}>
                        <Plus />
                        Commission
                    </Button>
                )}
            </CardHeader>

            {deliverables.length === 0 ? (
                <EmptyState
                    icon={Video}
                    title="Nothing commissioned yet"
                    description="Order the posters, reels or edits the creative team should produce."
                />
            ) : (
                <ul className="divide-y divide-border">
                    {deliverables.map((item) => (
                        <li key={item.id}>
                            <Link
                                href={`/deliverables/${item.id}`}
                                className="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/40"
                            >
                                <StageBadge family="deliverable" stage={item.stage} dot />

                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {item.kind_label}
                                        {item.slot_number > 1 ? ` ${item.slot_number}` : ''}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground tabular">{item.code}</p>
                                </div>

                                {item.social_url && (
                                    <ExternalLink className="size-3.5 shrink-0 text-success" aria-label="Live" />
                                )}

                                {item.assignee && <Avatar name={item.assignee.name} size="sm" />}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function HistoryCard({ history }: { history: HistoryEntry[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>History</CardTitle>
            </CardHeader>

            {history.length === 0 ? (
                <EmptyState icon={CalendarClock} title="Nothing recorded yet" />
            ) : (
                <CardContent>
                    <ol className="space-y-3">
                        {history.map((entry) => (
                            <li key={entry.id} className="flex items-start gap-3">
                                <StageBadge family="interview" stage={entry.to} dot />
                                <div className="min-w-0 flex-1 text-sm">
                                    <p className="text-muted-foreground">
                                        {entry.from ? (
                                            <>
                                                from <span className="text-foreground">{entry.from.replace(/_/g, ' ')}</span>
                                            </>
                                        ) : (
                                            'created'
                                        )}
                                        {entry.by ? ` · ${entry.by}` : ''}
                                    </p>
                                    {entry.note && <p className="text-xs text-muted-foreground">{entry.note}</p>}
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

function AttachDialog({
    open,
    onClose,
    interviewId,
    requiresArticle,
}: {
    open: boolean;
    onClose: () => void;
    interviewId: number;
    requiresArticle: boolean;
}) {
    const [mode, setMode] = useState<'file' | 'link'>('file');
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        kind: requiresArticle ? 'document' : 'recording',
        label: '',
        file: null as File | null,
        url: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        post(`/interviews/${interviewId}/assets`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    }

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Attach to this interview"
            description="Upload the Turboscript export, or paste a link to the recording."
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} loading={processing}>
                        Attach
                    </Button>
                </>
            }
        >
            <form onSubmit={submit} className="space-y-4">
                <Field label="What is it?" htmlFor="kind" error={errors.kind} required>
                    <Select id="kind" value={data.kind} onChange={(event) => setData('kind', event.target.value)}>
                        <option value="document">Write-up document</option>
                        <option value="recording">Recording</option>
                        <option value="transcript">Transcript</option>
                        <option value="photo">Photo</option>
                        <option value="link">Other link</option>
                    </Select>
                </Field>

                {/*
                  | File or link, chosen explicitly.
                  |
                  | A Meet recording is a Drive link — the file is gigabytes and
                  | nobody wants a second copy — so linking is a first-class
                  | option here, not a workaround.
                */}
                <div className="flex gap-1 rounded-control bg-muted p-1">
                    {(['file', 'link'] as const).map((option) => (
                        <button
                            key={option}
                            type="button"
                            onClick={() => {
                                setMode(option);
                                clearErrors();
                                setData(option === 'file' ? 'url' : 'file', option === 'file' ? '' : null);
                            }}
                            className={cn(
                                'flex-1 rounded-[6px] px-3 py-1.5 text-sm font-medium transition-colors',
                                mode === option ? 'bg-card shadow-sm' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {option === 'file' ? 'Upload a file' : 'Paste a link'}
                        </button>
                    ))}
                </div>

                {mode === 'file' ? (
                    <Field label="File" htmlFor="file" error={errors.file} hint="Up to 50 MB. Larger files belong in Drive.">
                        <Input
                            id="file"
                            type="file"
                            className="h-auto py-1.5 file:mr-3 file:rounded-control file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-xs file:font-medium"
                            onChange={(event) => setData('file', event.target.files?.[0] ?? null)}
                        />
                    </Field>
                ) : (
                    <Field label="Link" htmlFor="url" error={errors.url}>
                        <Input
                            id="url"
                            value={data.url}
                            onChange={(event) => setData('url', event.target.value)}
                            placeholder="https://drive.google.com/…"
                            invalid={Boolean(errors.url)}
                        />
                    </Field>
                )}

                <Field label="Label" htmlFor="label" error={errors.label} hint="Optional — helps when there are several.">
                    <Input
                        id="label"
                        value={data.label}
                        onChange={(event) => setData('label', event.target.value)}
                        placeholder="Turboscript transcript"
                    />
                </Field>
            </form>
        </Dialog>
    );
}

function HandoverDialog({
    open,
    onClose,
    interviewId,
    writers,
}: {
    open: boolean;
    onClose: () => void;
    interviewId: number;
    writers: Array<{ id: number; name: string }>;
}) {
    const { data, setData, post, processing, errors } = useForm({
        content_owner_id: '',
        note: '',
    });

    /*
     | The refusal reason is not a field error.
     |
     | HandoverService rejects an incomplete handover under a `handover` key
     | that matches nothing in this form, so it lands on the page's shared
     | errors instead of the form's own. Reading it from there is what surfaces
     | "still missing the recording link" to the person who pressed the button.
     */
    const { errors: pageErrors } = usePage<SharedProps>().props;
    const refusal = pageErrors.handover;

    function submit() {
        post(`/interviews/${interviewId}/handover`, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    }

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Hand over to the content team"
            description="They will get the transcript and the recording, and will write the article from them."
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} loading={processing}>
                        Hand over
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                {refusal && (
                    <p className="rounded-control bg-destructive/10 p-3 text-sm text-destructive">{refusal}</p>
                )}

                <Field
                    label="Writer"
                    htmlFor="content_owner_id"
                    error={errors.content_owner_id}
                    hint="Leave unassigned to put it in the shared content queue."
                >
                    <Select
                        id="content_owner_id"
                        value={data.content_owner_id}
                        onChange={(event) => setData('content_owner_id', event.target.value)}
                    >
                        <option value="">Unassigned — anyone in content</option>
                        {writers.map((writer) => (
                            <option key={writer.id} value={writer.id}>
                                {writer.name}
                            </option>
                        ))}
                    </Select>
                </Field>

                <Field label="Anything they should know?" htmlFor="note" error={errors.note}>
                    <Textarea
                        id="note"
                        value={data.note}
                        onChange={(event) => setData('note', event.target.value)}
                        rows={3}
                        placeholder="The best quotes are around the 12-minute mark."
                    />
                </Field>
            </div>
        </Dialog>
    );
}

function CancelDialog({ open, onClose, interviewId }: { open: boolean; onClose: () => void; interviewId: number }) {
    const { data, setData, post, processing, errors } = useForm({
        stage: 'no_show',
        reason: '',
    });

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Cancel this interview"
            size="sm"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Keep it
                    </Button>
                    <Button
                        variant="destructive"
                        loading={processing}
                        onClick={() =>
                            post(`/interviews/${interviewId}/cancel`, {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        Confirm
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="What happened?" htmlFor="cancel_stage" error={errors.stage} required>
                    <Select id="cancel_stage" value={data.stage} onChange={(event) => setData('stage', event.target.value)}>
                        {/* A no-show can go back in the diary; a cancellation cannot. */}
                        <option value="no_show">They did not turn up — can be rescheduled</option>
                        <option value="cancelled">Cancelled — this is final</option>
                    </Select>
                </Field>

                <Field label="Reason" htmlFor="reason" error={errors.reason} required>
                    <Textarea
                        id="reason"
                        value={data.reason}
                        onChange={(event) => setData('reason', event.target.value)}
                        rows={3}
                        invalid={Boolean(errors.reason)}
                    />
                </Field>
            </div>
        </Dialog>
    );
}

InterviewShow.layout = adminLayout;
