import { Check, Minus } from 'lucide-react';
import { forwardRef, useEffect, useRef, type InputHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
    /** Neither on nor off — a group where only some children are ticked. */
    indeterminate?: boolean;
}

/**
 * A checkbox that can show three states.
 *
 * `indeterminate` is a DOM property, not an attribute, so React cannot set it
 * through JSX — it has to be written to the node. The permission matrix leans on
 * this for its "some of this module is granted" module headers.
 */
export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(
    ({ className, indeterminate = false, checked, disabled, ...props }, forwardedRef) => {
        // Explicitly nullable so this is a mutable ref: the callback ref below
        // writes to it, and useRef<T>(null) alone yields a read-only RefObject.
        const inputRef = useRef<HTMLInputElement | null>(null);

        useEffect(() => {
            if (inputRef.current) {
                inputRef.current.indeterminate = indeterminate;
            }
        }, [indeterminate]);

        return (
            <span className={cn('relative inline-flex size-4 shrink-0 items-center justify-center', className)}>
                <input
                    ref={(node) => {
                        inputRef.current = node;

                        if (typeof forwardedRef === 'function') {
                            forwardedRef(node);
                        } else if (forwardedRef) {
                            forwardedRef.current = node;
                        }
                    }}
                    type="checkbox"
                    checked={checked}
                    disabled={disabled}
                    className="peer size-4 cursor-pointer appearance-none rounded-[4px] border border-input bg-card transition-colors checked:border-primary checked:bg-primary indeterminate:border-primary indeterminate:bg-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50"
                    {...props}
                />

                {/* Drawn over the input rather than inside it — an <input> has no children. */}
                <span
                    className="pointer-events-none absolute inset-0 hidden items-center justify-center text-primary-foreground peer-checked:flex"
                    aria-hidden="true"
                >
                    <Check className="size-3" strokeWidth={3} />
                </span>
                <span
                    className="pointer-events-none absolute inset-0 hidden items-center justify-center text-primary-foreground peer-indeterminate:flex peer-checked:peer-indeterminate:flex"
                    aria-hidden="true"
                >
                    <Minus className="size-3" strokeWidth={3} />
                </span>
            </span>
        );
    },
);
Checkbox.displayName = 'Checkbox';

export interface SwitchProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'size'> {
    label?: string;
    description?: string;
}

/**
 * A toggle for a setting that takes effect immediately.
 *
 * Use a Checkbox where the change is only committed on save — a switch that
 * silently needs a save button afterwards is the usual reason somebody thinks
 * they deactivated an account and did not.
 */
export const Switch = forwardRef<HTMLInputElement, SwitchProps>(
    ({ className, label, description, disabled, ...props }, ref) => {
        const control = (
            <span className="relative inline-flex shrink-0">
                <input
                    ref={ref}
                    type="checkbox"
                    role="switch"
                    disabled={disabled}
                    className="peer h-5 w-9 cursor-pointer appearance-none rounded-full bg-border transition-colors checked:bg-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50"
                    {...props}
                />
                <span
                    className="pointer-events-none absolute left-0.5 top-0.5 size-4 rounded-full bg-card shadow-sm transition-transform peer-checked:translate-x-4"
                    aria-hidden="true"
                />
            </span>
        );

        if (!label) {
            return <span className={className}>{control}</span>;
        }

        return (
            <label className={cn('flex cursor-pointer items-start gap-3', disabled && 'cursor-not-allowed opacity-60', className)}>
                {control}
                <span className="min-w-0">
                    <span className="block text-sm font-medium text-foreground">{label}</span>
                    {description && <span className="block text-xs text-muted-foreground">{description}</span>}
                </span>
            </label>
        );
    },
);
Switch.displayName = 'Switch';
