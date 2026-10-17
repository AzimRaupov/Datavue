import { defineConfig, loadEnv } from 'vite';
import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

/**
 * Сборка для Android-приложения (Capacitor). В отличие от сайта, где Laravel
 * отдаёт HTML и подключает собранные файлы, здесь весь интерфейс — обычный
 * статический набор в `dist-mobile`, который копируется внутрь APK. С сервера
 * приложение берёт только данные по API и сокетам.
 *
 *   npm run build:mobile                       — на сервер из MOBILE_API_URL ниже
 *   MOBILE_API_URL=http://1.2.3.4 npm run build:mobile
 */
const API_URL = process.env.MOBILE_API_URL ?? 'https://datavue.xyz';

export default defineConfig(({ mode }) => {
    // Ключ Reverb публичный (его видит любой посетитель сайта), берём из .env сайта.
    const env = loadEnv(mode, process.cwd(), '');
    const apiHost = new URL(API_URL);
    const secure = apiHost.protocol === 'https:';

    return {
        root: 'mobile',
        envDir: false,
        publicDir: false,
        plugins: [tailwindcss(), vue()],
        // На сайте алиас @ -> resources/js добавляет laravel-vite-plugin, здесь его нет.
        resolve: {
            alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
        },
        define: {
            'import.meta.env.VITE_API_URL': JSON.stringify(API_URL),
            'import.meta.env.VITE_REVERB_APP_KEY': JSON.stringify(process.env.MOBILE_REVERB_APP_KEY ?? env.REVERB_APP_KEY ?? ''),
            'import.meta.env.VITE_REVERB_HOST': JSON.stringify(apiHost.hostname),
            'import.meta.env.VITE_REVERB_PORT': JSON.stringify(apiHost.port || (secure ? '443' : '80')),
            'import.meta.env.VITE_REVERB_SCHEME': JSON.stringify(secure ? 'https' : 'http'),
        },
        build: {
            outDir: '../dist-mobile',
            emptyOutDir: true,
            chunkSizeWarningLimit: 2000,
        },
    };
});
