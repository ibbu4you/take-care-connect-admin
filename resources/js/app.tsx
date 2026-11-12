import { createInertiaApp, router } from '@inertiajs/react';
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
        /*
         | Send the session's CSRF token from the page itself on every visit.
         |
         | Laravel checks X-CSRF-TOKEN before the cookie-based X-XSRF-TOKEN, so
         | a stale duplicate XSRF-TOKEN cookie in the browser can no longer
         | turn every form into "page expired". Read per visit, so the fresh
         | token after sign-in is picked up from the new page's props.
         */
        let csrf = props.initialPage.props.csrf_token as string | undefined;

        router.on('navigate', (event) => {
            csrf = event.detail.page.props.csrf_token as string | undefined;
        });

        router.on('before', (event) => {
            if (csrf) {
                event.detail.visit.headers = { ...event.detail.visit.headers, 'X-CSRF-TOKEN': csrf };
            }
        });

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
