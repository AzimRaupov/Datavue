<script setup>
import DonutWidget from "../components/widgets/DonutWidget.vue";
import MiniCounters from "./widgets/MiniCounters.vue";
import api from "../api.js";
import { ref, onMounted } from 'vue';
import Table from "./widgets/Table.vue";
import Pie from "./widgets/Pie.vue";
import MultiSeriesTrend from "./widgets/MultiSeriesTrend.vue";
import Scatter from "./widgets/Scatter.vue";

const props = defineProps({
    widget: {
        type: Object, // Изменено на Object, так как это объект виджета
        required: true
    },
    chatId: {
        type: [String, Number],
        default: null,
    },
});

const chatId = props.chatId;
const widget = props.widget;

const contentWidget = ref(null); // Изначально null, пока данные не загружены

async function getWidgetContent() {
    try {
        const response = await api.post(
            '/get-widget-content/' + widget.id,
            { chat_id: chatId }
        );
        contentWidget.value = JSON.parse(response.data.output);
    } catch (err) {
        console.error("Ошибка загрузки данных виджета:", err);
    }
}

onMounted(async () => {
    await getWidgetContent();
});
</script>

<template>
    <div v-if="widget && widget.widget.name === 'donut-chart'">
        <DonutWidget
            v-if="contentWidget && contentWidget.series && contentWidget.series.length"
            :labels="contentWidget.labels"
            :series="contentWidget.series"
        />
        <div v-else class="loading-placeholder">
            Загрузка данных...
        </div>
    </div>

    <div v-if="widget && widget.widget.name === 'pie-chart'">
        <Pie
            v-if="contentWidget && contentWidget.series && contentWidget.series.length"
            :labels="contentWidget.labels"
            :series="contentWidget.series"
        />
        <div v-else class="loading-placeholder">
            Загрузка данных...
        </div>
    </div>

    <div v-else-if="widget.widget.name === 'scatter-plot'">
        <Scatter
            v-if="contentWidget && contentWidget.series && contentWidget.series.length"
            :series="contentWidget.series"
            :categories="contentWidget.categories || []"
        />
        <div v-else class="loading-placeholder">Загрузка графика...</div>
    </div>

    <div v-else-if="widget && widget.widget.name === 'multi-series-trend' ">
        <MultiSeriesTrend
            :counters="contentWidget"
        />

    </div>
    <div v-else-if="widget && widget.widget.name === 'mini-counters' ">
        <MiniCounters
            v-if="contentWidget && contentWidget.counters && contentWidget.counters.length"
            :counters="contentWidget"
        />
        <div v-else class="loading-placeholder">
            Загрузка данных...
        </div>
    </div>
    <div v-else-if="widget && widget.widget.name === 'table' ">
        <Table
            :table="contentWidget"
        />

    </div>
</template>
