<script setup>
import { ref, reactive, computed } from 'vue';
import api from '../../api.js';

/**
 * Собственный профиль: имя, e-mail, пароль и название компании.
 *
 * Раньше здесь лежала неизменённая демо-страница Tabler: ссылки вели на
 * несуществующие .html, поля были привязаны через :value без v-model, кнопки
 * сохранения не было вовсе. Изменить о себе что-либо было невозможно.
 *
 * Три независимых блока вместо одной большой формы: смена пароля требует
 * подтверждения текущего, и мешать её с правкой имени неудобно — из-за
 * ошибки в пароле не сохранялось бы и остальное.
 */

const stored = JSON.parse(localStorage.getItem('user') || 'null');

const user = ref(stored);

const canManageCompany = computed(() =>
    (user.value?.permissions ?? []).includes('manage company')
);

// --- Личные данные ---
const profile = reactive({
    name: stored?.name ?? '',
    email: stored?.email ?? '',
});
const savingProfile = ref(false);
const profileErrors = ref({});
const profileMessage = ref(null);

// --- Пароль ---
const passwordForm = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
});
const savingPassword = ref(false);
const passwordErrors = ref({});
const passwordMessage = ref(null);

// --- Компания ---
const company = reactive({ name: stored?.company?.name ?? '' });
const savingCompany = ref(false);
const companyErrors = ref({});
const companyMessage = ref(null);

const profileChanged = computed(() =>
    profile.name !== (user.value?.name ?? '') ||
    profile.email !== (user.value?.email ?? '')
);

const passwordFilled = computed(() =>
    !!passwordForm.current_password &&
    !!passwordForm.password &&
    !!passwordForm.password_confirmation
);

const companyChanged = computed(() =>
    company.name !== (user.value?.company?.name ?? '')
);

function initials(name) {
    return (name || '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map(part => part[0])
        .join('')
        .toUpperCase();
}

/**
 * Общая отправка: контроллер принимает любой набор полей и меняет только
 * присланные, поэтому три формы шлют по одному и тому же адресу.
 */
async function submit(payload, { saving, errors, message, onSuccess }) {
    if (saving.value) return;

    saving.value = true;
    errors.value = {};
    message.value = null;

    try {
        const { data } = await api.post('/settings/profile', payload);

        // Ответ содержит свежего пользователя с ролями и правами — им же
        // пользуется шапка, поэтому кладём его в localStorage целиком.
        user.value = data.user;
        localStorage.setItem('user', JSON.stringify(data.user));

        message.value = 'Сохранено.';
        onSuccess?.();
    } catch (err) {
        const data = err.response?.data;

        if (data?.errors) {
            errors.value = data.errors;
        } else {
            errors.value = { _: [data?.message || 'Не удалось сохранить.'] };
        }
    } finally {
        saving.value = false;
    }
}

const saveProfile = () => submit(
    { name: profile.name, email: profile.email },
    { saving: savingProfile, errors: profileErrors, message: profileMessage }
);

const savePassword = () => submit(
    { ...passwordForm },
    {
        saving: savingPassword,
        errors: passwordErrors,
        message: passwordMessage,
        onSuccess: () => {
            passwordForm.current_password = '';
            passwordForm.password = '';
            passwordForm.password_confirmation = '';
            passwordMessage.value = 'Пароль изменён. Остальные сеансы завершены.';
        },
    }
);

const saveCompany = () => submit(
    { company_name: company.name },
    { saving: savingCompany, errors: companyErrors, message: companyMessage }
);

/** Первая ошибка поля — валидация Laravel отдаёт массив на каждое поле. */
const firstError = (errors, field) => errors.value?.[field]?.[0] ?? null;
</script>

<template>
    <div class="page-wrapper">
        <!-- BEGIN PAGE HEADER -->
        <div class="page-header d-print-none">
            <div class="container-xl">
                <div class="row g-2 align-items-center">
                    <div class="col">
                        <div class="page-pretitle">Компания {{ user?.company?.name }}</div>
                        <h2 class="page-title">Мой профиль</h2>
                    </div>
                </div>
            </div>
        </div>
        <!-- END PAGE HEADER -->

        <!-- BEGIN PAGE BODY -->
        <main class="page-body">
            <div class="container-xl">
                <div class="row row-cards">
                    <!-- ЛЕВАЯ КОЛОНКА: кто я -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <span class="avatar avatar-xl mb-3">{{ initials(user?.name) }}</span>
                                <h3 class="mb-1">{{ user?.name }}</h3>
                                <div class="text-secondary">{{ user?.email }}</div>

                                <div class="mt-3">
                                    <span v-for="role in (user?.roles ?? [])" :key="role"
                                          class="badge bg-purple-lt me-1">
                                        {{ role }}
                                    </span>
                                    <span v-if="user?.is_company_owner" class="badge bg-yellow-lt">
                                        владелец
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ПРАВАЯ КОЛОНКА: что можно менять -->
                    <div class="col-lg-8">
                        <!-- Личные данные -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <h3 class="card-title">Личные данные</h3>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label required">Имя</label>
                                    <input v-model="profile.name" type="text" class="form-control"
                                           :class="{ 'is-invalid': firstError(profileErrors, 'name') }"
                                           :disabled="savingProfile" />
                                    <div v-if="firstError(profileErrors, 'name')" class="invalid-feedback">
                                        {{ firstError(profileErrors, 'name') }}
                                    </div>
                                </div>

                                <div class="mb-0">
                                    <label class="form-label required">E-mail</label>
                                    <input v-model="profile.email" type="email" class="form-control"
                                           :class="{ 'is-invalid': firstError(profileErrors, 'email') }"
                                           :disabled="savingProfile" />
                                    <div v-if="firstError(profileErrors, 'email')" class="invalid-feedback">
                                        {{ firstError(profileErrors, 'email') }}
                                    </div>
                                    <small class="form-hint">
                                        По этому адресу вы входите в систему.
                                    </small>
                                </div>

                                <div v-if="firstError(profileErrors, '_')" class="alert alert-danger mt-3 mb-0"
                                     role="alert">
                                    {{ firstError(profileErrors, '_') }}
                                </div>
                                <div v-else-if="profileMessage" class="alert alert-success mt-3 mb-0" role="alert">
                                    {{ profileMessage }}
                                </div>
                            </div>
                            <div class="card-footer text-end">
                                <button class="btn btn-primary" :class="{ 'btn-loading': savingProfile }"
                                        :disabled="savingProfile || !profileChanged" @click="saveProfile">
                                    Сохранить
                                </button>
                            </div>
                        </div>

                        <!-- Пароль -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <h3 class="card-title">Пароль</h3>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label required">Текущий пароль</label>
                                    <input v-model="passwordForm.current_password" type="password"
                                           class="form-control" autocomplete="current-password"
                                           :class="{ 'is-invalid': firstError(passwordErrors, 'current_password') }"
                                           :disabled="savingPassword" />
                                    <div v-if="firstError(passwordErrors, 'current_password')"
                                         class="invalid-feedback">
                                        {{ firstError(passwordErrors, 'current_password') }}
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label required">Новый пароль</label>
                                            <input v-model="passwordForm.password" type="password"
                                                   class="form-control" autocomplete="new-password"
                                                   :class="{ 'is-invalid': firstError(passwordErrors, 'password') }"
                                                   :disabled="savingPassword" />
                                            <div v-if="firstError(passwordErrors, 'password')"
                                                 class="invalid-feedback">
                                                {{ firstError(passwordErrors, 'password') }}
                                            </div>
                                            <small class="form-hint">Минимум 6 символов.</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-0">
                                            <label class="form-label required">Повторите пароль</label>
                                            <input v-model="passwordForm.password_confirmation" type="password"
                                                   class="form-control" autocomplete="new-password"
                                                   :disabled="savingPassword" />
                                        </div>
                                    </div>
                                </div>

                                <div v-if="firstError(passwordErrors, '_')" class="alert alert-danger mt-3 mb-0"
                                     role="alert">
                                    {{ firstError(passwordErrors, '_') }}
                                </div>
                                <div v-else-if="passwordMessage" class="alert alert-success mt-3 mb-0" role="alert">
                                    {{ passwordMessage }}
                                </div>
                            </div>
                            <div class="card-footer text-end">
                                <button class="btn btn-primary" :class="{ 'btn-loading': savingPassword }"
                                        :disabled="savingPassword || !passwordFilled" @click="savePassword">
                                    Изменить пароль
                                </button>
                            </div>
                        </div>

                        <!-- Компания: только для тех, кто ей управляет -->
                        <div v-if="canManageCompany" class="card">
                            <div class="card-header">
                                <h3 class="card-title">Компания</h3>
                            </div>
                            <div class="card-body">
                                <div class="mb-0">
                                    <label class="form-label required">Название</label>
                                    <input v-model="company.name" type="text" class="form-control"
                                           :class="{ 'is-invalid': firstError(companyErrors, 'company_name') }"
                                           :disabled="savingCompany" />
                                    <div v-if="firstError(companyErrors, 'company_name')" class="invalid-feedback">
                                        {{ firstError(companyErrors, 'company_name') }}
                                    </div>
                                    <small class="form-hint">Видно всем сотрудникам компании.</small>
                                </div>

                                <div v-if="firstError(companyErrors, '_')" class="alert alert-danger mt-3 mb-0"
                                     role="alert">
                                    {{ firstError(companyErrors, '_') }}
                                </div>
                                <div v-else-if="companyMessage" class="alert alert-success mt-3 mb-0" role="alert">
                                    {{ companyMessage }}
                                </div>
                            </div>
                            <div class="card-footer text-end">
                                <button class="btn btn-primary" :class="{ 'btn-loading': savingCompany }"
                                        :disabled="savingCompany || !companyChanged" @click="saveCompany">
                                    Сохранить
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        <!-- END PAGE BODY -->
    </div>
</template>
