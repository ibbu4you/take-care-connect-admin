import type { Config as ZiggyConfig } from 'ziggy-js';

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    job_title: string | null;
    avatar_url: string | null;
    initials: string;
    roles: string[];
}

export interface Flash {
    success: string | null;
    error: string | null;
    info: string | null;
}

/** Props Inertia shares with every page — see HandleInertiaRequests::share(). */
export interface SharedProps {
    auth: {
        user: AuthUser | null;
        /** Effective permissions: role grants + direct grants − revocations. */
        permissions: string[];
    };
    app: {
        name: string;
    };
    notifications: {
        unread: number;
    };
    flash: Flash;
    errors: Record<string, string>;
    ziggy: ZiggyConfig & { location: string };
    [key: string]: unknown;
}

/**
 * Laravel's paginator, exactly as it serialises.
 *
 * Flat, NOT wrapped in a `meta` object — that shape only appears when a
 * paginator is passed through an API Resource collection, which this app does
 * not do. Matching the real output means a controller can hand a paginator
 * straight to Inertia with no transformation step to forget.
 */
export interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
    path: string;
    first_page_url: string | null;
    last_page_url: string | null;
    next_page_url: string | null;
    prev_page_url: string | null;
}

/** A user as it appears in a dropdown or an assignee chip. */
export interface UserOption {
    id: number;
    name: string;
    initials: string;
    avatar_url: string | null;
    job_title: string | null;
}
