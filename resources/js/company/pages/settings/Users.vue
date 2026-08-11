<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Modal } from 'bootstrap';
import api from '../../api.js';

const users = ref([]);
const assignableRoles = ref([]);
const loading = ref(false);
const saving = ref(false);
const deleting = ref(false);
const listError = ref(null);
const formErrors = ref({});
const formError = ref(null);
const search = ref('');

// null — форма закрыта, 'create' — новый сотрудник, число — id редактируемого
const editing = ref(null);
const pendingDelete = ref(null);

const formModalEl = ref(null);
const deleteModalEl = ref(null);
let formModal = null;
let deleteModal = null;

const currentUser = JSON.parse(localStorage.getItem('user') || 'null');

const permissions = computed(() => currentUser?.permissions ?? []);
const canManage = computed(() => permissions.value.includes('manage users'));

const emptyForm = () => ({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'analyst',
    is_active: true,
});

const form = reactive(emptyForm());

const filteredUsers = computed(() => {
    const query = search.value.trim().toLowerCase();

    if (!query) return users.value;

    return users.value.filter(
        (u) =>
            u.name.toLowerCase().includes(query) ||
            u.email.toLowerCase().includes(query)
    );
});

const roleLabel = (name) =>
    assignableRoles.value.find((r) => r.name === name)?.label ?? name;

// Цвета бейджей — из палитры Tabler
const roleBadgeClass = (name) =>
    ({
        company_admin: 'bg-purple-lt',
        analyst: 'bg-azure-lt',
        viewer: 'bg-secondary-lt',
    }[name] ?? 'bg-secondary-lt');

function initials(name) {
    return (name || '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

async function fetchUsers() {
    loading.value = true;
    listError.value = null;

    try {
        const { data } = await api.get('/settings/users');
        users.value = data.users ?? [];
        assignableRoles.value = data.assignable_roles ?? [];
    } catch (err) {
        listError.value =
            err.response?.status === 403
                ? 'У вас нет прав на просмотр сотрудников.'
                : 'Не удалось загрузить список сотрудников.';
    } finally {
        loading.value = false;
    }
}

async function openForm(user = null) {
    formErrors.value = {};
    formError.value = null;

    if (user) {
        Object.assign(form, {
            name: user.name,
            email: user.email,
            password: '',
            password_confirmation: '',
            role: user.role ?? 'analyst',
            is_active: user.is_active,
        });
        editing.value = user.id;
    } else {
        Object.assign(form, emptyForm());
        editing.value = 'create';
    }

    await nextTick();
    formModal?.show();
}

async function submitForm() {
    if (saving.value) return;

    saving.value = true;
    formErrors.value = {};
    formError.value = null;

    // Пустой пароль при редактировании означает «не менять» — не отправляем его.
    const payload = { ...form };
    if (editing.value !== 'create' && !payload.password) {
        delete payload.password;
        delete payload.password_confirmation;
    }

    try {
        if (editing.value === 'create') {
            await api.post('/settings/users', payload);
        } else {
            await api.put(`/settings/users/${editing.value}`, payload);
        }

        formModal?.hide();
        await fetchUsers();
    } catch (err) {
        const data = err.response?.data;

        if (data?.errors) {
            formErrors.value = data.errors;
        } else {
            formError.value = data?.message || 'Не удалось сохранить сотрудника.';
        }
    } finally {
        saving.value = false;
    }
}

async function askDelete(user) {
    pendingDelete.value = user;
    await nextTick();
    deleteModal?.show();
}

async function confirmDelete() {
    if (!pendingDelete.value || deleting.value) return;

    deleting.value = true;

    try {
        await api.delete(`/settings/users/${pendingDelete.value.id}`);
        deleteModal?.hide();
        pendingDelete.value = null;
        await fetchUsers();
    } catch (err) {
        listError.value =
            err.response?.data?.message || 'Не удалось удалить сотрудника.';
        deleteModal?.hide();
    } finally {
        deleting.value = false;
    }
}

onMounted(async () => {
    await fetchUsers();
    await nextTick();

    if (formModalEl.value) formModal = new Modal(formModalEl.value);
    if (deleteModalEl.value) deleteModal = new Modal(deleteModalEl.value);
});

onBeforeUnmount(() => {
    formModal?.dispose();
    deleteModal?.dispose();
});
</script>

<template>
    <div class="page-wrapper">
        <!-- BEGIN PAGE HEADER -->
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="page-pretitle">Компания {{ currentUser?.company?.name }}</div>
                        <h2 class="page-title">Сотрудники</h2>
                        <div class="text-secondary mt-1">
                            {{ users.length }} {{ users.length === 1 ? 'человек' : 'человек(а)' }} в команде
                        </div>
                    </div>

                    <div class="col-auto ms-auto d-print-none">
                        <div class="d-flex">
                            <input
                                v-model="search"
                                type="search"
                                class="form-control d-inline-block w-9 me-3"
                                placeholder="Поиск сотрудника…"
                                aria-label="Поиск сотрудника"
                            />
                            <button v-if="canManage" class="btn btn-primary" @click="openForm()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true" focusable="false" class="icon icon-2">
                                    <path d="M12 5l0 14" />
                                    <path d="M5 12l14 0" />
                                </svg>
                                Добавить сотрудника
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- END PAGE HEADER -->

        <!-- BEGIN PAGE BODY -->
        <main class="page-body">
            <div class="container-xl">
                <div v-if="listError" class="alert alert-danger" role="alert">
                    {{ listError }}
                </div>

                <div class="card">
                    <!-- Загрузка -->
                    <div v-if="loading" class="card-body">
                        <div class="progress progress-sm">
                            <div class="progress-bar progress-bar-indeterminate"></div>
                        </div>
                    </div>

                    <!-- Пусто -->
                    <div v-else-if="!filteredUsers.length" class="card-body">
                        <div class="empty">
                            <p class="empty-title">
                                {{ search ? 'Ничего не найдено' : 'Сотрудников пока нет' }}
                            </p>
                            <p class="empty-subtitle text-secondary">
                                {{ search
                                    ? 'Попробуйте изменить поисковый запрос.'
                                    : 'Добавьте сотрудников и выдайте им нужные роли и доступы.' }}
                            </p>
                            <div class="empty-action" v-if="canManage && !search">
                                <button class="btn btn-primary" @click="openForm()">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         stroke-linejoin="round" aria-hidden="true" focusable="false" class="icon icon-2">
                                        <path d="M12 5l0 14" />
                                        <path d="M5 12l14 0" />
                                    </svg>
                                    Добавить сотрудника
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Таблица -->
                    <div v-else class="table-responsive">
                        <table class="table table-vcenter card-table">
                            <thead>
                            <tr>
                                <th>Сотрудник</th>
                                <th>Роль</th>
                                <th>Статус</th>
                                <th class="w-1"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr v-for="user in filteredUsers" :key="user.id">
                                <td>
                                    <div class="d-flex py-1 align-items-center">
                                        <span class="avatar me-2">{{ initials(user.name) }}</span>
                                        <div class="flex-fill">
                                            <div class="font-weight-medium">
                                                {{ user.name }}
                                                <span v-if="user.is_owner" class="badge bg-yellow-lt ms-1">владелец</span>
                                                <span v-else-if="user.is_self" class="badge bg-secondary-lt ms-1">это вы</span>
                                            </div>
                                            <div class="text-secondary">
                                                <a :href="`mailto:${user.email}`" class="text-reset">{{ user.email }}</a>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge" :class="roleBadgeClass(user.role)">
                                        {{ roleLabel(user.role) }}
                                    </span>
                                </td>
                                <td>
                                    <span v-if="user.is_active" class="badge bg-success me-1"></span>
                                    <span v-else class="badge bg-secondary me-1"></span>
                                    {{ user.is_active ? 'Активен' : 'Отключён' }}
                                </td>
                                <td>
                                    <div v-if="canManage" class="btn-list flex-nowrap justify-content-end">
                                        <button class="btn btn-sm" @click="openForm(user)">
                                            Изменить
                                        </button>
                                        <button
                                            v-if="!user.is_owner && !user.is_self"
                                            class="btn btn-sm btn-ghost-danger"
                                            @click="askDelete(user)"
                                        >
                                            Удалить
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
        <!-- END PAGE BODY -->

        <!-- BEGIN MODAL: форма сотрудника -->
        <div ref="formModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            {{ editing === 'create' ? 'Новый сотрудник' : 'Редактирование сотрудника' }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="mb-3">
                                    <label class="form-label required">Имя</label>
                                    <input v-model="form.name" type="text" class="form-control"
                                           :class="{ 'is-invalid': formErrors.name }" placeholder="Иван Иванов" />
                                    <div v-if="formErrors.name" class="invalid-feedback">{{ formErrors.name[0] }}</div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="mb-3">
                                    <label class="form-label required">E-mail</label>
                                    <input v-model="form.email" type="email" class="form-control"
                                           :class="{ 'is-invalid': formErrors.email }" placeholder="ivan@company.com" />
                                    <div v-if="formErrors.email" class="invalid-feedback">{{ formErrors.email[0] }}</div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="mb-3">
                                    <label class="form-label" :class="{ required: editing === 'create' }">Пароль</label>
                                    <input v-model="form.password" type="password" class="form-control"
                                           :class="{ 'is-invalid': formErrors.password }"
                                           :placeholder="editing === 'create' ? 'Минимум 6 символов' : 'Оставьте пустым, чтобы не менять'" />
                                    <div v-if="formErrors.password" class="invalid-feedback">{{ formErrors.password[0] }}</div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="mb-3">
                                    <label class="form-label">Подтверждение пароля</label>
                                    <input v-model="form.password_confirmation" type="password" class="form-control" />
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="mb-3">
                                    <label class="form-label required">Роль и доступы</label>
                                    <div class="row g-2">
                                        <div class="col-md-4" v-for="role in assignableRoles" :key="role.name">
                                            <label class="form-selectgroup-item flex-fill">
                                                <input type="radio" :value="role.name" v-model="form.role"
                                                       class="form-selectgroup-input" />
                                                <span class="form-selectgroup-label d-flex align-items-start p-3 text-start">
                                                    <span>
                                                        <span class="d-block fw-bold">{{ role.label }}</span>
                                                        <span class="d-block text-secondary small mt-1">{{ role.description }}</span>
                                                    </span>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                    <div v-if="formErrors.role" class="invalid-feedback d-block">{{ formErrors.role[0] }}</div>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" v-model="form.is_active" />
                                    <span class="form-check-label">Учётная запись активна</span>
                                </label>
                                <small class="form-hint">
                                    Отключённый сотрудник не сможет войти, но его данные и история сохранятся.
                                </small>
                                <div v-if="formErrors.is_active" class="invalid-feedback d-block">{{ formErrors.is_active[0] }}</div>
                            </div>
                        </div>

                        <div v-if="formError" class="alert alert-danger mt-3 mb-0" role="alert">{{ formError }}</div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="button" class="btn btn-primary ms-auto" :disabled="saving" @click="submitForm">
                            {{ saving ? 'Сохранение…' : 'Сохранить' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- END MODAL -->

        <!-- BEGIN MODAL: подтверждение удаления -->
        <div ref="deleteModalEl" class="modal modal-blur fade" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-status bg-danger"></div>
                    <div class="modal-body text-center py-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             class="icon mb-2 text-danger icon-lg">
                            <path d="M12 9v4" />
                            <path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" />
                            <path d="M12 16h.01" />
                        </svg>
                        <h3>Удалить сотрудника?</h3>
                        <div class="text-secondary">
                            {{ pendingDelete?.name }} потеряет доступ к системе. Действие необратимо.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <div class="w-100">
                            <div class="row">
                                <div class="col">
                                    <button class="btn w-100" data-bs-dismiss="modal">Отмена</button>
                                </div>
                                <div class="col">
                                    <button class="btn btn-danger w-100" :disabled="deleting" @click="confirmDelete">
                                        {{ deleting ? 'Удаление…' : 'Удалить' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- END MODAL -->
    </div>
</template>
