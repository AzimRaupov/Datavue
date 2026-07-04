<template>
    <div ref="chartRef" class="position-relative"></div>

</template>


<script setup>
import { ref, onMounted, watch, onBeforeUnmount } from "vue"
import ApexCharts from "apexcharts"

const chartRef = ref(null)
let chart = null

const props = defineProps({
    series: {
        type: Array,
        default: () => []
    },
    categories: {
        type: Array,
        default: () => []
    }
})

// Следим за изменениями данных, чтобы график обновлялся на лету
watch(() => [props.series, props.categories], ([newSeries, newCategories]) => {
    if (chart) {
        chart.updateOptions({
            series: newSeries,
            xaxis: {
                categories: newCategories
            }
        })
    }
}, { deep: true })

onMounted(() => {
    if (!chartRef.value) return
    if (!window.ApexCharts && !ApexCharts) return

    chart = new ApexCharts(chartRef.value, {
        chart: {
            type: "scatter",
            fontFamily: "inherit",
            height: 240,
            parentHeightOffset: 0,
            toolbar: {
                show: false,
            },
            animations: {
                enabled: false,
            },
        },
        // Данные берутся из реактивных props
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
            categories: props.categories,
        },
        yaxis: {
            labels: {
                padding: 4,
            },
        },
        colors: ["var(--chart-scatter-color-0)", "var(--chart-scatter-color-1)"],
        legend: {
            show: false,
        },
    })

    chart.render()
})

// Чистим память при уничтожении компонента
onBeforeUnmount(() => {
    if (chart) {
        chart.destroy()
    }
})
</script>
