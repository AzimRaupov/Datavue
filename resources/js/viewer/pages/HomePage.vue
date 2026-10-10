<script setup>
import { useI18n } from 'vue-i18n'

import TablerIcon from '../components/TablerIcon.vue'
import DashboardMockup from '../components/landing/DashboardMockup.vue'
import ChatMockup from '../components/landing/ChatMockup.vue'
import BuilderMockup from '../components/landing/BuilderMockup.vue'
import AlertMockup from '../components/landing/AlertMockup.vue'
import MiniShowcase from '../components/landing/MiniShowcase.vue'

const { t } = useI18n()

const sources = [
    { key: 'db', icon: 'database', color: 'blue', items: ['MySQL', 'PostgreSQL', 'SQLite', 'DuckDB'] },
    { key: 'files', icon: 'spreadsheet', color: 'green', items: ['Excel', 'CSV'] },
    { key: 'sheets', icon: 'table', color: 'teal', items: ['Google Sheets'] },
    { key: 'erp', icon: 'plug', color: 'orange', items: ['OData'] },
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
</script>

<template>
    <main id="content" class="dv-landing">
        <!-- HERO -->
        <header class="dv-hero">
            <div class="container">
                <div class="dv-hero-copy">
                    <span class="badge bg-primary-lt dv-hero-badge">
                        <TablerIcon name="sparkles" class="icon-sm" />
                        {{ t('home.hero.badge') }}
                    </span>
                    <h1 class="dv-hero-title">
                        {{ t('home.hero.title') }}
                        <span class="dv-accent">{{ t('home.hero.title_accent') }}</span>
                    </h1>
                    <p class="dv-hero-lead">{{ t('home.hero.description') }}</p>

                    <div class="btn-list justify-content-center">
                        <router-link to="/register" class="btn btn-lg btn-primary">
                            {{ t('home.hero.cta_primary') }}
                            <TablerIcon name="arrowRight" class="icon-end" />
                        </router-link>
                        <a href="#how" class="btn btn-lg">{{ t('home.hero.cta_secondary') }}</a>
                    </div>
                </div>

                <div class="dv-hero-visual">
                    <DashboardMockup />
                </div>
            </div>
        </header>

        <!-- SOURCES -->
        <section id="sources" class="section">
            <div class="container">
                <div class="section-header">
                    <h2 class="section-title">{{ t('home.sources.title') }}</h2>
                </div>

                <div class="row g-3 g-lg-4">
                    <div v-for="s in sources" :key="s.key" class="col-6 col-lg-3">
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
                <div class="section-header">
                    <h2 class="section-title">{{ t('home.how.title') }}</h2>
                </div>

                <div class="dv-flow">
                    <div v-for="(step, i) in flow" :key="step.key" class="dv-flow-step">
                        <div class="dv-flow-num">{{ i + 1 }}</div>
                        <span class="dv-tile-icon bg-primary-lt text-primary">
                            <TablerIcon :name="step.icon" />
                        </span>
                        <h3 class="h3 mb-1">{{ t(`home.how.${step.key}.title`) }}</h3>
                        <div class="text-secondary">{{ t(`home.how.${step.key}.description`) }}</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- DASHBOARDS -->
        <section id="dashboards" class="section">
            <div class="container">
                <div class="section-header">
                    <h2 class="section-title">{{ t('home.dashboards.title') }}</h2>
                </div>

                <div class="row g-4 g-lg-6 align-items-center mb-6 mb-lg-8">
                    <div class="col-lg-5">
                        <span class="dv-tile-icon bg-primary-lt text-primary"><TablerIcon name="sparkles" /></span>
                        <h3 class="h1 mb-2">{{ t('home.dashboards.ai.title') }}</h3>
                        <p class="text-secondary fs-4 mb-0">{{ t('home.dashboards.ai.description') }}</p>
                    </div>
                    <div class="col-lg-7">
                        <ChatMockup />
                    </div>
                </div>

                <div class="row g-4 g-lg-6 align-items-center">
                    <div class="col-lg-5 order-lg-2">
                        <span class="dv-tile-icon bg-primary-lt text-primary"><TablerIcon name="sliders" /></span>
                        <h3 class="h1 mb-2">{{ t('home.dashboards.manual.title') }}</h3>
                        <p class="text-secondary fs-4 mb-0">{{ t('home.dashboards.manual.description') }}</p>
                    </div>
                    <div class="col-lg-7 order-lg-1">
                        <BuilderMockup />
                    </div>
                </div>

                <div class="dv-widget-cloud">
                    <span v-for="w in widgets" :key="w" class="badge bg-primary-lt">{{ t(`home.dashboards.widget.${w}`) }}</span>
                </div>
            </div>
        </section>

        <!-- ALERTS -->
        <section id="alerts" class="section section-light">
            <div class="container">
                <div class="row g-4 g-lg-6 align-items-center">
                    <div class="col-lg-5">
                        <span class="dv-tile-icon bg-red-lt text-red"><TablerIcon name="bell" /></span>
                        <h2 class="h1 mb-2">{{ t('home.alerts.title') }}</h2>
                        <p class="text-secondary fs-4 mb-4">{{ t('home.alerts.description') }}</p>
                        <div class="d-flex flex-wrap gap-2">
                            <span v-for="m in alertModes" :key="m.key" class="dv-pill">
                                <TablerIcon :name="m.icon" class="icon-sm" />
                                {{ t(`home.alerts.modes.${m.key}`) }}
                            </span>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <AlertMockup />
                    </div>
                </div>
            </div>
        </section>

        <!-- SECURITY -->
        <section id="security" class="section">
            <div class="container">
                <div class="dv-trust">
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
                <div class="dv-cta">
                    <div class="row align-items-center g-4">
                        <div class="col-lg-6">
                            <h2 class="dv-cta-title">{{ t('home.cta.title') }}</h2>
                            <div class="btn-list">
                                <router-link to="/register" class="btn btn-lg btn-primary">
                                    {{ t('home.cta.start') }}
                                    <TablerIcon name="arrowRight" class="icon-end" />
                                </router-link>
                                <router-link to="/login" class="btn btn-lg">{{ t('home.cta.login') }}</router-link>
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
