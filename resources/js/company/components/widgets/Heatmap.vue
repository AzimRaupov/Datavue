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
 * Пороговые диапазоны строим по реальному минимуму и максимуму: фиксированные
 * границы на чужих данных дали бы одноцветную матрицу.
 */
const buildRanges = (series) => {
    const values = series.flatMap((row) =>
        (row.data ?? []).map((cell) => Number(cell?.y ?? 0))
    ).filter(Number.isFinite)

    if (!values.length) return undefined

    const min = Math.min(...values)
    const max = Math.max(...values)
    const step = (max - min) / 4 || 1

    const colors = [
        "var(--chart-color-3)",
        "var(--chart-color-4)",
        "var(--chart-color-2)",
        "var(--chart-color-8)",
    ]

    return colors.map((color, index) => ({
        from: min + step * index,
        to: index === colors.length - 1 ? max : min + step * (index + 1),
        color,
        name: `${Math.round(min + step * index)} — ${Math.round(
            index === colors.length - 1 ? max : min + step * (index + 1)
        )}`,
    }))
}

const renderChart = async () => {
    await nextTick()
    if (!chartRef.value) return

    if (chart) {
        chart.destroy()
        chart = null
    }

    if (!props.series.length) return

    const discrete = props.options.discrete === true
    const ranges = discrete ? buildRanges(props.series) : undefined

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
        series: props.series,
        tooltip: { theme: "dark" },
        stroke: { width: 1 },
        colors: colorsFor(props.options),
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
