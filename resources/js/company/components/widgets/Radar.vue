<template>
    <div class="card">
        <div class="card-body">
            <div ref="chartRef"></div>
        </div>
    </div>
</template>

<script setup>
import {
    ref,
    onMounted,
    onBeforeUnmount,
    watch,
} from "vue"

import ApexCharts from "apexcharts"

const props = defineProps({
    categories: {
        type: Array,
        default: () => [],
    },

    series: {
        type: Array,
        default: () => [],
    },
})

const chartRef = ref(null)

let chart = null


const renderChart = async () => {
    if (!chartRef.value) {
        return
    }

    // Если старый график существует — удаляем его
    if (chart) {
        chart.destroy()
        chart = null
    }

    // Нормализуем series
    const series = props.series.map((item) => ({
        name: item.name ?? "",
        data: Array.isArray(item.data)
            ? item.data.map(Number)
            : [],
    }))

    // Нормализуем categories
    const categories = props.categories.map(String)

    // Если данных нет — ничего не рисуем
    if (!series.length || !categories.length) {
        return
    }

    const options = {
        series,

        chart: {
            type: "radar",
            height: 400,
            fontFamily: "inherit",

            animations: {
                enabled: false,
            },

            toolbar: {
                show: false,
            },
        },

        title: {
            text: "",
            align: "left",
        },

        xaxis: {
            categories,

            labels: {
                show: true,

                style: {
                    colors: categories.map(() => "#000000"),
                    fontSize: "13px",
                    fontWeight: 400,
                },
            },
        },

        yaxis: {
            min: 0,
            max: 100,
            stepSize: 20,
        },

        colors: [
            "#206bc4",
            "#2fb344",
            "#d63939",
            "#ae3ec9",
            "#f59f00",
            "#0ca678",
        ],

        stroke: {
            width: 2,
        },

        fill: {
            opacity: 0.15,
        },

        plotOptions: {
            radar: {
                size: 130,

                polygons: {
                    strokeColors: "#d9dee4",
                    strokeWidth: 1,

                    connectorColors: "#d9dee4",

                    fill: {
                        colors: [
                            "#f8f9fa",
                            "#ffffff",
                        ],
                    },
                },
            },
        },

        markers: {
            size: 4,

            strokeWidth: 1,
            strokeColors: "#ffffff",
        },

        legend: {
            show: series.length > 1,
            position: "bottom",
            offsetY: 12,

            labels: {
                colors: "#000000",
            },

            markers: {
                width: 10,
                height: 10,
                radius: 100,
            },

            itemMargin: {
                horizontal: 10,
                vertical: 8,
            },
        },

        tooltip: {
            theme: "dark",
        },

        responsive: [
            {
                breakpoint: 768,

                options: {
                    chart: {
                        height: 350,
                    },

                    plotOptions: {
                        radar: {
                            size: 110,
                        },
                    },

                    xaxis: {
                        labels: {
                            style: {
                                fontSize: "11px",
                            },
                        },
                    },
                },
            },
        ],
    }

    chart = new ApexCharts(
        chartRef.value,
        options
    )

    await chart.render()
}


onMounted(() => {
    renderChart()
})


watch(
    () => [
        props.categories,
        props.series,
    ],
    () => {
        renderChart()
    },
    {
        deep: true,
    }
)


onBeforeUnmount(() => {
    if (chart) {
        chart.destroy()
        chart = null
    }
})
</script>
