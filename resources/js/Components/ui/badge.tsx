import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';
import { stageMeta, type StageFamily } from '@/config/stages';
import { cn } from '@/lib/utils';

const badgeVariants = cva(
    'inline-flex items-center gap-1.5 whitespace-nowrap rounded-control border px-2 py-0.5 text-xs font-medium',
    {
        variants: {
            tone: {
                default: 'border-border bg-muted text-muted-foreground',
                primary: 'border-primary/30 bg-primary/10 text-primary',
                success: 'border-success/30 bg-success/15 text-success',
                /* Waiting, not failing — see the note on --warning in app.css. */
                warning: 'border-warning/30 bg-warning/15 text-warning',
                danger: 'border-destructive/30 bg-destructive/15 text-destructive',
                info: 'border-info/30 bg-info/15 text-info',
                outline: 'border-border bg-transparent text-foreground',
            },
        },
        defaultVariants: {
            tone: 'default',
        },
    },
);

export interface BadgeProps
    extends HTMLAttributes<HTMLSpanElement>,
        VariantProps<typeof badgeVariants> {}

export function Badge({ className, tone, ...props }: BadgeProps) {
    return <span className={cn(badgeVariants({ tone }), className)} {...props} />;
}

export interface StageBadgeProps extends HTMLAttributes<HTMLSpanElement> {
    /** Which workflow the stage belongs to — see config/stages.ts. */
    family: StageFamily;
    stage: string | null | undefined;
    /** Show the colour swatch as well as the label. */
    dot?: boolean;
}

/**
 * A stage as a chip.
 *
 * Takes the family rather than deriving it, because `published` and `approved`
 * exist in more than one workflow and guessing would eventually label a reel
 * with an article's wording.
 */
export function StageBadge({ family, stage, dot = false, className, ...props }: StageBadgeProps) {
    const meta = stageMeta(family, stage);

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 whitespace-nowrap rounded-control border px-2 py-0.5 text-xs font-medium',
                meta.className,
                className,
            )}
            {...props}
        >
            {dot && <span className={cn('size-1.5 rounded-full', meta.dot)} aria-hidden="true" />}
            {meta.label}
        </span>
    );
}

export { badgeVariants };
