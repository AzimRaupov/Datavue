#!/usr/bin/env node
/**
 * Снимает страницу экспорта дашборда (/company/export/dashboard/{id}) в headless Chrome.
 * Запускается из DashboardExportJob; результат — одна JSON-строка в stdout.
 *
 *   --mode pdf    страницы A4 в PDF, виджеты не разрываются (--out: путь к файлу)
 *   --mode parts  каждый виджет отдельной PNG-картинкой (--out: каталог) — для Word
 *
 * Токен доступа приходит в переменной окружения EXPORT_ACCESS_TOKEN.
 */
import { existsSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
import puppeteer from 'puppeteer-core';

function args() {
    const result = {};
    const argv = process.argv.slice(2);

    for (let i = 0; i < argv.length; i += 2) {
        result[argv[i].replace(/^--/, '')] = argv[i + 1];
    }

    return result;
}

const CHROME_CANDIDATES = [
    '/usr/bin/google-chrome-stable',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/snap/bin/chromium',
];

const options = args();
const width = Number(options.width || 1280);
const timeout = Number(options.timeout || 120) * 1000;
const token = process.env.EXPORT_ACCESS_TOKEN || '';
const chrome = options.chrome || CHROME_CANDIDATES.find((path) => existsSync(path));

function fail(message) {
    console.log(JSON.stringify({ ok: false, error: message }));
    process.exit(1);
}

if (!options.url || !options.out || !options.mode) {
    fail('Не хватает аргументов: --url, --mode, --out');
}

if (!chrome) {
    fail('Не найден Chrome/Chromium. Установите его или задайте EXPORT_CHROME_PATH.');
}

if (!token) {
    fail('Не передан EXPORT_ACCESS_TOKEN.');
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

let browser;

try {
    browser = await puppeteer.launch({
        executablePath: chrome,
        headless: true,
        args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--font-render-hinting=none', '--hide-scrollbars'],
    });

    const page = await browser.newPage();

    // PDF — векторный, масштаб картинок не нужен; для Word снимаем в двойном разрешении.
    await page.setViewport({ width, height: 900, deviceScaleFactor: options.mode === 'parts' ? 2 : 1 });
    await page.emulateMediaType('screen');

    // Приложение берёт токен из localStorage — кладём его до загрузки страницы.
    await page.evaluateOnNewDocument((value) => {
        localStorage.setItem('token', value);
    }, token);

    await page.goto(options.url, { waitUntil: 'domcontentloaded', timeout });

    await page.waitForFunction(
        () => window.__EXPORT_STATE__ === 'ready' || String(window.__EXPORT_STATE__ || '').startsWith('error'),
        { timeout },
    );

    const state = await page.evaluate(() => window.__EXPORT_STATE__);

    if (state !== 'ready') {
        fail(state.replace(/^error:?\s*/, '') || 'Страница экспорта не загрузилась.');
    }

    // Шрифты и анимация графиков (Apex рисует линии и столбцы ~1 секунду).
    await page.evaluate(() => document.fonts.ready);
    await sleep(1800);

    const meta = await page.evaluate(() => ({
        title: window.__EXPORT_TITLE__ || '',
        subtitle: window.__EXPORT_SUBTITLE__ || '',
        height: Math.ceil(document.getElementById('export-root').getBoundingClientRect().height),
    }));

    if (options.mode === 'pdf') {
        // Виджет не должен разрываться на границе страницы: переносим его целиком.
        await page.addStyleTag({
            content: `
                /* Кабинет зажат в высоту окна (height: 100%; overflow: auto) — в печати это обрезало бы всё после первой страницы. */
                html, body, #app, .page { height: auto !important; min-height: 0 !important; overflow: visible !important; }
                .widgets-content { break-inside: avoid; page-break-inside: avoid; }
                h2, h3 { break-after: avoid; }
            `,
        });

        // A4 с полями 10 мм; страница сайта шириной `width` пикселей масштабируется
        // так, чтобы по ширине занять всю печатную область.
        const landscape = options.landscape !== '0';
        const MARGIN_MM = 10;
        const printableWidthPx = (((landscape ? 297 : 210) - 2 * MARGIN_MM) / 25.4) * 96;

        await page.pdf({
            path: options.out,
            format: 'A4',
            landscape,
            scale: Math.min(2, printableWidthPx / width),
            printBackground: true,
            margin: { top: `${MARGIN_MM}mm`, right: `${MARGIN_MM}mm`, bottom: '14mm', left: `${MARGIN_MM}mm` },
            displayHeaderFooter: true,
            headerTemplate: '<div></div>',
            footerTemplate:
                '<div style="width:100%;font-size:8px;color:#667085;text-align:center;font-family:sans-serif;">'
                + '<span class="pageNumber"></span> / <span class="totalPages"></span></div>',
        });

        console.log(JSON.stringify({ ok: true, ...meta }));
    } else {
        mkdirSync(options.out, { recursive: true });

        // Серый фон страницы остался бы полосами вокруг карточек на белом листе Word.
        // Белая карточка на белом листе теряет границы, а её пустые поля читаются как белые полосы —
        // поэтому рамку делаем чёткой.
        await page.addStyleTag({
            content: `
                html, body, .page, #export-root { background: #fff !important; }
                .widgets-content .card { border: 1px solid #c3ccd9 !important; box-shadow: none !important; }
            `,
        });

        const handles = await page.$$('.widgets-content');
        const parts = [];

        for (const [index, handle] of handles.entries()) {
            // Заголовок виджета уходит в Word обычным текстом (его можно править),
            // а картинкой снимается только само содержимое виджета.
            const title = await handle.evaluate((block) => block.querySelector('h3')?.textContent.trim() ?? '');
            const body = (await handle.evaluateHandle((block) => block.querySelector('.col-12')?.children[1] ?? null)).asElement();

            if (!body) continue;

            // Отступ в 3 px белого вокруг карточки: иначе её рамка прилегает к самому краю картинки.
            // Снимаем сам элемент, а не область страницы: кабинет прокручивается внутри body
            // (height: 100%; overflow: auto), и область за пределами окна выходит пустой.
            await body.evaluate((el) => {
                el.style.padding = '3px';
                el.style.background = '#fff';
            });

            const box = await body.boundingBox();

            if (!box || box.height < 2) continue;

            const file = join(options.out, `part-${String(index + 1).padStart(3, '0')}.png`);

            await body.screenshot({ path: file });
            parts.push({ file, title, width: Math.round(box.width), height: Math.round(box.height) });
        }

        if (!parts.length) {
            fail('На дашборде нет виджетов для выгрузки.');
        }

        console.log(JSON.stringify({ ok: true, ...meta, parts_dir: options.out, parts }));
    }
} catch (error) {
    fail(error?.message || String(error));
} finally {
    await browser?.close();
}
