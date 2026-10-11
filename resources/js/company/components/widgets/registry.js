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
 * подвешивают вкладку целиком, а не только его. Сервер отдаёт данные целиком
 * (до WidgetQueryRunner::MAX_FETCH_ROWS строк), поэтому читаемость
 * обеспечивает фронт — тремя способами, и ни один не теряет данные молча:
 *
 *  - ось (bar, combo, line, heatmap) показывается окном, по которому можно
 *    листать (см. AXIS_WINDOW и пейджер в WidgetContainer);
 *  - части целого (pie, radial) сворачивают хвост в «Прочее» (FOLD_LIMIT);
 *  - остальное (funnel, treemap, scatter, radar) режется по MAX_POINTS
 *    с пометкой «показано X из Y».
 */
const MAX_POINTS = 200;

/**
 * Сколько категорий оси видно за раз. Ось времени плотнее, чем категории,
 * поэтому окну линии можно быть шире.
 */
export const AXIS_WINDOW = {
    bar: 30,
    combo: 30,
    line: 60,
    heatmap: 30,
};

/**
 * Сколько долей остаётся у круговых до сворачивания хвоста в «Прочее».
 */
const FOLD_LIMIT = {
    pie: 12,
    radial: 8,
};

function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

/**
 * Ось выглядит как время: «2024-05», «2024-05-17», «2024-Q2», «2024-W21», «2024».
 * Для оси времени окно по умолчанию стоит на последних периодах — свежие данные
 * нужнее старых; у обычных категорий — на первых.
 */
function looksLikeTime(labels) {
    if (!labels.length) return false;

    const matching = labels.filter(label => /^\d{4}($|[-/.\s]?(\d{1,2}|[QqWw]\d{1,2})\b)/.test(String(label))).length;

    return matching / labels.length >= 0.8;
}

function axisLabels(familyName, content) {
    if (familyName === "line") {
        return Array.isArray(content?.labels) ? content.labels : [];
    }

    if (familyName === "heatmap") {
        const first = content?.series?.[0]?.data;

        return Array.isArray(first) ? first.map(cell => cell?.x ?? "") : [];
    }

    return Array.isArray(content?.categories) ? content.categories : [];
}

/**
 * Сведения об окне по оси или null, если ось умещается целиком (или у
 * семейства нет оси, по которой листают).
 */
function axisWindow(familyName, content, requestedOffset) {
    const size = AXIS_WINDOW[familyName];

    if (!size || !content) return null;

    const labels = axisLabels(familyName, content);
    const total = labels.length;

    if (total <= size) return null;

    const maxStart = total - size;
    const start = clamp(requestedOffset ?? (looksLikeTime(labels) ? maxStart : 0), 0, maxStart);
    const end = start + size;

    return {
        total,
        size,
        start,
        end,
        firstLabel: String(labels[start]),
        lastLabel: String(labels[end - 1]),
    };
}

/**
 * Режет series[i].data и параллельный ему массив категорий/подписей
 * по одному окну (bar, combo, line, radar-spider).
 */
function sliceByAxis(series, axis, start, end) {
    if (!Array.isArray(series) || !Array.isArray(axis)) {
        return { series, axis };
    }

    return {
        series: series.map(item =>
            item && Array.isArray(item.data)
                ? { ...item, data: item.data.slice(start, end) }
                : item
        ),
        axis: axis.slice(start, end),
    };
}

/**
 * Обрезает плоский числовой series вместе с параллельным labels
 * (funnel, radar-polarArea) — точка и подпись всегда идут парой.
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
 * Часть целого: оставляет крупнейшие доли, а хвост складывает в одну
 * долю «Прочее». Доли не отбрасываются — сумма круга остаётся равной целому.
 */
function foldFlat(series, labels, limit, otherLabel) {
    if (!Array.isArray(series) || series.length <= limit) {
        return { series, labels, folded: false };
    }

    const order = series
        .map((value, index) => ({ value: Number(value) || 0, label: labels?.[index] ?? "", index }))
        .sort((a, b) => b.value - a.value);

    const top = order.slice(0, limit - 1);
    const rest = order.slice(limit - 1).reduce((sum, item) => sum + item.value, 0);

    return {
        series: [...top.map(item => item.value), rest],
        labels: [...top.map(item => item.label), otherLabel],
        folded: true,
    };
}

/**
 * Обрезает собственные точки каждого ряда — у scatter нет общей оси
 * категорий, координаты точки лежат прямо в data ряда.
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
 * Treemap: блоки без общей оси, поэтому при переполнении оставляем самые
 * крупные — мелкие всё равно не разглядеть.
 */
function capTreemap(series) {
    if (!Array.isArray(series)) return series;

    return series.map(item => {
        if (!item || !Array.isArray(item.data) || item.data.length <= MAX_POINTS) return item;

        return {
            ...item,
            data: [...item.data].sort((a, b) => (Number(b?.y) || 0) - (Number(a?.y) || 0)).slice(0, MAX_POINTS),
        };
    });
}

function pointsIn(series) {
    return Array.isArray(series)
        ? series.reduce((sum, item) => sum + (Array.isArray(item?.data) ? item.data.length : 0), 0)
        : 0;
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
 * Всё, что нужно показать виджету.
 *
 * Возвращает:
 *  - props  — пропсы компонента семейства под форму его данных;
 *  - axis   — окно по оси ({ total, size, start, end, firstLabel, lastLabel })
 *             или null, если ось умещается целиком;
 *  - notice — пометка об урезанных данных ({ kind: 'folded' | 'capped', shown, total })
 *             или null.
 *
 * Компоненты принимают именно свои поля, а не сырой контент, — так несовпадение
 * формы видно здесь, а не внутри отрисовки.
 *
 * view.offset     — с какой категории начинается окно (null — по умолчанию);
 * view.otherLabel — подпись свёрнутой доли у круговых.
 */
export function prepare(familyName, content, options = {}, view = {}) {
    const data = content ?? {};
    const otherLabel = view.otherLabel ?? "Other";

    const done = (props, axis = null, notice = null) => ({ props, axis, notice });

    switch (familyName) {
        case "mini-counters":
            return done({ counters: data, options });

        case "table":
            return done({ table: data, options });

        case "bar":
        case "combo": {
            const axis = axisWindow(familyName, data, view.offset);
            const { series, axis: categories } = sliceByAxis(
                data.series,
                data.categories ?? [],
                axis?.start ?? 0,
                axis?.end ?? undefined
            );

            return done({ series, categories, options }, axis);
        }

        case "line": {
            const axis = axisWindow(familyName, data, view.offset);
            const { series, axis: labels } = sliceByAxis(
                data.series,
                data.labels ?? [],
                axis?.start ?? 0,
                axis?.end ?? undefined
            );

            return done({ series, labels, options }, axis);
        }

        case "pie":
        case "radial": {
            const total = Array.isArray(data.series) ? data.series.length : 0;
            const limit = FOLD_LIMIT[familyName];
            const { series, labels, folded } = foldFlat(data.series, data.labels ?? [], limit, otherLabel);

            return done(
                { series, labels, options },
                null,
                folded ? { kind: "folded", shown: limit - 1, total } : null
            );
        }

        case "funnel": {
            const total = Array.isArray(data.series) ? data.series.length : 0;
            const { series, labels } = capFlat(data.series, data.labels ?? []);

            return done(
                { series, labels, options },
                null,
                total > MAX_POINTS ? { kind: "capped", shown: MAX_POINTS, total } : null
            );
        }

        case "scatter": {
            const total = pointsIn(data.series);
            const series = capOwnData(data.series);
            const shown = pointsIn(series);

            return done({ series, options }, null, shown < total ? { kind: "capped", shown, total } : null);
        }

        case "treemap": {
            const total = pointsIn(data.series);
            const series = capTreemap(data.series);
            const shown = pointsIn(series);

            return done({ series, options }, null, shown < total ? { kind: "capped", shown, total } : null);
        }

        case "heatmap": {
            const axis = axisWindow(familyName, data, view.offset);
            const series = Array.isArray(data.series)
                ? data.series.map(item =>
                    item && Array.isArray(item.data) && axis
                        ? { ...item, data: item.data.slice(axis.start, axis.end) }
                        : item
                )
                : data.series;

            return done({ series, options }, axis);
        }

        case "radar": {
            // polar-area приходит с labels (точка = число, пары с labels),
            // обычный радар — с categories (точка = data ряда, пары с ними).
            if (options.chartType === "polarArea") {
                const total = Array.isArray(data.series) ? data.series.length : 0;
                const { series, labels } = capFlat(data.series, data.labels ?? data.categories ?? []);

                return done(
                    { series, categories: data.categories ?? [], labels, options },
                    null,
                    total > MAX_POINTS ? { kind: "capped", shown: MAX_POINTS, total } : null
                );
            }

            const categories = Array.isArray(data.categories) ? data.categories : [];
            const { series, axis } = sliceByAxis(data.series, categories, 0, MAX_POINTS);

            return done(
                { series, categories: axis, labels: data.labels ?? [], options },
                null,
                categories.length > MAX_POINTS ? { kind: "capped", shown: MAX_POINTS, total: categories.length } : null
            );
        }

        default:
            return done({ options });
    }
}

/**
 * Только пропсы — для мест, где окно по оси и пометки не нужны (галерея
 * виджетов рисует маленькие демо-данные).
 */
export function propsFor(familyName, content, options = {}) {
    return prepare(familyName, content, options).props;
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
