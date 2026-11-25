import { defineConfig } from 'vite';
import statamic from '@statamic/cms/vite-plugin';

export default defineConfig({
    plugins: [statamic()],
    build: {
        outDir: 'resources/dist/build',
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: 'resources/js/cp.js',
        },
    },
});
