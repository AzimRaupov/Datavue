<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { FAMILIES } from "../components/widgets/registry.js";
import WidgetPlaceholder from "../components/widgets/WidgetPlaceholder.vue";

/**
 * Служебная страница: заглушки загрузки всех семейств виджетов подряд.
 *
 * Нужна, чтобы смотреть и настраивать анимацию загрузки в одном месте, не
 * дожидаясь медленного запроса на реальном дашборде. Рисует тем же компонентом
 * WidgetPlaceholder, что и WidgetContainer, — что видно здесь, то и на дашборде.
 */
const { t } = useI18n();

const families = computed(() =>
    Object.entries(FAMILIES).map(([name, family]) => ({ name, kind: family.placeholder }))
);

// Counters и table растягиваются на всю ширину, остальные — компактные карточки.
const isWide = (kind) => kind === "counters" || kind === "table";
</script>

<template>
    <div class="page-body">
        <div class="container-xl">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="page-title mb-1">{{ t("widgetLoading.title") }}</h2>
                    <div class="text-secondary">{{ t("widgetLoading.subtitle", { count: families.length }) }}</div>
                </div>

                <router-link class="btn" :to="{ name: 'company.widgets' }">
                    {{ t("widgetLoading.back") }}
                </router-link>
            </div>

            <div class="row g-3">
                <div v-for="family in families" :key="family.name"
                     :class="isWide(family.kind) ? 'col-12' : 'col-12 col-lg-6'">
                    <div class="card h-100">
                        <div class="card-header">
                            <h3 class="card-title">{{ family.name }}</h3>
                            <div class="card-actions">
                                <span class="badge bg-blue-lt">{{ family.kind }}</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <WidgetPlaceholder :kind="family.kind" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
