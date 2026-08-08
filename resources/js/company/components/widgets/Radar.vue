<template>
    <div class="card">
        <div class="card-body">
            <div
                ref="chartRef"
                class="radar-chart"
            ></div>
        </div>
    </div>
</template>

<script setup>
import {
    ref,
    onMounted,
    onBeforeUnmount,
    watch,
    nextTick,
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

    // Тип "polar-area" использует другую форму данных: плоский список
    // значений с подписями вместо рядов по осям.
    labels: {
        type: Array,
        default: () => [],
    },

    options: {
        type: Object,
        default: () => ({}),
    },
})

const chartRef = ref(null)

let chart = null


const renderChart = async () => {
    await nextTick()

    if (!chartRef.value) {
        return
    }

    // Удаляем старый график
    if (chart) {
        chart.destroy()
        chart = null
    }

    // --------------------------------
    // Полярные секторы — своя форма данных и свой конфиг,
    // общий с радаром здесь только контейнер
    // --------------------------------

    if (props.options.chartType === "polarArea") {
        const values = props.series
            .map((value) => Number(value))
            .map((value) => (Number.isFinite(value) ? value : 0))

        if (!values.length) {
            return
        }

        chart = new ApexCharts(chartRef.value, {
            chart: {
                type: "polarArea",
                height: 360,
                fontFamily: "inherit",
                animations: { enabled: false },
                toolbar: { show: false },
            },
            series: values,
            labels: (props.labels.length ? props.labels : props.categories).map(String),
            stroke: { width: 1 },
            fill: { opacity: 0.75 },
            legend: { position: "bottom" },
            tooltip: { theme: "dark" },
            yaxis: { show: false },
        })

        chart.render()
        return
    }

    // --------------------------------
    // Categories
    // --------------------------------

    const categories = props.categories.map(String)

    // --------------------------------
    // Raw series
    // --------------------------------

    const rawSeries = props.series.map((item) => ({
        name: item.name ?? "",

        data: Array.isArray(item.data)
            ? item.data.map((value) => {
                const number = Number(value)

                return Number.isFinite(number)
                    ? number
                    : 0
            })
            : [],
    }))

    // Нет данных
    if (!categories.length || !rawSeries.length) {
        return
    }

    // --------------------------------
    // Нормализация данных
    //
    // Каждый показатель получает
    // собственную шкалу 0-100.
    //
    // Например:
    //
    // buyPrice        66
    // MSRP            150
    // quantityInStock 9997
    //
    // превращается примерно в:
    //
    // buyPrice        70
    // MSRP            100
    // quantityInStock 100
    // --------------------------------

    const maxByCategory = categories.map((_, categoryIndex) => {
        const values = rawSeries
            .map((item) => item.data[categoryIndex] ?? 0)
            .filter((value) => Number.isFinite(value))

        if (!values.length) {
            return 0
        }

        return Math.max(...values)
    })

    const series = rawSeries.map((item) => ({
        name: item.name,

        data: categories.map((_, categoryIndex) => {
            const value = item.data[categoryIndex] ?? 0
            const max = maxByCategory[categoryIndex]

            if (!max || max <= 0) {
                return 0
            }

            return Number(
                ((value / max) * 100).toFixed(2)
            )
        }),
    }))

    // --------------------------------
    // ApexCharts options
    // --------------------------------

    const options = {
        series,

        chart: {
            type: "radar",

            height: 450,

            width: "100%",

            fontFamily: "inherit",

            animations: {
                enabled: false,
            },

            toolbar: {
                show: false,
            },

            parentHeightOffset: 0,
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

                offsetX: 0,
                offsetY: 0,
            },
        },

        yaxis: {
            min: 0,
            max: 100,

            tickAmount: 5,

            labels: {
                show: false,
            },
        },

        colors: [
            "var(--chart-color-1)",
            "var(--chart-color-2)",
            "var(--chart-color-3)",
            "var(--chart-color-4)",
            "var(--chart-color-5)",
            "var(--chart-color-6)",
            "var(--chart-color-7)",
            "var(--chart-color-8)",
        ],

        stroke: {
            width: 2,
        },

        // Тип "filled" заливает контур заметно сильнее — его берут,
        // когда сущностей одна-две и перекрытия не мешают.
        fill: {
            opacity: props.options.filled === true ? 0.4 : 0.15,
        },

        plotOptions: {
            radar: {
                size: 110,

                offsetX: 0,
                offsetY: 0,

                polygons: {
                    strokeColors: "#d9dee4",

                    strokeWidth: 1,

                    connectorColors: "#d9dee4",

                    // Фон сетки радара — чередование двух нейтральных тонов.
                    // Это подложка, а не данные: цвета рядов сюда не идут.
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

            hover: {
                size: 6,
            },
        },

        legend: {
            show: series.length > 1,

            position: "bottom",

            horizontalAlign: "center",

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

            y: {
                formatter: (value, { seriesIndex, dataPointIndex }) => {
                    const originalValue =
                        rawSeries?.[seriesIndex]?.data?.[dataPointIndex]

                    if (
                        originalValue !== undefined &&
                        Number.isFinite(Number(originalValue))
                    ) {
                        return Number(originalValue).toLocaleString(
                            undefined,
                            {
                                maximumFractionDigits: 2,
                            }
                        )
                    }

                    return value
                },
            },
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

                    legend: {
                        offsetY: 5,
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


// --------------------------------
// Mounted
// --------------------------------

onMounted(() => {
    renderChart()
})


// --------------------------------
// Watch props
// --------------------------------

watch(
    () => [
        props.categories,
        props.series,
        props.labels,
        props.options,
    ],

    () => {
        renderChart()
    },

    {
        deep: true,
    }
)


// --------------------------------
// Destroy
// --------------------------------

onBeforeUnmount(() => {
    if (chart) {
        chart.destroy()
        chart = null
    }
})
</script>

<style scoped>
.radar-chart {
    width: 100%;
    min-width: 0;
    display: flex;
    justify-content: center;
}
</style>
