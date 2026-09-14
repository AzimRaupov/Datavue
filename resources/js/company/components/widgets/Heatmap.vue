<template>
    <div class="card">
        <div class="card-body">
            <div ref="chartRef"></div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from "vue"
import ApexCharts from "apexcharts"
import { colorsFor, resolveThemeColors } from "./palette.js"

/**
 * Семейство "heatmap": метрика на пересечении двух измерений.
 *
 * options.discrete — раскладывать значения по диапазонам с отдельным цветом
 * вместо непрерывного градиента.
 */
const props = defineProps({
    series: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
})

const chartRef = ref(null)
let chart = null

/**
 * Один общий диапазон цвета на всю матрицу ломается, когда у строк разный
 * порядок величин («экономкласс» и «бизнес-класс» бронирований отличаются
 * в разы): строка с маленькими числами занимает крошечный кусочек общей
 * шкалы и красится в один и тот же оттенок по всей длине, хотя внутри неё
 * разброс есть. Поэтому ApexCharts получает не сами значения, а положение
 * ячейки внутри диапазона ЕЁ СТРОКИ (0..100) — а настоящее число прячем
 * рядом в realValue и достаём его же в тултипе, чтобы человек видел не
 * проценты, а исходную величину.
 */
function rowMinMax(row) {
    const values = (row.data ?? []).map((cell) => Number(cell?.y ?? 0)).filter(Number.isFinite)

    if (!values.length) return { min: 0, max: 0 }

    return { min: Math.min(...values), max: Math.max(...values) }
}

function normalizeSeries(series) {
    return series.map((row) => {
        const { min, max } = rowMinMax(row)
        const span = max - min

        return {
            name: row.name,
            data: (row.data ?? []).map((cell) => {
                const realValue = Number(cell?.y ?? 0)
                const relative = span > 0 ? ((realValue - min) / span) * 100 : 50

                return { x: cell?.x, y: Math.round(relative * 100) / 100, realValue }
            }),
        }
    })
}

/** Ступени по относительной шкале 0..100 — она одна и та же для всех строк. */
const buildRanges = () => {
    // Раскладка диапазона (getShadeColor → shadeColor) разбирает цвет вручную
    // регуляркой на hex/rgb — «var(--chart-color-3)» она не парсит и на любом
    // значении молча откатывается к запасному серому (#999999 в hexToRgba).
    // Резолвим переменные в реальный rgb() заранее, как и для colors ниже.
    const colors = resolveThemeColors([
        "var(--chart-color-3)",
        "var(--chart-color-4)",
        "var(--chart-color-2)",
        "var(--chart-color-8)",
    ])
    const step = 100 / colors.length

    return colors.map((color, index) => {
        const from = step * index
        const to = index === colors.length - 1 ? 100 : step * (index + 1)

        return { from, to, color, name: `${Math.round(from)}–${Math.round(to)}%` }
    })
}

/** Настоящее значение ячейки — apex хранит объект точки как есть в w.config. */
const realValueOf = (w, seriesIndex, dataPointIndex) =>
    w?.config?.series?.[seriesIndex]?.data?.[dataPointIndex]?.realValue

const renderChart = async () => {
    await nextTick()
    if (!chartRef.value) return

    if (chart) {
        chart.destroy()
        chart = null
    }

    if (!props.series.length) return

    const discrete = props.options.discrete === true
    const series = normalizeSeries(props.series)
    const ranges = discrete ? buildRanges() : undefined

    chart = new ApexCharts(chartRef.value, {
        chart: {
            type: "heatmap",
            fontFamily: "inherit",
            height: Math.max(240, Math.min(520, props.series.length * 32 + 80)),
            toolbar: { show: false },
            animations: { enabled: false },
        },
        plotOptions: {
            heatmap: {
                shadeIntensity: 0.6,
                radius: 2,
                useFillColorAsStroke: false,
                colorScale: ranges ? { ranges } : {},
            },
        },
        dataLabels: { enabled: false },
        series,
        tooltip: {
            theme: "dark",
            y: { formatter: (_value, opts) => realValueOf(opts?.w, opts?.seriesIndex, opts?.dataPointIndex) },
        },
        stroke: { width: 1 },
        colors: resolveThemeColors(colorsFor(props.options)),
        xaxis: {
            type: "category",
            labels: { rotate: -45, trim: true },
            tooltip: { enabled: false },
        },
        legend: { show: discrete, position: "bottom" },
    })

    chart.render()
}

watch(
    () => [props.series, props.options],
    renderChart,
    { deep: true }
)

onMounted(renderChart)

onBeforeUnmount(() => {
    if (chart) {
        chart.destroy()
        chart = null
    }
})
</script>
