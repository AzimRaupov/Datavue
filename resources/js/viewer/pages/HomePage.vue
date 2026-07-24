<template>
    <main id="content">
        <!-- Hero Section -->
        <header class="hero pb-0">
            <div class="container">
                <h1 class="hero-title">Превратите ваши данные в интерактивный дашборд за секунды</h1>
                <p class="hero-description mt-4">
                    <strong>Data to Dashboard</strong> — это умный ИИ-агент, который анализирует ваши Excel, CSV файлы или базы данных (SQLite, удаленные БД) и мгновенно создает красивые интерактивные дашборды с виджетами по вашему текстовому запросу.
                </p>
                <div class="my-5">
                    <div class="row g-3 justify-content-center">
                        <div class="col-auto">
                            <a href="#" class="btn btn-lg btn-primary">Попробовать бесплатно</a>
                        </div>
                        <div class="col-auto">
                            <a href="#" class="btn btn-lg" target="_blank" rel="noopener noreferrer">Посмотреть демо</a>
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
                                        title="Открыть демо дашборда!"
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
                                        app.data-to-dashboard.ai
                                    </a>
                                </div>
                            </div>
                        </div>
                        <picture>
                            <img
                                src="../static/marketing/preview.png"
                                srcset="../static/marketing/preview.png 1x, ../static/marketing/preview@2x.png 2x"
                                alt="Интерфейс ИИ-агента для создания дашбордов"
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
                        <h2 class="h2">Любые источники данных</h2>
                        <p class="text-secondary">Загружайте файлы Excel и CSV или подключайте SQLite и удаленные базы данных (PostgreSQL, MySQL и др.) в пару кликов.</p>
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
                        <h2 class="h2">ИИ-агент для визуализации</h2>
                        <p class="text-secondary">Просто опишите в чате, что вы хотите увидеть. ИИ автоматически подберет нужные графики, таблицы и KPI-виджеты для ваших данных.</p>
                    </div>
                    <div class="col-md-6 col-lg">
                        <div class="shape shape-md mb-3">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                <path d="M13 3l0 7l6 0l-8 11l0 -7l-6 0l8 -11" />
                            </svg>
                        </div>
                        <h2 class="h2">Мгновенный результат</h2>
                        <p class="text-secondary">Забудьте о ручном создании отчетов. Получайте готовые, красивые и интерактивные дашборды за считанные секунды без навыков программирования.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Detailed Features 1 -->
        <section class="section section-light">
            <div class="container">
                <div class="section-header">
                    <h2 class="section-title">Все необходимое для глубокой аналитики</h2>
                    <div class="section-description">
                        Инструменты, которые помогают вам понимать данные, а не просто смотреть на цифры.
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
                                        <h3 class="h2 mb-2">Интуитивный чат с ИИ</h3>
                                        <p class="text-secondary m-0">
                                            Общайтесь с данными на естественном языке. Спросите «Покажи продажи по регионам за последний квартал», и агент мгновенно построит нужную диаграмму.
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
                                        <h3 class="h2 mb-2">Гибкая настройка виджетов</h3>
                                        <p class="text-secondary m-0">
                                            После генерации вы можете легко перетаскивать, изменять размер и настраивать каждый виджет, чтобы дашборд идеально соответствовал вашим задачам.
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
                                        <h3 class="h2 mb-2">Безопасность данных</h3>
                                        <p class="text-secondary m-0">
                                            Ваши данные надежно защищены. Мы поддерживаем безопасные подключения к удаленным базам данных и не используем вашу конфиденциальную информацию для обучения моделей.
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
                <h3 class="h1">Повысьте эффективность аналитики с нашим ИИ-агентом,<br />доступным прямо сейчас.</h3>
                <p class="text-secondary">
                    Перестаньте тратить часы на создание отчетов в Excel. Доверьте рутину искусственному интеллекту и сосредоточьтесь на принятии решений на основе данных.
                </p>
                <div class="btn-list justify-content-center mt-6">
                    <a href="#" class="btn btn-primary btn-2"> Начать работу </a>
                    <a href="#" class="btn btn-3">
                        Узнать больше
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
                    <h2 class="section-title">Три простых шага к вашему идеальному дашборду</h2>
                    <div class="section-description">
                        От сырых данных до готовой визуализации быстрее, чем вы успеете выпить чашку кофе.
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
                                        <h3 class="h2 mb-2">1. Загрузите данные</h3>
                                        <p class="text-secondary m-0">
                                            Импортируйте файл Excel, CSV или укажите параметры подключения к вашей базе данных (SQLite, PostgreSQL, MySQL).
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
                                        <h3 class="h2 mb-2">2. Опишите задачу</h3>
                                        <p class="text-secondary m-0">
                                            Напишите в чате с ИИ-агентом, какую метрику, тренд или закономерность вы хотите проанализировать.
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
                                        <h3 class="h2 mb-2">3. Получите дашборд</h3>
                                        <p class="text-secondary m-0">
                                            ИИ мгновенно сгенерирует интерактивный дашборд с подходящими виджетами, который можно сохранить, экспортировать или поделиться ссылкой.
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
                        <h4 class="pricing-title">Стартовый</h4>
                        <div class="pricing-price">
                            <span class="pricing-price-currency">₽</span>0
                            <div class="pricing-price-description">
                                <div>навсегда</div>
                            </div>
                        </div>
                        <ul class="pricing-features my-5">
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>До 3 источников данных
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>Базовые типы графиков и виджетов
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>Экспорт дашборда в PNG
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>До 10 запросов к ИИ в день
                            </li>
                        </ul>
                        <div class="pricing-btn"><a href="#" class="btn w-100">Начать бесплатно</a></div>
                    </div>
                    <div class="pricing-card featured">
                        <div class="pricing-label"><div class="badge bg-primary text-primary-fg">Популярный</div></div>
                        <h4 class="pricing-title">Профессиональный</h4>
                        <div class="pricing-price">
                            <span class="pricing-price-currency">₽</span>990
                            <div class="pricing-price-description">
                                <div>в месяц</div>
                            </div>
                        </div>
                        <ul class="pricing-features my-5">
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>Неограниченные источники данных
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>Все типы виджетов и продвинутая аналитика
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>Прямое подключение к любым БД
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>Неограниченные запросы к ИИ и экспорт в PDF/Excel
                            </li>
                        </ul>
                        <div class="pricing-btn"><a href="#" class="btn w-100 btn-primary">Выбрать тариф</a></div>
                    </div>
                    <div class="pricing-card">
                        <h4 class="pricing-title">Корпоративный</h4>
                        <div class="pricing-price">
                            <span class="pricing-price-currency"></span>По запросу
                            <div class="pricing-price-description">
                                <div>индивидуально</div>
                            </div>
                        </div>
                        <ul class="pricing-features my-5">
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>Все возможности "Профессионального"
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>Развертывание на вашем сервере (On-premise)
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>Приоритетная техническая поддержка
                            </li>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                    <path d="M5 12l5 5l10 -10" />
                                </svg>Персональное обучение команды
                            </li>
                        </ul>
                        <div class="pricing-btn"><a href="#" class="btn w-100">Связаться с нами</a></div>
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
                            <h3 class="h2">Лицензия для команд</h3>
                            <p class="m-0 text-secondary">Получите доступ к ИИ-аналитике для всей вашей команды аналитиков, менеджеров и руководителей.</p>
                        </div>
                        <div class="col-lg">
                            <ul class="list-unstyled m-0">
                                <li>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                        <path d="M5 12l5 5l10 -10" />
                                    </svg>До 10 активных пользователей
                                </li>
                                <li>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                        <path d="M5 12l5 5l10 -10" />
                                    </svg>Общие рабочие пространства и дашборды
                                </li>
                                <li>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                        <path d="M5 12l5 5l10 -10" />
                                    </svg>Централизованное управление источниками данных
                                </li>
                                <li>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-inline me-1 text-green icon-3">
                                        <path d="M5 12l5 5l10 -10" />
                                    </svg>Приоритетная скорость генерации
                                </li>
                            </ul>
                        </div>
                        <div class="col-6-sm col-lg">
                            <div class="pricing-price m-0">
                                <span class="pricing-price-currency">₽</span>4 990
                                <div class="pricing-price-description">
                                    <div>за команду</div>
                                    <div>в месяц</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6-sm col-lg-auto"><a href="#" class="btn btn-primary w-100">Подключить команду</a></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ Section -->
        <section class="section section-light">
            <div class="container">
                <div class="section-header">
                    <h2 class="section-title">Часто задаваемые вопросы</h2>
                </div>
                <div class="row g-lg-4">
                    <div class="col-md-4 prose">
                        <h3 class="h2">Какие форматы данных поддерживаются?</h3>
                        <p class="text-secondary">
                            Мы поддерживаем загрузку файлов Excel (.xlsx) и CSV, а также прямое подключение к SQLite, PostgreSQL, MySQL и другим популярным реляционным базам данных через защищенное соединение.
                        </p>
                    </div>
                    <div class="col-md-4 prose">
                        <h3 class="h2">Насколько безопасны мои данные?</h3>
                        <p class="text-secondary">
                            Мы используем шифрование при передаче и хранении. При подключении удаленных баз данных мы не храним ваши учетные данные, а ваши данные не используются для обучения наших ИИ-моделей без явного согласия.
                        </p>
                    </div>
                    <div class="col-md-4 prose">
                        <h3 class="h2">Можно ли редактировать дашборд после создания?</h3>
                        <p class="text-secondary">
                            Да, конечно! ИИ создает оптимальную базовую структуру, но вы можете в любой момент изменить тип графика, применить фильтры, поменять цвета и расположение виджетов вручную.
                        </p>
                    </div>
                    <div class="col-md-4 prose">
                        <h3 class="h2">Нужны ли мне навыки программирования или SQL?</h3>
                        <p class="text-secondary">
                            Нет, наш продукт создан для пользователей любого уровня подготовки. Просто загрузите данные и опишите свою задачу обычным человеческим языком в чате.
                        </p>
                    </div>
                    <div class="col-md-4 prose">
                        <h3 class="h2">Что делать, если ИИ неправильно понял запрос?</h3>
                        <p class="text-secondary">
                            Вы можете уточнить запрос в чате, например: «Сделай график столбчатым» или «Добавь фильтр по дате». ИИ-агент мгновенно внесет правки в дашборд.
                        </p>
                    </div>
                    <div class="col-md-4 prose">
                        <h3 class="h2">Какие ограничения есть у лицензии?</h3>
                        <ul class="text-secondary">
                            <li>Перепродавать саму платформу как свой собственный SaaS-продукт</li>
                            <li>Использовать сгенерированные дашборды для создания конкурирующего сервиса визуализации данных</li>
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
                    <h2 class="section-title">Подпишитесь на наши обновления</h2>
                    <p class="section-description">Узнавайте первыми о новых типах графиков, интеграциях с новыми базами данных и возможностях ИИ-агента.</p>
                </div>
            </div>
            <div class="container-tight">
                <div class="row">
                    <div class="col">
                        <input class="w-100 form-control" placeholder="Ваш Email" type="email" />
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                <path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10" />
                                <path d="M3 7l9 6l9 -6" />
                            </svg>
                            Подписаться
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
                            <div class="section-title">Остались вопросы?</div>
                            <p class="section-description">Не нашли ответ? Свяжитесь с нашей службой поддержки, и мы поможем вам настроить ваш первый дашборд.</p>
                        </div>
                    </div>
                    <div class="col-lg">
                        <div class="space-y-5">
                            <div>
                                <div class="h3 mb-1">Можно ли интегрировать дашборд в мой сайт или CRM?</div>
                                <div class="text-secondary">
                                    Да, мы предоставляем код для встраивания (iframe), чтобы вы могли легко добавить ваш интерактивный дашборд на любой веб-сайт, во внутреннюю систему или CRM.
                                </div>
                            </div>
                            <div>
                                <div class="h3 mb-1">Как быстро ИИ обрабатывает большие объемы данных?</div>
                                <div class="text-secondary">
                                    Благодаря оптимизированным алгоритмам, обработка файлов до 100 МБ или выполнение сложных запросов к БД занимает считанные секунды.
                                </div>
                            </div>
                            <div>
                                <div class="h3 mb-1">Есть ли у вас API для автоматизации?</div>
                                <div class="text-secondary">
                                    Да, тарифы "Профессиональный" и "Корпоративный" включают доступ к REST API, что позволяет генерировать дашборды программно на основе ваших внутренних процессов.
                                </div>
                            </div>
                        </div>
                        <div class="mt-5">
                            <a class="btn btn-primary" href="#">Написать в поддержку</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
</template>
