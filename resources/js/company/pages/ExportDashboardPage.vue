<script setup>
import { onBeforeUnmount, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { useI18n } from "vue-i18n";
import api from "../api.js";
import DashboardWidgetsView from "../components/dashboard/DashboardWidgetsView.vue";

/**
 * Страница, которую открывает headless Chrome при выгрузке дашборда в PDF/Word
 * (см. DashboardExportJob и scripts/export-dashboard.mjs). Люди сюда не заходят.
 *
 * Рисует те же виджеты тем же компонентом, что и режим просмотра, но без шапки,
 * меню, выбора вида виджета и листания осей — всё управляющее прячется стилем
 * ниже. Когда все запросы закончились, страница поднимает флаг
 * window.__EXPORT_STATE__ = 'ready', по которому Chrome понимает, что пора снимать.
 */
const route = useRoute();
const { locale } = useI18n();

const widgets = ref([]);
const dashboard = ref(null);
const generatedAt = ref("");

// Считаем запросы в полёте: виджеты грузят данные сами, после появления на странице.
let pending = 0;
const requestId = api.interceptors.request.use((config) => {
    pending++;

    return config;
});
const responseId = api.interceptors.response.use(
    (response) => {
        pending--;

        return response;
    },
    (error) => {
        pending--;

        return Promise.reject(error);
    },
);

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Тихо ждём, пока запросов нет несколько проверок подряд. */
async function waitUntilIdle() {
    let quiet = 0;

    while (quiet < 4) {
        await sleep(150);
        quiet = pending === 0 ? quiet + 1 : 0;
    }
}

onMounted(async () => {
    try {
        const { data } = await api.get(`/dashboards/${route.params.id}`);

        dashboard.value = data;
        // Черновики (ещё без данных) на экране показывают заглушку загрузки — в документ они не нужны.
        widgets.value = (data.widgets ?? []).filter((widget) => widget.status !== "draft");

        window.__EXPORT_TITLE__ = data.name || "";
        generatedAt.value = new Date().toLocaleString(locale.value, {
            dateStyle: "long",
            timeStyle: "short",
        });
        window.__EXPORT_SUBTITLE__ = generatedAt.value;

        // Виджеты появились на странице и начинают свои запросы.
        await sleep(400);
        await waitUntilIdle();

        window.__EXPORT_STATE__ = "ready";
    } catch (err) {
        window.__EXPORT_STATE__ = `error: ${err.response?.status === 403 || err.response?.status === 404 ? "Нет доступа к дашборду" : err.message}`;
    }
});

onBeforeUnmount(() => {
    api.interceptors.request.eject(requestId);
    api.interceptors.response.eject(responseId);
});
</script>

<template>
    <div id="export-root" class="export-page">
        <div class="container-xl py-3">
            <div v-if="dashboard" class="mb-3">
                <h2 class="page-title mb-1">{{ dashboard.name }}</h2>
                <div class="text-secondary small">{{ generatedAt }}</div>
            </div>

            <DashboardWidgetsView
                :widgets="widgets"
                :dashboard-id="dashboard?.id"
                :can-edit="false"
            />
        </div>
    </div>
</template>

<style>
/* Из выгрузки уходит всё, что на экране служит управлению, а не данным:
   выбор вида виджета, листание оси и прочее с d-print-none. */
.export-page .d-print-none,
.export-page .export-hidden {
    display: none !important;
}

/* Заголовки таблицы — кнопки сортировки; в документе это просто текст без стрелок. */
.export-page .table-sort {
    pointer-events: none;
}

.export-page .table-sort::after {
    display: none !important;
}

</style>
