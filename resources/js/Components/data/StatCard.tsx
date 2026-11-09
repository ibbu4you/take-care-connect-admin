import { Link } from '@inertiajs/react';
import type { ComponentType, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/*
 | Tints for the icon circle.
 |
 | Written out as literal class pairs rather than composed from a token name,
 | for the same reason config/stages.ts is: Tailwind scans source text, so
 | `bg-${tone}/10` is never generated.
 */
const TONES = {
    primary: 'bg-primary/10 text-primary',
    success: 'bg-success/10 text-success',
    warning: 'bg-stage-review/10 text-stage-review',
    danger: 'bg-destructive/10 text-destructive',
    info: 'bg-info/10 text-info',
    violet: 'bg-chart-5/10 text-chart-5',
} as const;

export type StatTone = keyof typeof TONES;

export interface StatCardProps {
    label: string;
    value: ReactNode;
    icon: ComponentType<{ className?: string }>;
    /** The small line under the figure — context, not decoration. */
    sub?: ReactNode;
    tone?: StatTone;
    /** Makes the whole cell a link to the list it summarises. */
    href?: string;
    className?: string;
}

/**
 * One headline figure: a cell inside a {@see StatGrid} strip.
 *
 * The label is uppercase and small, the figure large and `tabular` so a row of
 * them lines up on the digits rather than rippling.
 */
export function StatCard({ label, value, icon: Icon, sub, tone = 'primary', href, className }: StatCardProps) {
    const body = (
        <>
            {/* Dropped on a phone, where two cells share a row and the label needs the width. */}
            <span
                className={cn('hidden size-10 shrink-0 items-center justify-center rounded-full sm:inline-flex', TONES[tone])}
                aria-hidden="true"
            >
                <Icon className="size-5" />
            </span>

            <div className="min-w-0">
                <p className="truncate text-[11px] font-medium uppercase tracking-[0.04em] text-muted-foreground">
                    {label}
                </p>
                <p className="truncate text-2xl font-medium leading-8 tracking-tight tabular">{value}</p>
                {sub && <p className="truncate text-xs text-muted-foreground">{sub}</p>}
            </div>
        </>
    );

    const cell = cn('flex h-full min-w-0 items-center gap-3 bg-card p-3 sm:p-4', className);

    return href ? (
        <Link href={href} className={cn(cell, 'transition-colors hover:bg-muted/60')}>
            {body}
        </Link>
    ) : (
        <div className={cell}>{body}</div>
    );
}

/**
 * The strip a row of figures sits in: one card, split by hairlines.
 *
 * The hairlines are the 1px gaps of a grid laid over a border-coloured
 * background, so they stay correct however the cells wrap — two by two on a
 * phone, in a single row on a wide screen. Column counts are chosen so a row is
 * never left with an empty cell.
 */
export function StatGrid({ count, children }: { count: number; children: ReactNode }) {
    return (
        <section
            aria-label="Summary"
            className={cn(
                'grid gap-px overflow-hidden rounded-xl border border-border bg-border shadow-xs',
                count <= 1 && 'grid-cols-1',
                count === 2 && 'grid-cols-2',
                count === 3 && 'grid-cols-1 sm:grid-cols-3',
                count === 4 && 'grid-cols-2 xl:grid-cols-4',
                count >= 5 && 'grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6',
            )}
        >
            {children}
        </section>
    );
}
