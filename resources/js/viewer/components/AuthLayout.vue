<script setup>
import { useI18n } from 'vue-i18n'
import TablerIcon from './TablerIcon.vue'
import MiniShowcase from './landing/MiniShowcase.vue'

const { t } = useI18n()

const points = [
    { key: 'side_point1', icon: 'database' },
    { key: 'side_point2', icon: 'sparkles' },
    { key: 'side_point3', icon: 'bell' },
]
</script>

<template>
    <div class="dv-auth">
        <div class="dv-auth-form">
            <div class="container-tight w-100">
                <div class="mb-4">
                    <router-link to="/" aria-label="Datavue" class="navbar-brand navbar-brand-autodark">
                        <img :src="'/logos/logo.png'" width="135" alt="Datavue" />
                    </router-link>
                </div>
                <slot />
            </div>
        </div>

        <aside class="dv-auth-side">
            <h2 class="dv-auth-side-title">{{ t('auth.side_title') }}</h2>
            <ul class="dv-auth-points">
                <li v-for="p in points" :key="p.key">
                    <span class="dv-tile-icon dv-tile-icon-sm"><TablerIcon :name="p.icon" /></span>
                    {{ t(`auth.${p.key}`) }}
                </li>
            </ul>
            <MiniShowcase class="dv-show-auth" />
        </aside>
    </div>
</template>
