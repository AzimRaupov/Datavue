<script setup>
import CreateForm from '../components/chat/CreateForm.vue';

</script>


<template>


    <div class="page-wrapper">
        <div class="page-header d-print-none mb-3">
            <div class="container-xl">
                <div class="row g-2 align-items-center mb-4">
                    <div class="col">
                        <div class="page-pretitle">Data to Dashboard</div>
                        <h1 class="page-title">Обзор рабочего пространства</h1>
                    </div>
                    <div class="col-auto ms-auto d-print-none">
                        <button class="btn btn-primary"  data-bs-toggle="modal" data-bs-target="#modal-report" title="Создать новый дашборд через чат">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon me-1"><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                            Новый проект
                        </button>
                    </div>
                </div>

            </div>
        </div>
        <main id="content" class="page-body">
            <div class="container-xl">

                <!-- ===================== ЗАГОЛОВОК СТРАНИЦЫ ===================== -->

                <!-- ===================== KPI / СТАТИСТИКА ===================== -->
                <div class="row row-cards mb-4">
                    <div class="col-sm-6 col-lg-3">
                        <div class="card card-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-blue-lt me-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon text-blue"><path d="M4 4h16v4h-16z" /><path d="M4 12h16v8h-16z" /><path d="M4 12v-4" /></svg>
                                </div>
                                <div>
                                    <div class="text-secondary fs-6">Всего проектов</div>
                                    <div class="h2 mb-0">7</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card card-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-purple-lt me-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon text-purple"><path d="M4 20l4 -9l4 5l3 -4l5 8" /></svg>
                                </div>
                                <div>
                                    <div class="text-secondary fs-6">Всего дашбордов</div>
                                    <div class="h2 mb-0">19</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card card-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-green-lt me-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon text-green"><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /></svg>
                                </div>
                                <div>
                                    <div class="text-secondary fs-6">Загружено файлов</div>
                                    <div class="h2 mb-0">24</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card card-sm">
                            <div class="card-body d-flex align-items-center">
                                <div class="stat-icon bg-orange-lt me-3">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon text-orange"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 7v5l3 3" /></svg>
                                </div>
                                <div>
                                    <div class="text-secondary fs-6">Активность за неделю</div>
                                    <div class="h2 mb-0">+12%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===================== ВСЕ ПРОЕКТЫ ===================== -->
                <div class="row g-2 align-items-center mb-3">
                    <div class="col">
                        <h3 class="mb-0">Все проекты</h3>
                    </div>
                    <div class="col-auto">
                        <a href="#" class="text-secondary">Смотреть все →</a>
                    </div>
                </div>

                <div class="row row-cards mb-5">

                    <!-- Проект 1 -->
                    <div class="col-sm-6 col-lg-4 col-xl-3">
                        <div class="card d-flex flex-column justify-content-between h-100">
                            <div class="card-body pb-2">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="subheader text-muted">22.02.2026</div>
                                    <span class="badge bg-blue-lt">CSV</span>
                                </div>
                                <h3 class="card-title mb-3 text-truncate">
                                    <a href="#" class="text-reset">Продажи 2026</a>
                                </h3>
                                <div class="subheader text-secondary mb-2">Связанные дашборды</div>
                            </div>
                            <div class="list-group list-group-flush border-top">
                                <a href="/dashboard/1" class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3">
                                    <span class="status-dot status-green me-2"></span>
                                    <span class="text-truncate">Обзор KPI</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon ms-auto text-muted icon-xs"><path d="M9 6l6 6l-6 6" /></svg>
                                </a>
                                <a href="/dashboard/2" class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3">
                                    <span class="status-dot status-blue me-2"></span>
                                    <span class="text-truncate">Динамика выручки</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon ms-auto text-muted icon-xs"><path d="M9 6l6 6l-6 6" /></svg>
                                </a>
                                <a href="/dashboard/3" class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3">
                                    <span class="status-dot status-purple me-2"></span>
                                    <span class="text-truncate">Топ продукты</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon ms-auto text-muted icon-xs"><path d="M9 6l6 6l-6 6" /></svg>
                                </a>
                            </div>
                            <div class="card-footer bg-transparent py-2 px-3 border-top d-flex align-items-center justify-content-between">
                                <small class="text-muted">Всего: 3</small>
                                <button class="btn btn-sm btn-link p-0 text-primary fs-5" title="Создать еще один дашборд через чат">+ Добавить</button>
                            </div>
                        </div>
                    </div>

                    <!-- Проект 2 -->
                    <div class="col-sm-6 col-lg-4 col-xl-3">
                        <div class="card d-flex flex-column justify-content-between h-100">
                            <div class="card-body pb-2">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="subheader text-muted">18.02.2026</div>
                                    <span class="badge bg-green-lt">XLSX</span>
                                </div>
                                <h3 class="card-title mb-3 text-truncate">
                                    <a href="#" class="text-reset">Маркетинговые кампании</a>
                                </h3>
                                <div class="subheader text-secondary mb-2">Связанные дашборды</div>
                            </div>
                            <div class="list-group list-group-flush border-top">
                                <a href="/dashboard/4" class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3">
                                    <span class="status-dot status-green me-2"></span>
                                    <span class="text-truncate">ROI по каналам</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon ms-auto text-muted icon-xs"><path d="M9 6l6 6l-6 6" /></svg>
                                </a>
                                <a href="/dashboard/5" class="list-group-item list-group-item-action d-flex align-items-center py-2 px-3">
                                    <span class="status-dot status-yellow me-2"></span>
                                    <span class="text-truncate">Конверсии</span>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon ms-auto text-muted icon-xs"><path d="M9 6l6 6l-6 6" /></svg>
                                </a>
                            </div>
                            <div class="card-footer bg-transparent py-2 px-3 border-top d-flex align-items-center justify-content-between">
                                <small class="text-muted">Всего: 2</small>
                                <button class="btn btn-sm btn-link p-0 text-primary fs-5" title="Создать еще один дашборд через чат">+ Добавить</button>
                            </div>
                        </div>
                    </div>


                </div>

                <!-- ===================== ПОИСК + ЗАГОЛОВОК ДАШБОРДОВ ===================== -->
                <div class="row g-2 align-items-center mb-3">
                    <div class="col">
                        <div class="page-pretitle">Обзор</div>
                        <h1 class="page-title">Последние дашборды</h1>
                    </div>
                    <div class="col-auto ms-auto d-print-none">
                        <form method="GET" action="" class="d-flex">
                            <div class="input-icon me-2">
                        <span class="input-icon-addon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.35-4.35"></path></svg>
                        </span>
                                <input type="text" name="search" class="form-control" placeholder="Поиск дашбордов...">
                            </div>
                            <button type="submit" class="btn btn-primary">Найти</button>
                        </form>
                    </div>
                </div>

                <!-- ===================== СЕТКА ДАШБОРДОВ ===================== -->
                <div class="row row-cards mb-5">

                    <!-- Карточка 2 -->
                    <div class="col-sm-6 col-lg-4 col-xl-3">
                        <div class="card card-sm card-link card-link-pop h-100 d-flex flex-column justify-content-between">
                            <div class="card-body">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="d-flex align-items-center">
                                        <span class="status-dot status-blue me-2"></span>
                                        <span class="subheader text-muted">21.02.2026</span>
                                    </div>
                                    <div class="dropdown">
                                        <a class="text-secondary opacity-50-hover" href="#">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-xs"><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M12 19m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M12 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg>
                                        </a>
                                    </div>
                                </div>
                                <h3 class="card-title mb-2 text-truncate">
                                    <a href="/dashboard/2" class="text-reset">Динамика выручки</a>
                                </h3>
                                <p class="text-secondary text-truncate-2 fs-5 mb-1" style="height: 36px;">
                                    Построй график выручки по месяцам с разбивкой по регионам продаж
                                </p>
                            </div>
                            <div class="card-footer bg-transparent py-2 px-3 border-top d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center text-truncate me-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-secondary icon-xs"><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /></svg>
                                    <span class="text-secondary fs-5 text-truncate" title="sales_report_2026.csv">sales_report_2026.csv</span>
                                </div>
                                <a href="/dashboard/2" class="btn btn-icon btn-sm btn-ghost-primary" title="Открыть дашборд и чат">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon"><path d="M9 6l6 6l-6 6" /></svg>
                                </a>
                            </div>
                        </div>
                    </div>



                </div>



            </div>
        </main>

        <CreateForm />

    </div>


</template>
