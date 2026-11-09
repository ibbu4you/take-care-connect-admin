import { usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { CommandPalette } from '@/Components/layout/CommandPalette';
import { Sidebar } from '@/Components/layout/Sidebar';
import { Topbar } from '@/Components/layout/Topbar';
import { TopbarSlotContext } from '@/Components/layout/TopbarSlot';
import { useToast } from '@/Components/ui/toast';
import type { SharedProps } from '@/types';

/**
 * The shell every authenticated page sits in.
 *
 * Assigned as a *persistent* layout (`Page.layout = ...` at the bottom of each
 * page) rather than wrapped around the page's own JSX. That is what keeps the
 * sidebar and topbar mounted across an Inertia navigation — otherwise the rail
 * unmounts and remounts on every click, the scroll position of a long menu
 * resets, and any open dropdown closes mid-interaction.
 */
export function AdminLayout({ children }: { children: ReactNode }) {
    const [navOpen, setNavOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);

    // The top bar's two portal targets, held in state rather than refs so a page
    // rendering <TopbarHeading> re-renders once they exist.
    const [titleTarget, setTitleTarget] = useState<HTMLElement | null>(null);
    const [actionsTarget, setActionsTarget] = useState<HTMLElement | null>(null);
    const slots = useMemo(() => ({ title: titleTarget, actions: actionsTarget }), [titleTarget, actionsTarget]);
    const { url, props } = usePage<SharedProps>();
    const { notify } = useToast();

    // Close the mobile sheet on navigation. Without this, tapping a link leaves
    // the sheet covering the page it just opened.
    useEffect(() => {
        setNavOpen(false);
    }, [url]);

    /*
     | Server-side flash messages become toasts.
     |
     | Keyed on the message text: Inertia reuses this component across
     | navigations, so an effect keyed on the props object alone would re-fire
     | and re-toast the same "Saved" on the next unrelated page load.
     */
    const { success, error, info } = props.flash ?? { success: null, error: null, info: null };

    useEffect(() => {
        if (success) notify(success, 'success');
    }, [success, notify]);

    useEffect(() => {
        if (error) notify(error, 'error');
    }, [error, notify]);

    useEffect(() => {
        if (info) notify(info, 'info');
    }, [info, notify]);

    // Ctrl/Cmd-K from anywhere. Bound here rather than in the palette so the
    // shortcut works while the palette is closed and unmounted.
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setSearchOpen(true);
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, []);

    return (
        <TopbarSlotContext.Provider value={slots}>
        <div className="min-h-screen bg-app">
            <a href="#main" className="skip-link">
                Skip to content
            </a>

            <Sidebar open={navOpen} onClose={() => setNavOpen(false)} />

            {/* Offset by the rail's width from lg: up, full width below it. */}
            <div className="lg:pl-[272px]">
                <Topbar
                    onOpenNav={() => setNavOpen(true)}
                    onOpenSearch={() => setSearchOpen(true)}
                    titleRef={setTitleTarget}
                    actionsRef={setActionsTarget}
                />

                {/*
                  | A ceiling on the content width.
                  |
                  | Without one, every page stretches to whatever the monitor
                  | is. On a wide screen that pushes a row of four tiles a
                  | metre apart and leaves the right-hand column of every list
                  | stranded from the left — which reads as an empty page
                  | rather than a spacious one.
                */}
                <main id="main" className="mx-auto w-full max-w-[120rem] px-4 py-6 lg:px-8 lg:py-6">
                    {children}
                </main>
            </div>

            <CommandPalette open={searchOpen} onClose={() => setSearchOpen(false)} />
        </div>
        </TopbarSlotContext.Provider>
    );
}

/**
 * Shorthand for the bottom of every page component:
 *
 *     Dashboard.layout = adminLayout;
 */
export const adminLayout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
