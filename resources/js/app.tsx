import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { ToastProvider } from '@/Components/ui/toast';
import './bootstrap';

const appName = import.meta.env.VITE_APP_NAME || 'Take Care Connect Admin';

/*
 | No hydrateRoot, unlike takecareconnect.com.
 |
 | The public site server-renders every page for SEO, so its root element
 | already has markup to adopt. Nothing here is ever crawled and the shared
 | hosting cannot keep a Node SSR process alive, so this mounts fresh.
 */
void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx')),
    setup({ el, App, props }) {
        createRoot(el).render(
            <ToastProvider>
                <App {...props} />
            </ToastProvider>,
        );
    },
    progress: {
        // Reads the live token rather than a hard-coded hex, so the loading bar
        // follows the theme like everything else.
        color: 'hsl(229 56% 36%)',
    },
});
