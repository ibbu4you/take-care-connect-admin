import {
    BarChart3,
    Bell,
    Building2,
    CalendarDays,
    Clapperboard,
    FileText,
    FolderKanban,
    Handshake,
    HeartHandshake,
    Inbox,
    LayoutDashboard,
    Mic,
    PenLine,
    ScrollText,
    Settings,
    ShieldCheck,
    Target,
    UserSearch,
    TrendingUp,
    UserCog,
    type LucideIcon,
} from 'lucide-react';

/*
 | The sidebar, as data.
 |
 | One manifest rather than JSX in the layout, for three reasons: the ⌘K palette
 | searches the same list, every item states the permission that reveals it in
 | the same place the route states it, and adding a page is appending one object
 | rather than editing a component.
 |
 | `permissions` is OR — an item appears if the user holds ANY of them. That
 | matches how the pages themselves work: the Interviews list is worth showing to
 | somebody who can only see their own.
 */

export interface NavItem {
    label: string;
    href: string;
    icon: LucideIcon;
    section: NavSection;
    /** Any one of these reveals the item. Omit to show it to everybody. */
    permissions?: string[];
    /** Not built yet: rendered greyed out with a pill instead of a link. */
    soon?: boolean;
    /** Live count fetched by the layout, e.g. unread notifications. */
    badge?: 'notifications' | 'myQueue';
}

export const NAV_SECTIONS = [
    'Overview',
    'Sales',
    'Interviews',
    'Content',
    'Creative',
    'Reports',
    'Administration',
] as const;

export type NavSection = (typeof NAV_SECTIONS)[number];

export const NAV: NavItem[] = [
    {
        label: 'Dashboard',
        href: '/dashboard',
        icon: LayoutDashboard,
        section: 'Overview',
    },
    {
        label: 'Notifications',
        href: '/notifications',
        icon: Bell,
        section: 'Overview',
        badge: 'notifications',
    },

    {
        // First in Sales, because it is where the work starts: a manager talks
        // to far more people than ever become clients.
        label: 'Leads',
        href: '/leads',
        icon: UserSearch,
        section: 'Sales',
        permissions: ['leads.view', 'leads.view.all'],
    },
    {
        label: 'Clients',
        href: '/clients',
        icon: Building2,
        section: 'Sales',
        permissions: ['clients.view', 'clients.view.all'],
    },
    {
        label: 'Projects',
        href: '/projects',
        icon: FolderKanban,
        section: 'Sales',
        permissions: ['projects.view', 'projects.view.all'],
    },
    /*
     | The list first, the board second.
     |
     | A board answers "what is where"; a list answers "what is there", and the
     | second is the question somebody opening a section actually has. A board
     | also hides everything closed, so landing on one makes a chunk of the data
     | invisible until you notice there is another view.
     |
     | Deliverables already worked this way. Sales now matches it, which is the
     | other half of the point: two sections that behave differently for no
     | reason is a thing people have to remember.
     */
    {
        label: 'Deals',
        href: '/deals',
        icon: Handshake,
        section: 'Sales',
        permissions: ['deals.view', 'deals.view.all'],
    },
    {
        label: 'Pipeline board',
        href: '/pipeline',
        icon: LayoutDashboard,
        section: 'Sales',
        permissions: ['deals.view', 'deals.view.all'],
    },
    {
        label: 'Targets',
        href: '/targets',
        icon: Target,
        section: 'Sales',
        permissions: ['targets.view', 'targets.view.all', 'targets.manage'],
    },
    {
        // Gated on deals rather than clients: chasing a follow-up is pipeline
        // work. An Anchor holds `clients.view` so they can see who they are
        // interviewing, and should not inherit the sales chase list with it.
        label: 'Follow-ups',
        href: '/follow-ups',
        icon: Inbox,
        section: 'Sales',
        permissions: ['deals.view', 'deals.view.all'],
    },

    {
        label: 'All interviews',
        href: '/interviews',
        icon: Mic,
        section: 'Interviews',
        permissions: ['interviews.view', 'interviews.view.all'],
    },
    {
        label: 'My queue',
        href: '/interviews/my-queue',
        icon: CalendarDays,
        section: 'Interviews',
        permissions: ['interviews.view', 'interviews.view.all'],
    },

    {
        label: 'Article queue',
        href: '/articles/queue',
        icon: PenLine,
        section: 'Content',
        permissions: ['articles.view', 'articles.view.all'],
    },
    {
        label: 'All articles',
        href: '/articles',
        icon: FileText,
        section: 'Content',
        permissions: ['articles.view', 'articles.view.all'],
    },

    {
        label: 'Deliverables',
        href: '/deliverables',
        icon: Clapperboard,
        section: 'Creative',
        permissions: ['deliverables.view', 'deliverables.view.all'],
    },
    {
        label: 'Creative board',
        href: '/deliverables/board',
        icon: LayoutDashboard,
        section: 'Creative',
        permissions: ['deliverables.view', 'deliverables.view.all'],
    },

    {
        label: 'Reports',
        href: '/reports',
        icon: BarChart3,
        section: 'Reports',
        permissions: ['reports.view', 'reports.view.all'],
    },
    {
        // What the work added up to: businesses reached and featured. First in
        // the section's organisation-wide reports, because it is the one a
        // trustee or donor asks for.
        label: 'Impact',
        href: '/reports/impact',
        icon: HeartHandshake,
        section: 'Reports',
        permissions: ['reports.view.all'],
    },
    {
        label: 'Performance',
        href: '/reports/performance',
        icon: TrendingUp,
        section: 'Reports',
        permissions: ['reports.view.all'],
    },

    {
        label: 'People',
        href: '/users',
        icon: UserCog,
        section: 'Administration',
        permissions: ['users.view'],
    },
    {
        label: 'Roles',
        href: '/roles',
        icon: ShieldCheck,
        section: 'Administration',
        permissions: ['roles.view'],
    },
    {
        label: 'Audit log',
        href: '/audit',
        icon: ScrollText,
        section: 'Administration',
        permissions: ['audit.view'],
    },
    {
        label: 'Settings',
        href: '/settings',
        icon: Settings,
        section: 'Administration',
        permissions: ['settings.view'],
    },
];

/**
 * The manifest, filtered to what this user may see and grouped for rendering.
 *
 * A section with no visible items is dropped entirely, so an Anchor never sees
 * an empty "Administration" heading hinting at a door they cannot open.
 */
export function visibleNav(
    can: (permission: string | string[]) => boolean,
): Array<{ section: NavSection; items: NavItem[] }> {
    return NAV_SECTIONS.map((section) => ({
        section,
        items: NAV.filter((item) => item.section === section)
            .filter((item) => !item.permissions || can(item.permissions)),
    })).filter((group) => group.items.length > 0);
}

/**
 * Whether a nav item should read as the current page.
 *
 * Prefix matching so `/interviews/12` still highlights "All interviews", with
 * an exact-match guard for `/` so the dashboard is not permanently lit. The
 * longest match wins, which is what stops `/articles` and `/articles/queue`
 * from both highlighting at once.
 */
export function activeHref(currentUrl: string, items: NavItem[]): string | null {
    const path = currentUrl.split('?')[0].replace(/\/+$/, '') || '/';

    const matches = items
        .filter((item) => path === item.href || path.startsWith(`${item.href}/`))
        .sort((a, b) => b.href.length - a.href.length);

    return matches[0]?.href ?? null;
}
