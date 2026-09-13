<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <main id="content">
        <!-- Hero Section -->
        <header class="hero pb-0">
            <div class="container">
                <h1 class="hero-title">{{ t('home.hero.title') }}</h1>
                <p class="hero-description mt-4">
                    <strong>Data to Dashboard</strong> {{ t('home.hero.description') }}
                </p>
                <div class="my-5">
                    <div class="row g-3 justify-content-center">
                        <div class="col-auto">
                            <a href="#" class="btn btn-lg btn-primary">{{ t('home.hero.cta_primary') }}</a>
                        </div>
                        <div class="col-auto">
                            <a href="#" class="btn btn-lg" target="_blank" rel="noopener noreferrer">{{ t('home.hero.cta_secondary') }}</a>
                        </div>
                    </div>
                </div>
                <div class="hero-img img-overlap-margin">
                    <div class="browser">
                        <div class="browser-header">
                            <div class="row align-items-center">
                                <div class="col-auto col-md-2">
                                    <div class="browser-dots browser-dots-colored">
                                        <div class="browser-dot"></div>
                                        <div class="browser-dot"></div>
                                        <div class="browser-dot"></div>
                                    </div>
                                </div>
                                <div class="col-8">
                                    <a
                                        href="#"
                                        class="browser-input"
                                        data-bs-toggle="tooltip"
                                        data-bs-placement="top"
                                        :title="t('home.hero.demo_tooltip')"
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
                                            aria-hidden="true"
                                            focusable="false"
                                            class="icon me-2 text-green icon-sm"
                                        >
                                            <path d="M3 12h2l3 -9l4 18l3 -9h4" />
                                        </svg>
                                        datavue.startcoding.tj
                                    </a>
                                </div>
                            </div>
                        </div>
                        <picture>
                            <img
                                :src="'/static/dashboard-chat.png'"
                                :alt="t('home.hero.demo_alt')"
                                class="img-fluid"
                                width="1040"
                                height="760"
                                loading="lazy"
                            />
                        </picture>
                    </div>
                </div>
            </div>
        </header>

        <!-- Key Features Section -->
        <section class="section">
            <div class="container">
                <div class="row items-center text-center g-lg-10">
                    <div class="col-md-6 col-lg">
                        <div class="shape shape-md mb-3">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                <path d="M4 7v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-10a2 2 0 0 0 -2 -2h-12a2 2 0 0 0 -2 2z" />
                                <path d="M12 12m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                                <path d="M12 12v-7" />
                                <path d="M8 12v-2" />
                                <path d="M16 12v-4" />
                            </svg>
                        </div>
                        <h2 class="h2">{{ t('home.features.sources.title') }}</h2>
                        <p class="text-secondary">{{ t('home.features.sources.description') }}</p>
                    </div>
                    <div class="col-md-6 col-lg">
                        <div class="shape shape-md mb-3">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                                <path d="M12 12m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" />
                                <path d="M12 12l3.5 -3.5" />
                                <path d="M12 12l-3.5 3.5" />
                            </svg>
                        </div>
                        <h2 class="h2">{{ t('home.features.ai.title') }}</h2>
                        <p class="text-secondary">{{ t('home.features.ai.description') }}</p>
                    </div>
                    <div class="col-md-6 col-lg">
                        <div class="shape shape-md mb-3">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                <path d="M13 3l0 7l6 0l-8 11l0 -7l-6 0l8 -11" />
                            </svg>
                        </div>
                        <h2 class="h2">{{ t('home.features.instant.title') }}</h2>
                        <p class="text-secondary">{{ t('home.features.instant.description') }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Detailed Features 1 -->
        <section class="section section-light">
            <div class="container">
                <div class="section-header">
                    <h2 class="section-title">{{ t('home.detail1.title') }}</h2>
                    <div class="section-description">
                        {{ t('home.detail1.description') }}
                    </div>
                </div>
                <div class="row g-lg-10">
                    <div class="col-lg-6 mb-3 mb-lg-0">
                        <svg xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" width="500" height="400" viewBox="0 0 500 400" fill="transparent" stroke="var(--tblr-border-color, #b8cef1)" style="width: 100%; height: auto">
                            <rect x=".5" y=".5" width="499" height="399" rx="2"></rect>
                            <line x1="0" y1="0" x2="500" y2="400"></line>
                            <line x1="0" y1="400" x2="500" y2="0"></line>
                        </svg>
                    </div>
                    <div class="col-lg-6">
                        <div class="space-y-6">
                            <div>
                                <div class="row">
                                    <div class="col-auto">
                                        <div class="shape shape-md">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                                <path d="M8 9h8" />
                                                <path d="M8 13h6" />
                                                <path d="M18 4a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-5l-5 3v-3h-2a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h12z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <h3 class="h2 mb-2">{{ t('home.detail1.chat.title') }}</h3>
                                        <p class="text-secondary m-0">
                                            {{ t('home.detail1.chat.description') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="row">
                                    <div class="col-auto">
                                        <div class="shape shape-md">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                                <path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
                                                <path d="M12 7a5 5 0 1 0 5 5" />
                                                <path d="M13 3.055a9 9 0 1 0 7.941 7.945" />
                                                <path d="M15 6v3h3l3 -3h-3v-3z" />
                                                <path d="M15 9l-3 3" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <h3 class="h2 mb-2">{{ t('home.detail1.widgets.title') }}</h3>
                                        <p class="text-secondary m-0">
                                            {{ t('home.detail1.widgets.description') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="row">
                                    <div class="col-auto">
                                        <div class="shape shape-md">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                                <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                                                <path d="M12 12l3 -3" />
                                                <path d="M12 12v-4" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <h3 class="h2 mb-2">{{ t('home.detail1.security.title') }}</h3>
                                        <p class="text-secondary m-0">
                                            {{ t('home.detail1.security.description') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Call to Action -->
        <section class="section">
            <div class="container-narrow text-center">
                <h3 class="h1" v-html="t('home.cta.title')"></h3>
                <p class="text-secondary">
                    {{ t('home.cta.description') }}
                </p>
                <div class="btn-list justify-content-center mt-6">
                    <a href="#" class="btn btn-primary btn-2"> {{ t('home.cta.start') }} </a>
                    <a href="#" class="btn btn-3">
                        {{ t('home.cta.learn_more') }}
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-end icon-2">
                            <path d="M9 6l6 6l-6 6" />
                        </svg>
                    </a>
                </div>
            </div>
        </section>

        <!-- Detailed Features 2 (How it works) -->
        <section class="section section-light">
            <div class="container">
                <div class="section-header">
                    <h2 class="section-title">{{ t('home.how.title') }}</h2>
                    <div class="section-description">
                        {{ t('home.how.description') }}
                    </div>
                </div>
                <div class="row g-lg-10">
                    <div class="col-lg-6">
                        <div class="space-y-6">
                            <div>
                                <div class="row">
                                    <div class="col-auto">
                                        <div class="shape shape-md">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                                <path d="M14 3v4a1 1 0 0 0 1 1h4" />
                                                <path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" />
                                                <path d="M12 17v.01" />
                                                <path d="M12 14a2 2 0 1 0 0 -4a2 2 0 1 0 0 4z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <h3 class="h2 mb-2">{{ t('home.how.step1.title') }}</h3>
                                        <p class="text-secondary m-0">
                                            {{ t('home.how.step1.description') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="row">
                                    <div class="col-auto">
                                        <div class="shape shape-md">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                                <path d="M8 9h8" />
                                                <path d="M8 13h6" />
                                                <path d="M12.01 18.594l-4.01 2.406v-3h-2a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v5.5" />
                                                <path d="M16 19h6" />
                                                <path d="M19 16v6" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <h3 class="h2 mb-2">{{ t('home.how.step2.title') }}</h3>
                                        <p class="text-secondary m-0">
                                            {{ t('home.how.step2.description') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="row">
                                    <div class="col-auto">
                                        <div class="shape shape-md">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                                <path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" />
                                                <path d="M7 9l5 -5l5 5" />
                                                <path d="M12 4l0 12" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <h3 class="h2 mb-2">{{ t('home.how.step3.title') }}</h3>
                                        <p class="text-secondary m-0">
                                            {{ t('home.how.step3.description') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 mt-3 mt-lg-0">
                        <svg xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none" width="500" height="400" viewBox="0 0 500 400" fill="transparent" stroke="var(--tblr-border-color, #b8cef1)" style="width: 100%; height: auto">
                            <rect x=".5" y=".5" width="499" height="399" rx="2"></rect>
                            <line x1="0" y1="0" x2="500" y2="400"></line>
                            <line x1="0" y1="400" x2="500" y2="0"></line>
                        </svg>
                    </div>
                </div>
            </div>
        </section>

        <!-- Pricing Section -->
        <section class="section pt-0">
            <div class="container">
                <div class="pricing">
                    <div class="pricing-card">
                        <h4 class="pricing-title">{{ t('home.pricing.starter.title') }}</h4>
                        <div class="pricing-price">
                            <span class="pricing-price-currency">₽</span>0
                            <div class="pricing-price-description">
                                <div>{{ t('home.pricing.starter.period') }}</div>
                            </div>
                        </div>
                        <ul class="pricing-features my-5">
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>{{ t('home.pricing.starter.feature1') }}
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>{{ t('home.pricing.starter.feature2') }}
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>{{ t('home.pricing.starter.feature3') }}
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>{{ t('home.pricing.starter.feature4') }}
                            </li>
                        </ul>
                        <div class="pricing-btn"><a href="#" class="btn w-100">{{ t('home.pricing.starter.cta') }}</a></div>
                    </div>
                    <div class="pricing-card featured">
                        <div class="pricing-label"><div class="badge bg-primary text-primary-fg">{{ t('home.pricing.pro.badge') }}</div></div>
                        <h4 class="pricing-title">{{ t('home.pricing.pro.title') }}</h4>
                        <div class="pricing-price">
                            <span class="pricing-price-currency">₽</span>990
                            <div class="pricing-price-description">
                                <div>{{ t('home.pricing.pro.period') }}</div>
                            </div>
                        </div>
                        <ul class="pricing-features my-5">
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>{{ t('home.pricing.pro.feature1') }}
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>{{ t('home.pricing.pro.feature2') }}
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>{{ t('home.pricing.pro.feature3') }}
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>{{ t('home.pricing.pro.feature4') }}
                            </li>
                        </ul>
                        <div class="pricing-btn"><a href="#" class="btn w-100 btn-primary">{{ t('home.pricing.pro.cta') }}</a></div>
                    </div>
                    <div class="pricing-card">
                        <h4 class="pricing-title">{{ t('home.pricing.enterprise.title') }}</h4>
                        <div class="pricing-price">
                            <span class="pricing-price-currency"></span>{{ t('home.pricing.enterprise.price') }}
                            <div class="pricing-price-description">
                                <div>{{ t('home.pricing.enterprise.period') }}</div>
                            </div>
                        </div>
                        <ul class="pricing-features my-5">
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>{{ t('home.pricing.enterprise.feature1') }}
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>{{ t('home.pricing.enterprise.feature2') }}
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>{{ t('home.pricing.enterprise.feature3') }}
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>{{ t('home.pricing.enterprise.feature4') }}
                            </li>
                        </ul>
                        <div class="pricing-btn"><a href="#" class="btn w-100">{{ t('home.pricing.enterprise.cta') }}</a></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Team License Section -->
        <section class="section">
            <div class="container">
                <div class="bg-body-tertiary p-6 rounded-4">
                    <div class="row g-4 align-items-center">
                        <div class="col-lg">
                            <h3 class="h2">{{ t('home.team.title') }}</h3>
                            <p class="m-0 text-secondary">{{ t('home.team.description') }}</p>
                        </div>
                        <div class="col-lg">
                            <ul class="list-unstyled m-0">
                                <li>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                        <path d="M5 12l5 5l10 -10" />
                                    </svg>{{ t('home.team.feature1') }}
                                </li>
                                <li>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                        <path d="M5 12l5 5l10 -10" />
                                    </svg>{{ t('home.team.feature2') }}
                                </li>
                                <li>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                        <path d="M5 12l5 5l10 -10" />
                                    </svg>{{ t('home.team.feature3') }}
                                </li>
                                <li>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                        <path d="M5 12l5 5l10 -10" />
                                    </svg>{{ t('home.team.feature4') }}
                                </li>
                            </ul>
                        </div>
                        <div class="col-6-sm col-lg">
                            <div class="pricing-price m-0">
                                <span class="pricing-price-currency">₽</span>4 990
                                <div class="pricing-price-description">
                                    <div>{{ t('home.team.price_per') }}</div>
                                    <div>{{ t('home.team.price_period') }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6-sm col-lg-auto"><a href="#" class="btn btn-primary w-100">{{ t('home.team.cta') }}</a></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ Section -->
        <section class="section section-light">
            <div class="container">
                <div class="section-header">
                    <h2 class="section-title">{{ t('home.faq.title') }}</h2>
                </div>
                <div class="row g-lg-4">
                    <div class="col-md-4 prose">
                        <h3 class="h2">{{ t('home.faq.q1.title') }}</h3>
                        <p class="text-secondary">
                            {{ t('home.faq.q1.description') }}
                        </p>
                    </div>
                    <div class="col-md-4 prose">
                        <h3 class="h2">{{ t('home.faq.q2.title') }}</h3>
                        <p class="text-secondary">
                            {{ t('home.faq.q2.description') }}
                        </p>
                    </div>
                    <div class="col-md-4 prose">
                        <h3 class="h2">{{ t('home.faq.q3.title') }}</h3>
                        <p class="text-secondary">
                            {{ t('home.faq.q3.description') }}
                        </p>
                    </div>
                    <div class="col-md-4 prose">
                        <h3 class="h2">{{ t('home.faq.q4.title') }}</h3>
                        <p class="text-secondary">
                            {{ t('home.faq.q4.description') }}
                        </p>
                    </div>
                    <div class="col-md-4 prose">
                        <h3 class="h2">{{ t('home.faq.q5.title') }}</h3>
                        <p class="text-secondary">
                            {{ t('home.faq.q5.description') }}
                        </p>
                    </div>
                    <div class="col-md-4 prose">
                        <h3 class="h2">{{ t('home.faq.q6.title') }}</h3>
                        <ul class="text-secondary">
                            <li>{{ t('home.faq.q6.item1') }}</li>
                            <li>{{ t('home.faq.q6.item2') }}</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- Stats Section -->

        <!-- Newsletter Section -->
        <section class="section">
            <div class="container">
                <div class="section-header mb-6">
                    <h2 class="section-title">{{ t('home.newsletter.title') }}</h2>
                    <p class="section-description">{{ t('home.newsletter.description') }}</p>
                </div>
            </div>
            <div class="container-tight">
                <div class="row">
                    <div class="col">
                        <input class="w-100 form-control" :placeholder="t('home.newsletter.placeholder')" type="email" />
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                <path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10" />
                                <path d="M3 7l9 6l9 -6" />
                            </svg>
                            {{ t('home.newsletter.button') }}
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Bottom Support Section -->
        <section class="section section-light">
            <div class="container">
                <div class="row g-xl-6">
                    <div class="col-lg-4">
                        <div class="section-header text-start sticky-top">
                            <div class="section-title">{{ t('home.support.title') }}</div>
                            <p class="section-description">{{ t('home.support.description') }}</p>
                        </div>
                    </div>
                    <div class="col-lg">
                        <div class="space-y-5">
                            <div>
                                <div class="h3 mb-1">{{ t('home.support.q1.title') }}</div>
                                <div class="text-secondary">
                                    {{ t('home.support.q1.description') }}
                                </div>
                            </div>
                            <div>
                                <div class="h3 mb-1">{{ t('home.support.q2.title') }}</div>
                                <div class="text-secondary">
                                    {{ t('home.support.q2.description') }}
                                </div>
                            </div>
                            <div>
                                <div class="h3 mb-1">{{ t('home.support.q3.title') }}</div>
                                <div class="text-secondary">
                                    {{ t('home.support.q3.description') }}
                                </div>
                            </div>
                        </div>
                        <div class="mt-5">
                            <a class="btn btn-primary" href="#">{{ t('home.support.cta') }}</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
</template>
