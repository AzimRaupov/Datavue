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
 * Семейство "scatter": связь двух числовых метрик.
 *
 * options.chartType — "scatter" (точки, данные [x, y]) или
 * "bubble" (размер точки кодирует третью метрику, данные [x, y, z]).
 */
const props = defineProps({
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

    const chartType = props.options.chartType === "bubble" ? "bubble" : "scatter"

    chart = new ApexCharts(chartRef.value, {
        chart: {
            type: chartType,
            fontFamily: "inherit",
            height: 280,
            parentHeightOffset: 0,
            toolbar: { show: false },
            zoom: { enabled: false },
            animations: { enabled: false },
        },
        series: props.series,
        // У пузырьков площадь несёт смысл, поэтому им нужна заметная
        // прозрачность — иначе крупные полностью перекрывают мелкие.
        fill: { opacity: chartType === "bubble" ? 0.7 : 0.9 },
        markers: { size: chartType === "bubble" ? 0 : 6 },
        dataLabels: { enabled: false },
        tooltip: { theme: "dark" },
        grid: {
            padding: { top: -20, right: 0, left: -4, bottom: -4 },
            strokeDashArray: 4,
        },
        // Обе оси числовые: категорий у диаграммы рассеяния нет по определению.
        xaxis: {
            type: "numeric",
            tickAmount: 6,
            labels: { padding: 0 },
            tooltip: { enabled: false },
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
