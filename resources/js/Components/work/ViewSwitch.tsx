import { Link } from '@inertiajs/react';
import { KanbanSquare, LayoutList } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Board | List, as one control — two views of the same work.
 *
 * The current view is a plain label marked aria-current; the other one is the
 * link, so the control never offers to navigate to where you already are.
 */
export function ViewSwitch({ current, boardHref, listHref }: { current: 'board' | 'list'; boardHref: string; listHref: string }) {
    const options = [
        { key: 'board' as const, label: 'Board', href: boardHref, icon: KanbanSquare },
        { key: 'list' as const, label: 'List', href: listHref, icon: LayoutList },
    ];

    return (
        <div className="inline-flex h-10 items-center rounded-lg border border-border bg-muted p-1" role="group" aria-label="View">
            {options.map(({ key, label, href, icon: Icon }) =>
                key === current ? (
                    <span
                        key={key}
                        className="inline-flex h-8 items-center gap-1.5 rounded-md bg-card px-3 text-sm font-medium shadow-xs"
                        aria-current="page"
                    >
                        <Icon className="size-4 text-primary" aria-hidden="true" />
                        {label}
                    </span>
                ) : (
                    <Link
                        key={key}
                        href={href}
                        className={cn(
                            'inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground',
                        )}
                    >
                        <Icon className="size-4" aria-hidden="true" />
                        {label}
                    </Link>
                ),
            )}
        </div>
    );
}
