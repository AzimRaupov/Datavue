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
import { colorsFor } from "./palette.js"

/**
 * Семейство "line": динамика во времени.
 *
 * options.chartType — "line" или "area" (с заливкой);
 * options.curve     — "smooth" | "straight" | "stepline";
 * options.stacked   — накопительные области.
 */
const props = defineProps({
    labels: { type: Array, default: () => [] },
    series: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
})

const chartRef = ref(null)
let chart = null

/** Насколько один ряд должен быть мельче другого, чтобы уйти на свою ось. */
const AXIS_SCALE_RATIO = 8

function seriesMax(item) {
    const values = Array.isArray(item?.data) ? item.data.map(Number).filter(Number.isFinite) : []
    return values.length ? Math.max(...values.map(Math.abs)) : 0
}

/**
 * Несколько рядов на одной оси ломаются, когда их масштаб отличается на
 * порядки: «выручка» тянет ось под свой максимум, и «количество броней»
 * рядом с ней превращается в плоскую линию у нуля. Модель заранее это не
 * предвидит (у неё нет понятия оси, только числа), поэтому решаем по самим
 * данным — так же, как Combo.vue уже уводит line-ряд на правую ось.
 */
function buildYAxis(series) {
    const shared = { labels: { padding: 4 } }

    if (series.length < 2) return shared

    const maxes = series.map(seriesMax)
    const overallMax = Math.max(0, ...maxes)

    if (overallMax === 0) return shared

    const isSecondary = maxes.map((max) => max > 0 && max * AXIS_SCALE_RATIO < overallMax)

    // Раздельные оси нужны только когда масштаб реально разошёлся: если все
    // ряды крупные (или все мелкие), общая ось их не портит.
    if (!isSecondary.includes(true) || isSecondary.every(Boolean)) return shared

    const shownForBucket = { primary: false, secondary: false }

    return series.map((item, index) => {
        const bucket = isSecondary[index] ? "secondary" : "primary"
        const isFirstOfBucket = !shownForBucket[bucket]
        shownForBucket[bucket] = true

        return {
            seriesName: item.name,
            opposite: isSecondary[index],
            show: isFirstOfBucket,
            labels: { padding: 4 },
        }
    })
}

const renderChart = async () => {
    await nextTick()
    if (!chartRef.value) return

    if (chart) {
        chart.destroy()
        chart = null
    }

    if (!props.series.length) return

    const chartType = props.options.chartType === "area" ? "area" : "line"
    const curve = props.options.curve || "smooth"
    const stacked = props.options.stacked === true

    chart = new ApexCharts(chartRef.value, {
        chart: {
            type: chartType,
            stacked,
            fontFamily: "inherit",
            height: 280,
            parentHeightOffset: 0,
            toolbar: { show: false },
            animations: { enabled: false },
        },
        dataLabels: { enabled: false },
        stroke: {
            width: 2,
            lineCap: "round",
            curve,
        },
        // Заливка нужна только типам area; у линии она забивает сетку.
        fill: chartType === "area"
            ? { type: "gradient", gradient: { opacityFrom: 0.35, opacityTo: 0.05 } }
            : { opacity: 1 },
        series: props.series,
        labels: props.labels.map(String),
        tooltip: { theme: "dark", shared: true, intersect: false },
        grid: {
            padding: { top: -20, right: 0, left: -4, bottom: -4 },
            strokeDashArray: 4,
        },
        xaxis: {
            type: "category",
            labels: { padding: 0 },
            tooltip: { enabled: false },
            axisBorder: { show: false },
        },
        yaxis: buildYAxis(props.series),
        colors: colorsFor(props.options),
        legend: {
            show: props.series.length > 1,
            position: "bottom",
        },
    })

    chart.render()
}

watch(
    () => [props.series, props.labels, props.options],
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
