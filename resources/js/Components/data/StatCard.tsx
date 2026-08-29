import { Link } from '@inertiajs/react';
import type { ComponentType, ReactNode } from 'react';
import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

/*
 | Tints for the icon chip.
 |
 | Written out as literal class pairs rather than composed from a token name,
 | for the same reason config/stages.ts is: Tailwind scans source text, so
 | `bg-${tone}/10` is never generated.
 |
 | Each tone is a token, never a raw colour, so the whole row re-themes with the
 | rest of the app.
 */
const TONES = {
    primary: 'bg-primary/10 text-primary',
    success: 'bg-success/15 text-success',
    warning: 'bg-warning/15 text-warning',
    danger: 'bg-destructive/15 text-destructive',
    info: 'bg-info/15 text-info',
    violet: 'bg-chart-5/15 text-chart-5',
} as const;

export type StatTone = keyof typeof TONES;

export interface StatCardProps {
    label: string;
    value: ReactNode;
    icon: ComponentType<{ className?: string }>;
    /** The small line under the figure — context, not decoration. */
    sub?: ReactNode;
    tone?: StatTone;
    /** Makes the whole tile a link to the list it summarises. */
    href?: string;
    className?: string;
}

/**
 * One headline figure.
 *
 * The label is uppercase and small, the figure is large and `tabular` so a row
 * of tiles lines up on the digits rather than rippling.
 */
export function StatCard({ label, value, icon: Icon, sub, tone = 'primary', href, className }: StatCardProps) {
    const body = (
        <Card
            className={cn(
                'flex h-full items-center gap-3.5 p-4',
                href && 'transition-colors hover:border-primary/40 hover:shadow-card-hover',
                className,
            )}
        >
            <span
                className={cn(
                    'inline-flex size-10 shrink-0 items-center justify-center rounded-control',
                    TONES[tone],
                )}
                aria-hidden="true"
            >
                <Icon className="size-5" />
            </span>

            <div className="min-w-0">
                <p className="truncate text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    {label}
                </p>
                <p className="truncate text-xl font-bold leading-tight tabular">{value}</p>
                {sub && <p className="mt-0.5 truncate text-xs text-muted-foreground">{sub}</p>}
            </div>
        </Card>
    );

    return href ? (
        <Link href={href} className="block h-full">
            {body}
        </Link>
    ) : (
        body
    );
}

/**
 * The grid a row of tiles sits in.
 *
 * Column count is driven by how many tiles there are, so three tiles fill the
 * width in three columns instead of leaving a ragged gap where a fourth would
 * have been.
 */
export function StatGrid({ count, children }: { count: number; children: ReactNode }) {
    return (
        <div
            className={cn(
                'grid gap-4',
                count <= 1 && 'grid-cols-1',
                count === 2 && 'grid-cols-1 sm:grid-cols-2',
                count === 3 && 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
                count >= 4 && 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-4',
            )}
        >
            {children}
        </div>
    );
}
