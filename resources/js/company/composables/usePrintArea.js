import { onMounted, onBeforeUnmount } from "vue";

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
 * Печать зоны дашборда целиком, а не только видимой части.
 *
 * Страница со списком/чатом обычно зажата в 100vh с внутренним скроллом —
 * при печати это надо снять, иначе распечатается только то, что видно на
 * экране. Перед печатью браузер разворачивает прокручиваемые контейнеры
 * (таблицы, графики), после — сворачивает обратно.
 *
 * Общий приём для WorkspacePage (аналитик/админ) и DirectorDashboardViewer
 * (директор) — оба печатают один и тот же ref-контейнер с виджетами.
 */
export function usePrintArea(exportAreaRef) {
    let restoreList = [];

    function handleBeforePrint() {
        restoreList = expandScrollableAreas(exportAreaRef.value);
    }

    function handleAfterPrint() {
        restoreScrollableAreas(restoreList);
        restoreList = [];
    }

    onMounted(() => {
        window.addEventListener("beforeprint", handleBeforePrint);
        window.addEventListener("afterprint", handleAfterPrint);
    });

    onBeforeUnmount(() => {
        window.removeEventListener("beforeprint", handleBeforePrint);
        window.removeEventListener("afterprint", handleAfterPrint);
    });

    function printDashboard() {
        window.print();
    }

    return { printDashboard };
}
