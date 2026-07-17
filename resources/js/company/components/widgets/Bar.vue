<template>
        <div class="card">
            <div class="card-body">
                <div ref="chartRef" class="position-relative"></div>
            </div>
        </div>
</template>



<script setup>
import { onMounted, ref, watch, onBeforeUnmount } from "vue"
import ApexCharts from "apexcharts"

const chartRef = ref(null)
let chart = null

const props = defineProps({
    categories: {
        type: Array,
        default: () => []
    },
    series: {
        type: Array,
        default: () => []
    }
})

// Следим за изменениями пропсов для динамического обновления
watch(() => [props.series, props.categories], ([newSeries, newLabels]) => {
    if (chart) {
        chart.updateOptions({
            series: newSeries,
            categories: newLabels
        })
    }
}, { deep: true })

onMounted(() => {
    if (!chartRef.value) return
    if (!window.ApexCharts && !ApexCharts) return

    chart = new ApexCharts(chartRef.value, {
        chart: {
            type: "bar",
            fontFamily: "inherit",
            height: 320,
            parentHeightOffset: 0,
            toolbar: {
                show: false,
            },
            animations: {
                enabled: false,
            },
        },
        plotOptions: {
            bar: {
                columnWidth: "50%",
            },
        },
        dataLabels: {
            enabled: false,
        },
        series: props.series,
        tooltip: {
            theme: "dark",
        },
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
            labels: {
                padding: 0,
            },
            tooltip: {
                enabled: false,
            },
            axisBorder: {
                show: false,
            },
            categories: props.categories,
        },
        yaxis: {
            labels: {
                padding: 4,
            },
        },
        colors: ["var(--chart-tasks-overview-color-0)"],
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
