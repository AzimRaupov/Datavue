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
 * Семейство "treemap": доли категорий площадью блоков.
 * Замена круговой диаграмме, когда категорий десятки.
 *
 * options.distributed — каждый блок своим цветом вместо оттенков одного.
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

    chart = new ApexCharts(chartRef.value, {
        chart: {
            type: "treemap",
            fontFamily: "inherit",
            height: 320,
            toolbar: { show: false },
            animations: { enabled: false },
        },
        plotOptions: {
            treemap: {
                distributed: props.options.distributed === true,
                enableShades: props.options.distributed !== true,
                shadeIntensity: 0.4,
            },
        },
        dataLabels: {
            enabled: true,
            style: { fontSize: "12px" },
        },
        series: props.series,
        tooltip: { theme: "dark" },
        legend: { show: false },
        colors: colorsFor(props.options),
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
