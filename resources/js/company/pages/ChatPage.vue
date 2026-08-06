<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from "vue";
import DonutWidget from "../components/widgets/DonutWidget.vue";
import MultiSeriesTrend from "../components/widgets/MultiSeriesTrend.vue";
import MiniCounters from "../components/widgets/MiniCounters.vue";
import AiChatSidebar from "../components/chat/AiChatSidebar.vue";
import { useRoute, useRouter } from "vue-router";
import api from "../api.js";
import WidgetContainer from "../components/WidgetContainer.vue";
import Echo from "laravel-echo";
import Pusher from "pusher-js";
import html2canvas from "html2canvas";
import jsPDF from "jspdf";

window.Pusher = Pusher;

const isExportingPdf = ref(false);
const isExportingWord = ref(false);
const exportErrorMsg = ref(null);

// Область, которую экспортируем в PDF/Word и печатаем — только сами виджеты,
// без чата/шапки/бэкдропа.
const exportArea = ref(null);

const echo = new Echo({
    broadcaster: "reverb",
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST || "127.0.0.1",
    wsPort: import.meta.env.VITE_REVERB_PORT || 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT || 8080,
    forceTLS: false,
    disableStats: true,
    enabledTransports: ["ws"],
});

const empty_img = "/static/illustrations/light/chart-circle.png";
const generate_img = "/static/illustrations/light/boy-and-laptop.png";

const route = useRoute();
const router = useRouter();

const chatId = route.params.id;

const chat = ref({});
const dashboards = ref([]);
const error = ref(null);

const selectedDashboardId = ref(
    route.params.dashboard ? Number(route.params.dashboard) : null
);

const currentDashboard = ref({});
const widgets = ref([]);

let currentChannelName = null;

const isRefreshing = ref(false);

// Токен ручного обновления контента виджетов.
// Меняется ТОЛЬКО по клику на кнопку "Обновить".
// WidgetContainer следит за ним и перезапрашивает свои данные,
// независимо от того, поменялся ли widget.updated_at на бэке.
const refreshToken = ref(0);

const hasDashboards = computed(() => dashboards.value.length > 0);

const showDashboardSelect = computed(() =>
    hasDashboards.value && currentDashboard.value?.status !== "generating_scheme"
);

// Ключ теперь стабильный — только id виджета.
// Компонент WidgetContainer НЕ пересоздаётся при каждом обновлении дашборда,
// поэтому не мигает skeleton-заглушками по всем виджетам разом.
function widgetKey(widget) {
    return widget.id;
}

function toId(val) {
    return val === null || val === undefined ? null : Number(val);
}

async function getCurrentDashboard() {
    if (!hasDashboards.value) {
        selectedDashboardId.value = null;
        currentDashboard.value = {};
        widgets.value = [];
        return;
    }

    if (!selectedDashboardId.value) {
        selectedDashboardId.value = toId(dashboards.value[0]?.id) ?? null;
    }

    if (!selectedDashboardId.value) {
        error.value = "Дашборд не найден";
        return;
    }

    try {
        const { data } = await api.get(`/dashboards/${selectedDashboardId.value}`);
        currentDashboard.value = data;
        widgets.value = data.widgets ?? [];
    } catch (err) {
        console.error(err);
        error.value = "Не удалось загрузить дашборд";
    }
}

// Обновляет метаданные дашборда/список виджетов (структуру),
// но НЕ форсирует перезагрузку контента внутри WidgetContainer.
async function refreshWidgets() {
    if (!currentDashboard.value?.id) return;
    if (isRefreshing.value) return;

    isRefreshing.value = true;

    try {
        const { data } = await api.get(
            `/dashboards/${currentDashboard.value.id}`,
            { params: { refresh: Date.now() } }
        );

        widgets.value = data.widgets ?? [];
        currentDashboard.value = data;

        const idx = dashboards.value.findIndex(d => d.id === toId(data.id));
        if (idx !== -1) {
            dashboards.value[idx] = {
                ...dashboards.value[idx],
                name: data.name,
                status: data.status,
            };
        }
    } catch (err) {
        console.error("Не удалось обновить виджеты:", err);
    } finally {
        isRefreshing.value = false;
    }
}


async function onRefreshClick() {
    await refreshWidgets();
    refreshToken.value = Date.now();
}

async function getChat() {
    try {
        const { data } = await api.get(`/chats/${chatId}`);

        chat.value = data;

        dashboards.value = (data.dashboards ?? []).map(d => ({
            ...d,
            id: toId(d.id),
        }));

        if (selectedDashboardId.value) {
            const exists = dashboards.value.some(d => d.id === selectedDashboardId.value);
            if (!exists) {
                selectedDashboardId.value = dashboards.value[0]?.id ?? null;
            }
        } else if (dashboards.value.length > 0) {
            selectedDashboardId.value = dashboards.value[0].id;
        }
    } catch (err) {
        console.error(err);
        error.value = "Не удалось загрузить чат";
    }
}

async function onDashboardChange() {
    if (!selectedDashboardId.value) return;

    router.replace({
        name: "company.chat",
        params: {
            id: chatId,
            dashboard: selectedDashboardId.value,
        },
    });

    await getCurrentDashboard();
    subscribeToDashboardChannel();
}

const chatOpen = ref(true);

function toggleChat() {
    chatOpen.value = !chatOpen.value;
}

function closeChat() {
    chatOpen.value = false;
}

function subscribeToDashboardChannel() {
    if (currentChannelName) {
        echo.leave(currentChannelName);
        currentChannelName = null;
    }

    if (!currentDashboard.value?.id) return;

    currentChannelName = `dashboard.${currentDashboard.value.id}`;

    echo.channel(currentChannelName)
        .listen(".DashboardWidgetChanged", (e) => {
            console.log("--- РЕАЛТАЙМ ИЗМЕНЕНИЕ ДАШБОРДА ПОЙМАНО ---", e);
            refreshWidgets();

            // Принудительно обновляем данные всех WidgetContainer
            refreshToken.value = Date.now();
        });
}

watch(
    () => route.params.dashboard,
    async (newVal) => {
        if (!newVal) return;

        const newId = toId(newVal);
        if (newId === selectedDashboardId.value) return;

        const exists = dashboards.value.some(d => d.id === newId);
        if (!exists) {
            await getChat();
        }

        selectedDashboardId.value = newId;
        await getCurrentDashboard();
        subscribeToDashboardChannel();
    }
);

function sanitizeFileName(name) {
    return (name || "dashboard").replace(/[\\/:*?"<>|]+/g, "_");
}

/**
 * Утилита с таймаутом: если промис не завершился за ms — кидаем ошибку,
 * чтобы UI никогда не "завис" навечно.
 */
function withTimeout(promise, ms, label = "operation") {
    let timer;
    const timeout = new Promise((_, reject) => {
        timer = setTimeout(
            () => reject(new Error(`Превышено время ожидания: ${label}`)),
            ms
        );
    });
    return Promise.race([promise, timeout]).finally(() => clearTimeout(timer));
}

/**
 * Перед экспортом/печатью проходим по всем потомкам exportArea и снимаем
 * ограничения overflow/max-height/height, из-за которых видна только
 * прокрученная часть виджета (графики, таблицы, списки и т.п.).
 * Сохраняем исходные инлайн-стили, чтобы потом всё вернуть на место.
 */
const EXPAND_SELECTOR =
    "[style*='overflow'], .overflow-auto, .overflow-scroll, .table-responsive, .scroll, .chart-container, canvas, .echarts, .apexcharts-canvas";

function expandScrollableAreas(root) {
    if (!root) return [];

    const restoreList = [];

    const nodes = [root, ...root.querySelectorAll(EXPAND_SELECTOR)];

    nodes.forEach((el) => {
        const original = {
            overflow: el.style.overflow,
            overflowX: el.style.overflowX,
            overflowY: el.style.overflowY,
            maxHeight: el.style.maxHeight,
            height: el.style.height,
        };

        const computed = window.getComputedStyle(el);
        const hasClip =
            ["auto", "scroll", "hidden"].includes(computed.overflow) ||
            ["auto", "scroll", "hidden"].includes(computed.overflowY) ||
            (computed.maxHeight && computed.maxHeight !== "none");

        if (hasClip) {
            el.style.setProperty("overflow", "visible", "important");
            el.style.setProperty("overflow-x", "visible", "important");
            el.style.setProperty("overflow-y", "visible", "important");
            el.style.setProperty("max-height", "none", "important");
            if (el.scrollHeight > el.clientHeight) {
                el.style.setProperty("height", "auto", "important");
            }
            restoreList.push({ el, original });
        }
    });

    return restoreList;
}

function restoreScrollableAreas(restoreList) {
    restoreList.forEach(({ el, original }) => {
        el.style.overflow = original.overflow;
        el.style.overflowX = original.overflowX;
        el.style.overflowY = original.overflowY;
        el.style.maxHeight = original.maxHeight;
        el.style.height = original.height;
    });
}

/**
 * Разворачивает скроллящиеся блоки и рендерит exportArea в canvas через
 * html2canvas. Общая точка для PDF и Word — оба экспорта должны видеть
 * дашборд как растровую картинку, а не как живой DOM с SVG от ApexCharts
 * (см. комментарий в exportToWord, почему это важно).
 */
async function renderDashboardCanvas() {
    const restoreList = expandScrollableAreas(exportArea.value);

    try {
        // Ждём кадр, чтобы браузер применил стили и графики успели перерисоваться
        await new Promise((r) => requestAnimationFrame(() => setTimeout(r, 50)));

        return await withTimeout(
            html2canvas(exportArea.value, {
                // На дашбордах с десятками виджетов html2canvas и так рендерит
                // долго и блокирует вкладку; retina-scale x2 удваивал время почти
                // без заметной пользы на итоговой картинке, поэтому ограничиваем 1.5.
                scale: Math.min(window.devicePixelRatio || 1, 1.5),
                useCORS: true,
                allowTaint: false,
                logging: false,
                imageTimeout: 15000, // не ждать битые/медленные картинки бесконечно
                backgroundColor: "#ffffff",
                windowWidth: exportArea.value.scrollWidth,
                windowHeight: exportArea.value.scrollHeight,
                width: exportArea.value.scrollWidth,
                height: exportArea.value.scrollHeight,
                scrollX: 0,
                scrollY: 0,
            }),
            45000,
            "рендер дашборда"
        );
    } finally {
        restoreScrollableAreas(restoreList);
    }
}

// --- ЭКСПОРТ В PDF (html2canvas + jsPDF, полностью на клиенте) ---
async function exportToPdf() {
    if (!exportArea.value) return;
    if (isExportingPdf.value) return;

    isExportingPdf.value = true;
    exportErrorMsg.value = null;

    try {
        const canvas = await renderDashboardCanvas();
        const imgData = canvas.toDataURL("image/png");

        const pdf = new jsPDF({
            orientation: canvas.width > canvas.height ? "landscape" : "portrait",
            unit: "px",
            format: [canvas.width, canvas.height],
        });

        pdf.addImage(imgData, "PNG", 0, 0, canvas.width, canvas.height);

        const fileName = `${sanitizeFileName(currentDashboard.value.name)}.pdf`;
        pdf.save(fileName);
    } catch (err) {
        console.error("Ошибка экспорта в PDF:", err);
        exportErrorMsg.value = "Не удалось экспортировать в PDF. Попробуйте ещё раз.";
    } finally {
        isExportingPdf.value = false;
    }
}

// --- ЭКСПОРТ В WORD ---
// ВАЖНО: раньше сюда шёл живой innerHTML дашборда (включая SVG от ApexCharts).
// ApexCharts рисует атрибуты вида "data:realIndex"/"data:collapsed" — валидные
// для HTML5, но с двоеточием, которое строгий XML/OOXML-парсер Word трактует
// как необъявленный namespace-префикс, и Word показывал файл как повреждённый.
// Теперь дашборд рендерится в PNG (тем же html2canvas, что и PDF) и вставляется
// одной картинкой — сырого SVG в документе больше нет.
async function exportToWord() {
    if (!exportArea.value) return;
    if (isExportingWord.value) return;

    isExportingWord.value = true;
    exportErrorMsg.value = null;

    try {
        const canvas = await renderDashboardCanvas();
        const imgData = canvas.toDataURL("image/png");
        const dashboardName = currentDashboard.value.name ?? "Dashboard";

        const htmlDocument = `
            <html xmlns:o="urn:schemas-microsoft-com:office:office"
                  xmlns:w="urn:schemas-microsoft-com:office:word"
                  xmlns="http://www.w3.org/TR/REC-html40">
            <head>
                <meta charset="utf-8">
                <title>${dashboardName}</title>
                <!--[if gte mso 9]>
                <xml>
                    <w:WordDocument>
                        <w:View>Print</w:View>
                        <w:Zoom>100</w:Zoom>
                    </w:WordDocument>
                </xml>
                <![endif]-->
                <style>
                    body { font-family: Arial, sans-serif; }
                    h1 { color: #1a1a1a; }
                    img { width: 100%; }
                </style>
            </head>
            <body>
                <h1>${dashboardName}</h1>
                <img src="${imgData}" alt="${dashboardName}" />
            </body>
            </html>
        `;

        const blob = new Blob(["\ufeff", htmlDocument], {
            type: "application/msword",
        });

        const url = window.URL.createObjectURL(blob);
        const fileName = `${sanitizeFileName(currentDashboard.value.name)}.doc`;

        const link = document.createElement("a");
        link.href = url;
        link.download = fileName;
        document.body.appendChild(link);
        link.click();
        link.remove();

        window.URL.revokeObjectURL(url);
    } catch (err) {
        console.error("Ошибка экспорта в Word:", err);
        exportErrorMsg.value = "Не удалось экспортировать в Word. Попробуйте ещё раз.";
    } finally {
        isExportingWord.value = false;
    }
}

let printRestoreList = [];

function handleBeforePrint() {
    printRestoreList = expandScrollableAreas(exportArea.value);
}

function handleAfterPrint() {
    restoreScrollableAreas(printRestoreList);
    printRestoreList = [];
}

function printDashboard() {
    window.print();
}

onMounted(async () => {
    document.body.classList.add("chat-page");

    await getChat();
    await getCurrentDashboard();

    subscribeToDashboardChannel();

    window.addEventListener("beforeprint", handleBeforePrint);
    window.addEventListener("afterprint", handleAfterPrint);
});

onUnmounted(() => {
    document.body.classList.remove("chat-page");

    if (currentChannelName) {
        echo.leave(currentChannelName);
    }

    window.removeEventListener("beforeprint", handleBeforePrint);
    window.removeEventListener("afterprint", handleAfterPrint);
});
</script>

<style>
body.chat-page .page {
    height: 100vh;
    min-height: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.icon-spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Печать: страница обычно зажата в фиксированную высоту (100vh, overflow:hidden)
   под чат-разметку — для печати это нужно снять, иначе распечатается только
   то, что видно на экране, а не весь дашборд целиком. */
@media print {
    .d-print-none {
        display: none !important;
    }

    body.chat-page .page,
    html, body {
        height: auto !important;
        overflow: visible !important;
    }

    .dashboard-wrapper,
    .dashboard-main {
        display: block !important;
        overflow: visible !important;
        height: auto !important;
    }

    .widgets-content {
        page-break-inside: avoid;
        break-inside: avoid;
    }
}
</style>

<template>
    <div class="dashboard-wrapper">

        <div class="dashboard-main p-1" id="dashboardMain">

            <div class="page-header d-print-none mb-3 mt-2">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <h1 v-if="hasDashboards" class="page-title">{{ currentDashboard?.name }}</h1>
                        </div>

                        <div class="col-auto ms-auto d-print-none">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <select
                                    v-if="showDashboardSelect"
                                    class="form-select"
                                    style="width: 220px; max-width: 100%;"
                                    v-model="selectedDashboardId"
                                    @change="onDashboardChange"
                                    aria-label="Выбор дашборда"
                                >
                                    <option
                                        v-for="d in dashboards"
                                        :key="d.id"
                                        :value="d.id"
                                    >
                                        {{ d.name }}
                                    </option>
                                </select>

                                <!-- КНОПКА ОБНОВЛЕНИЯ -->
                                <button
                                    v-if="hasDashboards"
                                    class="btn btn-outline-primary"
                                    type="button"
                                    title="Обновить дашборд"
                                    @click="onRefreshClick"
                                    :disabled="isRefreshing"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="18"
                                        height="18"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        :class="{ 'icon-spin': isRefreshing }"
                                    >
                                        <path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 5v4h4" />
                                        <path d="M4 13a8.1 8.1 0 0 0 15.5 2M20 19v-4h-4" />
                                    </svg>

                                    {{ isRefreshing ? "Обновление..." : "Обновить" }}
                                </button>

                                <!-- ЭКСПОРТ В PDF -->
                                <button
                                    v-if="hasDashboards"
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Экспорт в PDF"
                                    @click="exportToPdf"
                                    :disabled="isExportingPdf"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="18"
                                        height="18"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="icon me-2"
                                        :class="{ 'icon-spin': isExportingPdf }"
                                    >
                                        <path d="M14 3v4a1 1 0 0 0 1 1h4" />
                                        <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" />
                                        <path d="M9 9l1 0" />
                                        <path d="M9 13l6 0" />
                                        <path d="M9 17l6 0" />
                                    </svg>

                                    {{ isExportingPdf ? "Экспорт..." : "PDF" }}
                                </button>

                                <!-- ЭКСПОРТ В WORD -->
                                <button
                                    v-if="hasDashboards"
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Экспорт в Word"
                                    @click="exportToWord"
                                    :disabled="isExportingWord"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="18"
                                        height="18"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="icon me-2"
                                        :class="{ 'icon-spin': isExportingWord }"
                                    >
                                        <path d="M14 3v4a1 1 0 0 0 1 1h4" />
                                        <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" />
                                        <path d="M9 9l1 0" />
                                        <path d="M9 13l6 0" />
                                        <path d="M9 17l6 0" />
                                    </svg>

                                    {{ isExportingWord ? "Экспорт..." : "Word" }}
                                </button>

                                <!-- ПЕЧАТЬ -->
                                <button
                                    v-if="hasDashboards"
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    title="Печать"
                                    @click="printDashboard"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="18"
                                        height="18"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="icon me-2"
                                    >
                                        <path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2" />
                                        <path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4" />
                                        <path d="M7 13m0 2a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z" />
                                    </svg>

                                    Печать
                                </button>

                                <button
                                    v-if="!chatOpen"
                                    class="btn btn-primary d-none d-lg-inline-flex align-items-center text-nowrap flex-shrink-0 px-3"
                                    title="Открыть AI ассистента"
                                    @click="toggleChat"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="18"
                                        height="18"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        class="icon me-2"
                                    >
                                        <path d="M12 8a4 4 0 0 1 4 4" />
                                        <path d="M12 4a8 8 0 0 1 8 8" />
                                        <path d="M12 20a8 8 0 0 1-8-8" />
                                        <circle cx="12" cy="12" r="1" />
                                    </svg>

                                    AI Ассистент
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="container-xl">

                <div
                    v-if="exportErrorMsg"
                    class="alert alert-danger d-print-none"
                    role="alert"
                >
                    {{ exportErrorMsg }}
                </div>

                <div
                    v-if="!hasDashboards"
                    class="d-flex align-items-center justify-content-center"
                    style="min-height: 60vh;"
                >
                    <div class="text-center">
                        <img
                            :src="empty_img"
                            alt="chart"
                            class="img-fluid d-block mx-auto mb-4"
                            style="max-width: 270px; width: 100%;"
                        >
                        <h3 class="mb-2">Дашбордов пока нет</h3>
                        <p class="text-muted mb-0">
                            Как только для этого чата будет создан дашборд, он появится здесь
                        </p>
                    </div>
                </div>

                <div
                    v-if="currentDashboard.status === 'generating_scheme'"
                    class="d-flex align-items-center justify-content-center"
                    style="min-height: 60vh;"
                >
                    <div class="text-center">
                        <img
                            :src="generate_img"
                            alt="generating"
                            class="img-fluid d-block mx-auto mb-4"
                            style="max-width: 270px; width: 100%;"
                        >
                        <div class="text-secondary mb-3">Генерация дашборда...</div>
                        <div class="progress progress-sm">
                            <div class="progress-bar progress-bar-indeterminate"></div>
                        </div>
                    </div>
                </div>

                <template v-else>
                    <div ref="exportArea">
                        <div
                            v-for="widget in widgets"
                            :key="widgetKey(widget)"
                            class="row row-cards widgets-content"
                        >
                            <div class="col-12 mt-4">
                                <h3 class="h3">{{ widget.title }}</h3>

                                <WidgetContainer
                                    :widget="widget"
                                    :chat-id="chatId"
                                    :refresh-token="refreshToken"
                                />
                            </div>
                        </div>
                    </div>
                </template>

            </div>
        </div>


        <div class="chat-backdrop d-print-none" :class="{ 'd-none': !chatOpen }" @click="closeChat"></div>

        <AiChatSidebar
            class="d-print-none"
            :open="chatOpen"
            :chat-title="chat.title"
            :chat-id="chatId"
            :dashboard-id="selectedDashboardId"
            @close="closeChat"
        />

        <button v-if="!chatOpen" class="chat-fab d-print-none" @click="toggleChat" aria-label="Открыть чат">
            <svg
                xmlns="http://www.w3.org/2000/svg"
                width="22"
                height="22"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="M12 8a4 4 0 0 1 4 4" />
                <path d="M12 4a8 8 0 0 1 8 8" />
                <path d="M12 20a8 8 0 0 1-8-8" />
                <circle cx="12" cy="12" r="1" />
            </svg>
        </button>

    </div>
</template>

<style>
:root {
    --chart-scatter-color-0: color-mix(in srgb, transparent, var(--tblr-primary) 100%);
    --chart-scatter-color-1: color-mix(in srgb, transparent, var(--tblr-pink) 100%);
}
</style>
