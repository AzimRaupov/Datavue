<script setup>
import { ref, provide, onMounted, watch, nextTick } from 'vue';
import axios from 'axios';

import Header from './Header.vue';
import Toast from './Toast.vue';
import NotFound from "../../viewer/components/Errors/NotFound.vue";

const token = localStorage.getItem('token');

const user = ref(null);
const loading = ref(true);
const error = ref(null);

const toastRef = ref(null);
provide('toast', toastRef);

async function getUser() {
    try {
        const response = await axios.post(
            '/api/get-user',
            {},
            {
                headers: {
                    Authorization: `Bearer ${token}`,
                    Accept: 'application/json',
                },
            }
        );

        user.value = response.data.user ?? response.data;

        localStorage.setItem(
            'user',
            JSON.stringify(user.value)
        );

    } catch (err) {
        console.error(err);

        error.value =
            err.response?.data?.message ||
            'Ошибка получения пользователя';

        user.value = null;

    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    getUser();
});

watch(user, async (value) => {
    if (!value) {
        return;
    }

    await nextTick();

    const themeConfig = {
        theme: 'light',
        'theme-base': 'gray',
        'theme-font': 'sans-serif',
        'theme-primary': 'blue',
        'theme-radius': '1',
    };

    const url = new URL(window.location.href);

    const form = document.getElementById('offcanvas-settings');
    const resetButton = document.getElementById('reset-changes');

    if (!form || !resetButton) {
        return;
    }

    const checkItems = () => {
        for (const key in themeConfig) {
            const value =
                localStorage.getItem(`tabler-${key}`) ||
                themeConfig[key];

            const radios = form.querySelectorAll(
                `[name="${key}"]`
            );

            radios.forEach((radio) => {
                radio.checked = radio.value === value;
            });

            document.documentElement.setAttribute(
                `data-bs-${key}`,
                value
            );
        }
    };

    form.addEventListener('change', (event) => {
        const target = event.target;

        if (!target.name || !target.value) {
            return;
        }

        const name = target.name;
        const value = target.value;

        if (themeConfig[name] !== undefined) {
            document.documentElement.setAttribute(
                `data-bs-${name}`,
                value
            );

            localStorage.setItem(
                `tabler-${name}`,
                value
            );

            url.searchParams.set(
                name,
                value
            );

            window.history.pushState(
                {},
                '',
                url
            );
        }
    });

    resetButton.addEventListener('click', () => {
        for (const key in themeConfig) {
            document.documentElement.removeAttribute(
                `data-bs-${key}`
            );

            localStorage.removeItem(
                `tabler-${key}`
            );

            url.searchParams.delete(key);
        }

        checkItems();

        window.history.pushState(
            {},
            '',
            url
        );
    });

    checkItems();
});
</script>
<template>
    <!-- Загрузка -->
    <div v-if="loading" class="page">
        <div class="container py-5 text-center">
            Загрузка...
        </div>
    </div>

    <!-- Пользователь авторизован -->
    <template v-else-if="user">
        <div class="page">
            <Header />

            <router-view />
        </div>

        <Toast ref="toastRef" />

        <div class="settings">
            <a
                href="#"
                class="btn btn-floating btn-icon btn-primary"
                data-bs-toggle="offcanvas"
                data-bs-target="#offcanvas-settings"
                aria-controls="offcanvas-settings"
                aria-label="Theme Settings"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="icon icon-1"
                >
                    <path d="M3 21v-4a4 4 0 1 1 4 4h-4" />
                    <path d="M21 3a16 16 0 0 0 -12.8 10.2" />
                    <path d="M21 3a16 16 0 0 1 -10.2 12.8" />
                    <path d="M10.6 9a9 9 0 0 1 4.4 4.4" />
                </svg>
            </a>

            <form
                class="offcanvas offcanvas-start offcanvas-narrow"
                tabindex="-1"
                id="offcanvas-settings"
                role="dialog"
                aria-modal="true"
                aria-labelledby="offcanvas-settings-title"
            >
                <div class="offcanvas-header">
                    <h2
                        class="offcanvas-title"
                        id="offcanvas-settings-title"
                    >
                        Theme Settings
                    </h2>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="offcanvas"
                        aria-label="Close"
                    ></button>
                </div>

                <div class="offcanvas-body d-flex flex-column">
                    <div>
                        <!-- Color mode -->
                        <div class="mb-4">
                            <label class="form-label">
                                Color mode
                            </label>

                            <p class="form-hint">
                                Choose the color mode for your app.
                            </p>

                            <label class="form-check">
                                <input
                                    type="radio"
                                    name="theme"
                                    value="light"
                                    class="form-check-input"
                                />

                                <span class="form-check-label">
                                    Light
                                </span>
                            </label>

                            <label class="form-check">
                                <input
                                    type="radio"
                                    name="theme"
                                    value="dark"
                                    class="form-check-input"
                                />

                                <span class="form-check-label">
                                    Dark
                                </span>
                            </label>
                        </div>

                        <!-- Color scheme -->
                        <div class="mb-4">
                            <label class="form-label">
                                Color scheme
                            </label>

                            <div class="row g-2">
                                <div
                                    v-for="color in [
                                        'blue',
                                        'azure',
                                        'indigo',
                                        'purple',
                                        'pink',
                                        'red',
                                        'orange',
                                        'yellow',
                                        'lime',
                                        'green',
                                        'teal',
                                        'cyan'
                                    ]"
                                    :key="color"
                                    class="col-auto"
                                >
                                    <label class="form-colorinput">
                                        <input
                                            name="theme-primary"
                                            type="radio"
                                            :value="color"
                                            class="form-colorinput-input"
                                        />

                                        <span
                                            class="form-colorinput-color"
                                            :class="`bg-${color}`"
                                        ></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Font family -->
                        <div class="mb-4">
                            <label class="form-label">
                                Font family
                            </label>

                            <label
                                v-for="font in [
                                    'sans-serif',
                                    'serif',
                                    'monospace',
                                    'comic'
                                ]"
                                :key="font"
                                class="form-check"
                            >
                                <input
                                    type="radio"
                                    name="theme-font"
                                    :value="font"
                                    class="form-check-input"
                                />

                                <span class="form-check-label">
                                    {{ font }}
                                </span>
                            </label>
                        </div>

                        <!-- Theme base -->
                        <div class="mb-4">
                            <label class="form-label">
                                Theme base
                            </label>

                            <label
                                v-for="base in [
                                    'slate',
                                    'gray',
                                    'zinc',
                                    'neutral',
                                    'stone'
                                ]"
                                :key="base"
                                class="form-check"
                            >
                                <input
                                    type="radio"
                                    name="theme-base"
                                    :value="base"
                                    class="form-check-input"
                                />

                                <span class="form-check-label">
                                    {{ base }}
                                </span>
                            </label>
                        </div>

                        <!-- Radius -->
                        <div class="mb-4">
                            <label class="form-label">
                                Corner Radius
                            </label>

                            <label
                                v-for="radius in [
                                    '0',
                                    '0.5',
                                    '1',
                                    '1.5',
                                    '2'
                                ]"
                                :key="radius"
                                class="form-check"
                            >
                                <input
                                    type="radio"
                                    name="theme-radius"
                                    :value="radius"
                                    class="form-check-input"
                                />

                                <span class="form-check-label">
                                    {{ radius }}
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="mt-auto space-y">
                        <button
                            type="button"
                            class="btn w-100"
                            id="reset-changes"
                        >
                            Reset changes
                        </button>

                        <a
                            href="#"
                            class="btn btn-primary w-100"
                            data-bs-dismiss="offcanvas"
                        >
                            Save
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </template>

    <template v-else>
        <NotFound />
    </template>
</template>
