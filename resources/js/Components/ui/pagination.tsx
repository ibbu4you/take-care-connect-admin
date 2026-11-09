import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

interface PaginationProps {
    /** The paginator straight from the controller — no unwrapping needed. */
    paginator: Paginated<unknown>;
    className?: string;
}

/**
 * Server-side pagination, driven straight from Laravel's paginator payload.
 *
 * Takes the whole paginator rather than picked-apart pieces, because the shape
 * is fixed by the framework and every caller would otherwise repeat the same
 * two-prop split — which is exactly how a page ends up passing `meta` from a
 * payload that has no `meta`.
 *
 * Renders nothing at all on a single page: a lone disabled "1" is furniture
 * that tells the reader nothing.
 */
export function Pagination({ paginator, className }: PaginationProps) {
    const { links, last_page: lastPage, from, to, total } = paginator;

    if (lastPage <= 1) {
        return null;
    }

    // Laravel puts "Previous" and "Next" at the ends of the same array as the
    // numbers; they need different markup, so they are split out here.
    const numbered = links.slice(1, -1);
    const previous = links[0];
    const next = links[links.length - 1];

    return (
        <nav
            className={cn(
                'flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3',
                className,
            )}
            aria-label="Pagination"
        >
            <p className="text-xs text-muted-foreground">
                Showing <span className="font-medium text-foreground tabular">{from ?? 0}</span>–
                <span className="font-medium text-foreground tabular">{to ?? 0}</span> of{' '}
                <span className="font-medium text-foreground tabular">{total}</span>
            </p>

            <div className="flex items-center gap-1">
                <Arrow url={previous?.url} label="Previous page">
                    <ChevronLeft className="size-4" />
                </Arrow>

                {numbered.map((link, index) => (
                    <PageLink key={`${link.label}-${index}`} url={link.url} active={link.active} label={link.label} />
                ))}

                <Arrow url={next?.url} label="Next page">
                    <ChevronRight className="size-4" />
                </Arrow>
            </div>
        </nav>
    );
}

const CELL = 'inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-xs font-medium';

function PageLink({ url, active, label }: { url: string | null; active: boolean; label: string }) {
    // Laravel emits "..." as an unlinked label for elided page ranges.
    if (!url) {
        return (
            <span className={cn(CELL, 'text-muted-foreground')} aria-hidden="true">
                {label}
            </span>
        );
    }

    return (
        <Link
            href={url}
            // Only the current page's own scroll position is worth keeping; the
            // list itself must jump back to the top on a page change.
            preserveScroll={false}
            aria-current={active ? 'page' : undefined}
            className={cn(
                CELL,
                'tabular transition-colors',
                active
                    ? 'bg-primary text-primary-foreground'
                    : 'text-foreground hover:bg-muted',
            )}
        >
            {label}
        </Link>
    );
}

function Arrow({ url, label, children }: { url: string | null; label: string; children: React.ReactNode }) {
    if (!url) {
        return (
            <span className={cn(CELL, 'cursor-not-allowed text-muted-foreground/50')} aria-hidden="true">
                {children}
            </span>
        );
    }

    return (
        <Link href={url} aria-label={label} className={cn(CELL, 'text-foreground transition-colors hover:bg-muted')}>
            {children}
        </Link>
    );
}
