import { defineConfig, loadEnv } from 'vite';
import { fileURLToPath } from 'node:url';
import { readdirSync, readFileSync } from 'node:fs';
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
/**
 * Страницы просят картинки из public по абсолютному пути (/logos/logo.png — логотип
 * в шапках, /static/illustrations/* — иллюстрации рабочего пространства), а на сайте
 * их отдаёт Laravel. В APK сервера нет (publicDir выключен), и картинки пропадали —
 * кладём нужное в сборку по тому же пути. Остальная public/static (десятки мегабайт
 * примеров Tabler) и логотипы клиентов с витрины приложению не нужны.
 */
const BUNDLED_DIRS = ['logos'];
const BUNDLED_FILES = [
    'static/illustrations/light/chart-circle.png',
    'static/illustrations/light/boy-and-laptop.png',
];
const bundledImages = () => ({
    name: 'bundled-images',
    generateBundle() {
        const emit = (file) => this.emitFile({ type: 'asset', fileName: file, source: readFileSync(`public/${file}`) });

        for (const dir of BUNDLED_DIRS) {
            readdirSync(`public/${dir}`).forEach((name) => emit(`${dir}/${name}`));
        }
        BUNDLED_FILES.forEach(emit);
    },
});

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
        plugins: [tailwindcss(), vue(), bundledImages()],
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
