import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
    plugins: [vue(), tailwindcss()],
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
