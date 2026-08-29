import { useCallback, useEffect, useState } from 'react';

export type Theme = 'light' | 'dark';

const STORAGE_KEY = 'tcc-theme';

/*
 | The initial class is applied by an inline script in app.blade.php, before
 | first paint. This module only has to stay in sync with it — hence the shared
 | storage key. Reading the DOM rather than storage on mount means the hook and
 | the page can never disagree about what is currently on screen.
 */

function currentTheme(): Theme {
    if (typeof document === 'undefined') {
        return 'light';
    }

    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

function apply(theme: Theme): void {
    document.documentElement.classList.toggle('dark', theme === 'dark');

    try {
        localStorage.setItem(STORAGE_KEY, theme);
    } catch {
        // Private browsing modes throw on write. The theme still applies for
        // this page load; it simply will not be remembered, which is a better
        // outcome than a crashed render.
    }
}

export function useTheme(): { theme: Theme; toggle: () => void; setTheme: (theme: Theme) => void } {
    const [theme, setThemeState] = useState<Theme>(currentTheme);

    const setTheme = useCallback((next: Theme) => {
        apply(next);
        setThemeState(next);
    }, []);

    const toggle = useCallback(() => {
        setThemeState((previous) => {
            const next: Theme = previous === 'dark' ? 'light' : 'dark';
            apply(next);

            return next;
        });
    }, []);

    /*
     | Follow the OS only while the user has not chosen for themselves. Once
     | they have, their choice outranks the system for good — a stored
     | preference that silently flips at sunset is a bug report.
     */
    useEffect(() => {
        const query = window.matchMedia('(prefers-color-scheme: dark)');

        const onChange = (event: MediaQueryListEvent) => {
            let stored: string | null = null;

            try {
                stored = localStorage.getItem(STORAGE_KEY);
            } catch {
                // Treated as "no preference stored", which is the safe default.
            }

            if (stored === null) {
                const next: Theme = event.matches ? 'dark' : 'light';
                document.documentElement.classList.toggle('dark', next === 'dark');
                setThemeState(next);
            }
        };

        query.addEventListener('change', onChange);

        return () => query.removeEventListener('change', onChange);
    }, []);

    return { theme, toggle, setTheme };
}
