<template>

    <div class="card">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col">
                    <div ref="chartRef" class="position-relative"></div>
                </div>

            </div>

        </div>
    </div>

</template>

<style scoped>
.card {
    --chart-active-users-2-color-0: #206bc4;
    --chart-active-users-2-color-1: #ea4c89;
    --chart-active-users-2-color-2: #2fb344;
}
</style>

<script setup>
import { onMounted, ref, watch, onBeforeUnmount } from "vue"
import ApexCharts from "apexcharts"

const chartRef = ref(null)
let chart = null

const props = defineProps({
    labels: {
        type: Array,
        default: () => []
    },
    series: {
        type: Array,
        default: () => []
    }
})

// Следим за изменениями пропсов для динамического обновления
watch(() => [props.series, props.labels], ([newSeries, newLabels]) => {
    if (chart) {
        chart.updateOptions({
            series: newSeries,
            labels: newLabels
        })
    }
}, { deep: true })

onMounted(() => {
    if (!chartRef.value) return
    if (!window.ApexCharts && !ApexCharts) return

    chart = new ApexCharts(chartRef.value, {
        chart: {
            type: "line",
            height: 288,
            fontFamily: "inherit",
            toolbar: { show: false },
            animations: { enabled: false },
        },
        stroke: {
            width: 2,
            curve: "smooth",
            lineCap: "round",
        },
        // ИСПРАВЛЕНО: Передаем массив напрямую, без дополнительной обертки []
        series: props.series,
        labels: props.labels,
        colors: [
            "var(--chart-active-users-2-color-0)",
            "var(--chart-active-users-2-color-1)",
            "var(--chart-active-users-2-color-2)",
        ],
        grid: {
            padding: {
                top: -20,
                right: 0,
                left: -4,
                bottom: -4,
            },
            strokeDashArray: 4,
        },
        xaxis: {
            type: "category",
            labels: { padding: 0 },
            tooltip: { enabled: false },
        },
        yaxis: {
            labels: { padding: 4 },
        },
        tooltip: {
            theme: "dark",
        },
        legend: {
            show: false,
        },
    })

    chart.render()
})

onBeforeUnmount(() => {
    if (chart) {
        chart.destroy()
    }
})
</script>
