import { router } from '@inertiajs/react';
import type { HTMLAttributes, MouseEvent, ReactNode, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * Table primitives.
 *
 * Deliberately thin: each list page writes its own markup rather than
 * configuring a generic <DataTable columns={...}>. The columns here differ a lot
 * page to page — two-line cells, avatar stacks, stage chips, inline actions —
 * and a config-driven table ends up with an escape hatch per column anyway.
 *
 * The rule every list follows: a real <table> from `md:` up, and a stacked card
 * list below it. A table squeezed onto a phone is unreadable, and horizontal
 * scrolling hides the column that matters.
 */

/** Wrap a <Table> in this so wide content scrolls inside the card, not the page. */
export function TableWrap({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('w-full overflow-x-auto', className)} {...props} />;
}

export function Table({ className, ...props }: HTMLAttributes<HTMLTableElement>) {
    return <table className={cn('w-full caption-bottom text-sm', className)} {...props} />;
}

export function THead({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <thead className={cn('border-b border-border bg-muted/70', className)} {...props} />;
}

export function TBody({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody className={cn('divide-y divide-border', className)} {...props} />;
}

/** Anything inside a row that already does something on click keeps doing it. */
const INTERACTIVE = 'a, button, input, select, textarea, label, summary, [role="button"], [data-row-ignore]';

/**
 * A table row. Give it `href` and the whole row opens that record.
 *
 * ── Why a click handler and not a link ────────────────────────────────────
 * A <tr> cannot be wrapped in an <a>, and stretching a link over the row with
 * an overlay swallows the clicks meant for the buttons and secondary links in
 * its other cells. So the row listens for a click and navigates, but only when
 * the click did not land on something interactive, and not when the reader was
 * selecting text to copy. Ctrl/⌘-click opens a new tab, as on a real link.
 *
 * The primary cell keeps its own <Link>, which is what a keyboard or a screen
 * reader uses; the row's handler is the mouse convenience on top.
 */
export function TR({
    className,
    href,
    onClick,
    ...props
}: HTMLAttributes<HTMLTableRowElement> & { href?: string }) {
    const handleClick = (event: MouseEvent<HTMLTableRowElement>) => {
        onClick?.(event);

        if (!href || event.defaultPrevented) {
            return;
        }

        if ((event.target as HTMLElement).closest(INTERACTIVE)) {
            return;
        }

        if (window.getSelection()?.toString()) {
            return;
        }

        if (event.metaKey || event.ctrlKey) {
            window.open(href, '_blank', 'noopener');
            return;
        }

        router.visit(href);
    };

    return (
        <tr
            className={cn('transition-colors hover:bg-muted/40', href && 'cursor-pointer', className)}
            onClick={href || onClick ? handleClick : undefined}
            {...props}
        />
    );
}

export function TH({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
    return (
        <th
            className={cn(
                'h-10 whitespace-nowrap px-4 text-left text-xs font-medium text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function TD({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
    return <td className={cn('px-4 py-3.5 align-middle', className)} {...props} />;
}

/**
 * The two-line cell used all over this app: something identifying on top,
 * something contextual underneath.
 */
export function CellStack({ primary, secondary }: { primary: ReactNode; secondary?: ReactNode }) {
    return (
        <div className="min-w-0">
            <div className="truncate font-medium text-foreground">{primary}</div>
            {secondary && <div className="truncate text-xs text-muted-foreground">{secondary}</div>}
        </div>
    );
}
