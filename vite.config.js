import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

const inDocker = process.env.VITE_DOCKER === 'true';
const vitePort = Number(process.env.VITE_PORT ?? 5173);

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                    optimizedFallbacks: false,
                }),
            ],
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        ...(inDocker ? {
            host: '0.0.0.0',
            port: vitePort,
            strictPort: true,
            hmr: {
                host: process.env.VITE_HMR_HOST ?? 'localhost',
                clientPort: vitePort,
            },
        } : {}),
        watch: {
            usePolling: process.env.VITE_USE_POLLING === 'true',
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
