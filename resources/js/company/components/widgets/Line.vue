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
        tooltip: { theme: "dark" },
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
        yaxis: {
            labels: { padding: 4 },
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
