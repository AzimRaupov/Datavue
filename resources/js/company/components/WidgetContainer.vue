<script setup>
import DonutWidget from "../components/widgets/DonutWidget.vue";
import MiniCounters from "./widgets/MiniCounters.vue";
import api from "../api.js";
import { ref, computed, onMounted, watch } from "vue";
import Table from "./widgets/Table.vue";
import Pie from "./widgets/Pie.vue";
import MultiSeriesTrend from "./widgets/MultiSeriesTrend.vue";
import Scatter from "./widgets/Scatter.vue";
import Bar from "./widgets/Bar.vue";

const props = defineProps({
    widget: {
        type: Object,
        required: true
    },
    chatId: {
        type: [String, Number],
        default: null,
    },
    refreshToken: {
        type: [String, Number],
        default: 0,
    },
});

const widget = computed(() => props.widget);

const contentWidget = ref(null);
const isLoading = ref(false);

async function getWidgetContent() {
    if (!widget.value?.id) return;

    try {
        isLoading.value = true;
        contentWidget.value = null;

        const response = await api.post(
            "/get-widget-content/" + widget.value.id,
            { chat_id: props.chatId }
        );

        const raw = response.data.output;

        let jsonString = null;

        if (Array.isArray(raw)) {
            jsonString = raw[0];
        } else if (typeof raw === "string") {
            jsonString = raw;
        }

        contentWidget.value = jsonString
            ? JSON.parse(jsonString)
            : null;

    } catch (err) {
        console.error("Ошибка загрузки данных виджета:", err);
    } finally {
        isLoading.value = false;
    }
}

watch(
    () => props.widget.updated_at,
    async (newValue, oldValue) => {
        if (newValue !== oldValue) {
            await getWidgetContent();
        }
    }
);

watch(
    () => props.refreshToken,
    async (newValue, oldValue) => {
        if (newValue !== oldValue) {
            await getWidgetContent();
        }
    }
);

onMounted(async () => {
    await getWidgetContent();
});
</script>

<template>

    <!-- DONUT CHART -->
    <div v-if="widget && widget.widget.name === 'donut-chart'">
        <DonutWidget
            v-if="contentWidget?.series?.length > 0 && widget?.status === 'active'"
            :labels="contentWidget.labels"
            :series="contentWidget.series"
        />
        <div v-else class="border rounded-3 p-4 d-flex flex-column align-items-center">
            <div class="ring-skeleton ring-donut mb-4"></div>
            <div class="d-flex flex-wrap justify-content-center gap-3 w-100" style="max-width: 320px;">
                <div class="d-flex align-items-center gap-2" v-for="n in 4" :key="n">
                    <span class="dot-skeleton" :class="'dot-skeleton-' + n"></span>
                    <span class="placeholder-glow flex-fill">
                    <span class="placeholder bg-secondary" style="width: 60px; height: 8px;"></span>
                </span>
                </div>
            </div>
        </div>
    </div>

    <div v-else-if="widget && widget.widget.name === 'bar'">
        <Bar
            v-if="contentWidget?.series?.length > 0 && widget?.status === 'active'"
            :categories="contentWidget.categories"
            :series="contentWidget.series"
        />
        <div v-else class="border rounded-3 p-3">
            <div class="chart-frame">
                load
            </div>
        </div>
    </div>

    <!-- PIE CHART -->
    <div v-else-if="widget && widget.widget.name === 'pie-chart'">
        <Pie
            v-if="contentWidget?.series?.length > 0 && widget?.status === 'active'"
            :labels="contentWidget.labels"
            :series="contentWidget.series"
        />
        <div v-else class="border rounded-3 p-4 d-flex flex-column align-items-center">
            <div class="ring-skeleton ring-pie mb-4"></div>
            <div class="d-flex flex-wrap justify-content-center gap-3 w-100" style="max-width: 320px;">
                <div class="d-flex align-items-center gap-2" v-for="n in 4" :key="n">
                    <span class="dot-skeleton" :class="'dot-skeleton-' + n"></span>
                    <span class="placeholder-glow flex-fill">
                    <span class="placeholder bg-secondary" style="width: 60px; height: 8px;"></span>
                </span>
                </div>
            </div>
        </div>
    </div>

    <!-- SCATTER PLOT -->
    <div v-else-if="widget.widget.name === 'scatter-plot'">
        <Scatter
            v-if="contentWidget?.series?.length > 0 && widget?.status === 'active'"
            :series="contentWidget.series"
            :categories="contentWidget.categories || []"
        />
        <div v-else class="border rounded-3 p-3">
            <div class="chart-frame">
            <span
                v-for="n in 16"
                :key="n"
                class="scatter-dot rounded-circle"
                :class="n === 4 ? 'bg-primary' : 'bg-secondary opacity-75'"
                :style="{
                    left: (6 + Math.random() * 86) + '%',
                    top: (10 + Math.random() * 76) + '%'
                }"
            ></span>
            </div>
        </div>
    </div>

    <!-- MULTI SERIES TREND -->
    <div v-else-if="widget && widget.widget.name === 'multi-series-trend'">
        <MultiSeriesTrend
            v-if="contentWidget?.series?.length > 0 && widget?.status === 'active'"
            :labels="contentWidget.labels"
            :series="contentWidget.series"
        />
        <div v-else class="border rounded-3 p-3">
            <div class="chart-frame">
                <div class="chart-gridlines">
                    <span v-for="n in 4" :key="n"></span>
                </div>
                <svg class="trend-svg" viewBox="0 0 300 140" preserveAspectRatio="none">
                    <polyline
                        class="trend-line trend-line-accent"
                        points="0,110 40,90 80,100 120,60 160,75 200,40 240,55 300,20"
                    />
                    <polyline
                        class="trend-line trend-line-muted"
                        points="0,130 40,120 80,125 120,105 160,110 200,95 240,100 300,85"
                    />
                </svg>
            </div>
        </div>
    </div>

    <!-- MINI COUNTERS (эталон — без изменений) -->
    <div v-else-if="widget && widget.widget.name === 'mini-counters' ">
        <MiniCounters
            v-if="contentWidget?.counters?.length > 0 && widget?.status === 'active'"
            :counters="contentWidget"
        />
        <div v-else class="row g-3">
            <div class="col-6 col-md-3" v-for="n in 4" :key="n">
                <div class="border rounded-3 p-3 placeholder-glow">
                    <span class="placeholder rounded-2 bg-primary d-block mb-2" style="width: 28px; height: 28px;"></span>
                    <span class="placeholder col-6 bg-secondary d-block mb-2" style="height: 20px;"></span>
                    <span class="placeholder col-8 bg-secondary d-block" style="height: 10px;"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLE -->
    <div v-else-if="widget && widget.widget.name === 'table' ">
        <Table
            v-if="contentWidget?.headers && contentWidget?.rows && widget?.status === 'active'"
            :table="contentWidget"
        />
        <div v-else class="border rounded-3 p-3">
            <table class="table align-middle placeholder-glow mb-0">
                <thead>
                <tr>
                    <th v-for="n in 4" :key="n">
                        <span class="placeholder col-8 bg-primary opacity-50"></span>
                    </th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="n in 6" :key="n">
                    <td v-for="c in 4" :key="c">
                        <span class="placeholder col-10 bg-secondary"></span>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div v-else class="alert alert-warning">
        <p>Неизвестный тип виджета: {{ widget.widget.name }}</p>
        <pre style="font-size: 0.75rem; color: #666;">{{ JSON.stringify(contentWidget, null, 2) }}</pre>
    </div>
</template>

<style scoped>
@keyframes ring-shimmer {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
@keyframes dot-pulse {
    0%, 100% { opacity: .35; transform: scale(0.9); }
    50% { opacity: 1; transform: scale(1.15); }
}
@keyframes dash-flow {
    to { stroke-dashoffset: -160; }
}
@keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}
@keyframes fade-in-out {
    0%, 100% { opacity: 0.3; }
    50% { opacity: 0.8; }
}
.ring-skeleton {
    width: 180px;
    height: 180px;
    border-radius: 50%;
    position: relative;
    background: conic-gradient(
        var(--bs-primary) 0deg 70deg,
        rgba(var(--bs-secondary-rgb), 0.5) 70deg 150deg,
        rgba(var(--bs-secondary-rgb), 0.25) 150deg 230deg,
        var(--bs-primary) 230deg 280deg,
        rgba(var(--bs-secondary-rgb), 0.35) 280deg 360deg
    );
    animation: ring-shimmer 6s linear infinite;
    box-shadow: 0 0 20px rgba(var(--bs-primary-rgb), 0.2);
}
.ring-donut::after {
    content: "";
    position: absolute;
    inset: 0;
    margin: auto;
    width: 88px;
    height: 88px;
    border-radius: 50%;
    background: var(--bs-body-bg, #fff);
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.05);
}
.dot-skeleton {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
    background: rgba(var(--bs-secondary-rgb), 0.5);
    animation: dot-pulse 1.5s infinite ease-in-out;
}
.dot-skeleton-1 { background: var(--bs-primary); animation-delay: 0s; }
.dot-skeleton-2 { background: rgba(var(--bs-secondary-rgb), 0.6); animation-delay: 0.2s; }
.dot-skeleton-3 { background: var(--bs-primary); opacity: 0.6; animation-delay: 0.4s; }
.dot-skeleton-4 { background: rgba(var(--bs-secondary-rgb), 0.6); animation-delay: 0.6s; }
.chart-frame {
    position: relative;
    width: 100%;
    height: 240px;
    border-radius: 6px;
    overflow: hidden;
    background: linear-gradient(
        90deg,
        rgba(var(--bs-secondary-rgb), 0.03) 0%,
        rgba(var(--bs-secondary-rgb), 0.08) 50%,
        rgba(var(--bs-secondary-rgb), 0.03) 100%
    );
    background-size: 200% 100%;
    animation: shimmer 3s ease-in-out infinite;
}
.chart-gridlines {
    position: absolute;
    inset: 0 0 24px 0;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
.chart-gridlines span {
    display: block;
    height: 1px;
    background: rgba(var(--bs-secondary-rgb), 0.2);
    animation: fade-in-out 2s ease-in-out infinite;
}
.chart-gridlines span:nth-child(1) { animation-delay: 0s; }
.chart-gridlines span:nth-child(2) { animation-delay: 0.3s; }
.chart-gridlines span:nth-child(3) { animation-delay: 0.6s; }
.chart-gridlines span:nth-child(4) { animation-delay: 0.9s; }
.scatter-dot {
    position: absolute;
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: rgba(var(--bs-secondary-rgb), 0.55);
    animation: dot-pulse 1.9s infinite ease-in-out;
    box-shadow: 0 0 8px rgba(var(--bs-secondary-rgb), 0.3);
}
.scatter-dot.bg-primary { box-shadow: 0 0 12px rgba(var(--bs-primary-rgb), 0.5); }
.scatter-dot:nth-child(odd) { animation-delay: 0.3s; }
.scatter-dot:nth-child(even) { animation-delay: 0.7s; }
.trend-svg {
    position: absolute;
    inset: 0 0 24px 0;
    width: 100%;
    height: calc(100% - 24px);
    filter: drop-shadow(0 2px 4px rgba(var(--bs-primary-rgb), 0.15));
}
.trend-line {
    fill: none;
    stroke-width: 3;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-dasharray: 12 8;
    animation: dash-flow 2s linear infinite;
}
.trend-line-accent {
    stroke: var(--bs-primary);
    filter: drop-shadow(0 0 6px rgba(var(--bs-primary-rgb), 0.4));
}
.trend-line-muted {
    stroke: rgba(var(--bs-secondary-rgb), 0.5);
    animation-delay: .4s;
    stroke-dasharray: 8 12;
}
</style>
