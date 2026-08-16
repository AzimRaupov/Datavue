<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../../api.js';
import { useEcho } from '../../echo.js';
import ProviderIcon from '../../components/source/ProviderIcon.vue';

/**
 * Мастер подключения источника данных.
 *
 * Разметка — штатные компоненты Tabler: .steps.steps-counter для шагов,
 * .form-selectgroup-boxes для выбора провайдера, .btn-loading для кнопок,
 * .steps.steps-vertical для хода группировки.
 *
 * Шаги:
 *   1. Выбор провайдера. Список приходит с бэкенда (data_source_types),
 *      поэтому новый провайдер появляется здесь без правок фронта — форму
 *      определяет поле kind: file / database / api.
 *   2. Параметры или загрузка файла. По кнопке источник создаётся, и бэкенд
 *      ПРОВЕРЯЕТ подключение; не вышло — остаёмся на шаге.
 *   3. Группировка. Идёт в фоне (DataSourceGroupingJob), ход приходит
 *      событиями по каналу data_source.{id}. По завершении — переход
 *      на страницу источника.
 */

const router = useRouter();

const logo = '/logos/logo.png';

const STEP_PROVIDER = 1;
const STEP_CONFIG = 2;
const STEP_GROUPING = 3;

const step = ref(STEP_PROVIDER);

const providers = ref([]);
const selectedProviderId = ref(null);

const isLoading = ref(false);
const formError = ref(null);
const formErrors = ref({});

const dataFile = ref(null);
const fileInputEl = ref(null);

const createdSource = ref(null);
const tables = ref([]);

// Ход группировки: заполняется событиями из сокета.
const groupingStatus = ref('pending');
const groupingLabel = ref('Ставим задачу в очередь');
const groupingStep = ref(0);
const groupingMessage = ref(null);

const form = reactive({
    name: '',
    version: '',
    host: '',
    port: '',
    database: '',
    username: '',
    password: '',
    sheet_url: '',
});

let echo = null;
let pollTimer = null;

const provider = computed(() =>
    providers.value.find((p) => p.id === selectedProviderId.value) ?? null
);

const kind = computed(() => provider.value?.kind ?? null);

/** Расширения, которые ждём под выбранный файловый провайдер. */
const fileAccept = computed(() =>
    provider.value?.name === 'sqlite'
        ? '.db,.sqlite,.sqlite3'
        : '.csv,.xls,.xlsx,.sql'
);

/**
 * Версию спрашиваем только там, где она влияет на генерируемый SQL:
 * у внешних СУБД и у готовых файлов-баз.
 */
const needsVersion = computed(() =>
    kind.value === 'database' || provider.value?.name === 'sqlite'
);

const isFormValid = computed(() => {
    if (!provider.value) return false;

    if (kind.value === 'file') {
        if (!dataFile.value) return false;
        return needsVersion.value ? !!form.version : true;
    }

    if (kind.value === 'api') return !!form.sheet_url.trim();

    return (
        !!form.version && !!form.host && !!form.port &&
        !!form.database && !!form.username
    );
});

const versionPlaceholder = computed(() => ({
    mysql: 'Например: 8.0',
    postgres: 'Например: 15',
    sqlite: 'Например: 3.42',
}[provider.value?.name] ?? 'Укажите версию'));

/** Этапы группировки для вертикальных шагов Tabler. */
const groupingStages = [
    'Подключаемся к источнику',
    'Читаем структуру источника',
    'Анализируем таблицы и связи',
    'Собираем смысловые группы',
];

/**
 * Заполнение полосы прогресса под карточкой — как в шаблоне wizard.html.
 * На последнем шаге растёт вместе с этапами группировки, чтобы полоса
 * не стояла на месте, пока идёт самая долгая часть.
 */
const progress = computed(() => {
    if (step.value === STEP_PROVIDER) return 20;
    if (step.value === STEP_CONFIG) return 55;

    if (groupingStatus.value === 'completed') return 100;

    // 70 → 95 по мере прохождения этапов группировки.
    return 70 + Math.round((groupingStep.value / groupingStages.length) * 25);
});

async function fetchProviders() {
    try {
        const { data } = await api.get('/data_source/types');
        providers.value = data ?? [];
    } catch {
        formError.value = 'Не удалось загрузить список провайдеров.';
    }
}

function selectProvider(id) {
    selectedProviderId.value = id;
    formError.value = null;
    formErrors.value = {};
}

function goToConfig() {
    if (!provider.value) return;

    // Порт подставляем из справочника провайдера, а не из таблицы в коде.
    if (kind.value === 'database' && !form.port && provider.value.default_port) {
        form.port = String(provider.value.default_port);
    }

    step.value = STEP_CONFIG;
}

function backToProvider() {
    step.value = STEP_PROVIDER;
    formError.value = null;
    formErrors.value = {};
}

function handleDataFile(event) {
    const file = event.target.files[0];
    dataFile.value = file;

    if (file && !form.name) form.name = file.name;
}

/** Шаг 2 → 3: создаём источник, бэкенд сам проверяет подключение. */
async function submitConfig() {
    if (isLoading.value || !isFormValid.value) return;

    isLoading.value = true;
    formError.value = null;
    formErrors.value = {};

    try {
        const payload = new FormData();

        if (form.name) payload.append('name', form.name);

        if (kind.value === 'file') {
            payload.append('connection_type', 'local');
            payload.append('data_file', dataFile.value);

            if (needsVersion.value) {
                payload.append('type_id', provider.value.id);
                payload.append('version', form.version);
            }

            // Дамп .sql импортируется в реальную СУБД — тип обязателен.
            if (dataFile.value?.name?.toLowerCase().endsWith('.sql')) {
                payload.append('type_id', provider.value.id);
            }
        } else if (kind.value === 'api') {
            payload.append('connection_type', 'google_sheet');
            payload.append('sheet_url', form.sheet_url.trim());
        } else {
            payload.append('connection_type', 'remote');
            payload.append('type_id', provider.value.id);
            payload.append('version', form.version);
            payload.append('host', form.host);
            payload.append('port', form.port);
            payload.append('database', form.database);
            payload.append('username', form.username);
            payload.append('password', form.password ?? '');
        }

        const { data } = await api.post('/data_source', payload);

        createdSource.value = data.data_source;

        await loadTables();
        startGrouping();

    } catch (err) {
        const data = err.response?.data;
        if (data?.errors) formErrors.value = data.errors;

        formError.value =
            data?.message ||
            'Не удалось подключиться. Проверьте параметры и попробуйте ещё раз.';
    } finally {
        isLoading.value = false;
    }
}

async function loadTables() {
    try {
        const { data } = await api.get(`/data_source/${createdSource.value.id}/tables`);
        tables.value = data.tables ?? [];
    } catch {
        tables.value = [];
    }
}

/** Шаг 3: ставим группировку в очередь и слушаем её ход. */
async function startGrouping() {
    step.value = STEP_GROUPING;
    groupingStatus.value = 'queued';
    groupingLabel.value = 'Ставим задачу в очередь';
    groupingStep.value = 0;
    groupingMessage.value = null;

    listenGrouping();

    try {
        await api.post(`/data_source/${createdSource.value.id}/grouping`);
    } catch (err) {
        groupingStatus.value = 'failed';
        groupingMessage.value =
            err.response?.data?.message || 'Не удалось запустить группировку.';
    }
}

function listenGrouping() {
    const id = createdSource.value.id;

    echo = useEcho();

    echo.private(`data_source.${id}`)
        .listen('.DataSourceGroupingProgress', (e) => applyProgress(e));

    // Запасной путь: сокет мог не подняться или событие потеряться.
    // Опрос раз в 5 секунд стоит дёшево и гарантирует, что мастер
    // не зависнет навсегда на «в очереди».
    pollTimer = setInterval(async () => {
        try {
            const { data } = await api.get(`/data_source/${id}/grouping`);

            if (data.status !== groupingStatus.value) {
                applyProgress({
                    status: data.status,
                    label: data.stage || groupingLabel.value,
                    step: data.status === 'completed' ? 3 : groupingStep.value,
                    message: data.message,
                });
            }
        } catch {
            // Молча: это подстраховка, а не основной канал.
        }
    }, 5000);
}

function applyProgress(payload) {
    groupingStatus.value = payload.status ?? groupingStatus.value;
    groupingLabel.value = payload.label ?? groupingLabel.value;
    groupingStep.value = payload.step ?? groupingStep.value;
    groupingMessage.value = payload.message ?? null;

    if (groupingStatus.value === 'completed') {
        stopListening();

        // Небольшая пауза, чтобы пользователь увидел завершённый последний шаг,
        // а не мгновенный скачок на другую страницу.
        setTimeout(goToSource, 900);
    }

    if (groupingStatus.value === 'failed') stopListening();
}

function stopListening() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }

    if (echo && createdSource.value) {
        echo.leave(`data_source.${createdSource.value.id}`);
    }
}

function goToSource() {
    router.push({
        name: 'company.source.show',
        params: { id: createdSource.value.id },
    });
}

/**
 * Состояние этапа: done | running | failed | pending.
 *
 * Раньше был флаг «активен ли», и всё остальное Tabler докрашивал сам через
 * .steps-vertical. Но у процесса, который может упасть, состояний четыре, и
 * различать их явно надёжнее, чем полагаться на CSS-соседей.
 */
function stageState(index) {
    if (groupingStatus.value === 'completed') return 'done';

    if (groupingStatus.value === 'failed') {
        if (index < groupingStep.value) return 'done';
        return index === groupingStep.value ? 'failed' : 'pending';
    }

    if (index < groupingStep.value) return 'done';
    if (index === groupingStep.value) return 'running';

    return 'pending';
}

onMounted(fetchProviders);
onUnmounted(stopListening);
</script>

<template>
    <div class="page page-center">
        <div class="container container-tight py-4">
            <div class="text-center mb-4">
                <router-link :to="{ name: 'company.sources' }" class="navbar-brand navbar-brand-autodark">
                    <img :src="logo" width="115" alt="DataVue" />
                </router-link>
            </div>

            <div class="card card-md">
                <!-- ============ ШАГ 1: ПРОВАЙДЕР ============ -->
                <template v-if="step === 1">
                    <div class="card-body">
                        <h2 class="card-title">Откуда берём данные</h2>
                        <p class="text-secondary mb-3">
                            Выберите, что подключаем.
                        </p>

                        <!-- Без form-selectgroup-boxes: у него padding 1.25rem на
                             каждый пункт, и список из пяти провайдеров занимал
                             пол-экрана. Обычный form-selectgroup вдвое плотнее. -->
                        <div class="form-selectgroup form-selectgroup-vertical w-100">
                            <label
                                v-for="item in providers"
                                :key="item.id"
                                class="form-selectgroup-item"
                            >
                                <input
                                    type="radio"
                                    name="provider"
                                    :value="item.id"
                                    class="form-selectgroup-input"
                                    :checked="selectedProviderId === item.id"
                                    @change="selectProvider(item.id)"
                                />
                                <span class="form-selectgroup-label d-flex align-items-center text-start">
                                    <ProviderIcon :name="item.icon" :size="18"
                                                  class="me-2 flex-shrink-0 text-secondary" />
                                    <span class="flex-fill">
                                        <span class="d-block">{{ item.label }}</span>
                                        <span class="d-block text-secondary">{{ item.description }}</span>
                                    </span>
                                </span>
                            </label>
                        </div>

                        <div v-if="formError" class="alert alert-danger mt-3 mb-0" role="alert">
                            {{ formError }}
                        </div>
                    </div>
                </template>

                <!-- ============ ШАГ 2: ПАРАМЕТРЫ ============ -->
                <template v-else-if="step === 2">
                    <div class="card-body">
                        <h2 class="card-title d-flex align-items-center">
                            <ProviderIcon :name="provider?.icon" :size="18"
                                          class="me-2 text-secondary" />
                            {{ provider?.label }}
                        </h2>
                        <p class="text-secondary mb-3">{{ provider?.description }}</p>

                        <div class="mb-3">
                            <label class="form-label">Название источника</label>
                            <input v-model="form.name" type="text" class="form-control"
                                   :class="{ 'is-invalid': formErrors.name }"
                                   placeholder="Например: Продажи 2026" :disabled="isLoading" />
                            <div v-if="formErrors.name" class="invalid-feedback">{{ formErrors.name[0] }}</div>
                            <div class="form-hint">Под этим именем источник увидит вся команда.</div>
                        </div>

                        <!-- ФАЙЛ -->
                        <template v-if="kind === 'file'">
                            <div class="mb-3">
                                <label class="form-label required">Файл</label>
                                <input ref="fileInputEl" type="file" class="form-control"
                                       :class="{ 'is-invalid': formErrors.data_file }"
                                       :accept="fileAccept" @change="handleDataFile" :disabled="isLoading" />
                                <div v-if="formErrors.data_file" class="invalid-feedback">
                                    {{ formErrors.data_file[0] }}
                                </div>
                                <div class="form-hint">Допустимые форматы: {{ fileAccept }}</div>
                            </div>

                            <div v-if="needsVersion" class="mb-3">
                                <label class="form-label required">Версия</label>
                                <input v-model="form.version" type="text" class="form-control"
                                       :placeholder="versionPlaceholder" :disabled="isLoading" />
                            </div>
                        </template>

                        <!-- ВНЕШНИЙ СЕРВИС -->
                        <template v-else-if="kind === 'api'">
                            <div class="mb-3">
                                <label class="form-label required">Ссылка на таблицу</label>
                                <input v-model="form.sheet_url" type="text" class="form-control"
                                       :class="{ 'is-invalid': formErrors.sheet_url }"
                                       placeholder="https://docs.google.com/spreadsheets/d/…"
                                       :disabled="isLoading" />
                                <div v-if="formErrors.sheet_url" class="invalid-feedback">
                                    {{ formErrors.sheet_url[0] }}
                                </div>
                                <div class="form-hint">Скопируйте адрес из строки браузера.</div>
                            </div>

                            <div class="alert alert-info mb-0">
                                <h4 class="alert-heading">Откройте доступ по ссылке</h4>
                                <div class="alert-description">
                                    В таблице нажмите «Настройки доступа» и включите
                                    «Всем, у кого есть ссылка». Загружается один лист —
                                    тот, что открыт в ссылке. Это снимок данных на текущий
                                    момент, а не постоянная синхронизация.
                                </div>
                            </div>
                        </template>

                        <!-- ВНЕШНЯЯ БАЗА -->
                        <template v-else>
                            <div class="row">
                                <div class="col-8">
                                    <div class="mb-3">
                                        <label class="form-label required">Хост</label>
                                        <input v-model="form.host" type="text" class="form-control"
                                               placeholder="127.0.0.1" :disabled="isLoading" />
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="mb-3">
                                        <label class="form-label required">Порт</label>
                                        <input v-model="form.port" type="number" class="form-control"
                                               :disabled="isLoading" />
                                    </div>
                                </div>
                                <div class="col-8">
                                    <div class="mb-3">
                                        <label class="form-label required">База данных</label>
                                        <input v-model="form.database" type="text" class="form-control"
                                               :disabled="isLoading" />
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="mb-3">
                                        <label class="form-label required">Версия</label>
                                        <input v-model="form.version" type="text" class="form-control"
                                               :placeholder="versionPlaceholder" :disabled="isLoading" />
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="mb-3">
                                        <label class="form-label required">Пользователь</label>
                                        <input v-model="form.username" type="text" class="form-control"
                                               :disabled="isLoading" />
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="mb-3">
                                        <label class="form-label">Пароль</label>
                                        <input v-model="form.password" type="password" class="form-control"
                                               autocomplete="new-password" :disabled="isLoading" />
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-info mb-0">
                                Подключение проверяется сразу — источник сохранится, только
                                если база действительно отвечает.
                            </div>
                        </template>

                        <div v-if="formError" class="alert alert-danger mt-3 mb-0" role="alert">
                            {{ formError }}
                        </div>
                    </div>
                </template>

                <!-- ============ ШАГ 3: ГРУППИРОВКА ============ -->
                <template v-else>
                    <div class="card-body">
                        <h2 class="card-title">
                            {{ groupingStatus === 'failed' ? 'Группировка не удалась' : 'Группируем таблицы' }}
                        </h2>
                        <p class="text-secondary mb-0">
                            Источник «{{ createdSource?.name }}» подключён.
                            <template v-if="tables.length">
                                Найдено таблиц: <strong>{{ tables.length }}</strong>.
                            </template>
                        </p>
                    </div>

                    <!--
                      Этапы — списком со статусными точками Tabler, а не .steps-vertical.
                      Тот компонент рассчитан на подписи-вехи и рисует точку через
                      псевдоэлемент: спиннер внутри строки ломал ей выравнивание.
                      Здесь у каждого этапа своё явное состояние — сделан, идёт, ждёт.
                    -->
                    <div class="list-group list-group-flush">
                        <div
                            v-for="(stage, index) in groupingStages"
                            :key="stage"
                            class="list-group-item"
                            :class="{ 'text-secondary': stageState(index) === 'pending' }"
                        >
                            <div class="row align-items-center">
                                <div class="col-auto" style="width: 2rem">
                                    <!-- Сделан -->
                                    <svg v-if="stageState(index) === 'done'"
                                         xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round" class="icon text-green">
                                        <path d="M5 12l5 5l10 -10" />
                                    </svg>

                                    <!-- Идёт сейчас -->
                                    <div v-else-if="stageState(index) === 'running'"
                                         class="spinner-border spinner-border-sm text-primary" role="status"></div>

                                    <!-- Ошибка -->
                                    <svg v-else-if="stageState(index) === 'failed'"
                                         xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round" class="icon text-danger">
                                        <path d="M18 6l-12 12" />
                                        <path d="M6 6l12 12" />
                                    </svg>

                                    <!-- Ещё не дошли -->
                                    <span v-else class="status-dot"></span>
                                </div>

                                <div class="col">
                                    <div :class="{ 'fw-bold': stageState(index) === 'running' }">
                                        {{ stage }}
                                    </div>
                                    <!-- Подробность приходит с бэкенда: например,
                                         «часть 2 из 5» на больших схемах. -->
                                    <div v-if="stageState(index) === 'running' && groupingLabel !== stage"
                                         class="text-secondary small">
                                        {{ groupingLabel }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <div v-if="groupingStatus === 'failed'" class="alert alert-danger mb-0" role="alert">
                            <h4 class="alert-heading">Не удалось сгруппировать таблицы</h4>
                            <div class="alert-description">{{ groupingMessage }}</div>
                        </div>
                        <div v-else-if="groupingStatus === 'completed'" class="alert alert-success mb-0"
                             role="alert">
                            <h4 class="alert-heading">Готово</h4>
                            <div class="alert-description">
                                {{ groupingMessage }} Открываем источник…
                            </div>
                        </div>
                        <p v-else class="text-secondary mb-0">
                            Страницу можно закрыть — работа продолжится в фоне.
                        </p>
                    </div>
                </template>
            </div>

            <!-- ============ ПРОГРЕСС И КНОПКИ ============
                 Как в шаблоне wizard.html: полоса слева заполняется по мере
                 прохождения шагов, кнопки прижаты вправо, всё под карточкой. -->
            <div class="row align-items-center mt-3">
                <div class="col-4">
                    <div class="progress progress-sm">
                        <div class="progress-bar" :style="{ width: progress + '%' }" role="progressbar"
                             :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                            <span class="visually-hidden">{{ progress }}%</span>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="btn-list justify-content-end">
                        <template v-if="step === 1">
                            <router-link :to="{ name: 'company.sources' }" class="btn btn-link link-secondary">
                                Отмена
                            </router-link>
                            <button class="btn btn-primary" :disabled="!provider" @click="goToConfig">
                                Далее
                            </button>
                        </template>

                        <template v-else-if="step === 2">
                            <button class="btn btn-link link-secondary" :disabled="isLoading"
                                    @click="backToProvider">
                                Назад
                            </button>
                            <button class="btn btn-primary" :class="{ 'btn-loading': isLoading }"
                                    :disabled="isLoading || !isFormValid" @click="submitConfig">
                                Подключить
                            </button>
                        </template>

                        <template v-else>
                            <button class="btn btn-link link-secondary" @click="goToSource">
                                К источнику
                            </button>
                            <button v-if="groupingStatus === 'failed'" class="btn btn-primary"
                                    @click="startGrouping">
                                Повторить
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
