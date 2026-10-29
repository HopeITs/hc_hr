import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // The dashboard's home screen is its own entry, loaded by that page
            // only; SortableJS is a dynamic import inside it (fetched on Customise).
            input: ['resources/js/app.js', 'resources/js/dashboard/home-screen.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
    },
    build: {
        rollupOptions: {
            output: {
                /**
                 * Split the always-on third-party code out of the app chunk.
                 *
                 * These libraries change only when we deliberately upgrade them,
                 * while app.js changes on most deploys. Bundled together, every
                 * deploy invalidated the entire ~1 MB download for returning
                 * users; separated, the vendor chunks stay in the browser cache
                 * across releases.
                 *
                 * amCharts is deliberately absent: it is already split into its
                 * own on-demand chunks by the dynamic imports in
                 * resources/js/charts/lazy-charts.js, and naming it here would
                 * pull it back into a chunk that loads eagerly.
                 */
                manualChunks(id) {
                    if (/node_modules[\\/](jquery|select2|flowbite)[\\/]/.test(id)) return 'vendor';
                    if (/node_modules[\\/]chart\.js[\\/]/.test(id)) return 'charts';
                    if (/node_modules[\\/]sweetalert2[\\/]/.test(id)) return 'alerts';
                },
            },
        },
    },
});
