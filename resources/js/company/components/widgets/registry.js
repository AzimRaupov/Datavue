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
 *
 * colors — умеет ли семейство красить ряды своей палитрой. Признак живёт
 * здесь, рядом с компонентом, потому что отвечает на него именно компонент:
 * если он не читает options.colors, шторка настройки не должна предлагать
 * выбор цвета. Пока такого признака не было, у таблицы и счётчиков честно
 * показывались восемь ячеек палитры и рапортовалось «Оформление сохранено»,
 * а на виджете не менялось ничего — обещание, которого продукт не выполнял.
 */
export const FAMILIES = {
    "mini-counters": { component: MiniCounters, placeholder: "counters", colors: true },
    "bar": { component: Bar, placeholder: "bars", colors: true },
    "line": { component: Line, placeholder: "chart", colors: true },
    "pie": { component: Pie, placeholder: "circle", colors: true },
    "radial": { component: Radial, placeholder: "circle", colors: true },
    "combo": { component: Combo, placeholder: "bars", colors: true },
    // У таблицы нет рядов — красить нечего.
    "table": { component: Table, placeholder: "table", colors: false },
    "scatter": { component: Scatter, placeholder: "chart", colors: true },
    "radar": { component: Radar, placeholder: "chart", colors: true },
    "heatmap": { component: Heatmap, placeholder: "bars", colors: true },
    "treemap": { component: Treemap, placeholder: "chart", colors: true },
    "funnel": { component: Funnel, placeholder: "bars", colors: true },
};

export function familyOf(name) {
    return FAMILIES[name] ?? null;
}

/**
 * Потолок числа точек на один график.
 *
 * ApexCharts рисует SVG-узел на каждую точку, а на дашборде одновременно
 * отрисовывается несколько виджетов сразу — лишние точки одного графика
 * подвешивают вкладку целиком, а не только его. 200 — уже принятая в
 * проекте граница «одной страницы данных» (WidgetQueryRunner::MAX_PER_PAGE
 * на бэке), и график с таким числом точек всё ещё читаем глазом: больше
 * категорий на оси X всё равно превращается в нечитаемую кашу подписей
 * раньше, чем начинает лагать браузер.
 */
const MAX_POINTS = 200;

/**
 * Обрезает series[i].data и параллельный ему массив категорий/подписей
 * до одной длины (bar, combo, line, radar-spider).
 */
function capByAxis(series, axis) {
    if (!Array.isArray(series) || !Array.isArray(axis)) {
        return { series, axis };
    }

    const limit = Math.min(axis.length, MAX_POINTS);

    return {
        series: series.map(item =>
            item && Array.isArray(item.data)
                ? { ...item, data: item.data.slice(0, limit) }
                : item
        ),
        axis: axis.slice(0, limit),
    };
}

/**
 * Обрезает плоский числовой series вместе с параллельным labels
 * (pie, radial, funnel, radar-polarArea) — точка и подпись всегда идут парой.
 */
function capFlat(series, labels) {
    if (!Array.isArray(series)) {
        return { series, labels };
    }

    const limit = Math.min(series.length, MAX_POINTS);

    return {
        series: series.slice(0, limit),
        labels: Array.isArray(labels) ? labels.slice(0, limit) : labels,
    };
}

/**
 * Обрезает собственные точки каждого ряда — у scatter/heatmap/treemap нет
 * общей оси категорий, координаты точки лежат прямо в data ряда.
 */
function capOwnData(series) {
    if (!Array.isArray(series)) return series;

    return series.map(item =>
        item && Array.isArray(item.data)
            ? { ...item, data: item.data.slice(0, MAX_POINTS) }
            : item
    );
}

/**
 * Красит ли это семейство ряды выбранной палитрой.
 *
 * Незнакомое семейство считаем красящим: скрытая настройка хуже лишней —
 * новый виджет починят по жалобе «цвет не применился», а не по её отсутствию.
 */
export function supportsColors(name) {
    return FAMILIES[name]?.colors !== false;
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
        case "combo": {
            const { series, axis } = capByAxis(data.series, data.categories ?? []);
            return { series, categories: axis, options };
        }

        case "line": {
            const { series, axis } = capByAxis(data.series, data.labels ?? []);
            return { series, labels: axis, options };
        }

        case "pie":
        case "radial":
        case "funnel": {
            const { series, labels } = capFlat(data.series, data.labels ?? []);
            return { series, labels, options };
        }

        case "scatter":
        case "heatmap":
        case "treemap":
            return { series: capOwnData(data.series), options };

        case "radar": {
            // polar-area приходит с labels (точка = число, пары с labels),
            // обычный радар — с categories (точка = data ряда, пары с ними).
            if (options.chartType === "polarArea") {
                const { series, labels } = capFlat(data.series, data.labels ?? data.categories ?? []);
                return { series, categories: data.categories ?? [], labels, options };
            }

            const { series, axis } = capByAxis(data.series, data.categories ?? []);
            return { series, categories: axis, labels: data.labels ?? [], options };
        }

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
