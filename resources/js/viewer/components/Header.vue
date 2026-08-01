<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'

import ruFlag from '@tabler/core/dist/img/flags/ru.svg'
import enFlag from '@tabler/core/dist/img/flags/gb.svg'
import tjFlag from '@tabler/core/dist/img/flags/tj.svg'

const { t, locale } = useI18n()

const isOpen = ref(false)

const languages = [
    {
        code: 'ru',
        name: 'Русский',
        flag: ruFlag,
    },
    {
        code: 'en',
        name: 'English',
        flag: enFlag,
    },
    {
        code: 'tj',
        name: 'Тоҷикӣ',
        flag: tjFlag,
    },
]

// Получаем сохранённый язык
const savedLanguage = localStorage.getItem('lang') || 'ru'

// Устанавливаем текущий язык vue-i18n
locale.value = savedLanguage

// Устанавливаем выбранный язык
const selectedLanguage = ref(
    languages.find(lang => lang.code === savedLanguage) || languages[0]
)

const dropdownRef = ref(null)

// Изменение языка
const changeLanguage = (language) => {
    selectedLanguage.value = language

    // Меняем язык vue-i18n
    locale.value = language.code

    // Сохраняем язык
    localStorage.setItem('lang', language.code)

    // Закрываем dropdown
    isOpen.value = false
}

// Открыть / закрыть dropdown
const toggleDropdown = () => {
    isOpen.value = !isOpen.value
}

// Закрыть dropdown
const closeDropdown = () => {
    isOpen.value = false
}

// Закрытие при клике вне dropdown
const handleClickOutside = (event) => {
    if (
        dropdownRef.value &&
        !dropdownRef.value.contains(event.target)
    ) {
        closeDropdown()
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside)
})
</script>

<template>
    <nav class="navbar navbar-expand-lg navbar-transparent py-3" role="banner">
        <div class="container">
            <!-- BEGIN NAVBAR LOGO -->
            <a href=".." aria-label="Tabler" class="navbar-brand navbar-brand-autodark">
                <img :src="'/logos/logo.png'" width="120" />

            </a>
            <!-- END NAVBAR LOGO -->

            <!-- Мобильный toggler -->
            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#navbarTogglerDemo01"
                aria-controls="navbarTogglerDemo01"
                aria-expanded="false"
                aria-label="Toggle primary navigation"
            >
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Сворачиваемый контент -->
            <div class="collapse navbar-collapse" id="navbarTogglerDemo01">
                <!-- Навигация по центру -->
                <ul class="navbar-nav mx-auto mb-2 mb-lg-0 text-center text-lg-start">
                    <li class="nav-item">
                        <a class="nav-link active" href="../marketing"><span class="nav-link-title">{{ t('header.home')}}</span></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../marketing/pricing.html"><span class="nav-link-title">{{ t('header.pricing') }}</span></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../marketing/about.html"><span class="nav-link-title">{{ t('header.about') }}</span></a>
                    </li>

                </ul>

                <!-- Правая часть -->
                <div class="d-flex align-items-center justify-content-center justify-content-lg-start gap-3 mt-3 mt-lg-0">
                    <router-link  :to="'register'" class="btn btn-primary">{{ t('header.startBtn') }}</router-link>

                    <div class="dropdown position-relative" ref="dropdownRef">
                        <button
                            type="button"
                            class="btn dropdown-toggle d-flex align-items-center gap-2"
                            @click.stop="toggleDropdown"
                        >
                            <img
                                :src="selectedLanguage.flag"
                                width="20"
                                height="14"
                                alt=""
                            >
                            {{ selectedLanguage.name }}
                        </button>

                        <div
                            v-if="isOpen"
                            class="dropdown-menu show"
                            style="position: absolute; right: 0; min-width: 160px;"
                        >
                            <button
                                v-for="language in languages"
                                :key="language.code"
                                type="button"
                                class="dropdown-item d-flex align-items-center gap-2"
                                @click="changeLanguage(language)"
                            >
                                <img
                                    :src="language.flag"
                                    width="20"
                                    height="14"
                                    alt=""
                                >
                                {{ language.name }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>
</template>
