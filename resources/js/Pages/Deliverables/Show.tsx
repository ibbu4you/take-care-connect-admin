import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ExternalLink,
    FileText,
    Film,
    Globe,
    Image as ImageIcon,
    Link2,
    Mic,
    Plus,
    Save,
    Send,
    Trash2,
    Undo2,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge, StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { ConfirmDialog, Dialog } from '@/Components/ui/dialog';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { Avatar, EmptyState, SectionDivider } from '@/Components/ui/misc';
import { adminLayout } from '@/Layouts/AdminLayout';
import { bytes, dateTime, duration as formatDuration, relative, sentenceList } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

interface DeliverableFile {
    id: number;
    version: number;
    is_link: boolean;
    url: string;
    original_filename: string | null;
    size_bytes: number | null;
    duration_seconds: number | null;
    note: string | null;
    uploader: string | null;
    created_at: string | null;
}

interface SocialPostRow {
    id: number;
    platform: string;
    platform_label: string;
    url: string;
    posted_at: string | null;
    posted_by: string | null;
}

interface ShowProps {
    deliverable: {
        id: number;
        code: string;
        kind: string;
        kind_label: string;
        is_video: boolean;
        title: string | null;
        slot_number: number;
        stage: string;
        stage_label: string;
        assignee: { id: number; name: string } | null;
        assigner: { id: number; name: string } | null;
        approver: { id: number; name: string } | null;
        due_on: string | null;
        priority: string;
        brief: string | null;
        caption: string | null;
        hashtags: string | null;
        platforms: string[];
        parent_label: string;
        parent_code: string | null;
        parent_kind: string;
        parent_id: number;
        rejection_note: string | null;
        submitted_at: string | null;
        approved_at: string | null;
        published_at: string | null;
        missing_for_review: string[];
        files: DeliverableFile[];
        social_posts: SocialPostRow[];
        history: Array<{
            id: number;
            from: string | null;
            to: string;
            note: string | null;
            by: string | null;
            at: string | null;
        }>;
    };
    platforms: string[];
    creatives: Array<{ id: number; name: string }>;
    can: {
        update: boolean;
        work: boolean;
        submit: boolean;
        approve: boolean;
        publish: boolean;
        assign: boolean;
        recordLink: boolean;
    };
}

export default function DeliverableShow({ deliverable, platforms, can }: ShowProps) {
    const [uploading, setUploading] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const [publishing, setPublishing] = useState(false);
    const [removeFile, setRemoveFile] = useState<DeliverableFile | null>(null);

    const { errors: pageErrors } = usePage<SharedProps>().props;
    const missing = deliverable.missing_for_review;
    const ready = missing.length === 0;

    const parentHref =
        deliverable.parent_kind === 'interview'
            ? `/interviews/${deliverable.parent_id}`
            : `/articles/${deliverable.parent_id}`;

    return (
        <div className="space-y-6">
            <Head title={`${deliverable.kind_label} · ${deliverable.code}`} />

            <PageHeader
                title={deliverable.title || `${deliverable.kind_label} ${deliverable.slot_number}`}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="tabular font-medium text-foreground">{deliverable.code}</span>
                        <StageBadge family="deliverable" stage={deliverable.stage} dot />
                        <Badge tone="default">
                            {deliverable.is_video ? <Film className="size-3" /> : <ImageIcon className="size-3" />}
                            {deliverable.kind_label}
                        </Badge>
                        <Link
                            href={parentHref}
                            className="inline-flex items-center gap-1 text-sm text-primary hover:underline"
                        >
                            {deliverable.parent_kind === 'interview' ? (
                                <Mic className="size-3" />
                            ) : (
                                <FileText className="size-3" />
                            )}
                            {deliverable.parent_code}
                        </Link>
                    </span>
                }
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href="/deliverables/board">
                                <ArrowLeft />
                                Board
                            </Link>
                        </Button>

                        {can.work && (
                            <Button variant="outline" onClick={() => setUploading(true)}>
                                <Plus />
                                Upload a cut
                            </Button>
                        )}

                        {can.submit && (
                            <Button
                                disabled={!ready}
                                title={ready ? undefined : `Still missing ${sentenceList(missing)}.`}
                                onClick={() =>
                                    router.post(`/deliverables/${deliverable.id}/submit`, {}, { preserveScroll: true })
                                }
                            >
                                <Send />
                                Send for approval
                            </Button>
                        )}

                        {can.approve && (
                            <>
                                <Button variant="outline" onClick={() => setRejecting(true)}>
                                    <Undo2 />
                                    Send back
                                </Button>
                                <Button
                                    onClick={() =>
                                        router.post(
                                            `/deliverables/${deliverable.id}/approve`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <CheckCircle2 />
                                    Approve
                                </Button>
                            </>
                        )}

                        {can.publish && (
                            <Button onClick={() => setPublishing(true)}>
                                <Globe />
                                Mark posted
                            </Button>
                        )}
                    </>
                }
            />

            {/* What is still outstanding, on the page rather than behind a button. */}
            {deliverable.stage !== 'published' && can.work && !ready && (
                <Card className="border-warning/30 bg-warning/5">
                    <CardContent className="flex items-start gap-3 py-4">
                        <AlertTriangle className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden="true" />
                        <div className="min-w-0">
                            <p className="text-sm font-semibold">Not ready for approval yet.</p>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                Still missing {sentenceList(missing)}.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            )}

            {deliverable.rejection_note && deliverable.stage === 'rejected' && (
                <Card className="border-destructive/30 bg-destructive/5">
                    <CardContent className="flex items-start gap-3 py-4">
                        <Undo2 className="mt-0.5 size-5 shrink-0 text-destructive" aria-hidden="true" />
                        <div className="min-w-0">
                            <p className="text-sm font-semibold">
                                Sent back by {deliverable.approver?.name ?? 'the reviewer'}
                            </p>
                            <p className="mt-1 whitespace-pre-wrap text-sm text-muted-foreground">
                                {deliverable.rejection_note}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            )}

            {pageErrors.review && (
                <Card className="border-warning/30 bg-warning/5">
                    <CardContent className="flex items-start gap-2 py-3 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                        <p>{pageErrors.review}</p>
                    </CardContent>
                </Card>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <FilesCard
                        files={deliverable.files}
                        canWork={can.work}
                        onUpload={() => setUploading(true)}
                        onRemove={setRemoveFile}
                    />

                    <CaptionCard deliverable={deliverable} platforms={platforms} readOnly={!can.update} />

                    <SocialCard
                        posts={deliverable.social_posts}
                        canRecord={can.recordLink && deliverable.stage === 'published'}
                        deliverableId={deliverable.id}
                        platforms={platforms}
                    />
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Brief</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            {deliverable.brief ? (
                                <p className="whitespace-pre-wrap text-muted-foreground">{deliverable.brief}</p>
                            ) : (
                                <p className="text-muted-foreground">No brief was written.</p>
                            )}

                            <SectionDivider label="From" />
                            <Link href={parentHref} className="flex items-start gap-2 hover:text-primary">
                                {deliverable.parent_kind === 'interview' ? (
                                    <Mic className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                                ) : (
                                    <FileText className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                                )}
                                <span className="min-w-0">{deliverable.parent_label}</span>
                            </Link>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>People</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <PersonRow role="Making it" person={deliverable.assignee} />
                            <PersonRow role="Commissioned" person={deliverable.assigner} />
                            <PersonRow role="Approved" person={deliverable.approver} />
                        </CardContent>
                    </Card>

                    <HistoryCard history={deliverable.history} />
                </div>
            </div>

            <UploadDialog
                open={uploading}
                onClose={() => setUploading(false)}
                deliverableId={deliverable.id}
                isVideo={deliverable.is_video}
            />

            <RejectDialog open={rejecting} onClose={() => setRejecting(false)} deliverableId={deliverable.id} />

            <PublishDialog
                open={publishing}
                onClose={() => setPublishing(false)}
                deliverableId={deliverable.id}
                platforms={platforms}
                suggested={deliverable.platforms[0] ?? 'instagram'}
            />

            <ConfirmDialog
                open={removeFile !== null}
                onClose={() => setRemoveFile(null)}
                onConfirm={() => {
                    if (removeFile) {
                        router.delete(`/deliverable-files/${removeFile.id}`, {
                            preserveScroll: true,
                            onFinish: () => setRemoveFile(null),
                        });
                    }
                }}
                title={`Remove version ${removeFile?.version}?`}
                description="The file is deleted permanently. Earlier versions are unaffected."
                confirmLabel="Remove"
            />
        </div>
    );
}

function FilesCard({
    files,
    canWork,
    onUpload,
    onRemove,
}: {
    files: DeliverableFile[];
    canWork: boolean;
    onUpload: () => void;
    onRemove: (file: DeliverableFile) => void;
}) {
    return (
        <Card>
            <CardHeader>
                <div>
                    <CardTitle>Versions</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Each upload is a new version. The newest is what a reviewer sees.
                    </p>
                </div>
                {canWork && (
                    <Button size="sm" variant="outline" onClick={onUpload}>
                        <Plus />
                        Upload
                    </Button>
                )}
            </CardHeader>

            {files.length === 0 ? (
                <EmptyState
                    icon={Film}
                    title="Nothing uploaded yet"
                    description="Upload the cut, or paste a Drive link if the file is large."
                />
            ) : (
                <ul className="divide-y divide-border">
                    {files.map((file, index) => (
                        <li key={file.id} className="flex items-center gap-3 px-5 py-3">
                            <span
                                className={cn(
                                    'inline-flex size-9 shrink-0 items-center justify-center rounded-control',
                                    index === 0 ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground',
                                )}
                                aria-hidden="true"
                            >
                                {file.is_link ? <Link2 className="size-4" /> : <Film className="size-4" />}
                            </span>

                            <div className="min-w-0 flex-1">
                                <a
                                    href={file.url}
                                    target={file.is_link ? '_blank' : undefined}
                                    rel={file.is_link ? 'noreferrer' : undefined}
                                    className="flex items-center gap-1.5 truncate text-sm font-medium hover:text-primary"
                                >
                                    <span className="tabular">v{file.version}</span>
                                    <span className="truncate">
                                        {file.original_filename || file.url}
                                    </span>
                                    {file.is_link && <ExternalLink className="size-3 shrink-0" />}
                                    {index === 0 && (
                                        <Badge tone="primary" className="ml-1 text-[10px]">
                                            current
                                        </Badge>
                                    )}
                                </a>
                                <p className="truncate text-xs text-muted-foreground">
                                    {file.size_bytes ? bytes(file.size_bytes) : 'link'}
                                    {file.duration_seconds ? ` · ${formatDuration(file.duration_seconds)}` : ''}
                                    {file.uploader ? ` · ${file.uploader}` : ''}
                                    {file.created_at ? ` · ${relative(file.created_at)}` : ''}
                                </p>
                                {file.note && <p className="truncate text-xs text-muted-foreground">{file.note}</p>}
                            </div>

                            {canWork && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="text-destructive hover:bg-destructive/10"
                                    aria-label={`Remove version ${file.version}`}
                                    onClick={() => onRemove(file)}
                                >
                                    <Trash2 />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/**
 * The caption, written here rather than on the platform.
 *
 * A reel posted with no caption is a reel somebody has to go back and edit on
 * Instagram, which is far more annoying than writing it while the work is in
 * front of them — which is why it is one of the two things required before
 * approval.
 */
function CaptionCard({
    deliverable,
    platforms,
    readOnly,
}: {
    deliverable: ShowProps['deliverable'];
    platforms: string[];
    readOnly: boolean;
}) {
    const { data, setData, patch, processing, isDirty } = useForm({
        title: deliverable.title ?? '',
        brief: deliverable.brief ?? '',
        caption: deliverable.caption ?? '',
        hashtags: deliverable.hashtags ?? '',
        platforms: deliverable.platforms,
        due_on: deliverable.due_on ?? '',
        priority: deliverable.priority,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        patch(`/deliverables/${deliverable.id}`, { preserveScroll: true });
    }

    return (
        <Card>
            <CardHeader>
                <div>
                    <CardTitle>Caption &amp; posting</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Written here so it is ready to paste, not typed into the app at midnight.
                    </p>
                </div>
                {!readOnly && (
                    <Button size="sm" variant="outline" onClick={submit} loading={processing} disabled={!isDirty}>
                        <Save />
                        Save
                    </Button>
                )}
            </CardHeader>

            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <Field label="Caption" htmlFor="caption" hint={`${data.caption.length}/2200 characters`}>
                        <Textarea
                            id="caption"
                            value={data.caption}
                            onChange={(event) => setData('caption', event.target.value)}
                            disabled={readOnly}
                            rows={5}
                            maxLength={2200}
                        />
                    </Field>

                    <Field label="Hashtags" htmlFor="hashtags">
                        <Textarea
                            id="hashtags"
                            value={data.hashtags}
                            onChange={(event) => setData('hashtags', event.target.value)}
                            disabled={readOnly}
                            rows={2}
                            placeholder="#handloom #kanchipuram #madeinindia"
                        />
                    </Field>

                    <fieldset>
                        <legend className="mb-2 text-sm font-medium">Going to</legend>
                        <div className="flex flex-wrap gap-3">
                            {platforms.map((platform) => (
                                <label key={platform} className="flex cursor-pointer items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={data.platforms.includes(platform)}
                                        disabled={readOnly}
                                        onChange={(event) =>
                                            setData(
                                                'platforms',
                                                event.target.checked
                                                    ? [...data.platforms, platform]
                                                    : data.platforms.filter((p) => p !== platform),
                                            )
                                        }
                                    />
                                    <span className="capitalize">{platform}</span>
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Due" htmlFor="due_on">
                            <Input
                                id="due_on"
                                type="date"
                                value={data.due_on}
                                onChange={(event) => setData('due_on', event.target.value)}
                                disabled={readOnly}
                            />
                        </Field>

                        <Field label="Priority" htmlFor="priority">
                            <Select
                                id="priority"
                                value={data.priority}
                                onChange={(event) => setData('priority', event.target.value)}
                                disabled={readOnly}
                            >
                                <option value="low">Low</option>
                                <option value="normal">Normal</option>
                                <option value="high">High</option>
                            </Select>
                        </Field>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function SocialCard({
    posts,
    canRecord,
    deliverableId,
    platforms,
}: {
    posts: SocialPostRow[];
    canRecord: boolean;
    deliverableId: number;
    platforms: string[];
}) {
    const [adding, setAdding] = useState(false);

    return (
        <Card>
            <CardHeader>
                <div>
                    <CardTitle>Where it went live</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Until a link is here, nobody can tell this from an approved piece that never went out.
                    </p>
                </div>
                {canRecord && (
                    <Button size="sm" variant="outline" onClick={() => setAdding(true)}>
                        <Plus />
                        Add link
                    </Button>
                )}
            </CardHeader>

            {posts.length === 0 ? (
                <EmptyState icon={Globe} title="Not posted yet" />
            ) : (
                <ul className="divide-y divide-border">
                    {posts.map((post) => (
                        <li key={post.id} className="flex items-center gap-3 px-5 py-3">
                            <Badge tone="success">{post.platform_label}</Badge>
                            <a
                                href={post.url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex min-w-0 flex-1 items-center gap-1 truncate text-sm text-primary hover:underline"
                            >
                                <span className="truncate">{post.url}</span>
                                <ExternalLink className="size-3 shrink-0" />
                            </a>
                            <span className="shrink-0 text-xs text-muted-foreground">
                                {post.posted_at ? relative(post.posted_at) : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <AddLinkDialog
                open={adding}
                onClose={() => setAdding(false)}
                deliverableId={deliverableId}
                platforms={platforms}
            />
        </Card>
    );
}

function PersonRow({ role, person }: { role: string; person: { id: number; name: string } | null }) {
    return (
        <div className="flex items-center gap-2">
            <span className="w-28 shrink-0 text-xs uppercase tracking-wide text-muted-foreground">{role}</span>
            {person ? (
                <span className="flex min-w-0 items-center gap-2">
                    <Avatar name={person.name} size="sm" />
                    <span className="truncate text-sm">{person.name}</span>
                </span>
            ) : (
                <span className="text-sm text-muted-foreground">—</span>
            )}
        </div>
    );
}

function HistoryCard({ history }: { history: ShowProps['deliverable']['history'] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>History</CardTitle>
            </CardHeader>
            {history.length === 0 ? (
                <EmptyState icon={Globe} title="Nothing yet" />
            ) : (
                <CardContent>
                    <ol className="space-y-3">
                        {history.map((entry) => (
                            <li key={entry.id} className="flex items-start gap-2">
                                <StageBadge family="deliverable" stage={entry.to} dot />
                                <div className="min-w-0 flex-1 text-xs">
                                    <p className="text-muted-foreground">{entry.by ?? 'System'}</p>
                                    {entry.note && (
                                        <p className="mt-0.5 line-clamp-2 text-muted-foreground">{entry.note}</p>
                                    )}
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

function UploadDialog({
    open,
    onClose,
    deliverableId,
    isVideo,
}: {
    open: boolean;
    onClose: () => void;
    deliverableId: number;
    isVideo: boolean;
}) {
    const [mode, setMode] = useState<'file' | 'link'>('link');
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        file: null as File | null,
        url: '',
        note: '',
        duration_seconds: '',
    });

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Upload a cut"
            description="A finished reel is usually too large to upload — pasting a Drive link is the normal way."
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() =>
                            post(`/deliverables/${deliverableId}/files`, {
                                forceFormData: true,
                                preserveScroll: true,
                                onSuccess: () => {
                                    reset();
                                    onClose();
                                },
                            })
                        }
                    >
                        Upload
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <div className="flex gap-1 rounded-control bg-muted p-1">
                    {(['link', 'file'] as const).map((option) => (
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
                            {option === 'link' ? 'Paste a link' : 'Upload a file'}
                        </button>
                    ))}
                </div>

                {mode === 'link' ? (
                    <Field label="Link" htmlFor="url" error={errors.url}>
                        <Input
                            id="url"
                            value={data.url}
                            onChange={(event) => setData('url', event.target.value)}
                            placeholder="https://drive.google.com/…"
                            invalid={Boolean(errors.url)}
                        />
                    </Field>
                ) : (
                    <Field label="File" htmlFor="file" error={errors.file} hint="Up to 200 MB.">
                        <Input
                            id="file"
                            type="file"
                            className="h-auto py-1.5 file:mr-3 file:rounded-control file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-xs file:font-medium"
                            onChange={(event) => setData('file', event.target.files?.[0] ?? null)}
                        />
                    </Field>
                )}

                {isVideo && (
                    <Field label="Length" htmlFor="duration_seconds" error={errors.duration_seconds} hint="Seconds.">
                        <Input
                            id="duration_seconds"
                            type="number"
                            min={0}
                            value={data.duration_seconds}
                            onChange={(event) => setData('duration_seconds', event.target.value)}
                        />
                    </Field>
                )}

                <Field label="What changed?" htmlFor="note" error={errors.note} hint="Optional.">
                    <Input
                        id="note"
                        value={data.note}
                        onChange={(event) => setData('note', event.target.value)}
                        placeholder="Trimmed the intro, fixed the lower third."
                    />
                </Field>
            </div>
        </Dialog>
    );
}

function RejectDialog({ open, onClose, deliverableId }: { open: boolean; onClose: () => void; deliverableId: number }) {
    const { data, setData, post, processing, errors } = useForm({ note: '' });

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Send it back"
            description="Your note goes to the top of the deliverable, where whoever is fixing it will read it."
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() =>
                            post(`/deliverables/${deliverableId}/reject`, {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        Send back
                    </Button>
                </>
            }
        >
            <Field label="What needs changing?" htmlFor="reject_note" error={errors.note} required>
                <Textarea
                    id="reject_note"
                    value={data.note}
                    onChange={(event) => setData('note', event.target.value)}
                    rows={4}
                    placeholder="The client's phone number is wrong on the second frame."
                    invalid={Boolean(errors.note)}
                />
            </Field>
        </Dialog>
    );
}

function PublishDialog({
    open,
    onClose,
    deliverableId,
    platforms,
    suggested,
}: {
    open: boolean;
    onClose: () => void;
    deliverableId: number;
    platforms: string[];
    suggested: string;
}) {
    const { data, setData, post, processing, errors } = useForm({
        platform: suggested,
        url: '',
        posted_at: '',
    });

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Mark this posted"
            description="Post it on the platform first, then paste the link to the post."
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() =>
                            post(`/deliverables/${deliverableId}/publish`, {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        Mark posted
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Platform" htmlFor="platform" error={errors.platform} required>
                    <Select
                        id="platform"
                        value={data.platform}
                        onChange={(event) => setData('platform', event.target.value)}
                    >
                        {platforms.map((platform) => (
                            <option key={platform} value={platform} className="capitalize">
                                {platform}
                            </option>
                        ))}
                    </Select>
                </Field>

                <Field
                    label="Link to the post"
                    htmlFor="publish_url"
                    error={errors.url}
                    required
                    hint="This is what closes the loop — until it is here, this counts as unposted."
                >
                    <Input
                        id="publish_url"
                        value={data.url}
                        onChange={(event) => setData('url', event.target.value)}
                        placeholder="https://www.instagram.com/p/…"
                        invalid={Boolean(errors.url)}
                    />
                </Field>

                <Field label="Posted on" htmlFor="posted_at" error={errors.posted_at} hint="Leave blank for now.">
                    <Input
                        id="posted_at"
                        type="date"
                        value={data.posted_at}
                        onChange={(event) => setData('posted_at', event.target.value)}
                    />
                </Field>
            </div>
        </Dialog>
    );
}

function AddLinkDialog({
    open,
    onClose,
    deliverableId,
    platforms,
}: {
    open: boolean;
    onClose: () => void;
    deliverableId: number;
    platforms: string[];
}) {
    const { data, setData, post, processing, errors } = useForm({ platform: 'instagram', url: '' });

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Record another link"
            description="For the same piece cross-posted somewhere else."
            size="sm"
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() =>
                            post(`/deliverables/${deliverableId}/links`, {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        Record
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field label="Platform" htmlFor="link_platform" error={errors.platform} required>
                    <Select
                        id="link_platform"
                        value={data.platform}
                        onChange={(event) => setData('platform', event.target.value)}
                    >
                        {platforms.map((platform) => (
                            <option key={platform} value={platform} className="capitalize">
                                {platform}
                            </option>
                        ))}
                    </Select>
                </Field>

                <Field label="Link" htmlFor="link_url" error={errors.url} required>
                    <Input
                        id="link_url"
                        value={data.url}
                        onChange={(event) => setData('url', event.target.value)}
                        invalid={Boolean(errors.url)}
                    />
                </Field>
            </div>
        </Dialog>
    );
}

DeliverableShow.layout = adminLayout;
