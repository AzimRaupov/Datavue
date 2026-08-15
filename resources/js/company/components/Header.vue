<script setup>
import { computed, ref } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const logo = '/logos/logo.png'


const { t, locale } = useI18n()

if (localStorage.getItem('lang')) {
    locale.value = localStorage.getItem('lang')
}

const user = JSON.parse(localStorage.getItem('user') || 'null')

// Пункты меню показываем только тем, кому они доступны на бэкенде,
// чтобы пользователь не упирался в 403 из интерфейса.
const permissions = computed(() => user?.permissions ?? [])
const canViewUsers = computed(() => permissions.value.includes('view users'))
const canViewSources = computed(() => permissions.value.includes('view data sources'))
const canViewDashboards = computed(() => permissions.value.includes('view dashboards'))

// Выпадающие меню работают на штатном data-api Bootstrap (data-bs-toggle="dropdown").
// Раньше здесь была своя реализация через Dropdown.getOrCreateInstance(), потому что
// штатная не срабатывала: на страницу попадали ДВЕ копии Bootstrap (импорт из '@tabler/core/'),
// data-api регистрировался дважды, и каждый клик обрабатывался два раза — меню
// открывалось и тут же закрывалось. Дубликат устранён, ручная логика больше не нужна
// и только конфликтовала со штатной.
const changeLanguage = (lang) => {
    locale.value = lang
    localStorage.setItem('lang', lang)
}

/**
 * Выход из аккаунта.
 *
 * Раньше пункт меню вёл на несуществующий ./sign-in.html: токен оставался
 * и в localStorage, и действующим на сервере. Теперь он сначала отзывается
 * на бэкенде, и только потом чистится локально.
 *
 * Ошибку сети намеренно проглатываем: если сервер недоступен, пользователь
 * всё равно должен выйти локально, иначе выйти будет невозможно вообще.
 */
const loggingOut = ref(false)

const logout = async () => {
    if (loggingOut.value) return
    loggingOut.value = true

    try {
        // Через axios напрямую, а не через api.js: у того baseURL '/api/company',
        // а выход живёт на уровень выше — '/api/logout'.
        await axios.post('/api/logout', {}, {
            headers: {
                Authorization: `Bearer ${localStorage.getItem('token')}`,
                Accept: 'application/json',
            },
        })
    } catch (e) {
        // намеренно тихо, см. комментарий выше
    } finally {
        localStorage.removeItem('token')
        localStorage.removeItem('user')
        window.location.href = '/login'
    }
}
</script>
<template>
    <header class="navbar navbar-expand-md d-print-none">
        <div class="container" >
            <!-- BEGIN NAVBAR TOGGLER -->
            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#navbar-menu"
                aria-controls="navbar-menu"
                aria-expanded="false"
                aria-label="Toggle primary navigation"
            >
                <span class="navbar-toggler-icon"></span>
            </button>
            <!-- END NAVBAR TOGGLER -->
            <!-- BEGIN NAVBAR LOGO -->
            <div class="navbar-brand navbar-brand-autodark d-none-navbar-horizontal pe-0 pe-md-3">
                <a href="." aria-label="Tabler">
                    <img :src="logo" width="115" />

                </a>
            </div>
            <!-- END NAVBAR LOGO -->
            <div class="navbar-nav flex-row order-md-last">
                <div class="d-none d-md-flex me-3">
                    <!-- BEGIN THEME TOGGLE -->
                    <div class="nav-item">
                        <a href="?theme=dark" class="nav-link px-0 hide-theme-dark" title="Enable dark mode" data-bs-toggle="tooltip" data-bs-placement="bottom">
                            <!-- Download SVG icon from http://tabler.io/icons/icon/moon -->
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
                                <path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454l0 .008" />
                            </svg>
                        </a>
                        <a href="?theme=light" class="nav-link px-0 hide-theme-light" title="Enable light mode" data-bs-toggle="tooltip" data-bs-placement="bottom">
                            <!-- Download SVG icon from http://tabler.io/icons/icon/sun -->
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
                                <path d="M8 12a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" />
                                <path d="M3 12h1m8 -9v1m8 8h1m-9 8v1m-6.4 -15.4l.7 .7m12.1 -.7l-.7 .7m0 11.4l.7 .7m-12.1 -.7l-.7 .7" />
                            </svg>
                        </a>
                    </div>
                    <!-- END THEME TOGGLE -->
                    <!-- BEGIN NOTIFICATIONS -->
                    <div class="nav-item dropdown d-none d-md-flex">
                        <a
                            href="#"
                            class="nav-link px-0"
                            data-bs-toggle="dropdown"
                            tabindex="-1"
                            aria-label="Show notifications"
                            data-bs-auto-close="outside"
                            aria-expanded="false"
                        >
                            <!-- Download SVG icon from http://tabler.io/icons/icon/bell -->
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
                                <path d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6" />
                                <path d="M9 17v1a3 3 0 0 0 6 0v-1" />
                            </svg>
                            <span class="badge bg-red"></span>
                        </a>
                        <!-- BEGIN NAVBAR NOTIFICATIONS -->
                        <div class="dropdown-menu dropdown-menu-arrow dropdown-menu-end dropdown-menu-card">
                            <div class="card">
                                <div class="card-header d-flex">
                                    <h3 class="card-title">Notifications</h3>
                                    <div class="btn-close ms-auto" data-bs-dismiss="dropdown"></div>
                                </div>
                                <div class="list-group list-group-flush list-group-hoverable">
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col-auto"><span class="status-dot status-dot-animated bg-red d-block"></span></div>
                                            <div class="col text-truncate">
                                                <a href="#" class="text-body d-block">Example 1</a>
                                                <div class="d-block text-secondary text-truncate mt-n1">Change deprecated html tags to text decoration classes (#29604)</div>
                                            </div>
                                            <div class="col-auto">
                                                <a href="#" class="list-group-item-actions">
                                                    <!-- Download SVG icon from http://tabler.io/icons/icon/star -->
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
                                                        class="icon text-muted icon-2"
                                                    >
                                                        <path
                                                            d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1l3.086 -6.253l3.086 6.253l6.9 1l-5 4.867l1.179 6.873l-6.158 -3.245"
                                                        />
                                                    </svg>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col-auto"><span class="status-dot d-block"></span></div>
                                            <div class="col text-truncate">
                                                <a href="#" class="text-body d-block">Example 2</a>
                                                <div class="d-block text-secondary text-truncate mt-n1">justify-content:between ⇒ justify-content:space-between (#29734)</div>
                                            </div>
                                            <div class="col-auto">
                                                <a href="#" class="list-group-item-actions show">
                                                    <!-- Download SVG icon from http://tabler.io/icons/icon/star -->
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
                                                        class="icon text-yellow icon-2"
                                                    >
                                                        <path
                                                            d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1l3.086 -6.253l3.086 6.253l6.9 1l-5 4.867l1.179 6.873l-6.158 -3.245"
                                                        />
                                                    </svg>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col-auto"><span class="status-dot d-block"></span></div>
                                            <div class="col text-truncate">
                                                <a href="#" class="text-body d-block">Example 3</a>
                                                <div class="d-block text-secondary text-truncate mt-n1">Update change-version.js (#29736)</div>
                                            </div>
                                            <div class="col-auto">
                                                <a href="#" class="list-group-item-actions">
                                                    <!-- Download SVG icon from http://tabler.io/icons/icon/star -->
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
                                                        class="icon text-muted icon-2"
                                                    >
                                                        <path
                                                            d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1l3.086 -6.253l3.086 6.253l6.9 1l-5 4.867l1.179 6.873l-6.158 -3.245"
                                                        />
                                                    </svg>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col-auto"><span class="status-dot status-dot-animated bg-green d-block"></span></div>
                                            <div class="col text-truncate">
                                                <a href="#" class="text-body d-block">Example 4</a>
                                                <div class="d-block text-secondary text-truncate mt-n1">Regenerate package-lock.json (#29730)</div>
                                            </div>
                                            <div class="col-auto">
                                                <a href="#" class="list-group-item-actions">
                                                    <!-- Download SVG icon from http://tabler.io/icons/icon/star -->
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
                                                        class="icon text-muted icon-2"
                                                    >
                                                        <path
                                                            d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1l3.086 -6.253l3.086 6.253l6.9 1l-5 4.867l1.179 6.873l-6.158 -3.245"
                                                        />
                                                    </svg>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col">
                                            <a href="#" class="btn btn-2 w-100"> Archive all </a>
                                        </div>
                                        <div class="col">
                                            <a href="#" class="btn btn-2 w-100"> Mark all as read </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- END NAVBAR NOTIFICATIONS -->
                    </div>
                    <!-- END NOTIFICATIONS -->
                    <!-- BEGIN APPS -->
                    <!-- END APPS -->
                    <!-- BEGIN LANGUAGE SELECTOR -->
                    <div class="nav-item dropdown d-none d-md-flex">
                        <a
                            href="#"
                            class="nav-link px-0"
                            data-bs-toggle="dropdown"
                            tabindex="-1"
                            aria-label="Select language"
                            aria-expanded="false"
                        >
                            {{ locale === 'ru' ? 'RU' : locale === 'tj' ? 'ТҶ' : 'EN' }}
                        </a>
                        <div class="dropdown-menu dropdown-menu-arrow dropdown-menu-end">
                        <a class="dropdown-item" href="#" @click.prevent="changeLanguage('ru')">
                            Русский
                        </a>

                        <a class="dropdown-item" href="#" @click.prevent="changeLanguage('tj')">
                                Тоҷики
                            </a>

                            <a class="dropdown-item" href="#" @click.prevent="changeLanguage('en')">
                                English
                            </a>
                        </div>
                    </div>
                    <!-- END LANGUAGE SELECTOR -->
                </div>
                    <!-- BEGIN USER MENU -->
                <div class="nav-item dropdown">
                    <a
                        href="#"
                        class="nav-link d-flex lh-1 p-0 px-2"
                        data-bs-toggle="dropdown"
                        aria-label="Open user menu"
                        aria-expanded="false"
                    >
                       <span class="avatar avatar-0">
                      <!-- Download SVG icon from http://tabler.io/icons/icon/user -->
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="icon avatar-icon icon-2">
                        <path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"></path>
                        <path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"></path>
                      </svg>
                    </span>
                        <div class="d-none d-xl-block ps-2">
                            <div>{{ user.name }}</div>
                            <div class="mt-1 small text-secondary">{{ user.company.name }}</div>
                        </div>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                        <router-link
                            class="dropdown-item"
                            :to="{ name: 'settings.profile' }"
                        >
                            <!-- Download SVG icon from http://tabler.io/icons/icon/user -->
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
                                class="icon dropdown-item-icon icon-2"
                            >
                                <path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" />
                                <path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
                            </svg>
                            Profile
                        </router-link>
                        <a class="dropdown-item" href="#"
                        ><!-- Download SVG icon from http://tabler.io/icons/icon/chart-pie -->
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
                                class="icon dropdown-item-icon icon-2"
                            >
                                <path d="M10 3.2a9 9 0 1 0 10.8 10.8a1 1 0 0 0 -1 -1h-6.8a2 2 0 0 1 -2 -2v-7a.9 .9 0 0 0 -1 -.8" />
                                <path d="M15 3.5a9 9 0 0 1 5.5 5.5h-4.5a1 1 0 0 1 -1 -1v-4.5" />
                            </svg>
                            Analytics</a
                        >
                        <div class="dropdown-divider"></div>
                        <router-link
                            v-if="canViewUsers"
                            class="dropdown-item"
                            :to="{ name: 'settings.users' }"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" aria-hidden="true" focusable="false"
                                 class="icon dropdown-item-icon icon-2">
                                <path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" />
                                <path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
                                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                <path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />
                            </svg>
                            Сотрудники
                        </router-link>
                        <router-link class="dropdown-item" :to="{ name: 'company.widgets' }">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" aria-hidden="true" focusable="false"
                                 class="icon dropdown-item-icon icon-2">
                                <path d="M4 4h6v6h-6z" />
                                <path d="M14 4h6v6h-6z" />
                                <path d="M4 14h6v6h-6z" />
                                <path d="M14 14h6v6h-6z" />
                            </svg>
                            Все виджеты
                        </router-link>
                        <a class="dropdown-item" href="./settings.html">Settings &amp; Privacy</a>
                        <a class="dropdown-item" href="#">Help</a>
                        <a class="dropdown-item" href="#" @click.prevent="logout">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" aria-hidden="true" focusable="false"
                                 class="icon dropdown-item-icon icon-2">
                                <path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" />
                                <path d="M9 12h12l-3 -3" />
                                <path d="M18 15l3 -3" />
                            </svg>
                            {{ loggingOut ? '…' : t('header.logout') }}
                        </a>
                    </div>
                </div>
                <!-- END USER MENU -->
            </div>

            <!-- BEGIN NAVBAR MENU -->
            <!-- Меню живёт в том же .container, что логотип и меню пользователя,
                 поэтому рисуется с ними в один ряд. Меню пользователя выше стоит
                 с order-md-last — оно и остаётся прижатым вправо. -->
            <div class="collapse navbar-collapse" id="navbar-menu">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <router-link class="nav-link" :to="{ name: 'company.dashboard' }">
                            <span class="nav-link-icon d-md-none d-lg-inline-block">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true" class="icon icon-2">
                                    <path d="M5 12l-2 0l9 -9l9 9l-2 0" />
                                    <path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7" />
                                    <path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6" />
                                </svg>
                            </span>
                            <span class="nav-link-title">Обзор</span>
                        </router-link>
                    </li>

                    <li v-if="canViewDashboards" class="nav-item">
                        <router-link class="nav-link" :to="{ name: 'company.dashboards' }">
                            <span class="nav-link-icon d-md-none d-lg-inline-block">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true" class="icon icon-2">
                                    <path d="M4 4h6v8h-6z" />
                                    <path d="M4 16h6v4h-6z" />
                                    <path d="M14 12h6v8h-6z" />
                                    <path d="M14 4h6v4h-6z" />
                                </svg>
                            </span>
                            <span class="nav-link-title">Дашборды</span>
                        </router-link>
                    </li>

                    <li v-if="canViewSources" class="nav-item">
                        <router-link class="nav-link" :to="{ name: 'company.sources' }">
                            <span class="nav-link-icon d-md-none d-lg-inline-block">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true" class="icon icon-2">
                                    <path d="M12 6m-8 0a8 3 0 1 0 16 0a8 3 0 1 0 -16 0" />
                                    <path d="M4 6v6a8 3 0 0 0 16 0v-6" />
                                    <path d="M4 12v6a8 3 0 0 0 16 0v-6" />
                                </svg>
                            </span>
                            <span class="nav-link-title">Источники данных</span>
                        </router-link>
                    </li>

                    <li class="nav-item">
                        <router-link class="nav-link" :to="{ name: 'company.widgets' }">
                            <span class="nav-link-icon d-md-none d-lg-inline-block">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true" class="icon icon-2">
                                    <path d="M4 4h6v6h-6z" />
                                    <path d="M14 4h6v6h-6z" />
                                    <path d="M4 14h6v6h-6z" />
                                    <path d="M14 14h6v6h-6z" />
                                </svg>
                            </span>
                            <span class="nav-link-title">Виджеты</span>
                        </router-link>
                    </li>

                    <li v-if="canViewUsers" class="nav-item">
                        <router-link class="nav-link" :to="{ name: 'settings.users' }">
                            <span class="nav-link-icon d-md-none d-lg-inline-block">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true" class="icon icon-2">
                                    <path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" />
                                    <path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                    <path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />
                                </svg>
                            </span>
                            <span class="nav-link-title">Сотрудники</span>
                        </router-link>
                    </li>
                </ul>
            </div>
            <!-- END NAVBAR MENU -->
        </div>
    </header>
</template>
