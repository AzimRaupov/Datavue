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
 * Семейство "pie": структура целого.
 *
 * options.chartType — "pie" (сплошной круг) или "donut" (кольцо);
 * options.startAngle / options.endAngle — полукольцо (-90 / 90).
 *
 * Раньше кольцо было отдельным компонентом DonutWidget.vue, отличавшимся
 * ровно одной строкой конфига.
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

    const series = props.series.map((value) => {
        const number = Number(value)
        return Number.isFinite(number) ? number : 0
    })

    if (!series.length) return

    const chartType = props.options.chartType === "donut" ? "donut" : "pie"
    const isSemi = props.options.startAngle !== undefined && props.options.endAngle !== undefined

    chart = new ApexCharts(chartRef.value, {
        chart: {
            type: chartType,
            fontFamily: "inherit",
            height: 240,
            sparkline: { enabled: true },
            animations: { enabled: false },
        },
        plotOptions: {
            pie: {
                // Полукольцо: рисуем только верхнюю половину и смещаем центр вниз,
                // иначе фигура повиснет в верхней части карточки.
                startAngle: isSemi ? props.options.startAngle : 0,
                endAngle: isSemi ? props.options.endAngle : 360,
                offsetY: isSemi ? 40 : 0,
                donut: {
                    size: chartType === "donut" ? "62%" : "0%",
                },
            },
        },
        series,
        labels: props.labels.map(String),
        colors: colorsFor(props.options),
        legend: {
            show: true,
            position: "bottom",
            offsetY: isSemi ? -20 : 12,
            markers: { width: 10, height: 10, radius: 100 },
            itemMargin: { horizontal: 8, vertical: 8 },
        },
        tooltip: { theme: "dark", fillSeriesColor: false },
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
