import { Link, router, usePage } from '@inertiajs/react';
import { BadgeCheck, ChevronRight, LogOut, Moon, Sun, UserRound, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Avatar } from '@/Components/ui/misc';
import { activeHref, NAV, visibleNav, type NavItem } from '@/config/nav';
import { usePermissions } from '@/hooks/usePermissions';
import { useTheme } from '@/lib/theme';
import { cn } from '@/lib/utils';
import { Logo } from './Logo';

interface SidebarProps {
    open: boolean;
    onClose: () => void;
    /** Live counts for items that declare a `badge` in the nav manifest. */
    counts?: Partial<Record<NonNullable<NavItem['badge']>, number>>;
}

/**
 * The navigation rail.
 *
 * A fixed 272px column from `lg:` up, and a slide-over sheet below it. White,
 * separated from the page by a hairline; the row you are on is a grey pill with
 * a blue marker on the rail's edge, so the only saturated colour in the rail is
 * where you are. The account sits at the foot, where it stays in reach without
 * taking space from the page's own header.
 */
export function Sidebar({ open, onClose, counts = {} }: SidebarProps) {
    const { can } = usePermissions();
    const { url } = usePage();

    const groups = useMemo(() => visibleNav(can), [can]);

    // Computed against the whole manifest, not per group, so the longest-prefix
    // rule can compare items that live in different sections.
    const current = useMemo(() => activeHref(url, NAV), [url]);

    return (
        <>
            {/* Scrim. Only exists on mobile, where the sheet overlays content. */}
            {open && (
                <div
                    className="fixed inset-0 z-40 bg-overlay/50 backdrop-blur-sm lg:hidden"
                    onClick={onClose}
                    aria-hidden="true"
                />
            )}

            <aside
                className={cn(
                    'fixed inset-y-0 left-0 z-50 flex w-[272px] flex-col border-r border-sidebar-border bg-sidebar transition-transform duration-200 lg:translate-x-0',
                    open ? 'translate-x-0' : '-translate-x-full',
                )}
                aria-label="Main navigation"
            >
                <div className="flex shrink-0 items-center gap-3 px-5 py-6">
                    <Link href="/dashboard" className="flex min-w-0 items-center gap-3">
                        <Logo size={40} />
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-medium leading-5 text-sidebar-heading">
                                Take Care Connect
                            </span>
                            <span className="block truncate text-xs leading-4 text-sidebar-foreground">
                                Operations
                            </span>
                        </span>
                    </Link>

                    <button
                        type="button"
                        onClick={onClose}
                        className="ml-auto rounded-lg p-1.5 text-sidebar-foreground transition-colors hover:bg-sidebar-hover lg:hidden"
                        aria-label="Close navigation"
                    >
                        <X className="size-5" />
                    </button>
                </div>

                <div className="mx-5 h-px shrink-0 bg-sidebar-border" aria-hidden="true" />

                <nav className="scrollbar-slim flex-1 space-y-5 overflow-y-auto px-5 py-5">
                    {groups.map((group) => (
                        <div key={group.section}>
                            <p className="px-1 pb-2 text-xs font-medium uppercase tracking-[0.04em] text-sidebar-section">
                                {group.section}
                            </p>

                            <ul className="space-y-1">
                                {group.items.map((item) => (
                                    <li key={item.href}>
                                        <NavRow
                                            item={item}
                                            active={current === item.href}
                                            onNavigate={onClose}
                                            unread={item.badge ? (counts[item.badge] ?? 0) : 0}
                                        />
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </nav>

                <div className="mx-5 h-px shrink-0 bg-sidebar-border" aria-hidden="true" />

                <AccountCard />
            </aside>
        </>
    );
}

function NavRow({
    item,
    active,
    onNavigate,
    unread,
}: {
    item: NavItem;
    active: boolean;
    onNavigate: () => void;
    unread: number;
}) {
    const Icon = item.icon;

    const shell = 'relative flex h-9 items-center gap-2 rounded-lg px-3 text-sm font-medium transition-colors';

    /*
     | A page that exists in the manifest but is not built yet renders as inert
     | text with a "soon" pill, rather than being hidden — the team can see the
     | shape of what is coming, and nobody files "the link 404s".
     */
    if (item.soon) {
        return (
            <span className={cn(shell, 'cursor-default text-sidebar-foreground/50')} aria-disabled="true">
                <Icon className="size-5 shrink-0" aria-hidden="true" />
                <span className="truncate">{item.label}</span>
                <span className="ml-auto rounded-full bg-sidebar-deep px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sidebar-section">
                    soon
                </span>
            </span>
        );
    }

    return (
        <Link
            href={item.href}
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            className={cn(
                shell,
                active
                    ? 'bg-sidebar-active text-sidebar-active-foreground'
                    : 'text-sidebar-foreground hover:bg-sidebar-hover hover:text-foreground',
            )}
        >
            {/* The marker on the rail's outer edge, outside the pill. */}
            {active && (
                <span
                    className="absolute -left-5 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-primary"
                    aria-hidden="true"
                />
            )}

            <Icon className={cn('size-5 shrink-0', active && 'text-primary')} aria-hidden="true" />
            <span className="truncate">{item.label}</span>

            {item.badge && unread > 0 ? (
                <span className="ml-auto rounded-full bg-destructive px-1.5 py-0.5 text-[10px] font-semibold text-destructive-foreground tabular">
                    {unread > 99 ? '99+' : unread}
                </span>
            ) : (
                active && <ChevronRight className="ml-auto size-4 shrink-0 text-sidebar-section" aria-hidden="true" />
            )}
        </Link>
    );
}

/**
 * Who is signed in, and the menu for their own account.
 *
 * The menu opens upwards, since the card is pinned to the bottom of the rail.
 */
function AccountCard() {
    const { user } = usePermissions();
    const { theme, toggle } = useTheme();
    const [open, setOpen] = useState(false);
    const container = useRef<HTMLDivElement>(null);

    // Close on an outside click or on Escape. Both, because a menu that only
    // handles one of them is the kind of thing people notice.
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
        <div ref={container} className="relative shrink-0 p-3">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                className="flex w-full items-center gap-3 rounded-xl p-3 text-left transition-colors hover:bg-sidebar-hover"
                aria-haspopup="menu"
                aria-expanded={open}
            >
                <Avatar name={user.name} initials={user.initials} src={user.avatar_url} size="md" className="size-10" />

                <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-1">
                        <span className="truncate text-sm font-medium leading-5 text-sidebar-heading">{user.name}</span>
                        <BadgeCheck className="size-4 shrink-0 fill-primary text-sidebar" aria-hidden="true" />
                    </span>
                    <span className="block truncate text-xs leading-4 text-sidebar-foreground">{user.email}</span>
                </span>

                <ChevronRight className="size-5 shrink-0 text-sidebar-foreground" aria-hidden="true" />
            </button>

            {open && (
                <div
                    role="menu"
                    className="absolute inset-x-3 bottom-full z-50 mb-1 overflow-hidden rounded-xl border border-border bg-popover text-popover-foreground shadow-card-hover"
                >
                    {user.roles.length > 0 && (
                        <div className="flex flex-wrap gap-1 border-b border-border px-4 py-3">
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

                    <div className="p-1">
                        <Link
                            href="/profile"
                            role="menuitem"
                            className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors hover:bg-muted"
                            onClick={() => setOpen(false)}
                        >
                            <UserRound className="size-4 text-muted-foreground" aria-hidden="true" />
                            My profile
                        </Link>

                        <button
                            type="button"
                            role="menuitem"
                            onClick={toggle}
                            className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm transition-colors hover:bg-muted"
                        >
                            {theme === 'dark' ? (
                                <Sun className="size-4 text-muted-foreground" aria-hidden="true" />
                            ) : (
                                <Moon className="size-4 text-muted-foreground" aria-hidden="true" />
                            )}
                            {theme === 'dark' ? 'Light theme' : 'Dark theme'}
                        </button>

                        {/*
                          | A POST, not a link: logging out changes state, so it
                          | must not be reachable by a prefetch, a crawler or a
                          | link-scanning antivirus.
                        */}
                        <button
                            type="button"
                            role="menuitem"
                            onClick={() => router.post('/logout')}
                            className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm text-destructive transition-colors hover:bg-destructive/10"
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
