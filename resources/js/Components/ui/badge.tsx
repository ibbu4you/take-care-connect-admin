import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';
import { stageMeta, type StageFamily } from '@/config/stages';
import { cn } from '@/lib/utils';

const badgeVariants = cva(
    'inline-flex h-6 items-center gap-1.5 whitespace-nowrap rounded-full border border-transparent px-2.5 text-xs font-medium',
    {
        variants: {
            tone: {
                default: 'bg-muted text-muted-foreground',
                primary: 'bg-primary/10 text-primary',
                success: 'bg-success/10 text-success',
                /* Waiting, not failing — see the note on --warning in app.css. */
                warning: 'bg-stage-review/10 text-stage-review',
                danger: 'bg-destructive/10 text-destructive',
                info: 'bg-info/10 text-info',
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
                'inline-flex h-6 items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 text-xs font-medium',
                meta.className,
                // The stage tint is the signal; an outline on top only adds noise.
                'border-transparent',
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
