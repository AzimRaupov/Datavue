<template>
    <div class="card">
        <div class="card-body">
            <div ref="chartRef"></div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from "vue"
import ApexCharts from "apexcharts"

const chartRef = ref(null)

const props = defineProps({
    labels: Array,
    series: Array
})


onMounted(() => {
    if (!window.ApexCharts && !ApexCharts) return

    const chart = new ApexCharts(chartRef.value, {
        chart: {
            type: "pie",
            fontFamily: "inherit",
            height: 240,
            sparkline: {
                enabled: true,
            },
            animations: {
                enabled: false,
            },
        },
        series: props.series,
        labels: props.labels,
        colors: [
            "var(--chart-demo-pie-color-0)",
            "var(--chart-demo-pie-color-1)",
            "var(--chart-demo-pie-color-2)",
        ],
        legend: {
            show: true,
            position: "bottom",
            offsetY: 12,
            markers: {
                width: 10,
                height: 10,
                radius: 100,
            },
            itemMargin: {
                horizontal: 8,
                vertical: 8,
            },
        },
        tooltip: {
            theme: "dark",
            fillSeriesColor: false,
        },
    })

    chart.render()
})
</script>
