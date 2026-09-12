<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import api from '../../api.js';

const { t } = useI18n();

/**
 * Заведение и правка сотрудника — отдельной страницей.
 *
 * Раньше это было модальное окно. Настройка доступа его переросла: кроме
 * имени и пароля здесь пятнадцать прав по четырём разделам, и всё это
 * не помещалось в окно, не давая при этом ни ссылки на конкретного
 * сотрудника, ни возврата кнопкой «назад».
 *
 * Одна страница на оба случая: отличаются они только тем, есть ли id
 * в адресе, и обязательностью пароля.
 */

const route = useRoute();
const router = useRouter();

const isCreate = computed(() => route.name === 'settings.users.create');
const employeeId = computed(() => (isCreate.value ? null : Number(route.params.id)));

const loading = ref(true);
const saving = ref(false);
const loadError = ref(null);
const formError = ref(null);
const formErrors = ref({});

const assignableRoles = ref([]);
const permissionGroups = ref([]);
const canManageRoles = ref(false);

/** Сотрудник в том виде, в каком его отдал сервер, — для заголовка и запретов. */
const employee = ref(null);

const currentUser = JSON.parse(localStorage.getItem('user') || 'null');
const permissions = computed(() => currentUser?.permissions ?? []);
const canManage = computed(() => permissions.value.includes('manage users'));

const form = reactive({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'analyst',
    permissions: [],
    is_active: true,
});

const isCustom = computed(() => form.role === 'custom');

/**
 * Права, которые нельзя выдать, потому что их нет у самого себя.
 *
 * Это же проверяет сервер (UsersController::authorizeAccessChange), но
 * упереться в 403 после заполнения формы — плохой способ об этом узнать.
 */
function canGrant(name) {
    return permissions.value.includes(name);
}

function togglePermission(name) {
    if (!canGrant(name)) return;

    const index = form.permissions.indexOf(name);

    if (index === -1) {
        form.permissions.push(name);
    } else {
        form.permissions.splice(index, 1);
    }
}

/**
 * Переключение набора.
 *
 * При переходе к особым правам за отправную точку берём состав выбранной
 * роли: собирать доступ с нуля галочками — работа на ровном месте, обычно
 * нужно «как аналитик, но без источников».
 */
function onRoleChange(next) {
    const previous = form.role;

    form.role = next;

    if (next !== 'custom') {
        form.permissions = [];

        return;
    }

    if (form.permissions.length) return;

    const preset = assignableRoles.value.find((r) => r.name === previous);

    form.permissions = (preset?.permissions ?? []).filter(canGrant);
}

const roleLabel = (name) =>
    assignableRoles.value.find((r) => r.name === name)?.label ?? name;

/**
 * Справочник ролей и прав приходит вместе со списком сотрудников — там же,
 * откуда его берёт и сама страница списка. Отдельной выдачи под форму нет
 * намеренно: список компании невелик, а один запрос вместо двух означает,
 * что форма открывается сразу заполненной.
 */
async function load() {
    loading.value = true;
    loadError.value = null;

    try {
        const { data } = await api.get('/settings/users');

        assignableRoles.value = data.assignable_roles ?? [];
        permissionGroups.value = data.permission_groups ?? [];
        canManageRoles.value = Boolean(data.can_manage_roles);

        if (isCreate.value) return;

        const found = (data.users ?? []).find((u) => u.id === employeeId.value);

        if (!found) {
            loadError.value = t('settingsUserForm.errors.employee_not_found');

            return;
        }

        employee.value = found;

        Object.assign(form, {
            name: found.name,
            email: found.email,
            password: '',
            password_confirmation: '',
            role: found.role ?? 'analyst',
            // У особых прав показываем действующий набор; у готовой роли
            // состав задаёт сама роль.
            permissions: found.role === 'custom' ? [...(found.permissions ?? [])] : [],
            is_active: found.is_active,
        });
    } catch (err) {
        loadError.value =
            err.response?.status === 403
                ? t('settingsUserForm.errors.no_permission_manage')
                : t('settingsUserForm.errors.load_failed');
    } finally {
        loading.value = false;
    }
}

async function submit() {
    if (saving.value) return;

    saving.value = true;
    formErrors.value = {};
    formError.value = null;

    const payload = { ...form };

    // У готовой роли состав определяет она сама — список галочек серверу
    // не нужен и только сбивал бы с толку в логах.
    if (payload.role !== 'custom') delete payload.permissions;

    // Пустой пароль при правке означает «не менять» — не отправляем его.
    if (!isCreate.value && !payload.password) {
        delete payload.password;
        delete payload.password_confirmation;
    }

    try {
        if (isCreate.value) {
            await api.post('/settings/users', payload);
        } else {
            await api.put(`/settings/users/${employeeId.value}`, payload);
        }

        router.push({ name: 'settings.users' });
    } catch (err) {
        const data = err.response?.data;

        if (data?.errors) {
            formErrors.value = data.errors;
        } else {
            formError.value = data?.message || t('settingsUserForm.errors.save_failed');
        }
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="page-wrapper">
        <!-- ШАПКА -->
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="page-pretitle">
                            <router-link :to="{ name: 'settings.users' }" class="text-reset">
                                {{ t('settingsUserForm.breadcrumb_employees') }}
                            </router-link>
                        </div>
                        <h2 class="page-title">
                            {{ isCreate ? t('settingsUserForm.title_create') : employee?.name || t('settingsUserForm.title_edit_fallback') }}
                        </h2>
                        <div v-if="!isCreate && employee" class="text-secondary mt-1">
                            {{ employee.email }}
                            <span v-if="employee.is_owner" class="badge bg-yellow-lt ms-1">{{ t('settingsUserForm.owner_badge') }}</span>
                            <span v-else-if="employee.is_self" class="badge bg-secondary-lt ms-1">{{ t('settingsUserForm.self_badge') }}</span>
                        </div>
                    </div>

                    <div class="col-auto ms-auto d-print-none">
                        <router-link :to="{ name: 'settings.users' }" class="btn btn-link link-secondary">
                            {{ t('settingsUserForm.back_to_list') }}
                        </router-link>
                    </div>
                </div>
            </div>
        </div>

        <!-- ТЕЛО -->
        <main class="page-body">
            <div class="container-xl">
                <div v-if="loading" class="card">
                    <div class="card-body">
                        <div class="progress progress-sm">
                            <div class="progress-bar progress-bar-indeterminate"></div>
                        </div>
                    </div>
                </div>

                <div v-else-if="loadError" class="alert alert-danger" role="alert">
                    {{ loadError }}
                </div>

                <div v-else-if="!canManage" class="alert alert-warning" role="alert">
                    {{ t('settingsUserForm.errors.no_permission_manage') }}
                </div>

                <form v-else class="card" @submit.prevent="submit">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="mb-3">
                                    <label class="form-label required">{{ t('settingsUserForm.fields.name_label') }}</label>
                                    <input v-model="form.name" type="text" class="form-control"
                                           :class="{ 'is-invalid': formErrors.name }" :placeholder="t('settingsUserForm.fields.name_placeholder')" />
                                    <div v-if="formErrors.name" class="invalid-feedback">{{ formErrors.name[0] }}</div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="mb-3">
                                    <label class="form-label required">{{ t('settingsUserForm.fields.email_label') }}</label>
                                    <input v-model="form.email" type="email" class="form-control"
                                           :class="{ 'is-invalid': formErrors.email }" :placeholder="t('settingsUserForm.fields.email_placeholder')" />
                                    <div v-if="formErrors.email" class="invalid-feedback">{{ formErrors.email[0] }}</div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="mb-3">
                                    <label class="form-label" :class="{ required: isCreate }">{{ t('settingsUserForm.fields.password_label') }}</label>
                                    <input v-model="form.password" type="password" class="form-control"
                                           :class="{ 'is-invalid': formErrors.password }"
                                           :placeholder="isCreate ? t('settingsUserForm.fields.password_placeholder_create') : t('settingsUserForm.fields.password_placeholder_edit')" />
                                    <div v-if="formErrors.password" class="invalid-feedback">{{ formErrors.password[0] }}</div>
                                </div>
                            </div>

                            <div class="col-lg-6">
                                <div class="mb-3">
                                    <label class="form-label">{{ t('settingsUserForm.fields.password_confirm_label') }}</label>
                                    <input v-model="form.password_confirmation" type="password" class="form-control" />
                                </div>
                            </div>
                        </div>

                        <hr class="my-3" />

                        <!-- РОЛЬ -->
                        <div class="mb-3">
                            <label class="form-label required">{{ t('settingsUserForm.role.label') }}</label>
                            <div class="row g-2">
                                <div class="col-md-6 col-lg-3"
                                     v-for="role in assignableRoles"
                                     :key="role.name"
                                     v-show="role.name !== 'custom' || canManageRoles">
                                    <label class="form-selectgroup-item flex-fill">
                                        <input type="radio" :value="role.name" :checked="form.role === role.name"
                                               class="form-selectgroup-input"
                                               @change="onRoleChange(role.name)" />
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

                        <!-- ПРАВА: только для режима особых прав -->
                        <div v-if="isCustom" class="mb-3">
                            <div class="row g-3">
                                <div class="col-md-6" v-for="group in permissionGroups" :key="group.key">
                                    <div class="card card-sm h-100">
                                        <div class="card-header py-2">
                                            <span class="card-title fw-bold">{{ group.label }}</span>
                                        </div>
                                        <div class="card-body py-2">
                                            <label v-for="item in group.items" :key="item.name"
                                                   class="form-check"
                                                   :class="{ 'opacity-50': !canGrant(item.name) }">
                                                <input class="form-check-input" type="checkbox"
                                                       :checked="form.permissions.includes(item.name)"
                                                       :disabled="!canGrant(item.name)"
                                                       @change="togglePermission(item.name)" />
                                                <span class="form-check-label">{{ item.label }}</span>
                                                <span v-if="!canGrant(item.name)"
                                                      class="form-check-description text-secondary">
                                                    {{ t('settingsUserForm.permissions.not_grantable') }}
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-hint mt-2">
                                {{ t('settingsUserForm.permissions.selected_count', { count: form.permissions.length }) }}
                            </div>

                            <div v-if="formErrors.permissions" class="invalid-feedback d-block">
                                {{ formErrors.permissions[0] }}
                            </div>
                        </div>

                        <hr class="my-3" />

                        <div>
                            <label class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" v-model="form.is_active" />
                                <span class="form-check-label">{{ t('settingsUserForm.active.label') }}</span>
                            </label>
                            <small class="form-hint">
                                {{ t('settingsUserForm.active.hint') }}
                            </small>
                            <div v-if="formErrors.is_active" class="invalid-feedback d-block">
                                {{ formErrors.is_active[0] }}
                            </div>
                        </div>

                        <div v-if="formError" class="alert alert-danger mt-3 mb-0" role="alert">{{ formError }}</div>
                    </div>

                    <div class="card-footer d-flex align-items-center">
                        <router-link :to="{ name: 'settings.users' }" class="btn btn-link link-secondary">
                            {{ t('settingsUserForm.cancel') }}
                        </router-link>
                        <button type="submit" class="btn btn-primary ms-auto" :disabled="saving">
                            {{ saving ? t('settingsUserForm.submit_saving') : (isCreate ? t('settingsUserForm.submit_create') : t('settingsUserForm.submit_edit')) }}
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</template>
