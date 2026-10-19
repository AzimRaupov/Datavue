<script setup>
import { onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'

import TablerIcon from '../components/TablerIcon.vue'
import DashboardMockup from '../components/landing/DashboardMockup.vue'
import ChatMockup from '../components/landing/ChatMockup.vue'
import BuilderMockup from '../components/landing/BuilderMockup.vue'
import AlertMockup from '../components/landing/AlertMockup.vue'
import MiniShowcase from '../components/landing/MiniShowcase.vue'

const { t } = useI18n()

// Флаг приходит из .env (SHOW_COMPANIES) через viewer.blade.php.
const showCompanies = window.__DATAVUE__?.showCompanies ?? true

const sources = [
    { key: 'db', icon: 'database', color: 'blue', items: ['MySQL', 'PostgreSQL', 'SQLite', 'DuckDB'] },
    { key: 'files', icon: 'spreadsheet', color: 'green', items: ['Excel', 'CSV'] },
    { key: 'sheets', icon: 'table', color: 'teal', items: ['Google Sheets'] },
    { key: 'erp', icon: 'plug', color: 'orange', items: ['OData'] },
]

// Короткий список для строки «Работает с» под кнопками героя.
const worksWith = ['MySQL', 'PostgreSQL', 'Excel', 'CSV', 'Google Sheets', 'DuckDB']

const clients = [

    { file: 'fatir.jpg', name: 'Фатир' },
    { file: 'anas.png', name: 'Ширинихои Анас', cream: true },
    { file: 'khimus.jpg', name: 'Химус' },
]

const flow = [
    { key: 'source', icon: 'database' },
    { key: 'workspace', icon: 'folder' },
    { key: 'dashboard', icon: 'dashboard' },
    { key: 'alert', icon: 'bell' },
]

const widgets = [
    'bar', 'line', 'pie', 'combo', 'scatter', 'radar', 'radial',
    'funnel', 'heatmap', 'treemap', 'map', 'table', 'counters',
]

const alertModes = [
    { key: 'builder', icon: 'sliders' },
    { key: 'sql', icon: 'database' },
    { key: 'python', icon: 'code' },
    { key: 'ai', icon: 'sparkles' },
]

const security = [
    { key: 'schema', icon: 'shield' },
    { key: 'readonly', icon: 'lock' },
    { key: 'transparent', icon: 'eye' },
]

// Плавное появление блоков при прокрутке. Без IntersectionObserver или при
// prefers-reduced-motion блоки просто остаются видимыми.
const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
const observer = !reduceMotion && 'IntersectionObserver' in window
    ? new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) continue
            entry.target.classList.add('is-visible')
            observer.unobserve(entry.target)
        }
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' })
    : null

const vReveal = {
    mounted(el) {
        if (!observer) return
        el.classList.add('dv-reveal')
        observer.observe(el)
    },
}

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
    <main id="content" class="dv-landing">
        <!-- HERO -->
        <header class="dv-hero">
            <div class="dv-hero-bg" aria-hidden="true">
                <i class="dv-blob dv-blob-1"></i>
                <i class="dv-blob dv-blob-2"></i>
                <i class="dv-blob dv-blob-3"></i>
            </div>

            <div class="container">
                <div class="dv-hero-copy">
                    <span class="dv-hero-badge">
                        <i class="dv-hero-dot"></i>
                        {{ t('home.hero.badge') }}
                    </span>
                    <h1 class="dv-hero-title">
                        {{ t('home.hero.title') }}
                        <span class="dv-accent">{{ t('home.hero.title_accent') }}</span>
                    </h1>
                    <p class="dv-hero-lead">{{ t('home.hero.description') }}</p>

                    <div class="btn-list justify-content-center">
                        <router-link to="/register" class="btn btn-lg btn-primary dv-btn-glow">
                            {{ t('home.hero.cta_primary') }}
                            <TablerIcon name="arrowRight" class="icon-end" />
                        </router-link>
                        <a href="#how" class="btn btn-lg dv-btn-soft">{{ t('home.hero.cta_secondary') }}</a>
                    </div>

                    <div class="dv-works">
                        <span class="dv-works-label">{{ t('home.hero.works_with') }}</span>
                        <span v-for="name in worksWith" :key="name" class="dv-works-chip">{{ name }}</span>
                    </div>
                </div>

                <div class="dv-hero-visual">
                    <div class="dv-float dv-float-alert" aria-hidden="true">
                        <span class="dv-show-ico bg-red-lt text-red"><TablerIcon name="bell" class="icon-sm" /></span>
                        <span class="fw-semibold">{{ t('home.mock.alert_title') }}</span>
                        <span class="badge bg-red-lt">{{ t('home.mock.alert_firing') }}</span>
                    </div>
                    <div class="dv-float dv-float-kpi" aria-hidden="true">
                        <span class="dv-show-ico bg-green-lt text-green"><TablerIcon name="trendingUp" class="icon-sm" /></span>
                        <div>
                            <div class="dv-kpi-label">{{ t('home.mock.kpi.revenue') }}</div>
                            <div class="dv-float-value">128.4k <small class="text-green">+12.4%</small></div>
                        </div>
                    </div>

                    <DashboardMockup />
                </div>
            </div>
        </header>

        <!-- CLIENTS -->
        <section v-if="showCompanies" id="clients" class="dv-clients">
            <div class="container">
                <p class="dv-eyebrow">{{ t('home.clients.title') }}</p>

                <div v-reveal class="dv-client-row">
                    <div v-for="c in clients" :key="c.file" class="dv-client" :class="{ 'dv-client-wide': c.wide, 'dv-client-zoom': c.zoom, 'dv-client-cream': c.cream }" :title="c.name">
                        <img
                            :src="`/company-logos/${c.file}`"
                            :alt="c.name"
                            decoding="async"
                        >
                    </div>
                </div>
            </div>
        </section>

        <!-- SOURCES -->
        <section id="sources" class="section">
            <div class="container">
                <div v-reveal class="section-header">
                    <h2 class="section-title">{{ t('home.sources.title') }}</h2>
                </div>

                <div class="row g-3 g-lg-4">
                    <div v-for="(s, i) in sources" :key="s.key" v-reveal :style="{ '--d': `${i * 80}ms` }" class="col-6 col-lg-3">
                        <div class="dv-tile h-100">
                            <span class="dv-tile-icon" :class="`bg-${s.color}-lt text-${s.color}`">
                                <TablerIcon :name="s.icon" />
                            </span>
                            <h3 class="h3 mb-2">{{ t(`home.sources.${s.key}`) }}</h3>
                            <div class="d-flex flex-wrap gap-1">
                                <span v-for="item in s.items" :key="item" class="badge bg-secondary-lt">{{ item }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- HOW IT WORKS -->
        <section id="how" class="section section-light">
            <div class="container">
                <div v-reveal class="section-header">
                    <h2 class="section-title">{{ t('home.how.title') }}</h2>
                </div>

                <div class="dv-flow">
                    <div v-for="(step, i) in flow" :key="step.key" v-reveal :style="{ '--d': `${i * 100}ms` }" class="dv-flow-step">
                        <div class="dv-flow-icon">
                            <TablerIcon :name="step.icon" />
                            <span class="dv-flow-num">{{ i + 1 }}</span>
                        </div>
                        <h3 class="h3 mb-1">{{ t(`home.how.${step.key}.title`) }}</h3>
                        <div class="text-secondary">{{ t(`home.how.${step.key}.description`) }}</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- DASHBOARDS -->
        <section id="dashboards" class="section">
            <div class="container">
                <div v-reveal class="section-header">
                    <h2 class="section-title">{{ t('home.dashboards.title') }}</h2>
                </div>

                <div class="row g-4 g-lg-6 align-items-center mb-6 mb-lg-8">
                    <div v-reveal class="col-lg-5">
                        <span class="dv-tile-icon bg-primary-lt text-primary"><TablerIcon name="sparkles" /></span>
                        <h3 class="dv-feature-title">{{ t('home.dashboards.ai.title') }}</h3>
                        <p class="dv-feature-text">{{ t('home.dashboards.ai.description') }}</p>
                    </div>
                    <div v-reveal style="--d: 120ms" class="col-lg-7">
                        <div class="dv-stage"><ChatMockup /></div>
                    </div>
                </div>

                <div class="row g-4 g-lg-6 align-items-center">
                    <div v-reveal class="col-lg-5 order-lg-2">
                        <span class="dv-tile-icon bg-primary-lt text-primary"><TablerIcon name="sliders" /></span>
                        <h3 class="dv-feature-title">{{ t('home.dashboards.manual.title') }}</h3>
                        <p class="dv-feature-text">{{ t('home.dashboards.manual.description') }}</p>
                    </div>
                    <div v-reveal style="--d: 120ms" class="col-lg-7 order-lg-1">
                        <div class="dv-stage"><BuilderMockup /></div>
                    </div>
                </div>

                <div v-reveal class="dv-widget-cloud">
                    <span v-for="w in widgets" :key="w" class="badge bg-primary-lt">{{ t(`home.dashboards.widget.${w}`) }}</span>
                </div>
            </div>
        </section>

        <!-- ALERTS -->
        <section id="alerts" class="section section-light">
            <div class="container">
                <div class="row g-4 g-lg-6 align-items-center">
                    <div v-reveal class="col-lg-5">
                        <span class="dv-tile-icon bg-red-lt text-red"><TablerIcon name="bell" /></span>
                        <h2 class="dv-feature-title">{{ t('home.alerts.title') }}</h2>
                        <p class="dv-feature-text mb-4">{{ t('home.alerts.description') }}</p>
                        <div class="d-flex flex-wrap gap-2">
                            <span v-for="m in alertModes" :key="m.key" class="dv-pill">
                                <TablerIcon :name="m.icon" class="icon-sm" />
                                {{ t(`home.alerts.modes.${m.key}`) }}
                            </span>
                        </div>
                    </div>
                    <div v-reveal style="--d: 120ms" class="col-lg-7">
                        <div class="dv-stage"><AlertMockup /></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- SECURITY -->
        <section id="security" class="section">
            <div class="container">
                <div v-reveal class="dv-trust">
                    <span v-for="s in security" :key="s.key" class="dv-trust-item">
                        <span class="dv-tile-icon dv-tile-icon-sm bg-green-lt text-green"><TablerIcon :name="s.icon" /></span>
                        {{ t(`home.security.${s.key}`) }}
                    </span>
                    <router-link to="/ai-consent" class="dv-trust-link">
                        {{ t('home.security.link') }}
                        <TablerIcon name="chevronRight" class="icon-sm" />
                    </router-link>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="section pt-0">
            <div class="container">
                <div v-reveal class="dv-cta">
                    <div class="row align-items-center g-4">
                        <div class="col-lg-6">
                            <h2 class="dv-cta-title">{{ t('home.cta.title') }}</h2>
                            <div class="btn-list">
                                <router-link to="/register" class="btn btn-lg btn-primary dv-btn-glow">
                                    {{ t('home.cta.start') }}
                                    <TablerIcon name="arrowRight" class="icon-end" />
                                </router-link>
                                <router-link to="/login" class="btn btn-lg dv-btn-soft">{{ t('home.cta.login') }}</router-link>
                            </div>
                        </div>
                        <div class="col-lg-6 d-none d-lg-block">
                            <MiniShowcase />
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
</template>
