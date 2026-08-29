import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export interface PageHeaderProps {
    title: string;
    /**
     * One sentence saying what this screen is for and who it is for.
     *
     * Worth writing on every list page: the difference between "Interviews" and
     * "Interviews you are the anchor on" is the difference between someone
     * filing a bug about missing rows and someone understanding the scoping.
     */
    subtitle?: ReactNode;
    actions?: ReactNode;
    className?: string;
}

export function PageHeader({ title, subtitle, actions, className }: PageHeaderProps) {
    return (
        <div className={cn('flex flex-wrap items-start justify-between gap-4', className)}>
            <div className="min-w-0">
                <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">{title}</h1>
                {subtitle && <p className="mt-1 max-w-3xl text-sm text-muted-foreground">{subtitle}</p>}
            </div>

            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
