<template>
    <div class="card">
        <div class="card-body">
            <div class="d-flex">
                <h3 class="card-title">Active users</h3>

                <div class="ms-auto">
                    <div class="dropdown">
                        <a
                            class="dropdown-toggle text-secondary"
                            id="active-users-dropdown"
                            href="#"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                        >
                            Last 7 days
                        </a>

                        <div class="dropdown-menu dropdown-menu-end">
                            <a class="dropdown-item active" href="#">Last 7 days</a>
                            <a class="dropdown-item" href="#">Last 30 days</a>
                            <a class="dropdown-item" href="#">Last 3 months</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col">
                    <div ref="chartRef" class="position-relative"></div>
                </div>

                <div class="col-md-auto">
                    <div class="divide-y divide-y-fill">
                        <div class="px-3">
                            <div class="text-secondary">
                                <span class="status-dot bg-primary"></span> Mobile
                            </div>
                            <div class="h2">11,425</div>
                        </div>

                        <div class="px-3">
                            <div class="text-secondary">
                                <span class="status-dot bg-azure"></span> Desktop
                            </div>
                            <div class="h2">6,458</div>
                        </div>

                        <div class="px-3">
                            <div class="text-secondary">
                                <span class="status-dot bg-green"></span> Tablet
                            </div>
                            <div class="h2">3,985</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref, onBeforeUnmount } from "vue"
import ApexCharts from "apexcharts"

const chartRef = ref(null)
let chart = null

onMounted(() => {
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

        series: [
            {
                name: "Users",
                data: [1, 2, 3],
            },
        ],

        labels: ["1", "2", "3"],

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
