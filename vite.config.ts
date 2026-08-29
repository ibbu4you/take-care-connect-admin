import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/*
 | The admin is authenticated-only, so unlike takecareconnect.com there is no
 | SSR build here: nothing in this app is ever crawled, and a Node process that
 | must stay alive is exactly what the shared hosting cannot provide. One Vite
 | pass, hydrated client-side.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
