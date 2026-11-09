import { createContext, useContext, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

/**
 * Lets a page put its title and its main actions into the top bar.
 *
 * The layout owns the bar and is persistent across navigations; the page owns
 * the words. A portal is the seam between the two: the layout exposes two empty
 * elements, and a page that renders <TopbarHeading> fills them. A page that
 * does not simply leaves the bar with its icons, so nothing else has to change.
 */
export interface TopbarTargets {
    title: HTMLElement | null;
    actions: HTMLElement | null;
}

export const TopbarSlotContext = createContext<TopbarTargets>({ title: null, actions: null });

export function TopbarHeading({
    title,
    description,
    actions,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
}) {
    const targets = useContext(TopbarSlotContext);

    return (
        <>
            {targets.title &&
                createPortal(
                    <div className="min-w-0">
                        <h1 className="truncate text-lg font-medium leading-7 tracking-tight">{title}</h1>
                        {/* Two lines at most: some pages explain their scoping in a full sentence. */}
                        {description && (
                            <p className="hidden max-w-4xl text-sm leading-5 text-muted-foreground line-clamp-2 sm:[display:-webkit-box]">
                                {description}
                            </p>
                        )}
                    </div>,
                    targets.title,
                )}

            {targets.actions && actions && createPortal(actions, targets.actions)}
        </>
    );
}
