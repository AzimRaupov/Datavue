/**
 * Палитра рядов — одна на все семейства виджетов.
 *
 * Раньше этот массив был скопирован в десять компонентов слово в слово.
 * Пока цвета были только стандартные, разницы не было; с появлением своей
 * палитры у виджета правку пришлось бы вносить в десять мест, и один
 * забытый файл означал бы виджет, который цвета игнорирует.
 *
 * Значения — переменные CSS (см. resources/css/company/app.css): так график
 * следует за темой Tabler и остаётся согласованным с остальным интерфейсом.
 */
export const CHART_COLORS = [
    "var(--chart-color-1)",
    "var(--chart-color-2)",
    "var(--chart-color-3)",
    "var(--chart-color-4)",
    "var(--chart-color-5)",
    "var(--chart-color-6)",
    "var(--chart-color-7)",
    "var(--chart-color-8)",
];

/**
 * Цвета конкретного виджета: свои, если автор их выбрал, иначе общие.
 *
 * Своя палитра приходит из оформления виджета (query_spec.presentation.colors)
 * и добирается сюда через WidgetContainer. Пустой или битый список
 * игнорируется — виджет обязан нарисоваться в любом случае, пусть и
 * стандартными цветами.
 *
 * Недостающие цвета добираются из общей палитры: автор мог задать один
 * цвет, а рядов оказалось три, и оставлять их без цвета нельзя — ApexCharts
 * нарисует их чёрными.
 */
export function colorsFor(options = {}) {
    const chosen = options?.colors;

    if (!Array.isArray(chosen) || chosen.length === 0) return CHART_COLORS;

    // Позиция важнее плотности: пустая ячейка означает «этот ряд оставить
    // стандартным», а не «сдвинуть остальные». Выкинь мы пустые — цвет,
    // выбранный для третьего ряда, уехал бы на первый.
    const length = Math.max(chosen.length, CHART_COLORS.length);
    const result = [];

    for (let index = 0; index < length; index++) {
        const color = chosen[index];

        result.push(
            typeof color === "string" && color.trim() !== ""
                ? color.trim()
                : CHART_COLORS[index % CHART_COLORS.length]
        );
    }

    return result;
}
