import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { TopbarHeading } from './TopbarSlot';

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

/**
 * A page's title, description and main actions — rendered in the top bar.
 *
 * Every page used to repeat its title as a large heading under the bar, which
 * spent a fifth of a laptop screen saying the same thing twice. Now the bar is
 * the heading. On a phone the bar has no room for the description or the
 * buttons, so those two render here, in the page, instead.
 */
export function PageHeader({ title, subtitle, actions, className }: PageHeaderProps) {
    return (
        <>
            <TopbarHeading title={title} description={subtitle} actions={actions} />

            {(subtitle || actions) && (
                <div className={cn('phone-only space-y-3', className)}>
                    {subtitle && <p className="text-sm text-muted-foreground">{subtitle}</p>}
                    {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
                </div>
            )}
        </>
    );
}
