import MiniCounters from "./MiniCounters.vue";
import Bar from "./Bar.vue";
import Line from "./Line.vue";
import Pie from "./Pie.vue";
import Radial from "./Radial.vue";
import Combo from "./Combo.vue";
import Table from "./Table.vue";
import Scatter from "./Scatter.vue";
import Radar from "./Radar.vue";
import Heatmap from "./Heatmap.vue";
import Treemap from "./Treemap.vue";
import Funnel from "./Funnel.vue";

/**
 * Реестр семейств виджетов.
 *
 * Один источник правды о том, каким компонентом рисуется семейство и какие
 * пропсы он ждёт. Используется и на дашборде (WidgetContainer), и в галерее
 * виджетов — иначе галерея показывала бы не то, что реально отрисуется.
 *
 * placeholder — форма заглушки на время генерации.
 */
export const FAMILIES = {
    "mini-counters": { component: MiniCounters, placeholder: "counters" },
    "bar": { component: Bar, placeholder: "bars" },
    "line": { component: Line, placeholder: "chart" },
    "pie": { component: Pie, placeholder: "circle" },
    "radial": { component: Radial, placeholder: "circle" },
    "combo": { component: Combo, placeholder: "bars" },
    "table": { component: Table, placeholder: "table" },
    "scatter": { component: Scatter, placeholder: "chart" },
    "radar": { component: Radar, placeholder: "chart" },
    "heatmap": { component: Heatmap, placeholder: "bars" },
    "treemap": { component: Treemap, placeholder: "chart" },
    "funnel": { component: Funnel, placeholder: "bars" },
};

export function familyOf(name) {
    return FAMILIES[name] ?? null;
}

/**
 * Пропсы под форму данных семейства.
 *
 * Компоненты принимают именно свои поля, а не сырой контент, — так несовпадение
 * формы видно здесь, а не внутри отрисовки.
 */
export function propsFor(familyName, content, options = {}) {
    const data = content ?? {};

    switch (familyName) {
        case "mini-counters":
            return { counters: data, options };

        case "table":
            return { table: data, options };

        case "bar":
        case "combo":
            return { series: data.series, categories: data.categories ?? [], options };

        case "line":
        case "pie":
        case "radial":
        case "funnel":
            return { series: data.series, labels: data.labels ?? [], options };

        case "scatter":
        case "heatmap":
        case "treemap":
            return { series: data.series, options };

        case "radar":
            // polar-area приходит с labels, обычный радар — с categories.
            return {
                series: data.series,
                categories: data.categories ?? [],
                labels: data.labels ?? [],
                options,
            };

        default:
            return { options };
    }
}

/**
 * Есть ли в содержимом данные, которые семейство умеет нарисовать.
 */
export function hasData(familyName, content) {
    if (!content) return false;

    if (familyName === "mini-counters") {
        return Array.isArray(content.counters) && content.counters.length > 0;
    }

    if (familyName === "table") {
        return Boolean(content.headers && content.rows);
    }

    return Array.isArray(content.series) && content.series.length > 0;
}
