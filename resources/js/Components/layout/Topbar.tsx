import { Link, router, usePage } from '@inertiajs/react';
import { Bell, LogOut, Menu, Moon, Search, Sun, UserRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Avatar } from '@/Components/ui/misc';
import { usePermissions } from '@/hooks/usePermissions';
import { useTheme } from '@/lib/theme';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

interface TopbarProps {
    onOpenNav: () => void;
    onOpenSearch: () => void;
}

export function Topbar({ onOpenNav, onOpenSearch }: TopbarProps) {
    const { user } = usePermissions();
    const { theme, toggle } = useTheme();

    return (
        <header className="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-border bg-card/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-card/80 lg:px-6">
            <button
                type="button"
                onClick={onOpenNav}
                className="rounded-control p-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground lg:hidden"
                aria-label="Open navigation"
            >
                <Menu className="size-5" />
            </button>

            {/*
              | The search field is a button, not an input.
              |
              | Typing here opens the command palette, so making it a real input
              | would mean capturing the first keystroke and replaying it into a
              | different field — which drops characters on a slow render.
            */}
            <button
                type="button"
                onClick={onOpenSearch}
                className="flex h-9 max-w-md flex-1 items-center gap-2 rounded-control border border-input bg-app px-3 text-sm text-muted-foreground transition-colors hover:border-primary/40 hover:text-foreground"
            >
                <Search className="size-4 shrink-0" aria-hidden="true" />
                <span className="truncate">Search…</span>
                <kbd className="ml-auto hidden shrink-0 rounded border border-border bg-card px-1.5 py-0.5 font-sans text-[10px] font-medium text-muted-foreground sm:inline-block">
                    Ctrl K
                </kbd>
            </button>

            <div className="ml-auto flex items-center gap-1">
                <button
                    type="button"
                    onClick={toggle}
                    className="rounded-control p-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
                >
                    {theme === 'dark' ? <Sun className="size-5" /> : <Moon className="size-5" />}
                </button>

                <NotificationBell />
                {user && <AccountMenu />}
            </div>
        </header>
    );
}

function NotificationBell() {
    // Read from the shared props rather than fetched: the server already knows
    // the number on every request, so asking for it again is a round trip for
    // an integer.
    const { props } = usePage<SharedProps>();
    const unread = props.notifications?.unread ?? 0;

    return (
        <span className="relative inline-flex">
            <Link
                href="/notifications"
                className="inline-flex rounded-control p-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
            >
                <Bell className="size-5" />
            </Link>

            {unread > 0 && (
                <span className="pointer-events-none absolute right-1 top-1 inline-flex min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold leading-4 text-destructive-foreground tabular">
                    {unread > 9 ? '9+' : unread}
                </span>
            )}
        </span>
    );
}

function AccountMenu() {
    const { user } = usePermissions();
    const [open, setOpen] = useState(false);
    const container = useRef<HTMLDivElement>(null);

    // Close on an outside click or on Escape. Both, because a dropdown that
    // only handles one of them is the kind of thing people notice.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointerDown = (event: PointerEvent) => {
            if (!container.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    if (!user) {
        return null;
    }

    return (
        <div ref={container} className="relative">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                className="ml-1 rounded-full transition-opacity hover:opacity-80"
                aria-haspopup="menu"
                aria-expanded={open}
                aria-label="Account menu"
            >
                <Avatar name={user.name} initials={user.initials} src={user.avatar_url} />
            </button>

            {open && (
                <div
                    role="menu"
                    className="absolute right-0 top-full z-50 mt-2 w-60 overflow-hidden rounded-xl border border-border bg-popover text-popover-foreground shadow-card-hover"
                >
                    <div className="border-b border-border px-4 py-3">
                        <p className="truncate text-sm font-semibold">{user.name}</p>
                        <p className="truncate text-xs text-muted-foreground">{user.email}</p>

                        {user.roles.length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-1">
                                {user.roles.map((role) => (
                                    <span
                                        key={role}
                                        className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold text-primary"
                                    >
                                        {role}
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="p-1">
                        <Link
                            href="/profile"
                            className={cn(
                                'flex items-center gap-2 rounded-control px-3 py-2 text-sm transition-colors hover:bg-muted',
                            )}
                            onClick={() => setOpen(false)}
                        >
                            <UserRound className="size-4 text-muted-foreground" aria-hidden="true" />
                            My profile
                        </Link>

                        {/*
                          | A POST, not a link.
                          |
                          | Logging out changes state, so it must not be
                          | reachable by a prefetch, a crawler or somebody's
                          | link-scanning antivirus.
                        */}
                        <button
                            type="button"
                            onClick={() => router.post('/logout')}
                            className="flex w-full items-center gap-2 rounded-control px-3 py-2 text-left text-sm text-destructive transition-colors hover:bg-destructive/10"
                        >
                            <LogOut className="size-4" aria-hidden="true" />
                            Sign out
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
