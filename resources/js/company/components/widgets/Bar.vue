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
 * Семейство "bar": сравнение метрики по категориям.
 *
 * options.horizontal — полосы вправо вместо столбцов вверх;
 * options.stacked    — ряды складываются друг на друга;
 * options.stackType  — "100%" для долей внутри категории.
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

    const horizontal = props.options.horizontal === true
    const stacked = props.options.stacked === true
    const stackType = props.options.stackType

    // Горизонтальным полосам нужно больше высоты и места слева под подписи —
    // ради этого их и выбирают, когда названия категорий длинные.
    const height = horizontal
        ? Math.max(240, Math.min(560, props.categories.length * 28 + 80))
        : 320

    chart = new ApexCharts(chartRef.value, {
        chart: {
            type: "bar",
            stacked,
            stackType: stackType || undefined,
            fontFamily: "inherit",
            height,
            parentHeightOffset: 0,
            toolbar: { show: false },
            animations: { enabled: false },
        },
        plotOptions: {
            bar: {
                horizontal,
                columnWidth: "50%",
                barHeight: "70%",
                borderRadius: 2,
            },
        },
        dataLabels: { enabled: false },
        series: props.series,
        tooltip: { theme: "dark" },
        grid: {
            padding: {
                top: -20,
                right: 0,
                left: horizontal ? 8 : -4,
                bottom: -4,
            },
            strokeDashArray: 4,
        },
        xaxis: {
            labels: { padding: 0 },
            tooltip: { enabled: false },
            axisBorder: { show: false },
            categories: props.categories.map(String),
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
        // Легенда нужна только когда рядов больше одного — иначе она
        // дублирует заголовок виджета.
        legend: {
            show: props.series.length > 1,
            position: "bottom",
        },
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
