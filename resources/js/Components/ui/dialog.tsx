import { X } from 'lucide-react';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/lib/utils';

export interface DialogProps {
    open: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
    size?: 'sm' | 'md' | 'lg' | 'xl';
}

const SIZES = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-lg',
    lg: 'sm:max-w-2xl',
    xl: 'sm:max-w-4xl',
} as const;

/**
 * A modal.
 *
 * Portalled to <body> because the layout uses `sticky` and `backdrop-blur`, both
 * of which create containing blocks — a fixed overlay rendered inside them
 * positions against the wrong ancestor.
 *
 * A bottom sheet below `sm:` and a centred card above it: on a phone, a centred
 * modal with a long form pushes its own action buttons off-screen behind the
 * keyboard.
 */
export function Dialog({ open, onClose, title, description, children, footer, size = 'md' }: DialogProps) {
    const panel = useRef<HTMLDivElement>(null);

    /*
     | The latest onClose, read at the moment it is needed.
     |
     | Callers pass an inline function, which is a new one on every render. As
     | an effect dependency it re-ran the setup below on every keystroke in the
     | dialog — and the setup moves focus to the panel, so typing in a field
     | lost focus after each letter.
     */
    const closeRef = useRef(onClose);
    closeRef.current = onClose;

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                closeRef.current();
            }
        };

        /*
         | Lock the page behind the modal.
         |
         | Without it, scrolling inside a short dialog chains to the page
         | underneath, which then sits at a different position when the dialog
         | closes. The previous value is restored rather than cleared, so two
         | stacked dialogs do not unlock the page when the inner one closes.
         */
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', onKeyDown);

        // Move focus into the dialog so the keyboard goes with the eye.
        requestAnimationFrame(() => panel.current?.focus());

        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    if (!open || typeof document === 'undefined') {
        return null;
    }

    return createPortal(
        <div className="fixed inset-0 z-[70] flex items-end justify-center sm:items-center sm:p-4">
            <div
                className="absolute inset-0 bg-overlay/50 backdrop-blur-sm"
                onClick={onClose}
                aria-hidden="true"
            />

            <div
                ref={panel}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                tabIndex={-1}
                className={cn(
                    'relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-2xl border border-border bg-card text-card-foreground shadow-card-hover outline-none sm:rounded-2xl',
                    SIZES[size],
                )}
            >
                <div className="flex shrink-0 items-start justify-between gap-4 border-b border-border px-6 py-5">
                    <div className="min-w-0">
                        <h2 className="text-lg font-semibold tracking-tight">{title}</h2>
                        {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
                    </div>

                    <button
                        type="button"
                        onClick={onClose}
                        className="-mr-1 shrink-0 rounded-control p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        aria-label="Close"
                    >
                        <X className="size-4" />
                    </button>
                </div>

                {/* The body scrolls; the header and footer stay put. */}
                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">{children}</div>

                {footer && (
                    <div className="flex shrink-0 items-center justify-end gap-2 border-t border-border bg-muted/40 px-6 py-4">
                        {footer}
                    </div>
                )}
            </div>
        </div>,
        document.body,
    );
}

/**
 * A confirmation for something irreversible.
 *
 * Takes the action's own wording as the button label — "Delete account" rather
 * than "OK", so the last thing read before the click says what will happen.
 */
export function ConfirmDialog({
    open,
    onClose,
    onConfirm,
    title,
    description,
    confirmLabel,
    processing = false,
    destructive = true,
}: {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
    title: string;
    description: string;
    confirmLabel: string;
    processing?: boolean;
    destructive?: boolean;
}) {
    /*
     | Busy from the first click until the request it started finishes.
     |
     | Most callers do not pass `processing`, so a quick double click on
     | "Delete" or "Approve" used to send the action twice.
     */
    const [busy, setBusy] = useState(false);

    useEffect(() => router.on('finish', () => setBusy(false)), []);

    useEffect(() => {
        if (!open) {
            setBusy(false);
        }
    }, [open]);

    const working = processing || busy;

    return (
        <Dialog
            open={open}
            onClose={onClose}
            title={title}
            size="sm"
            footer={
                <>
                    <button
                        type="button"
                        onClick={onClose}
                        className="inline-flex h-9 items-center rounded-control border border-input bg-card px-4 text-sm font-medium transition-colors hover:bg-muted"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            if (working) return;
                            setBusy(true);
                            onConfirm();
                        }}
                        disabled={working}
                        className={cn(
                            'inline-flex h-9 items-center rounded-control px-4 text-sm font-medium transition-colors disabled:opacity-50',
                            destructive
                                ? 'bg-destructive text-destructive-foreground hover:bg-accent-dark'
                                : 'bg-primary text-primary-foreground hover:bg-primary-dark',
                        )}
                    >
                        {confirmLabel}
                    </button>
                </>
            }
        >
            <p className="text-sm text-muted-foreground">{description}</p>
        </Dialog>
    );
}
