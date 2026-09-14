<script setup>
import { computed, reactive } from "vue";
import { useI18n } from "vue-i18n";
import WidgetContainer from "../WidgetContainer.vue";
import { typesOf, currentTypeId, changeWidgetType } from "../../utils/widgetTypes.js";

const { t } = useI18n();

/**
 * Read-only отрисовка виджетов дашборда — без карточек конструктора,
 * перетаскивания и меню редактирования.
 *
 * Вынесена из WorkspacePage (режим просмотра), чтобы тот же ряд карточек
 * можно было показать и в интерфейсе директора (DirectorDashboardViewer),
 * не заводя второй способ рисовать виджеты.
 */
const props = defineProps({
    widgets: {
        type: Array,
        default: () => [],
    },
    dashboardId: {
        type: [String, Number],
        default: null,
    },
    chatId: {
        type: [String, Number],
        default: null,
    },
    refreshToken: {
        type: [String, Number],
        default: 0,
    },
    // Селектор вида виджета показываем только тому, кто и так может менять
    // дашборд — у director'а (да и у viewer'а) его нет вовсе.
    canEdit: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["widget-updated", "error"]);

// Виджеты, которые не удалось посчитать (не сгенерировались или сломались
// при запросе), просто не показываем — ни заголовка, ни сообщения об ошибке.
const unavailableIds = reactive(new Set());

const visibleWidgets = computed(() =>
    props.widgets.filter(w => w.status !== "failed" && !unavailableIds.has(w.id))
);

function markUnavailable(id) {
    unavailableIds.add(id);
}

async function changeType(widget, typeId) {
    try {
        const data = await changeWidgetType(props.dashboardId, widget, typeId);
        emit("widget-updated", data);
    } catch (err) {
        emit("error", err.response?.data?.message || t("workspacePage.errors.change_type_failed"));
    }
}
</script>

<template>
    <div v-for="widget in visibleWidgets" :key="widget.id" class="row row-cards widgets-content mb-3">
        <div class="col-12">
            <div class="d-flex align-items-center mb-2">
                <h3 class="mb-0 flex-fill">{{ widget.title }}</h3>

                <select
                    v-if="canEdit && typesOf(widget).length > 1"
                    class="form-select form-select-sm w-auto ms-2 d-print-none"
                    :value="currentTypeId(widget)"
                    :aria-label="t('workspacePage.widget_type_aria', { title: widget.title })"
                    @change="changeType(widget, $event.target.value)"
                >
                    <option v-for="type in typesOf(widget)" :key="type.id" :value="type.id">
                        {{ type.title || type.name }}
                    </option>
                </select>
            </div>

            <WidgetContainer
                :widget="widget"
                :chat-id="chatId"
                :refresh-token="refreshToken"
                @unavailable="markUnavailable"
            />
        </div>
    </div>
</template>
