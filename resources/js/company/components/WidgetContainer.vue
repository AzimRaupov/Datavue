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
import Radar from "./widgets/Radar.vue";

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
        <div v-else class="border rounded-3 p-4 d-flex flex-column align-items-center placeholder-glow">
            <span class="placeholder rounded-circle bg-secondary mb-4" style="width: 160px; height: 160px;"></span>
            <div class="d-flex flex-wrap justify-content-center gap-3 w-100" style="max-width: 320px;">
                <div class="d-flex align-items-center gap-2" v-for="n in 4" :key="n">
                    <span class="placeholder rounded-circle bg-secondary" style="width: 10px; height: 10px;"></span>
                    <span class="placeholder bg-secondary" style="width: 60px; height: 8px;"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- BAR CHART -->
    <div v-else-if="widget && widget.widget.name === 'bar'">
        <Bar
            v-if="contentWidget?.series?.length > 0 && widget?.status === 'active'"
            :categories="contentWidget.categories"
            :series="contentWidget.series"
        />
        <div v-else class="border rounded-3 p-3 placeholder-glow">
            <div class="d-flex align-items-end gap-2" style="height: 220px;">
                <span
                    v-for="n in 8"
                    :key="n"
                    class="placeholder bg-secondary rounded-1 flex-fill"
                    :style="{ height: (30 + (n % 5) * 12) + '%' }"
                ></span>
            </div>
        </div>
    </div>

    <div v-else-if="widget && widget.widget.name === 'radar'">
        <Radar
            v-if="
            contentWidget?.series?.length > 0 &&
            contentWidget?.categories?.length > 0 &&
            widget?.status === 'active'
        "
            :categories="contentWidget.categories"
            :series="contentWidget.series"
        />

        <div v-else class="border rounded-3 p-3 placeholder-glow">
            <div class="d-flex align-items-end gap-2" style="height: 220px;">
            <span
                v-for="n in 8"
                :key="n"
                class="placeholder bg-secondary rounded-1 flex-fill"
                :style="{
                    height: (30 + (n % 5) * 12) + '%'
                }"
            ></span>
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
        <div v-else class="border rounded-3 p-4 d-flex flex-column align-items-center placeholder-glow">
            <span class="placeholder rounded-circle bg-secondary mb-4" style="width: 160px; height: 160px;"></span>
            <div class="d-flex flex-wrap justify-content-center gap-3 w-100" style="max-width: 320px;">
                <div class="d-flex align-items-center gap-2" v-for="n in 4" :key="n">
                    <span class="placeholder rounded-circle bg-secondary" style="width: 10px; height: 10px;"></span>
                    <span class="placeholder bg-secondary" style="width: 60px; height: 8px;"></span>
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
        <div v-else class="border rounded-3 p-3 placeholder-glow">
            <span class="placeholder bg-secondary rounded-2 d-block" style="width: 100%; height: 240px;"></span>
        </div>
    </div>

    <!-- MULTI SERIES TREND -->
    <div v-else-if="widget && widget.widget.name === 'multi-series-trend'">
        <MultiSeriesTrend
            v-if="contentWidget?.series?.length > 0 && widget?.status === 'active'"
            :labels="contentWidget.labels"
            :series="contentWidget.series"
        />
        <div v-else class="border rounded-3 p-3 placeholder-glow">
            <span class="placeholder bg-secondary rounded-2 d-block" style="width: 100%; height: 200px;"></span>
            <div class="d-flex justify-content-center gap-3 mt-3">
                <div class="d-flex align-items-center gap-2" v-for="n in 2" :key="n">
                    <span class="placeholder rounded-circle bg-secondary" style="width: 10px; height: 10px;"></span>
                    <span class="placeholder bg-secondary" style="width: 60px; height: 8px;"></span>
                </div>
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

    <!-- TABLE (эталон — без изменений) -->
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
