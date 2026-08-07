import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
    plugins: [vue(), tailwindcss()],

    /**
     * The app is built into `public/`, which is the document root.
     *
     * One web root, where every PHP developer expects to find it. The API's
     * front controller already lives there, and `public/.htaccess` sends
     * `/api/*` to it and everything else to the app — so a vhost is
     * `DocumentRoot .../public` and nothing more. The alternative, serving the
     * built app from one directory and aliasing the API in from another, is
     * two roots to keep in step and it produced a rewrite loop twice in one
     * afternoon.
     *
     * `emptyOutDir: false` because `public/` is not ours to empty. It holds
     * `index.php` and `.htaccess`, both committed, and a build that wiped them
     * would take the API and the routing rules with it.
     *
     * `publicDir: false` for the same reason from the other side. Vite copies
     * its public directory into the output, and with the output now being
     * `public/` that copy would overwrite the `.htaccess` it just wrote.
     */
    build: {
        outDir: '../public',
        emptyOutDir: false,
    },
    publicDir: false,
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
            // Templates are shared with the server, so they are read from
            // resources/ rather than copied. A copy would be one instrument
            // revision away from disagreeing with the one being scored.
            '@resources': fileURLToPath(new URL('../resources', import.meta.url)),
        },
    },
    server: {
        port: 5173,
        fs: {
            allow: ['..'],
        },
        proxy: {
            // The API is served by nginx in Docker, or by bin/serve natively.
            '/api': {
                target: process.env.VITE_API_ORIGIN ?? 'http://localhost:8080',
                changeOrigin: true,
            },
        },
    },
    test: {
        // The scoring suite reads the shared fixtures off disk, so it runs in
        // node rather than a browser environment.
        environment: 'node',
        include: ['src/**/*.test.ts'],
    },
})
