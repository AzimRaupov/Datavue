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
 * Семейство "radial": процент достижения цели.
 *
 * options.multiple — несколько вложенных колец вместо одного индикатора.
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
        return Number.isFinite(number) ? Math.max(number, 0) : 0
    })

    if (!series.length) return

    const multiple = props.options.multiple === true && series.length > 1

    chart = new ApexCharts(chartRef.value, {
        chart: {
            type: "radialBar",
            fontFamily: "inherit",
            height: 260,
            animations: { enabled: false },
        },
        plotOptions: {
            radialBar: {
                hollow: { size: multiple ? "40%" : "62%" },
                track: { strokeWidth: "90%" },
                dataLabels: {
                    name: { fontSize: "13px" },
                    value: {
                        fontSize: "20px",
                        formatter: (value) => `${Math.round(value)}%`,
                    },
                    // У одного индикатора подпись "total" не нужна: значение
                    // и так стоит в центре. У нескольких — показываем среднее.
                    total: multiple
                        ? {
                            show: true,
                            label: "Среднее",
                            formatter: () => {
                                const sum = series.reduce((acc, value) => acc + value, 0)
                                return `${Math.round(sum / series.length)}%`
                            },
                        }
                        : { show: false },
                },
            },
        },
        series,
        labels: props.labels.map(String),
        colors: colorsFor(props.options),
        legend: {
            show: multiple,
            position: "bottom",
        },
        tooltip: { theme: "dark" },
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
