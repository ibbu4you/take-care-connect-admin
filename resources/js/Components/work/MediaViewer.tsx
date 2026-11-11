import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    ChevronLeft,
    ChevronRight,
    Download,
    ExternalLink,
    FileQuestion,
    Film,
    Image as ImageIcon,
    Link2,
    Play,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { bytes, duration as formatDuration } from '@/lib/format';
import { cn } from '@/lib/utils';

/** One uploaded or linked version, as the server shapes it (DeliverableFile::media()). */
export interface MediaInfo {
    id: number;
    version: number;
    type: 'image' | 'video' | 'link' | 'file';
    preview_url: string | null;
    thumbnail_url: string | null;
    download_url: string;
    filename: string | null;
    size_bytes: number | null;
    duration_seconds: number | null;
}

export interface ViewerItem {
    key: string | number;
    media: MediaInfo;
    title: string;
    subtitle?: string;
    href?: string;
}

/* ─── Thumbnail ─────────────────────────────────────────────────────────── */

/**
 * The picture on a card: the image itself, the first frame of a video, a still
 * from the host of a link — or, failing all three, the kind's icon.
 *
 * A broken external still (a private Drive file, say) falls back to the icon
 * rather than showing the browser's broken-image glyph.
 */
export function MediaThumb({
    media,
    fallbackThumbnail,
    isVideo,
    emptyLabel,
    className,
    children,
}: {
    media: MediaInfo | null;
    fallbackThumbnail?: string | null;
    isVideo: boolean;
    emptyLabel: string;
    className?: string;
    children?: ReactNode;
}) {
    const [failed, setFailed] = useState(false);
    const still = media?.type === 'video' ? null : (media?.thumbnail_url ?? fallbackThumbnail ?? null);
    const KindIcon = isVideo ? Film : ImageIcon;

    useEffect(() => setFailed(false), [still]);

    let body: ReactNode;

    /*
     | The whole picture, never cropped: a poster's attribution sits at the
     | bottom and a reel is tall, so filling the frame would cut off exactly
     | what a reviewer is checking. The spare space is filled with a blurred
     | copy (images) or the dark of a player (video), so every card's frame
     | is the same size and the board stays even.
     */
    if (media?.type === 'video' && media.preview_url) {
        body = (
            <div className="size-full bg-gradient-to-br from-neutral-800 to-neutral-950">
                <video
                    src={`${media.preview_url}#t=0.5`}
                    preload="metadata"
                    muted
                    playsInline
                    className="size-full object-contain"
                    aria-hidden="true"
                    tabIndex={-1}
                />
            </div>
        );
    } else if (still && !failed) {
        body = (
            <div className="relative size-full overflow-hidden bg-neutral-900">
                <img
                    src={still}
                    alt=""
                    aria-hidden="true"
                    loading="lazy"
                    referrerPolicy="no-referrer"
                    className="absolute inset-0 size-full scale-125 object-cover opacity-70 blur-2xl"
                />
                <img
                    src={still}
                    alt=""
                    loading="lazy"
                    referrerPolicy="no-referrer"
                    onError={() => setFailed(true)}
                    className="relative size-full object-contain drop-shadow-lg"
                />
            </div>
        );
    } else {
        const Icon: LucideIcon = media?.type === 'link' ? Link2 : media?.type === 'file' ? FileQuestion : KindIcon;
        const label =
            media?.type === 'link' ? 'Linked file' : media?.type === 'file' ? 'No preview' : emptyLabel;

        body = (
            <div
                className={cn(
                    'flex size-full flex-col items-center justify-center gap-2',
                    isVideo
                        ? 'bg-gradient-to-br from-stage-in-progress/10 via-muted to-muted'
                        : 'bg-gradient-to-br from-primary/10 via-muted to-muted',
                )}
            >
                <span
                    className={cn(
                        'flex size-10 items-center justify-center rounded-full bg-card shadow-xs',
                        isVideo ? 'text-stage-in-progress' : 'text-primary',
                    )}
                >
                    <Icon className="size-5" aria-hidden="true" />
                </span>
                <span className="text-xs font-medium text-muted-foreground">{label}</span>
            </div>
        );
    }

    return (
        <div className={cn('relative overflow-hidden bg-muted', className)}>
            {body}

            {media?.type === 'video' && (
                <span className="pointer-events-none absolute inset-0 flex items-center justify-center">
                    <span className="flex size-10 items-center justify-center rounded-full bg-black/55 text-white backdrop-blur-sm">
                        <Play className="ml-0.5 size-4 fill-current" aria-hidden="true" />
                    </span>
                </span>
            )}

            {children}
        </div>
    );
}

/* ─── Viewer ────────────────────────────────────────────────────────────── */

/**
 * The full-size look at a cut, with a download.
 *
 * Steps through every item it is given (arrow keys or the side buttons), so a
 * reviewer can go through the board's work without opening each page.
 */
export function MediaViewer({
    items,
    index,
    onIndexChange,
    onClose,
}: {
    items: ViewerItem[];
    index: number | null;
    onIndexChange: (index: number) => void;
    onClose: () => void;
}) {
    const open = index !== null && items[index] !== undefined;
    const count = items.length;

    const step = useCallback(
        (by: number) => {
            if (index === null || count < 2) {
                return;
            }

            onIndexChange((index + by + count) % count);
        },
        [index, count, onIndexChange],
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
            if (event.key === 'ArrowRight') step(1);
            if (event.key === 'ArrowLeft') step(-1);
        };

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open, onClose, step]);

    if (!open || typeof document === 'undefined') {
        return null;
    }

    const item = items[index];
    const { media } = item;
    const isLink = media.type === 'link';
    const details = [
        `Version ${media.version}`,
        media.filename,
        media.size_bytes ? bytes(media.size_bytes) : null,
        media.duration_seconds ? formatDuration(media.duration_seconds) : null,
    ].filter(Boolean);

    return createPortal(
        <div className="fixed inset-0 z-[80] flex flex-col bg-black/95 text-white backdrop-blur-md" role="dialog" aria-modal="true" aria-label={item.title}>
            {/* Top bar */}
            <header className="flex shrink-0 items-center gap-3 px-4 py-3 sm:px-6">
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">{item.title}</p>
                    <p className="truncate text-xs text-white/60">
                        {[item.subtitle, ...details].filter(Boolean).join(' · ')}
                    </p>
                </div>

                {count > 1 && (
                    <span className="hidden text-xs tabular text-white/60 sm:inline">
                        {index + 1} / {count}
                    </span>
                )}

                {item.href && (
                    <Link
                        href={item.href}
                        className="hidden h-9 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-white/80 transition-colors hover:bg-white/10 hover:text-white sm:inline-flex"
                    >
                        Open page
                        <ArrowUpRight className="size-4" aria-hidden="true" />
                    </Link>
                )}

                <a
                    href={media.download_url}
                    {...(isLink ? { target: '_blank', rel: 'noreferrer' } : { download: media.filename ?? true })}
                    className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-white px-3 text-sm font-semibold text-black transition-colors hover:bg-white/85"
                >
                    {isLink ? <ExternalLink className="size-4" aria-hidden="true" /> : <Download className="size-4" aria-hidden="true" />}
                    {isLink ? 'Open link' : 'Download'}
                </a>

                <button
                    type="button"
                    onClick={onClose}
                    className="inline-flex size-9 items-center justify-center rounded-lg text-white/80 transition-colors hover:bg-white/10 hover:text-white"
                    aria-label="Close"
                >
                    <X className="size-5" />
                </button>
            </header>

            {/* Stage */}
            <div className="relative flex min-h-0 flex-1 items-center justify-center px-4 pb-6 sm:px-16" onClick={onClose}>
                <div className="flex max-h-full max-w-full items-center justify-center" onClick={(event) => event.stopPropagation()}>
                    <ViewerStage key={media.id} media={media} />
                </div>

                {count > 1 && (
                    <>
                        <NavButton side="left" onClick={() => step(-1)} />
                        <NavButton side="right" onClick={() => step(1)} />
                    </>
                )}
            </div>
        </div>,
        document.body,
    );
}

function ViewerStage({ media }: { media: MediaInfo }) {
    const [failed, setFailed] = useState(false);

    if (media.type === 'image' && media.preview_url) {
        return (
            <img
                src={media.preview_url}
                alt=""
                className="max-h-[calc(100vh-7rem)] max-w-full rounded-lg object-contain shadow-2xl"
            />
        );
    }

    if (media.type === 'video' && media.preview_url) {
        return (
            <video
                src={media.preview_url}
                controls
                autoPlay
                playsInline
                className="max-h-[calc(100vh-7rem)] max-w-full rounded-lg bg-black shadow-2xl"
            />
        );
    }

    const isLink = media.type === 'link';

    return (
        <div className="flex w-[min(28rem,90vw)] flex-col items-center gap-4 rounded-2xl bg-white/5 p-8 text-center ring-1 ring-white/10">
            {media.thumbnail_url && !failed ? (
                <img
                    src={media.thumbnail_url}
                    alt=""
                    referrerPolicy="no-referrer"
                    onError={() => setFailed(true)}
                    className="max-h-[50vh] rounded-lg object-contain"
                />
            ) : (
                <span className="flex size-14 items-center justify-center rounded-full bg-white/10">
                    {isLink ? <Link2 className="size-6" /> : <FileQuestion className="size-6" />}
                </span>
            )}
            <div>
                <p className="text-sm font-semibold">{isLink ? 'This version is a link' : 'No preview for this file'}</p>
                <p className="mt-1 text-xs text-white/60">
                    {isLink
                        ? 'It lives on Drive or another host. Open it there to watch or download.'
                        : 'Only images and common video formats show here. Download it to open it.'}
                </p>
            </div>
            <a
                href={media.download_url}
                {...(isLink ? { target: '_blank', rel: 'noreferrer' } : { download: media.filename ?? true })}
                className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-white px-4 text-sm font-semibold text-black hover:bg-white/85"
            >
                {isLink ? <ExternalLink className="size-4" /> : <Download className="size-4" />}
                {isLink ? 'Open link' : 'Download'}
            </a>
        </div>
    );
}

function NavButton({ side, onClick }: { side: 'left' | 'right'; onClick: () => void }) {
    const Icon = side === 'left' ? ChevronLeft : ChevronRight;

    return (
        <button
            type="button"
            onClick={(event) => {
                event.stopPropagation();
                onClick();
            }}
            className={cn(
                'absolute top-1/2 inline-flex size-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur transition-colors hover:bg-white/20',
                side === 'left' ? 'left-2 sm:left-4' : 'right-2 sm:right-4',
            )}
            aria-label={side === 'left' ? 'Previous' : 'Next'}
        >
            <Icon className="size-6" />
        </button>
    );
}
