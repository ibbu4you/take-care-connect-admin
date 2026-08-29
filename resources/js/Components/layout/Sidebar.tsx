import { Link, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useMemo } from 'react';
import { activeHref, NAV, visibleNav, type NavItem } from '@/config/nav';
import { usePermissions } from '@/hooks/usePermissions';
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
 * A fixed 16rem column from `lg:` up, and a slide-over sheet below it. The rail
 * is light rather than dark on purpose — see the note on --sidebar in app.css.
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
                    'fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-sidebar-border bg-sidebar transition-transform duration-200 lg:translate-x-0',
                    open ? 'translate-x-0' : '-translate-x-full',
                )}
                aria-label="Main navigation"
            >
                <div className="flex h-16 shrink-0 items-center gap-3 px-5">
                    <Link href="/dashboard" className="flex min-w-0 items-center gap-3">
                        <Logo size={36} />
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-bold leading-tight text-sidebar-heading">
                                Take Care Connect
                            </span>
                            <span className="block truncate text-xs leading-tight text-sidebar-section">
                                Operations
                            </span>
                        </span>
                    </Link>

                    <button
                        type="button"
                        onClick={onClose}
                        className="ml-auto rounded-control p-1.5 text-sidebar-foreground transition-colors hover:bg-sidebar-hover lg:hidden"
                        aria-label="Close navigation"
                    >
                        <X className="size-5" />
                    </button>
                </div>

                <nav className="scrollbar-slim flex-1 space-y-6 overflow-y-auto px-3 pb-6">
                    {groups.map((group) => (
                        <div key={group.section}>
                            <p className="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-sidebar-section">
                                {group.section}
                            </p>

                            <ul className="space-y-0.5">
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

    const shell =
        'flex items-center gap-3 rounded-control px-3 py-2 text-sm font-medium transition-colors';

    /*
     | A page that exists in the manifest but is not built yet renders as inert
     | text with a "soon" pill, rather than being hidden.
     |
     | Hiding it would be tidier but less honest: the team can see the shape of
     | what is coming, and nobody files "the interviews link 404s".
     */
    if (item.soon) {
        return (
            <span className={cn(shell, 'cursor-default text-sidebar-foreground/50')} aria-disabled="true">
                <Icon className="size-4 shrink-0" aria-hidden="true" />
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
                    ? 'bg-sidebar-active font-semibold text-sidebar-active-foreground'
                    : 'text-sidebar-foreground hover:bg-sidebar-hover hover:text-foreground',
            )}
        >
            <Icon className="size-4 shrink-0" aria-hidden="true" />
            <span className="truncate">{item.label}</span>

            {item.badge && unread > 0 && (
                <span className="ml-auto rounded-full bg-destructive px-1.5 py-0.5 text-[10px] font-semibold text-destructive-foreground tabular">
                    {unread > 99 ? '99+' : unread}
                </span>
            )}
        </Link>
    );
}
