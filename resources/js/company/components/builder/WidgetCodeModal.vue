<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from "vue";
import { Modal } from "bootstrap";
import { useI18n } from "vue-i18n";
import api from "../../api.js";
import { templateFor } from "./codeTemplates.js";

/**
 * Редактор кода виджета.
 *
 * Порядок работы намеренно такой: написал → «Выполнить» → увидел данные или
 * ошибку → «Сохранить». Прогон черновика ничего не пишет в базу, поэтому
 * подобрать запрос можно сколько угодно раз, не оставляя на дашборде
 * сломанный виджет.
 *
 * Редактор — textarea с моноширинным шрифтом и обработкой Tab. Подсветку
 * синтаксиса даст CodeMirror, но он тянет отдельную зависимость: контракт
 * этого компонента от неё не изменится.
 */

const { t } = useI18n();

const props = defineProps({
    dashboardId: { type: [String, Number], required: true },
    widget: { type: Object, default: null },
    schema: { type: Array, default: () => [] },
});

const emit = defineEmits(["saved", "closed"]);

const modalEl = ref(null);
let modal = null;

const code = ref("");
const running = ref(false);
const saving = ref(false);
const restoring = ref(false);
const errors = ref([]);
const preview = ref(null);
const okMessage = ref(null);

// Пробный SELECT — подобрать запрос до того, как он попадёт в код.
const sql = ref("");
const sqlRunning = ref(false);
const sqlError = ref(null);
const sqlResult = ref(null);

const familyName = computed(() => props.widget?.widget?.name ?? null);
const scheme = computed(() => props.widget?.widget?.scheme ?? null);
const schemeDescription = computed(() => props.widget?.widget?.scheme_description ?? null);
const hasPrevious = computed(() => Boolean(props.widget?.has_previous_code));

function resetState() {
    errors.value = [];
    preview.value = null;
    okMessage.value = null;
    sqlError.value = null;
    sqlResult.value = null;
}

watch(
    () => props.widget?.id,
    () => {
        resetState();
        // Пустому виджету подставляем заготовку под его семейство: автор
        // правит рабочий пример, а не вспоминает контракт рантайма.
        code.value = props.widget?.code || templateFor(familyName.value);
    }
);

/** Tab внутри кода — отступ, а не переход к следующему полю. */
function onTab(event) {
    const field = event.target;
    const start = field.selectionStart;
    const end = field.selectionEnd;

    code.value = code.value.slice(0, start) + "    " + code.value.slice(end);

    nextTick(() => {
        field.selectionStart = field.selectionEnd = start + 4;
    });
}

async function runDraft() {
    if (running.value) return;

    running.value = true;
    resetState();

    try {
        const { data } = await api.post(
            `/dashboards/${props.dashboardId}/widgets/${props.widget.id}/run`,
            { code: code.value }
        );

        preview.value = data.data;
        okMessage.value = t("widgetCodeModal.success_run");
    } catch (err) {
        const body = err.response?.data;
        errors.value = body?.errors?.length
            ? body.errors
            : [body?.message || t("widgetCodeModal.error_run_default")];
        preview.value = body?.data ?? null;
    } finally {
        running.value = false;
    }
}

async function save() {
    if (saving.value) return;

    saving.value = true;
    resetState();

    try {
        const { data } = await api.put(
            `/dashboards/${props.dashboardId}/widgets/${props.widget.id}/code`,
            { code: code.value }
        );

        preview.value = data.data ?? null;

        if (data.ok) {
            okMessage.value = t("widgetCodeModal.success_save");
        } else {
            // Сохранили, но виджет сломан: это предупреждение, а не отказ —
            // автору есть куда вернуться, чтобы починить.
            errors.value = data.errors ?? [];
            okMessage.value = t("widgetCodeModal.success_save_broken");
        }

        emit("saved", data.widget);
    } catch (err) {
        const body = err.response?.data;
        errors.value = body?.errors?.length
            ? body.errors
            : [body?.message || t("widgetCodeModal.error_save_default")];
    } finally {
        saving.value = false;
    }
}

async function restore() {
    if (restoring.value) return;

    restoring.value = true;
    resetState();

    try {
        const { data } = await api.post(
            `/dashboards/${props.dashboardId}/widgets/${props.widget.id}/code/restore`
        );

        code.value = data.widget?.code ?? code.value;
        okMessage.value = t("widgetCodeModal.success_restore");
        emit("saved", data.widget);
    } catch (err) {
        const body = err.response?.data;
        errors.value = body?.errors?.length
            ? body.errors
            : [body?.message || t("widgetCodeModal.error_restore_default")];
    } finally {
        restoring.value = false;
    }
}

async function runSql() {
    if (sqlRunning.value || !sql.value.trim()) return;

    sqlRunning.value = true;
    sqlError.value = null;
    sqlResult.value = null;

    try {
        const { data } = await api.post(`/dashboards/${props.dashboardId}/query`, {
            query: sql.value,
        });

        sqlResult.value = data;
    } catch (err) {
        sqlError.value = err.response?.data?.message || t("widgetCodeModal.error_sql_default");
    } finally {
        sqlRunning.value = false;
    }
}

function insertTable(table) {
    sql.value = `SELECT * FROM ${table.name} LIMIT 10`;
}

function show() {
    modal?.show();
}

defineExpose({ show });

onMounted(() => {
    if (modalEl.value) {
        modal = new Modal(modalEl.value);
        modalEl.value.addEventListener("hidden.bs.modal", () => emit("closed"));
    }
});

onBeforeUnmount(() => {
    modal?.dispose();
});
</script>

<template>
    <div ref="modalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        {{ t('widgetCodeModal.title') }}
                        <span v-if="widget" class="text-secondary">— {{ widget.title }}</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" :aria-label="t('widgetCodeModal.close_aria')"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <!-- ЛЕВО: сам код -->
                        <div class="col-lg-7">
                            <label class="form-label d-flex align-items-center">
                                <span>{{ t('widgetCodeModal.function_body_label') }} <code>main()</code></span>
                                <span class="ms-auto text-secondary small">
                                    {{ t('widgetCodeModal.available_function_label') }} <code>query(sql, params=None)</code>
                                </span>
                            </label>

                            <textarea
                                v-model="code"
                                class="form-control builder-code"
                                spellcheck="false"
                                rows="18"
                                :aria-label="t('widgetCodeModal.code_aria')"
                                @keydown.tab.prevent="onTab"
                            ></textarea>

                            <div class="d-flex gap-2 mt-2 flex-wrap">
                                <button type="button" class="btn"
                                        :class="{ 'btn-loading': running }"
                                        :disabled="running || saving" @click="runDraft">
                                    {{ t('widgetCodeModal.run_button') }}
                                </button>
                                <button type="button" class="btn btn-primary"
                                        :class="{ 'btn-loading': saving }"
                                        :disabled="running || saving" @click="save">
                                    {{ t('widgetCodeModal.save_button') }}
                                </button>
                                <button v-if="hasPrevious" type="button" class="btn btn-link link-secondary"
                                        :class="{ 'btn-loading': restoring }"
                                        :disabled="restoring" @click="restore">
                                    {{ t('widgetCodeModal.restore_previous_button') }}
                                </button>
                            </div>

                            <div v-if="okMessage" class="alert alert-success mt-3 mb-0" role="status">
                                {{ okMessage }}
                            </div>

                            <div v-if="errors.length" class="alert alert-danger mt-3 mb-0" role="alert">
                                <div class="fw-bold mb-1">{{ t('widgetCodeModal.errors_heading') }}</div>
                                <pre class="mb-0 builder-errors">{{ errors.join("\n") }}</pre>
                            </div>

                            <div v-if="preview" class="mt-3">
                                <div class="form-label">{{ t('widgetCodeModal.result_label') }}</div>
                                <pre class="builder-preview">{{ JSON.stringify(preview, null, 2) }}</pre>
                            </div>
                        </div>

                        <!-- ПРАВО: справка и подбор запроса -->
                        <div class="col-lg-5">
                            <div class="mb-3">
                                <div class="form-label">{{ t('widgetCodeModal.data_shape_label', { family: familyName }) }}</div>
                                <pre v-if="scheme" class="builder-scheme">{{ scheme }}</pre>
                                <div v-if="schemeDescription" class="text-secondary small builder-scheme-text">
                                    {{ schemeDescription }}
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="form-label">{{ t('widgetCodeModal.trial_query_label') }}</div>
                                <textarea v-model="sql" class="form-control builder-code" rows="3"
                                          spellcheck="false"
                                          placeholder="SELECT * FROM orders LIMIT 10"
                                          :aria-label="t('widgetCodeModal.sql_aria')"></textarea>
                                <button type="button" class="btn btn-sm mt-2"
                                        :class="{ 'btn-loading': sqlRunning }"
                                        :disabled="sqlRunning" @click="runSql">
                                    {{ t('widgetCodeModal.check_query_button') }}
                                </button>

                                <div v-if="sqlError" class="alert alert-danger mt-2 mb-0">{{ sqlError }}</div>

                                <div v-if="sqlResult" class="table-responsive mt-2 builder-sql-result">
                                    <table class="table table-sm table-vcenter">
                                        <tbody>
                                        <tr v-for="(row, index) in sqlResult.rows.slice(0, 10)" :key="index">
                                            <td v-for="(value, key) in row" :key="key">{{ value }}</td>
                                        </tr>
                                        </tbody>
                                    </table>
                                    <div class="text-secondary small">
                                        {{ t('widgetCodeModal.rows_label', { count: sqlResult.row_count }) }}
                                        <span v-if="sqlResult.truncated">{{ t('widgetCodeModal.truncated_label') }}</span>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <div class="form-label">{{ t('widgetCodeModal.source_tables_label') }}</div>
                                <div class="builder-schema">
                                    <div v-for="table in schema" :key="table.name" class="mb-2">
                                        <button type="button" class="btn btn-link p-0"
                                                @click="insertTable(table)">
                                            {{ table.name }}
                                        </button>
                                        <div class="text-secondary small">
                                            {{ (table.columns ?? []).map(c => c.name).join(", ") }}
                                        </div>
                                    </div>
                                    <div v-if="!schema.length" class="text-secondary small">
                                        {{ t('widgetCodeModal.schema_not_loaded') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.builder-code {
    font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
    font-size: 13px;
    line-height: 1.5;
    tab-size: 4;
    white-space: pre;
    overflow-x: auto;
}

/*
 * Голый <pre> Tabler отдаёт под примеры кода: тёмная заливка
 * (--tblr-bg-surface-dark) и светлый текст (--tblr-light). Здесь заливка уже
 * переопределялась на светлую, а цвет текста — нет, и он оставался почти белым
 * на светлом фоне. Возвращаем и цвет тоже.
 */
.builder-preview,
.builder-scheme,
.builder-errors {
    font-size: 12px;
    max-height: 220px;
    overflow: auto;
    background: var(--tblr-bg-surface-secondary);
    color: var(--tblr-body-color);
    border-radius: 4px;
    padding: 8px;
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
}

.builder-scheme-text {
    white-space: pre-wrap;
    margin-top: 6px;
}

.builder-schema,
.builder-sql-result {
    max-height: 220px;
    overflow: auto;
}
</style>
