import { CheckCircle2, Info, X, XCircle } from 'lucide-react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { cn } from '@/lib/utils';

export type ToastTone = 'success' | 'error' | 'info';

interface Toast {
    id: number;
    message: string;
    tone: ToastTone;
}

interface ToastContextValue {
    notify: (message: string, tone?: ToastTone) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

const DISMISS_AFTER_MS = 4_000;

/**
 * Transient messages.
 *
 * Lives above Inertia's <App> so a toast survives a page navigation — the
 * common case is "saved" firing as the server redirects to the record it just
 * created, and a provider inside the page would unmount mid-flight.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
    const [toasts, setToasts] = useState<Toast[]>([]);
    const nextId = useRef(1);
    const timers = useRef(new Map<number, ReturnType<typeof setTimeout>>());

    const visible = useRef<Toast[]>([]);

    const dismiss = useCallback((id: number) => {
        visible.current = visible.current.filter((toast) => toast.id !== id);
        setToasts((current) => current.filter((toast) => toast.id !== id));

        const timer = timers.current.get(id);
        if (timer) {
            clearTimeout(timer);
            timers.current.delete(id);
        }
    }, []);

    const notify = useCallback(
        (message: string, tone: ToastTone = 'success') => {
            /*
             | Deduped on the message text.
             |
             | A double-submitted form, or two failed rows in one batch, would
             | otherwise stack identical toasts on top of each other and read as
             | though something had gone wrong twice.
             */
            // Checked against what is on screen right now, and the id and
            // timer made here rather than inside the state updater — React may
            // run that later, which left a toast with no timer, on screen for
            // good.
            if (visible.current.some((toast) => toast.message === message && toast.tone === tone)) {
                return;
            }

            const id = nextId.current++;
            visible.current = [...visible.current, { id, message, tone }];

            setToasts((current) => [...current, { id, message, tone }]);
            timers.current.set(
                id,
                setTimeout(() => dismiss(id), DISMISS_AFTER_MS),
            );
        },
        [dismiss],
    );

    // Clears any timer still pending if the whole tree unmounts.
    useEffect(() => {
        const pending = timers.current;

        return () => {
            pending.forEach(clearTimeout);
            pending.clear();
        };
    }, []);

    const value = useMemo(() => ({ notify }), [notify]);

    return (
        <ToastContext.Provider value={value}>
            {children}

            <div
                className="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2"
                // Announced without stealing focus: a save confirmation must
                // not interrupt someone mid-keystroke in the next field.
                role="status"
                aria-live="polite"
            >
                {toasts.map((toast) => (
                    <ToastItem key={toast.id} toast={toast} onDismiss={() => dismiss(toast.id)} />
                ))}
            </div>
        </ToastContext.Provider>
    );
}

const TONES: Record<ToastTone, { icon: typeof CheckCircle2; className: string }> = {
    success: { icon: CheckCircle2, className: 'text-success' },
    error: { icon: XCircle, className: 'text-destructive' },
    info: { icon: Info, className: 'text-info' },
};

function ToastItem({ toast, onDismiss }: { toast: Toast; onDismiss: () => void }) {
    const { icon: Icon, className } = TONES[toast.tone];

    return (
        <div className="pointer-events-auto flex items-start gap-3 rounded-xl border border-border bg-popover p-3 text-popover-foreground shadow-card-hover animate-in slide-in-from-bottom-2 fade-in">
            <Icon className={cn('mt-0.5 size-4 shrink-0', className)} aria-hidden="true" />
            <p className="flex-1 text-sm">{toast.message}</p>
            <button
                type="button"
                onClick={onDismiss}
                className="shrink-0 rounded-control p-0.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                aria-label="Dismiss"
            >
                <X className="size-4" />
            </button>
        </div>
    );
}

export function useToast(): ToastContextValue {
    const context = useContext(ToastContext);

    if (!context) {
        throw new Error('useToast must be used inside <ToastProvider>.');
    }

    return context;
}
