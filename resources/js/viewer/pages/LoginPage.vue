<template>
    <div class="page page-center">
        <div class="container container-tight py-4">
            <div class="text-center mb-4">
                <!-- BEGIN NAVBAR LOGO --><router-link to="/" aria-label="Datavue" class="navbar-brand navbar-brand-autodark"
            >
                <img :src="'/logos/logo.png'" width="135" />

            </router-link
            ><!-- END NAVBAR LOGO -->
            </div>
            <form class="card card-md" @submit.prevent="login" autocomplete="off" novalidate>
                <div class="card-body">
                    <h2 class="card-title text-center mb-4">{{ t('auth.page_login')}}</h2>

                    <div class="mb-3">
                        <label class="form-label">{{ t('auth.input_email')}}</label>
                        <input v-model="form.email" type="email" class="form-control" placeholder="Enter email" />
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ t('auth.input_password')}}</label>
                        <div class="input-group input-group-flat">
                            <input v-model="form.password" :type="showPassword ? 'text' : 'password'"  class="form-control" placeholder="Password" autocomplete="off" />
                            <span class="input-group-text">
                  <button type="button" class="link-secondary p-0 border-0 bg-transparent lh-1" title="Show password" @click="showPassword = !showPassword"
                  ><!-- Download SVG icon from http://tabler.io/icons/icon/eye -->
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
                        aria-hidden="true"
                        focusable="false"
                        class="icon icon-1"
                    >
                      <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                      <path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg
                    ></button>
                </span>
                        </div>
                    </div>


                    <div v-if="generalError" class="alert alert-danger py-2 px-3 small">{{ generalError }}</div>

                    <div class="form-footer">
                        <button type="submit" class="btn btn-primary w-100" :disabled="loading">
                            {{ loading ? '...' : t('auth.page_login') }}
                        </button>
                    </div>
                </div>
            </form>
            <div class="text-center text-secondary mt-3">{{ t('auth.no_account')}} <router-link to="register" tabindex="-1">{{ t('auth.page_register')}}</router-link></div>
        </div>
    </div>
</template>

<script setup>
import {reactive ,ref} from 'vue';
import api from '../api.js';
import { useI18n } from 'vue-i18n'
const { t, locale } = useI18n()


const form = reactive({
    'email': '',
    'password': '',
});

const showPassword = ref(false);
const generalError = ref(null);
const loading = ref(false);

async function login() {
    if (loading.value) return;

    loading.value = true;
    generalError.value = null;

    try {
        const response = await api.post('/login', form);

        if (response.data.token) {
            localStorage.setItem('token', response.data.token);
        }

        if (response.data.user) {
            localStorage.setItem('user', JSON.stringify(response.data.user));
        }

        window.location.href = '/company';

    } catch (error) {
        generalError.value = error.response?.data?.message || 'Не удалось войти. Попробуйте ещё раз.';
    } finally {
        loading.value = false;
    }
}

</script>
