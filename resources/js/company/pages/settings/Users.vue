<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Modal } from 'bootstrap';
import api from '../../api.js';

/**
 * Список сотрудников компании.
 *
 * Заведение и правка живут на отдельной странице (UserForm.vue): форма
 * доступа переросла модальное окно, и на неё должна работать ссылка.
 * Здесь остаётся только список и подтверждение удаления — оно короткое
 * и обратного пути не имеет, окну там самое место.
 */

const users = ref([]);
const assignableRoles = ref([]);
const loading = ref(false);
const deleting = ref(false);
const listError = ref(null);
const search = ref('');

const pendingDelete = ref(null);

const deleteModalEl = ref(null);
let deleteModal = null;

const currentUser = JSON.parse(localStorage.getItem('user') || 'null');

const permissions = computed(() => currentUser?.permissions ?? []);
const canManage = computed(() => permissions.value.includes('manage users'));

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
        custom: 'bg-orange-lt',
    }[name] ?? 'bg-secondary-lt');

/** Сколько прав реально открыто сотруднику — для строки в таблице. */
function permissionCount(user) {
    return user.permissions?.length ?? 0;
}

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

    if (deleteModalEl.value) deleteModal = new Modal(deleteModalEl.value);
});

onBeforeUnmount(() => {
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
                            <router-link v-if="canManage" class="btn btn-primary"
                                         :to="{ name: 'settings.users.create' }">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true" focusable="false" class="icon icon-2">
                                    <path d="M12 5l0 14" />
                                    <path d="M5 12l14 0" />
                                </svg>
                                Добавить сотрудника
                            </router-link>
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
                                <router-link class="btn btn-primary" :to="{ name: 'settings.users.create' }">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                         stroke-linejoin="round" aria-hidden="true" focusable="false" class="icon icon-2">
                                        <path d="M12 5l0 14" />
                                        <path d="M5 12l14 0" />
                                    </svg>
                                    Добавить сотрудника
                                </router-link>
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
                                    <div class="text-secondary small mt-1">
                                        прав: {{ permissionCount(user) }}
                                    </div>
                                </td>
                                <td>
                                    <span v-if="user.is_active" class="badge bg-success me-1"></span>
                                    <span v-else class="badge bg-secondary me-1"></span>
                                    {{ user.is_active ? 'Активен' : 'Отключён' }}
                                </td>
                                <td>
                                    <div v-if="canManage" class="btn-list flex-nowrap justify-content-end">
                                        <router-link class="btn btn-sm"
                                                     :to="{ name: 'settings.users.edit', params: { id: user.id } }">
                                            Изменить
                                        </router-link>
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
