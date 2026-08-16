<template>
    <div class="card">
        <div class="card-body">
            <div ref="chartRef"></div>

            <!-- Конверсия между этапами — главное, ради чего смотрят на воронку,
                 но сама диаграмма её не показывает. -->
            <div v-if="steps.length > 1" class="mt-3 small text-secondary">
                <div
                    v-for="step in steps.slice(1)"
                    :key="step.label"
                    class="d-flex justify-content-between border-top py-1"
                >
                    <span>{{ step.label }}</span>
                    <span>
                        <!-- .text-dark фиксирует чёрный и не переключается
                             вместе с темой — акцент даём весом шрифта. -->
                        <span class="fw-bold">{{ step.fromPrev }}%</span>
                        <span class="ms-2">от первого этапа: {{ step.fromFirst }}%</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from "vue"
import ApexCharts from "apexcharts"
import { colorsFor } from "./palette.js"

/**
 * Семейство "funnel": последовательные этапы процесса.
 *
 * ApexCharts не умеет воронку отдельным типом — она собирается из
 * горизонтальных полос с центрированным выравниванием (isFunnel).
 *
 * options.shape — "funnel" (сужается вниз) или "pyramid" (расширяется вниз).
 */
const props = defineProps({
    labels: { type: Array, default: () => [] },
    series: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
})

const chartRef = ref(null)
let chart = null

const steps = computed(() => {
    const values = props.series.map((value) => Number(value) || 0)
    const first = values[0] || 0

    return values.map((value, index) => ({
        label: String(props.labels[index] ?? ""),
        value,
        fromPrev: index === 0 || !values[index - 1]
            ? 100
            : Math.round((value / values[index - 1]) * 1000) / 10,
        fromFirst: first ? Math.round((value / first) * 1000) / 10 : 0,
    }))
})

const renderChart = async () => {
    await nextTick()
    if (!chartRef.value) return

    if (chart) {
        chart.destroy()
        chart = null
    }

    if (!props.series.length) return

    const isPyramid = props.options.shape === "pyramid"

    // Пирамида — та же воронка, просто этапы идут снизу вверх.
    const values = props.series.map((value) => Number(value) || 0)
    const labels = props.labels.map(String)

    const data = isPyramid ? [...values].reverse() : values
    const categories = isPyramid ? [...labels].reverse() : labels

    chart = new ApexCharts(chartRef.value, {
        chart: {
            type: "bar",
            fontFamily: "inherit",
            height: Math.max(220, data.length * 48 + 40),
            toolbar: { show: false },
            animations: { enabled: false },
        },
        plotOptions: {
            bar: {
                horizontal: true,
                distributed: true,
                barHeight: "78%",
                isFunnel: true,
            },
        },
        series: [{ name: "Этап", data }],
        xaxis: { categories },
        dataLabels: {
            enabled: true,
            formatter: (value, opt) =>
                `${opt.w.globals.labels[opt.dataPointIndex]}: ${value}`,
            dropShadow: { enabled: false },
        },
        colors: colorsFor(props.options),
        tooltip: { theme: "dark" },
        legend: { show: false },
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
