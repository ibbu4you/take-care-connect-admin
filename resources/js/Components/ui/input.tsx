import { forwardRef, type InputHTMLAttributes, type ReactNode, type TextareaHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/*
 | One focus state for every field: a blue border and a soft 3px halo.
 |
 | `ring-offset-0` matters. The global :focus-visible rule in app.css gives
 | buttons and links a 2px offset ring for keyboard users; inherited by a field
 | it opened a white gap between the border and the halo, which read as two
 | rings.
 */
const FIELD =
    'w-full rounded-lg border border-input bg-card text-sm text-foreground shadow-xs transition-[border-color,box-shadow] placeholder:text-muted-foreground hover:border-muted-foreground/40 focus-visible:border-primary focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-primary/15 focus-visible:ring-offset-0 disabled:cursor-not-allowed disabled:opacity-60';

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
    /** A leading icon, inset in the field. */
    icon?: ReactNode;
    /** Turns the border red and wires up aria-invalid. */
    invalid?: boolean;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(
    ({ className, icon, invalid, ...props }, ref) => {
        const field = (
            <input
                ref={ref}
                aria-invalid={invalid || undefined}
                className={cn(
                    FIELD,
                    'h-9 px-3 py-1',
                    icon && 'pl-9',
                    invalid && 'border-destructive focus-visible:border-destructive focus-visible:ring-destructive/15',
                    className,
                )}
                {...props}
            />
        );

        if (!icon) {
            return field;
        }

        return (
            <div className="relative">
                <span
                    className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground [&_svg]:size-4"
                    aria-hidden="true"
                >
                    {icon}
                </span>
                {field}
            </div>
        );
    },
);
Input.displayName = 'Input';

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
    invalid?: boolean;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(
    ({ className, invalid, rows = 4, ...props }, ref) => (
        <textarea
            ref={ref}
            rows={rows}
            aria-invalid={invalid || undefined}
            className={cn(
                FIELD,
                'px-3 py-2',
                invalid && 'border-destructive focus-visible:border-destructive focus-visible:ring-destructive/15',
                className,
            )}
            {...props}
        />
    ),
);
Textarea.displayName = 'Textarea';

export interface SelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
    invalid?: boolean;
}

/**
 * The native select, styled.
 *
 * Deliberately not a custom listbox: this app is full of short, known option
 * lists (stage, kind, assignee) where the platform control is more accessible,
 * works on a phone, and costs nothing.
 */
export const Select = forwardRef<HTMLSelectElement, SelectProps>(
    ({ className, invalid, children, ...props }, ref) => (
        <select
            ref={ref}
            aria-invalid={invalid || undefined}
            className={cn(
                FIELD,
                // The chevron lives in app.css (.select-field), not in a class
                // here. As an arbitrary Tailwind value it held spaces, so it was
                // never a valid class: the arrow never rendered, and
                // tailwind-merge misread it as a background colour and dropped
                // bg-card — which left every select on the browser's own fill,
                // a grey block in the dark theme.
                'select-field h-9 cursor-pointer appearance-none px-3 pr-9',
                invalid && 'border-destructive focus-visible:border-destructive focus-visible:ring-destructive/15',
                className,
            )}
            {...props}
        >
            {children}
        </select>
    ),
);
Select.displayName = 'Select';

export function Label({
    className,
    required,
    children,
    ...props
}: React.LabelHTMLAttributes<HTMLLabelElement> & { required?: boolean }) {
    return (
        <label className={cn('block text-sm font-medium text-foreground', className)} {...props}>
            {children}
            {required && (
                <span className="ml-0.5 text-destructive" aria-hidden="true">
                    *
                </span>
            )}
        </label>
    );
}

/**
 * Label, control and error as one unit.
 *
 * Bundled so the error can never drift away from the field it describes, which
 * is the usual way a long form ends up showing "The title field is required"
 * under the wrong input.
 */
export function Field({
    label,
    error,
    hint,
    required,
    htmlFor,
    className,
    children,
}: {
    label?: string;
    error?: string;
    hint?: string;
    required?: boolean;
    htmlFor?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('space-y-1.5', className)}>
            {label && (
                <Label htmlFor={htmlFor} required={required}>
                    {label}
                </Label>
            )}
            {children}
            {error ? (
                <p className="text-xs font-medium text-destructive">{error}</p>
            ) : (
                hint && <p className="text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}
