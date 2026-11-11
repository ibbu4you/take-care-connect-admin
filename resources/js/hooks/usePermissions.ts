import { usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';
import type { AuthUser, SharedProps } from '@/types';

/*
 | What a response that never passed through HandleInertiaRequests looks like.
 |
 | An error raised before that middleware — a 419 from the CSRF check, a 503
 | from maintenance mode — is rendered with no shared props at all. Treating
 | that as "signed out" keeps the page standing instead of a white screen.
 | Module-level so the memo below sees the same array every render.
 */
const SIGNED_OUT: SharedProps['auth'] = { user: null, permissions: [] };

/**
 * The frontend half of the permission gate.
 *
 * Reads the effective list shared by HandleInertiaRequests — role grants plus
 * direct grants minus revocations — so this answers exactly what the backend
 * Gate would answer. Anything else would let the sidebar offer a page that then
 * returns 403.
 *
 * `can` is deliberately OR across an array: a nav item or a button is shown if
 * ANY of the listed permissions would let the user do something useful there.
 * Where every permission is genuinely required, use `canAll`.
 */
export function usePermissions(): {
    user: AuthUser | null;
    permissions: string[];
    can: (permission: string | string[]) => boolean;
    canAll: (permissions: string[]) => boolean;
    hasRole: (role: string | string[]) => boolean;
} {
    const auth = usePage<SharedProps>().props.auth ?? SIGNED_OUT;

    // A Set so a sidebar of ~40 items with a couple of permissions each is 80
    // hash lookups rather than 80 linear scans of a 74-item array on every
    // render.
    const granted = useMemo(() => new Set(auth.permissions ?? []), [auth.permissions]);

    const can = useCallback(
        (permission: string | string[]): boolean => {
            const needed = Array.isArray(permission) ? permission : [permission];

            return needed.some((name) => granted.has(name));
        },
        [granted],
    );

    const canAll = useCallback(
        (permissions: string[]): boolean => permissions.every((name) => granted.has(name)),
        [granted],
    );

    const hasRole = useCallback(
        (role: string | string[]): boolean => {
            const needed = Array.isArray(role) ? role : [role];
            const held = auth.user?.roles ?? [];

            return needed.some((name) => held.includes(name));
        },
        [auth.user],
    );

    return {
        user: auth.user,
        permissions: auth.permissions ?? [],
        can,
        canAll,
        hasRole,
    };
}
