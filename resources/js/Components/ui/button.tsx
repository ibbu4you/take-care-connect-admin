import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { Loader2 } from 'lucide-react';
import { forwardRef, type ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
    'inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-control text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                primary: 'bg-primary text-primary-foreground hover:bg-primary-dark',
                secondary: 'bg-surface text-foreground hover:bg-border/60',
                outline: 'border border-input bg-card hover:bg-muted hover:text-foreground',
                ghost: 'hover:bg-muted hover:text-foreground',
                /*
                 | The brand red. Reserved for actions that destroy something —
                 | it is the same colour the public site uses for its donate
                 | CTA, so using it for an ordinary submit would make every
                 | form look like a warning.
                 */
                destructive: 'bg-destructive text-destructive-foreground hover:bg-accent-dark',
                link: 'text-primary underline-offset-4 hover:underline',
            },
            size: {
                sm: 'h-8 px-3 text-xs',
                md: 'h-9 px-4',
                lg: 'h-10 px-6',
                icon: 'size-9',
            },
        },
        defaultVariants: {
            variant: 'primary',
            size: 'md',
        },
    },
);

export interface ButtonProps
    extends ButtonHTMLAttributes<HTMLButtonElement>,
        VariantProps<typeof buttonVariants> {
    /** Render as the child element — for a <Link> that should look like a button. */
    asChild?: boolean;
    /** Swaps the leading icon for a spinner and blocks further clicks. */
    loading?: boolean;
}

/**
 * The one button.
 *
 * `loading` also disables, because the double-submit it prevents is the whole
 * reason a spinner is on a button at all — showing one while the click still
 * lands would create the duplicate record it is meant to stop.
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
    ({ className, variant, size, asChild = false, loading = false, disabled, children, ...props }, ref) => {
        // Slot forwards to a single child, so it cannot also render a spinner
        // alongside it. A composed link never has a loading state anyway.
        if (asChild) {
            return (
                <Slot className={cn(buttonVariants({ variant, size }), className)} ref={ref} {...props}>
                    {children}
                </Slot>
            );
        }

        return (
            <button
                ref={ref}
                className={cn(buttonVariants({ variant, size }), className)}
                disabled={disabled || loading}
                {...props}
            >
                {loading && <Loader2 className="animate-spin" aria-hidden="true" />}
                {children}
            </button>
        );
    },
);

Button.displayName = 'Button';

export { buttonVariants };
