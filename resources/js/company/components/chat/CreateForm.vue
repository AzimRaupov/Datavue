<template>
    <div class="modal modal-blur fade" id="modal-report" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <form @submit.prevent="createChat" enctype="multipart/form-data">

                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Data to Dashboard</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">

                        <div class="mb-3">
                            <label class="form-label">Источник данных</label>
                            <div class="btn-group w-100" role="group">
                                <button
                                    type="button"
                                    class="btn"
                                    :class="mode === 'local' ? 'btn-primary' : 'btn-outline-primary'"
                                    :disabled="isLoading"
                                    @click="setMode('local')"
                                >
                                    Файл
                                </button>
                                <button
                                    type="button"
                                    class="btn"
                                    :class="mode === 'remote' ? 'btn-primary' : 'btn-outline-primary'"
                                    :disabled="isLoading"
                                    @click="setMode('remote')"
                                >
                                    Подключение
                                </button>
                            </div>
                        </div>

                        <div v-if="mode === 'local'">
                            <input type="hidden" name="connection_type" value="local">

                            <div class="mb-3">
                                <label class="form-label">Файл Excel, Csv, Sql</label>
                                <input type="file" class="form-control" @change="handleDataFile" :disabled="isLoading" />
                            </div>

                            <div class="row" v-if="isDbFile">
                                <div class="col-lg-6">
                                    <div class="mb-3">
                                        <label class="form-label">База данных</label>
                                        <select class="form-select" v-model="form.type_id" :disabled="isLoading" required>
                                            <option value="" disabled>Выберите базу данных</option>
                                            <option v-for="type in dataSourceTypes" :key="type.id" :value="type.id">
                                                {{ type.name }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="mb-3">
                                        <label class="form-label">Версия</label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            v-model="form.version"
                                            :placeholder="versionPlaceholder"
                                            :disabled="isLoading"
                                            required
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-else>
                            <input type="hidden" name="connection_type" value="remote">
                            <div class="row">
                                <div class="col-lg-6">
                                    <div class="mb-3">
                                        <label class="form-label">База данных</label>
                                        <select class="form-select" v-model="form.type_id" :disabled="isLoading" required>
                                            <option value="" disabled>Выберите базу данных</option>
                                            <option v-for="type in dataSourceTypes" :key="type.id" :value="type.id">
                                                {{ type.name }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="mb-3">
                                        <label class="form-label">Версия</label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            v-model="form.version"
                                            :placeholder="versionPlaceholder"
                                            :disabled="isLoading"
                                            required
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-lg-8">
                                    <div class="mb-3">
                                        <label class="form-label">Host</label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            v-model="form.host"
                                            placeholder="Например: 127.0.0.1"
                                            :disabled="isLoading"
                                            required
                                        />
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="mb-3">
                                        <label class="form-label">Port</label>
                                        <input
                                            type="number"
                                            class="form-control"
                                            v-model="form.port"
                                            :placeholder="portPlaceholder"
                                            :disabled="isLoading"
                                            required
                                        />
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Database</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    v-model="form.database"
                                    placeholder="Название базы данных"
                                    :disabled="isLoading"
                                    required
                                />
                            </div>

                            <div class="row">
                                <div class="col-lg-6">
                                    <div class="mb-3">
                                        <label class="form-label">Username</label>
                                        <input
                                            type="text"
                                            class="form-control"
                                            v-model="form.username"
                                            :disabled="isLoading"
                                            required
                                        />
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="mb-3">
                                        <label class="form-label">Password</label>
                                        <input
                                            type="password"
                                            class="form-control"
                                            v-model="form.password"
                                            :disabled="isLoading"
                                            autocomplete="new-password"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer">
                        <a href="#" class="btn btn-link link-secondary btn-3" data-bs-dismiss="modal" v-if="!isLoading"> Cancel </a>

                        <button type="submit" class="btn btn-primary btn-5 ms-auto" :disabled="isLoading || !isFormValid">
                            <span v-if="isLoading" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                            <svg
                                v-else
                                xmlns="http://www.w3.org/2000/svg"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                                focusable="false"
                                class="icon icon-2"
                            >
                                <path d="M16 18a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2zm0 -12a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2zm-11 5a3 3 0 0 1 3 3a3 3 0 0 1 3 -3a3 3 0 0 1 -3 -3a3 3 0 0 1 -3 3z" />
                            </svg>
                            {{ isLoading ? 'Loading...' : 'Create dashboard' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, inject } from 'vue';
import { useRouter } from 'vue-router';
import api from '../../api.js';
import { bootstrap } from "@tabler/core/";

const router = useRouter();

// toast приходит из App.vue через provide('toast', toastRef)
const toast = inject('toast');

const mode = ref('local');

const form = reactive({
    type_id: '',
    version: '',
    host: '',
    port: '',
    database: '',
    username: '',
    password: '',
});

const dataFile = ref(null);
const isLoading = ref(false);
const fileExtension = ref('');

const dataSourceTypes = ref([]);

const DB_EXTENSIONS = ['db', 'sqlite', 'sqlite3', 'duckdb', 'sql'];

const EXTENSION_TYPE_MAP = {
    db: 'sqlite',
    sqlite: 'sqlite',
    sqlite3: 'sqlite',
    duckdb: 'duckdb',
};

const DEFAULT_PORTS = {
    mysql: '3306',
    postgres: '5432',
};

const isDbFile = computed(() => DB_EXTENSIONS.includes(fileExtension.value));

const isFormValid = computed(() => {
    if (mode.value === 'local') {
        if (!dataFile.value) return false;
        if (!isDbFile.value) return true;
        return !!form.type_id && !!form.version;
    }

    return (
        !!form.type_id &&
        !!form.version &&
        !!form.host &&
        !!form.port &&
        !!form.database &&
        !!form.username
    );
});

const versionPlaceholder = computed(() => {
    const selected = dataSourceTypes.value.find((t) => t.id === form.type_id);
    if (!selected) return 'Например: 8.0, 15, 3.42';

    switch (selected.name) {
        case 'mysql':
            return 'Например: 8.0';
        case 'postgres':
            return 'Например: 15';
        case 'sqlite':
            return 'Например: 3.42';
        case 'duckdb':
            return 'Например: 1.0';
        default:
            return 'Укажите версию';
    }
});

const portPlaceholder = computed(() => {
    const selected = dataSourceTypes.value.find((t) => t.id === form.type_id);
    return selected && DEFAULT_PORTS[selected.name] ? DEFAULT_PORTS[selected.name] : 'Порт';
});

async function getDataSourceTypes() {
    try {
        const response = await api.get('/data_source/types');
        dataSourceTypes.value = response.data;
    } catch (error) {
        toast?.value?.error('Не удалось загрузить список источников данных');
    }
}

function setMode(newMode) {
    if (isLoading.value) return;
    mode.value = newMode;

    form.type_id = '';
    form.version = '';
    form.host = '';
    form.port = '';
    form.database = '';
    form.username = '';
    form.password = '';
    dataFile.value = null;
    fileExtension.value = '';
}

function handleDataFile(event) {
    const file = event.target.files[0];
    dataFile.value = file;

    form.type_id = '';
    form.version = '';

    if (!file) {
        fileExtension.value = '';
        return;
    }

    const parts = file.name.split('.');
    fileExtension.value = parts.length > 1 ? parts.pop().toLowerCase() : '';

    const guessedName = EXTENSION_TYPE_MAP[fileExtension.value];
    if (guessedName) {
        const guessedType = dataSourceTypes.value.find((t) => t.name === guessedName);
        if (guessedType) {
            form.type_id = guessedType.id;
        }
    }
}

function closeModalAndWait(modalEl) {
    return new Promise((resolve) => {
        const bsModal = bootstrap.Modal.getInstance(modalEl);

        if (!bsModal) {
            resolve();
            return;
        }

        const onHidden = () => {
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            resolve();
        };

        modalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });
        bsModal.hide();

        setTimeout(() => {
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            cleanupBackdrops();
            resolve();
        }, 500);
    });
}

function cleanupBackdrops() {
    document.querySelectorAll('.modal-backdrop').forEach((el) => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
}

// Достаём человекочитаемое сообщение об ошибке из ответа Laravel
function extractErrorMessage(error) {
    const data = error?.response?.data;
    if (!data) return 'Произошла ошибка. Попробуйте ещё раз.';

    if (data.errors) {
        const firstField = Object.keys(data.errors)[0];
        return data.errors[firstField]?.[0] || data.message || 'Ошибка валидации';
    }

    return data.message || 'Произошла ошибка. Попробуйте ещё раз.';
}

async function createChat() {
    if (isLoading.value) return;
    if (!isFormValid.value) return;

    try {
        isLoading.value = true;

        const formData = new FormData();
        formData.append('connection_type', mode.value);

        if (mode.value === 'local') {
            if (dataFile.value) {
                formData.append('data_file', dataFile.value);
            }

            if (isDbFile.value) {
                formData.append('type_id', form.type_id);
                formData.append('version', form.version);
            }
        } else {
            formData.append('type_id', form.type_id);
            formData.append('version', form.version);
            formData.append('host', form.host);
            formData.append('port', form.port);
            formData.append('database', form.database);
            formData.append('username', form.username);
            formData.append('password', form.password);
        }

        const response = await api.post('/chats', formData);

        // Бэкенд при неудачном remote-подключении отвечает HTTP 200 + success:false,
        // это не попадёт в catch — проверяем вручную.
        if (response.data.success === false) {
            toast?.value?.error(response.data.message || 'Не удалось подключиться к базе данных');
            isLoading.value = false;
            return;
        }

        toast?.value?.success(response.data.message || 'Дашборд успешно создан');

        const modal = document.getElementById('modal-report');
        await closeModalAndWait(modal);

        await router.push({
            name: 'company.chat',
            params: { id: response.data.chat.id }
        });

    } catch (error) {
        console.error(error);
        toast?.value?.error(extractErrorMessage(error));
        isLoading.value = false;
    }
}

onMounted(getDataSourceTypes);
</script>
