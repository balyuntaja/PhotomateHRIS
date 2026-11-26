import { createRequire } from 'node:module';
import path from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

const require = createRequire(import.meta.url);

function resolveTailwindV4Css() {
    try {
        // Filament 3 membutuhkan tailwindcss v3 di root node_modules, sedangkan Vite memakai @tailwindcss/vite v4.
        const tailwindViteDir = path.dirname(require.resolve('@tailwindcss/vite'));

        return require.resolve('tailwindcss/index.css', { paths: [tailwindViteDir] });
    } catch {
        return null;
    }
}

const tailwindV4Css = resolveTailwindV4Css();

export default defineConfig({
    resolve: {
        alias: tailwindV4Css
            ? [{ find: /^tailwindcss$/, replacement: tailwindV4Css }]
            : [],
    },
    plugins: [
        laravel({
            input: [
                'resources/css/app.css', 
                'resources/css/operator.css', 
                'resources/js/app.js', 
                'resources/js/react/main.tsx'
            ],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
});
