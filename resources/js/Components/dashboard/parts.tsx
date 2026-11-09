import { Link } from '@inertiajs/react';
import { Check, Clock, X, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/*
 | The building blocks of the dashboard cards.
 |
 | Every colour is a theme token (see resources/css/app.css), so these render
 | correctly in the dark theme without a single `dark:` variant.
 */

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

export interface Person {
    name: string;
    initials: string;
    avatar_url: string | null;
}

/** A dashboard card: icon, title, an optional small button, then a hairline. */
export function DashCard({
    icon: Icon,
    title,
    action,
    children,
    className,
}: {
    icon: LucideIcon;
    title: string;
    action?: { label: ReactNode; href: string } | null;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn('rounded-xl border border-border bg-card p-4 text-card-foreground shadow-xs', className)}
        >
            <header className="flex min-h-8 items-center gap-2 border-b border-border pb-4">
                <Icon className="size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                <h2 className="min-w-0 flex-1 truncate text-base font-medium leading-6 tracking-tight">{title}</h2>
                {action && <SmallButton href={action.href}>{action.label}</SmallButton>}
            </header>

            <div className="pt-4">{children}</div>
        </section>
    );
}

/** The white, hairline "See All" / "Details" button in a card's header. */
export function SmallButton({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Link
            href={href}
            className="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg border border-border bg-card px-2.5 text-sm font-medium text-muted-foreground shadow-xs transition-colors hover:bg-muted hover:text-foreground [&_svg]:size-4"
        >
            {children}
        </Link>
    );
}

const PILL_TONES: Record<Tone, string> = {
    success: 'bg-success/10 text-success',
    warning: 'bg-stage-revisions/10 text-stage-revisions',
    danger: 'bg-destructive/10 text-destructive',
    info: 'bg-primary/10 text-primary',
    neutral: 'border border-border text-muted-foreground',
};

export function Pill({ tone, children, className }: { tone: Tone; children: ReactNode; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex h-5 shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 text-xs font-medium',
                PILL_TONES[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}

const STATUS_DOT: Record<Tone, string> = {
    success: 'bg-success',
    warning: 'bg-stage-revisions',
    danger: 'bg-destructive',
    info: 'bg-primary',
    neutral: 'bg-muted-foreground',
};

/** The small filled circle at the start of a row: clock, tick or cross. */
export function StatusIcon({ tone }: { tone: Tone }) {
    const Icon = tone === 'success' ? Check : tone === 'danger' ? X : Clock;

    return (
        <span
            className={cn('inline-flex size-4 shrink-0 items-center justify-center rounded-full', STATUS_DOT[tone])}
            aria-hidden="true"
        >
            <Icon className="size-2.5 text-card" strokeWidth={3} />
        </span>
    );
}

export function statusDotClass(tone: Tone): string {
    return STATUS_DOT[tone];
}

/** A full ring, filled clockwise from the top. */
export function ProgressRing({ percent, size = 80 }: { percent: number; size?: number }) {
    const value = Math.max(0, Math.min(100, percent));

    return (
        <div className="relative shrink-0" style={{ width: size, height: size }}>
            <svg viewBox="0 0 80 80" className="size-full -rotate-90" aria-hidden="true">
                <circle cx="40" cy="40" r="34" fill="none" strokeWidth="8" className="stroke-muted" />
                {/* Not drawn at zero: a round cap on a zero-length arc is a dot. */}
                {value > 0 && <circle
                    cx="40"
                    cy="40"
                    r="34"
                    fill="none"
                    strokeWidth="8"
                    strokeLinecap="round"
                    pathLength={100}
                    strokeDasharray={`${value} 100`}
                    className="stroke-primary transition-[stroke-dasharray] duration-700"
                />}
            </svg>
            <span className="absolute inset-0 flex items-center justify-center text-base font-medium tabular">
                {Math.round(value)}%
            </span>
        </div>
    );
}

/**
 * The half-circle gauge with the figure in its middle.
 *
 * The caption is kept short ("out of 12") because it sits on the baseline
 * between the arc's two ends: anything longer runs into the round caps. Say
 * what period it covers underneath the gauge, not inside it.
 */
export function Gauge({ value, max, label }: { value: number; max: number; label: string }) {
    const percent = max > 0 ? Math.min(100, (value / max) * 100) : 0;
    const arc = 'M 20 100 A 80 80 0 0 1 180 100';

    return (
        <div
            className="relative mx-auto w-full max-w-[240px]"
            role="img"
            aria-label={`${value} ${label} out of ${max}`}
        >
            <svg viewBox="0 0 200 110" className="w-full overflow-visible" aria-hidden="true">
                {/* --border rather than --muted: on a white card --muted is all but invisible. */}
                <path d={arc} fill="none" strokeWidth="20" strokeLinecap="round" className="stroke-border" />
                {percent > 0 && (
                    <path
                        d={arc}
                        fill="none"
                        strokeWidth="20"
                        strokeLinecap="round"
                        pathLength={100}
                        strokeDasharray={`${percent} 100`}
                        className="stroke-primary transition-[stroke-dasharray] duration-700"
                    />
                )}
            </svg>

            <div className="absolute inset-x-0 bottom-0 flex flex-col items-center">
                <span className="text-[32px] font-medium leading-9 tracking-tight tabular">{value}</span>
                <span className="mt-0.5 text-[11px] font-medium uppercase leading-4 tracking-[0.06em] text-muted-foreground tabular">
                    Out of {max}
                </span>
            </div>
        </div>
    );
}

/** Overlapping faces, then "+N" for the rest. */
export function AvatarStack({ people, extra = 0, size = 'sm' }: { people: Person[]; extra?: number; size?: 'sm' | 'md' }) {
    const dimension = size === 'md' ? 'size-7 text-[10px]' : 'size-6 text-[9px]';

    return (
        <div className="flex items-center -space-x-1.5">
            {people.map((person) =>
                person.avatar_url ? (
                    <img
                        key={person.name}
                        src={person.avatar_url}
                        alt={person.name}
                        title={person.name}
                        className={cn('rounded-full object-cover ring-2 ring-card', dimension)}
                    />
                ) : (
                    <span
                        key={person.name}
                        title={person.name}
                        className={cn(
                            'inline-flex items-center justify-center rounded-full bg-primary/15 font-semibold text-primary ring-2 ring-card',
                            dimension,
                        )}
                    >
                        {person.initials}
                    </span>
                ),
            )}

            {extra > 0 && (
                <span
                    className={cn(
                        'inline-flex items-center justify-center rounded-full bg-card font-medium text-muted-foreground ring-2 ring-card',
                        dimension,
                    )}
                >
                    +{extra}
                </span>
            )}
        </div>
    );
}

/** What a card shows when it has nothing to list. */
export function CardEmpty({ icon: Icon, children }: { icon: LucideIcon; children: ReactNode }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 py-6 text-center">
            <span className="inline-flex size-10 items-center justify-center rounded-full bg-muted">
                <Icon className="size-5 text-muted-foreground" aria-hidden="true" />
            </span>
            <p className="max-w-[16rem] text-sm text-muted-foreground">{children}</p>
        </div>
    );
}
