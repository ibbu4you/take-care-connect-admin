import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Download,
    ExternalLink,
    FileText,
    Globe,
    History,
    Mic,
    Plus,
    Save,
    Send,
    Upload,
    Undo2,
    X,
} from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { PageHeader } from '@/Components/layout/PageHeader';
import { Badge, StageBadge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog } from '@/Components/ui/dialog';
import { Field, Input, Select, Textarea } from '@/Components/ui/input';
import { EmptyState, SectionDivider } from '@/Components/ui/misc';
import { adminLayout } from '@/Layouts/AdminLayout';
import { bytes, dateTime, relative, sentenceList } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

interface EditorProps {
    article: {
        id: number;
        code: string;
        title: string;
        slug: string | null;
        stage: string;
        stage_label: string;
        target_type: string;
        writer: { id: number; name: string } | null;
        reviewer: { id: number; name: string } | null;
        category: string | null;
        category_id: number | null;
        city: string | null;
        due_on: string | null;
        priority: string;
        interview_code: string | null;
        review_note: string | null;
        published_url: string | null;
        published_at: string | null;
        missing_for_review: string[];
        assets: Array<{
            id: number;
            kind: string;
            label: string | null;
            url: string | null;
            original_filename: string | null;
            size_bytes: number | null;
            uploader: string | null;
            created_at: string | null;
        }>;
        source: {
            id: number;
            code: string;
            subject_name: string;
            recording_url: string | null;
            brief: string | null;
            transcripts: Array<{ id: number; label: string; url: string }>;
        } | null;
        history: Array<{
            id: number;
            from: string | null;
            to: string;
            note: string | null;
            by: string | null;
            at: string | null;
        }>;
    };
    categories: Array<{ id: number; name: string }>;
    writers: Array<{ id: number; name: string }>;
    can: {
        update: boolean;
        submit: boolean;
        review: boolean;
        approve: boolean;
        publish: boolean;
        export: boolean;
        assign: boolean;
        upload: boolean;
    };
}

export default function ArticleEditor({ article, categories, writers, can }: EditorProps) {
    const [sendingBack, setSendingBack] = useState(false);
    const [publishing, setPublishing] = useState(false);
    const [showSource, setShowSource] = useState(false);

    const { errors: pageErrors } = usePage<SharedProps>().props;

    const { data, setData, patch, processing, isDirty, errors } = useForm({
        title: article.title,
        category_id: article.category_id ? String(article.category_id) : '',
        city: article.city ?? '',
        target_type: article.target_type,
        due_on: article.due_on ?? '',
    });

    const readOnly = !can.update;

    function save(event?: FormEvent) {
        event?.preventDefault();
        patch(`/articles/${article.id}`, { preserveScroll: true });
    }

    /** Approve, publish, send back — a stage change with no payload. */
    function act(url: string) {
        router.post(url, {}, { preserveScroll: true });
    }

    return (
        <div className="space-y-6">
            <Head title={article.title} />

            <PageHeader
                title={article.title}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="tabular font-medium text-foreground">{article.code}</span>
                        <StageBadge family="article" stage={article.stage} dot />
                        <Badge tone={article.target_type === 'business' ? 'info' : 'default'}>
                            {article.target_type === 'business' ? 'Craftsman profile' : 'Blog post'}
                        </Badge>
                        {article.interview_code && (
                            <Badge tone="default">
                                <Mic className="size-3" />
                                {article.interview_code}
                            </Badge>
                        )}
                    </span>
                }
                actions={
                    <>
                        <Button asChild variant="outline">
                            <Link href="/articles/queue">
                                <ArrowLeft />
                                Queue
                            </Link>
                        </Button>

                        {can.update && (
                            <Button variant="outline" onClick={() => save()} loading={processing} disabled={!isDirty}>
                                <Save />
                                Save
                            </Button>
                        )}

                        {can.submit && <SubmitButton article={article} isDirty={isDirty} onSave={save} />}

                        {can.review && (
                            <>
                                <Button variant="outline" onClick={() => setSendingBack(true)}>
                                    <Undo2 />
                                    Send back
                                </Button>
                                {can.approve && (
                                    <Button
                                        onClick={() => act(`/articles/${article.id}/approve`)}
                                    >
                                        <CheckCircle2 />
                                        Approve
                                    </Button>
                                )}
                            </>
                        )}


                        {can.publish && (
                            <Button onClick={() => setPublishing(true)}>
                                <Globe />
                                Mark published
                            </Button>
                        )}
                    </>
                }
            />

            {/* The reviewer's notes, at the top where the writer will read them. */}
            {article.review_note && article.stage === 'revisions' && (
                <Card className="border-warning/30 bg-warning/5">
                    <CardContent className="flex items-start gap-3 py-4">
                        <Undo2 className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden="true" />
                        <div className="min-w-0">
                            <p className="text-sm font-semibold">
                                Sent back by {article.reviewer?.name ?? 'the reviewer'}
                            </p>
                            <p className="mt-1 whitespace-pre-wrap text-sm text-muted-foreground">
                                {article.review_note}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            )}

            {article.published_url && (
                <Card className="border-success/30 bg-success/5">
                    <CardContent className="flex flex-wrap items-center gap-2 py-3 text-sm">
                        <CheckCircle2 className="size-4 shrink-0 text-success" aria-hidden="true" />
                        <span>
                            Published {article.published_at ? relative(article.published_at) : ''} —
                        </span>
                        <a
                            href={article.published_url}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                        >
                            view it live
                            <ExternalLink className="size-3" />
                        </a>
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

            <form onSubmit={save} className="grid gap-6 xl:grid-cols-3">
                <div className="space-y-6 xl:col-span-2">
                    <Card>
                        <CardContent className="space-y-4">
                            <Field label="Headline" htmlFor="title" required error={errors.title}>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(event) => setData('title', event.target.value)}
                                    disabled={readOnly}
                                    className="text-lg font-semibold"
                                />
                            </Field>

                        </CardContent>
                    </Card>

                    <DraftCard
                        article={article}
                        canUpload={can.upload && !readOnly}
                        onOpenSource={article.source ? () => setShowSource(true) : undefined}
                    />
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Publishing details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Field label="Becomes" htmlFor="target_type" error={errors.target_type}>
                                <Select
                                    id="target_type"
                                    value={data.target_type}
                                    onChange={(event) => setData('target_type', event.target.value)}
                                    disabled={readOnly}
                                >
                                    <option value="post">A blog post</option>
                                    <option value="business">A craftsman / business profile</option>
                                </Select>
                            </Field>

                            <Field label="Category" htmlFor="category_id" error={errors.category_id}>
                                <Select
                                    id="category_id"
                                    value={data.category_id}
                                    onChange={(event) => setData('category_id', event.target.value)}
                                    disabled={readOnly}
                                >
                                    <option value="">No category</option>
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>

                            <Field label="City" htmlFor="city" error={errors.city}>
                                <Input
                                    id="city"
                                    value={data.city}
                                    onChange={(event) => setData('city', event.target.value)}
                                    disabled={readOnly}
                                />
                            </Field>

                            <Field label="Deadline" htmlFor="due_on" error={errors.due_on}>
                                <Input
                                    id="due_on"
                                    type="date"
                                    value={data.due_on}
                                    onChange={(event) => setData('due_on', event.target.value)}
                                    disabled={readOnly}
                                />
                            </Field>

                        </CardContent>
                    </Card>

                    <WriterCard
                        articleId={article.id}
                        writer={article.writer}
                        writers={writers}
                        canAssign={can.assign}
                    />

                    <HistoryCard history={article.history} />
                </div>
            </form>

            {article.source && (
                <SourceDialog open={showSource} onClose={() => setShowSource(false)} source={article.source} />
            )}

            <SendBackDialog open={sendingBack} onClose={() => setSendingBack(false)} articleId={article.id} />
            <PublishDialog open={publishing} onClose={() => setPublishing(false)} articleId={article.id} />
        </div>
    );
}


/**
 * Submit for review, with the checklist attached.
 *
 * Disabled with a reason rather than enabled-then-refused: the writer should
 * find out that the SEO fields are empty here, not after pressing the button.
 */
function SubmitButton({
    article,
    isDirty,
    onSave,
}: {
    article: EditorProps['article'];
    isDirty: boolean;
    onSave: () => void;
}) {
    const missing = article.missing_for_review;
    const ready = missing.length === 0 && !isDirty;

    const title = isDirty
        ? 'Save your changes first.'
        : missing.length > 0
          ? `Still missing ${sentenceList(missing)}.`
          : undefined;

    return (
        <Button
            disabled={!ready}
            title={title}
            onClick={() => {
                if (isDirty) {
                    onSave();

                    return;
                }

                router.post(`/articles/${article.id}/submit`, {}, { preserveScroll: true });
            }}
        >
            <Send />
            Send for review
        </Button>
    );
}

function SourceDialog({
    open,
    onClose,
    source,
}: {
    open: boolean;
    onClose: () => void;
    source: NonNullable<EditorProps['article']['source']>;
}) {
    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={`From ${source.code}`}
            description={`The transcript of the interview with ${source.subject_name}.`}
            size="lg"
            footer={
                <>
                    {source.recording_url && (
                        <Button asChild variant="outline">
                            <a href={source.recording_url} target="_blank" rel="noreferrer">
                                <ExternalLink />
                                Open the recording
                            </a>
                        </Button>
                    )}
                    <Button asChild variant="outline">
                        <Link href={`/interviews/${source.id}`}>
                            <Mic />
                            Open the interview
                        </Link>
                    </Button>
                    <Button onClick={onClose}>Close</Button>
                </>
            }
        >
            {source.brief && (
                <div className="mb-4">
                    <p className="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        The anchor's brief
                    </p>
                    <p className="whitespace-pre-wrap rounded-control bg-muted/50 p-3 text-sm">{source.brief}</p>
                </div>
            )}

            {source.transcripts.length === 0 ? (
                <EmptyState
                    icon={FileText}
                    title="No transcript attached"
                    description="The anchor uploads the transcript on the interview. Open it to see what is there."
                />
            ) : (
                <ul className="space-y-2">
                    {source.transcripts.map((file) => (
                        <li key={file.id}>
                            <a
                                href={file.url}
                                target="_blank"
                                rel="noreferrer"
                                className="flex items-center gap-3 rounded-control border border-border p-3 transition-colors hover:bg-muted/50"
                            >
                                <FileText className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                <span className="min-w-0 flex-1 truncate text-sm font-medium">{file.label}</span>
                                <ExternalLink className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </Dialog>
    );
}

function HistoryCard({ history }: { history: EditorProps['article']['history'] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>History</CardTitle>
            </CardHeader>
            {history.length === 0 ? (
                <EmptyState icon={History} title="Nothing yet" />
            ) : (
                <CardContent>
                    <ol className="space-y-3">
                        {history.map((entry) => (
                            <li key={entry.id} className="flex items-start gap-2">
                                <StageBadge family="article" stage={entry.to} dot />
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

function SendBackDialog({ open, onClose, articleId }: { open: boolean; onClose: () => void; articleId: number }) {
    const { data, setData, post, processing, errors } = useForm({ note: '' });

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Send back for revisions"
            description="Your notes go to the top of the writer's editor, where they will actually be read."
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() =>
                            post(`/articles/${articleId}/revisions`, {
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
            <Field label="What needs changing?" htmlFor="note" error={errors.note} required>
                <Textarea
                    id="note"
                    value={data.note}
                    onChange={(event) => setData('note', event.target.value)}
                    rows={5}
                    placeholder="The opening is strong, but the section on the second year needs the figure Suresh gave us."
                    invalid={Boolean(errors.note)}
                />
            </Field>
        </Dialog>
    );
}

function PublishDialog({ open, onClose, articleId }: { open: boolean; onClose: () => void; articleId: number }) {
    const { data, setData, post, processing, errors } = useForm({
        published_url: '',
        published_at: '',
    });

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title="Mark this published"
            description="Publish the exported bundle on takecareconnect.com first, then paste the live URL here."
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={processing}
                        onClick={() =>
                            post(`/articles/${articleId}/publish`, {
                                preserveScroll: true,
                                onSuccess: onClose,
                            })
                        }
                    >
                        Mark published
                    </Button>
                </>
            }
        >
            <div className="space-y-4">
                <Field
                    label="Live URL"
                    htmlFor="published_url"
                    error={errors.published_url}
                    required
                    hint="This is what closes the loop — until it is recorded, the article counts as outstanding."
                >
                    <Input
                        id="published_url"
                        value={data.published_url}
                        onChange={(event) => setData('published_url', event.target.value)}
                        placeholder="https://takecareconnect.com/blog/…"
                        invalid={Boolean(errors.published_url)}
                    />
                </Field>

                <Field label="Published on" htmlFor="published_at" error={errors.published_at} hint="Leave blank for now.">
                    <Input
                        id="published_at"
                        type="date"
                        value={data.published_at}
                        onChange={(event) => setData('published_at', event.target.value)}
                    />
                </Field>
            </div>
        </Dialog>
    );
}

/**
 * Who is writing it, and — for whoever runs the content desk — a way to give
 * it to somebody else.
 *
 * Not a form of its own: this sits inside the editor's form, and forms cannot
 * nest, so the button sends the request directly.
 */
function WriterCard({
    articleId,
    writer,
    writers,
    canAssign,
}: {
    articleId: number;
    writer: { id: number; name: string } | null;
    writers: Array<{ id: number; name: string }>;
    canAssign: boolean;
}) {
    const [choice, setChoice] = useState('');
    const [saving, setSaving] = useState(false);
    const { errors } = usePage<SharedProps>().props;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Writer</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                <p className="text-sm">{writer ? writer.name : <span className="text-muted-foreground">Nobody yet</span>}</p>

                {canAssign && (
                    <div className="flex items-end gap-2">
                        <Field label="Give it to" htmlFor="assign_writer" error={errors.writer_id} className="min-w-0 flex-1">
                            <Select id="assign_writer" value={choice} onChange={(event) => setChoice(event.target.value)}>
                                <option value="">Choose…</option>
                                {writers
                                    .filter((person) => person.id !== writer?.id)
                                    .map((person) => (
                                        <option key={person.id} value={person.id}>
                                            {person.name}
                                        </option>
                                    ))}
                            </Select>
                        </Field>
                        <Button
                            type="button"
                            variant="outline"
                            loading={saving}
                            disabled={choice === ''}
                            onClick={() => {
                                setSaving(true);
                                router.post(
                                    `/articles/${articleId}/assign`,
                                    { writer_id: choice },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setChoice(''),
                                        onFinish: () => setSaving(false),
                                    },
                                );
                            }}
                        >
                            Assign
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

ArticleEditor.layout = adminLayout;

/**
 * The draft itself.
 *
 * ── Why a file rather than an editor ───────────────────────────────────────
 * There was a rich-text editor here, and it existed to produce publish-ready
 * HTML for a bundle somebody then pasted into the website. The team writes in
 * Word and publishes on the site by hand, so the editor was a second place to
 * write the same article — and the one that nobody would keep in step.
 *
 * What is left is the part that was always doing the work: upload the draft,
 * download it to read or to publish from. Every upload is kept rather than
 * replaced, so "what did the reviewer actually see" has an answer.
 */
function DraftCard({
    article,
    canUpload,
    onOpenSource,
}: {
    article: EditorProps['article'];
    canUpload: boolean;
    onOpenSource?: () => void;
}) {
    const drafts = article.assets.filter((asset) => asset.kind === 'document');
    const [uploading, setUploading] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    function upload(file: File) {
        const form = new FormData();

        form.append('kind', 'document');
        form.append('file', file);

        setUploading(true);
        router.post(`/articles/${article.id}/assets`, form, {
            preserveScroll: true,
            onFinish: () => {
                setUploading(false);

                // Clear it, or choosing the same filename twice does nothing —
                // the change event never fires for an identical value.
                if (inputRef.current) inputRef.current.value = '';
            },
        });
    }

    return (
        <Card>
            <CardHeader>
                <div>
                    <CardTitle>The draft</CardTitle>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Written in Word or Docs, uploaded here. The newest upload is what a reviewer reads.
                    </p>
                </div>

                <div className="flex shrink-0 items-center gap-2">
                    {onOpenSource && (
                        <Button size="sm" variant="outline" onClick={onOpenSource}>
                            <FileText />
                            Source material
                        </Button>
                    )}

                    {canUpload && (
                        <Button size="sm" loading={uploading} onClick={() => inputRef.current?.click()}>
                            <Upload />
                            {drafts.length === 0 ? 'Upload draft' : 'Upload new version'}
                        </Button>
                    )}
                </div>
            </CardHeader>

            <input
                ref={inputRef}
                type="file"
                className="hidden"
                onChange={(event) => {
                    const file = event.target.files?.[0];

                    if (file) upload(file);
                }}
            />

            {drafts.length === 0 ? (
                <EmptyState
                    icon={FileText}
                    title="No draft yet"
                    description="Write it up from the source material, then upload the file. It cannot go for review without one."
                />
            ) : (
                <ul className="divide-y divide-border border-t border-border">
                    {drafts.map((asset, index) => (
                        <li key={asset.id} className="flex items-center gap-3 px-5 py-3">
                            <FileText className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />

                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {asset.original_filename ?? asset.label ?? 'Draft'}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    {index === 0 ? 'Latest · ' : ''}
                                    {asset.uploader ?? 'Someone'}
                                    {asset.created_at ? ` · ${relative(asset.created_at)}` : ''}
                                    {asset.size_bytes ? ` · ${bytes(asset.size_bytes)}` : ''}
                                </p>
                            </div>

                            <Button asChild size="sm" variant="outline">
                                <a href={`/article-assets/${asset.id}`}>
                                    <Download />
                                    Download
                                </a>
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
