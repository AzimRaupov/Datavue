import { inject, onBeforeUnmount, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Capacitor } from "@capacitor/core";
import { Browser } from "@capacitor/browser";
import api from "../api.js";

const POLL_MS = 1500;
const MAX_WAIT_MS = 10 * 60 * 1000;

/**
 * Выгрузка дашборда в PDF или Word.
 *
 * Файл делает сервер (DashboardExportJob): открывает дашборд в headless Chrome
 * и снимает его как на экране. Здесь — только запуск, ожидание готовности и
 * скачивание. Опрос, а не сокет: так же работает в приложении и не зависит от Reverb.
 */
export function useDashboardExport(getDashboardId) {
    const { t } = useI18n();
    const toast = inject("toast", null);

    // 'pdf' | 'docx' | null — какой формат сейчас готовится.
    const busy = ref(null);

    let stopped = false;

    onBeforeUnmount(() => {
        stopped = true;
    });

    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    async function download(url) {
        if (Capacitor.isNativePlatform()) {
            // В приложении ссылку открывает системный браузер: WebView файлы не скачивает.
            await Browser.open({ url });

            return;
        }

        // Ответ сервера — вложение, поэтому страница остаётся на месте.
        const link = document.createElement("a");
        link.href = url;
        link.rel = "noopener";
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    async function start(format) {
        const id = getDashboardId();

        if (!id || busy.value) return;

        busy.value = format;
        stopped = false;

        try {
            let { data } = await api.post(`/dashboards/${id}/export`, { format });

            toast?.value?.info?.(t("dashboardExport.started"));

            const deadline = Date.now() + MAX_WAIT_MS;

            while (data.status !== "ready" && data.status !== "failed") {
                if (stopped || Date.now() > deadline) return;

                await sleep(POLL_MS);

                ({ data } = await api.get(`/dashboard-exports/${data.id}`));
            }

            if (data.status === "failed") {
                toast?.value?.error?.(data.error || t("dashboardExport.failed"));

                return;
            }

            await download(data.url);
            toast?.value?.success?.(t("dashboardExport.ready"));
        } catch (err) {
            toast?.value?.error?.(err.response?.data?.message || t("dashboardExport.failed"));
        } finally {
            busy.value = null;
        }
    }

    return { busy, start };
}
