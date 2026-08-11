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

/**
 * Семейство "combo": две метрики разного масштаба на одной оси времени.
 *
 * Ряд с kind="line" уходит на правую ось — иначе процент рядом с выручкой
 * в миллионах превратился бы в прямую по нулю.
 *
 * options.columnKind — чем рисовать объёмный ряд: "column" или "area".
 */
const props = defineProps({
    categories: { type: Array, default: () => [] },
    series: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
})

const chartRef = ref(null)
let chart = null

const renderChart = async () => {
    await nextTick()
    if (!chartRef.value) return

    if (chart) {
        chart.destroy()
        chart = null
    }

    if (!props.series.length) return

    const columnKind = props.options.columnKind === "area" ? "area" : "column"

    // kind из данных описывает роль ряда; в ApexCharts она превращается
    // в конкретный тип отрисовки.
    const series = props.series.map((item) => ({
        name: item.name ?? "",
        type: item.kind === "line" ? "line" : columnKind,
        data: Array.isArray(item.data) ? item.data : [],
    }))

    const lineIndexes = series
        .map((item, index) => (item.type === "line" ? index : -1))
        .filter((index) => index !== -1)

    chart = new ApexCharts(chartRef.value, {
        chart: {
            type: "line",
            fontFamily: "inherit",
            height: 320,
            parentHeightOffset: 0,
            toolbar: { show: false },
            animations: { enabled: false },
        },
        plotOptions: {
            bar: { columnWidth: "50%", borderRadius: 2 },
        },
        stroke: {
            width: series.map((item) => (item.type === "line" ? 3 : 0)),
            curve: "smooth",
        },
        fill: {
            opacity: series.map((item) => (item.type === "area" ? 0.25 : 1)),
        },
        dataLabels: { enabled: false },
        series,
        // Своя ось для каждой линии: у линии и столбцов масштабы разные.
        yaxis: series.map((item, index) => ({
            seriesName: item.name,
            opposite: lineIndexes.includes(index),
            show: index === 0 || lineIndexes.includes(index),
            labels: { padding: 4 },
        })),
        xaxis: {
            categories: props.categories.map(String),
            labels: { padding: 0 },
            tooltip: { enabled: false },
            axisBorder: { show: false },
        },
        tooltip: { theme: "dark", shared: true, intersect: false },
        grid: {
            padding: { top: -20, right: 0, left: -4, bottom: -4 },
            strokeDashArray: 4,
        },
        colors: [
            "var(--chart-color-1)",
            "var(--chart-color-2)",
            "var(--chart-color-3)",
            "var(--chart-color-4)",
            "var(--chart-color-5)",
            "var(--chart-color-6)",
            "var(--chart-color-7)",
            "var(--chart-color-8)",
        ],
        legend: { show: true, position: "bottom" },
    })

    chart.render()
}

watch(
    () => [props.series, props.categories, props.options],
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
