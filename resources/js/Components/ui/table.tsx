import type { HTMLAttributes, ReactNode, TdHTMLAttributes, ThHTMLAttributes } from 'react';
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
    return <thead className={cn('border-b border-border', className)} {...props} />;
}

export function TBody({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody className={cn('divide-y divide-border', className)} {...props} />;
}

export function TR({ className, ...props }: HTMLAttributes<HTMLTableRowElement>) {
    return <tr className={cn('transition-colors hover:bg-muted/50', className)} {...props} />;
}

export function TH({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
    return (
        <th
            className={cn(
                'px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}

export function TD({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
    return <td className={cn('px-4 py-3 align-middle', className)} {...props} />;
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
