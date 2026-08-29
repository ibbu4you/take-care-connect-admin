import { Loader2 } from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function Spinner({ className }: { className?: string }) {
    return <Loader2 className={cn('size-4 animate-spin text-muted-foreground', className)} aria-hidden="true" />;
}

export function PageLoader({ label = 'Loading' }: { label?: string }) {
    return (
        <div className="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground" role="status">
            <Spinner />
            {label}…
        </div>
    );
}

export interface AvatarProps {
    name: string;
    initials?: string;
    src?: string | null;
    size?: 'sm' | 'md' | 'lg';
    className?: string;
}

const AVATAR_SIZES = {
    sm: 'size-7 text-[11px]',
    md: 'size-9 text-xs',
    lg: 'size-12 text-sm',
} as const;

/**
 * Photo when there is one, initials when there is not.
 *
 * Initials are computed server-side (User::initials()) so the same person shows
 * the same two letters everywhere, including in places that only receive a name
 * string.
 */
export function Avatar({ name, initials, src, size = 'md', className }: AvatarProps) {
    const fallback = initials ?? name.slice(0, 2).toUpperCase();

    if (src) {
        return (
            <img
                src={src}
                alt={name}
                className={cn('shrink-0 rounded-full object-cover', AVATAR_SIZES[size], className)}
            />
        );
    }

    return (
        <span
            className={cn(
                'inline-flex shrink-0 select-none items-center justify-center rounded-full bg-primary/10 font-semibold uppercase text-primary',
                AVATAR_SIZES[size],
                className,
            )}
            // The name is already in the row beside it; announcing the initials
            // as well would read the person out twice.
            aria-hidden="true"
            title={name}
        >
            {fallback}
        </span>
    );
}

export interface EmptyStateProps {
    icon?: ComponentType<{ className?: string }>;
    title: string;
    description?: string;
    action?: ReactNode;
    className?: string;
}

/**
 * What a list shows when it has nothing in it.
 *
 * Always worth the space: an empty table with only headers reads as broken,
 * whereas "No interviews scheduled yet" plus the button that fixes it reads as
 * a starting point.
 */
export function EmptyState({ icon: Icon, title, description, action, className }: EmptyStateProps) {
    return (
        <div className={cn('flex flex-col items-center justify-center px-6 py-14 text-center', className)}>
            {Icon && (
                <span className="mb-3 inline-flex size-11 items-center justify-center rounded-xl bg-muted text-muted-foreground">
                    <Icon className="size-5" />
                </span>
            )}
            <p className="text-sm font-semibold text-foreground">{title}</p>
            {description && <p className="mt-1 max-w-sm text-sm text-muted-foreground">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

/** A horizontal rule with a caption, for splitting long forms into sections. */
export function SectionDivider({ label, className }: { label: string; className?: string }) {
    return (
        <div className={cn('flex items-center gap-3', className)}>
            <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</span>
            <span className="h-px flex-1 bg-border" />
        </div>
    );
}
