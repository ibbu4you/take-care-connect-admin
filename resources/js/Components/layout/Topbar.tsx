import { Link, usePage } from '@inertiajs/react';
import { Bell, ChevronRight, Menu, Search } from 'lucide-react';
import { useMemo } from 'react';
import { activeHref, NAV } from '@/config/nav';
import type { SharedProps } from '@/types';

interface TopbarProps {
    onOpenNav: () => void;
    onOpenSearch: () => void;
    /** Where a page's <TopbarHeading> renders its title. */
    titleRef: (element: HTMLDivElement | null) => void;
    /** Where a page's <TopbarHeading> renders its buttons. */
    actionsRef: (element: HTMLDivElement | null) => void;
}

const iconButton =
    'inline-flex size-10 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground';

/**
 * The page header bar: the page's title on the left, then search and
 * notifications, then the page's own primary actions.
 *
 * The title and actions are portalled in by the page (see TopbarSlot.tsx), so
 * the bar itself stays mounted across navigations while its words change.
 */
export function Topbar({ onOpenNav, onOpenSearch, titleRef, actionsRef }: TopbarProps) {
    return (
        <header className="sticky top-0 z-30 flex h-[72px] shrink-0 items-center gap-3 border-b border-border bg-card/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-card/80 lg:h-[88px] lg:px-8">
            <button type="button" onClick={onOpenNav} className={`${iconButton} lg:hidden`} aria-label="Open navigation">
                <Menu className="size-5" />
            </button>

            {/*
              | A page that renders <TopbarHeading> fills the target with its
              | own title; until one does, a breadcrumb says where you are, so
              | the bar is never an empty strip above the page's own heading.
            */}
            <div className="group flex min-w-0 flex-1 items-center">
                <Breadcrumb />
                <div ref={titleRef} className="min-w-0 flex-1 empty:hidden" />
            </div>

            <div className="flex shrink-0 items-center gap-1">
                {/*
                  | A button, not an input: it opens the command palette (also on
                  | Ctrl/Cmd-K), and a real input here would have to capture the
                  | first keystroke and replay it into a different field.
                */}
                <button type="button" onClick={onOpenSearch} className={iconButton} aria-label="Search (Ctrl K)">
                    <Search className="size-5" />
                </button>

                <NotificationBell />
            </div>

            <div ref={actionsRef} className="hidden shrink-0 items-center gap-3 empty:hidden sm:flex sm:pl-2" />
        </header>
    );
}

function Breadcrumb() {
    const { url } = usePage();
    const item = useMemo(() => {
        const href = activeHref(url, NAV);
        return NAV.find((entry) => entry.href === href);
    }, [url]);

    if (!item) {
        return null;
    }

    return (
        <p className="flex min-w-0 items-center gap-1.5 text-sm group-has-[h1]:hidden">
            <span className="truncate text-muted-foreground">{item.section}</span>
            <ChevronRight className="size-4 shrink-0 text-muted-foreground/60" aria-hidden="true" />
            <span className="truncate font-medium">{item.label}</span>
        </p>
    );
}

function NotificationBell() {
    // Read from the shared props rather than fetched: the server already knows
    // the number on every request, so asking again is a round trip for an int.
    const { props } = usePage<SharedProps>();
    const unread = props.notifications?.unread ?? 0;

    return (
        <Link
            href="/notifications"
            className={`${iconButton} relative`}
            aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
        >
            <Bell className="size-5" />

            {unread > 0 && (
                <span
                    className="pointer-events-none absolute right-2.5 top-2.5 size-2 rounded-full bg-destructive ring-2 ring-card"
                    aria-hidden="true"
                />
            )}
        </Link>
    );
}
