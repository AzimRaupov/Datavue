# Datavue

Datavue — платформа для генерации BI-дашбордов с помощью ИИ. Пользователь подключает источник данных (MySQL, PostgreSQL, SQLite, DuckDB/файлы), описывает в чате на естественном языке, что хочет увидеть, а система сама:

1. группирует таблицы источника по смыслу;
2. определяет, какие данные нужны под запрос;
3. проектирует набор виджетов дашборда (счётчики, графики, таблицы) и подбирает под каждый подходящий тип визуализации;
4. генерирует Python-код, который считает нужные метрики прямо по источнику данных;
5. прогоняет сгенерированный код, валидирует результат и при ошибках сама себя чинит.

Дальнейшие правки («добавь график по странам», «переведи подписи на английский», «удали второй виджет») обрабатываются тем же пайплайном в режиме перегенерации — без необходимости пересоздавать дашборд с нуля.

## Стек

- **Backend:** Laravel 13 (PHP 8.3+), очереди (`QUEUE_CONNECTION=database`), Laravel Reverb для realtime-обновлений, OpenAI API (`openai-php/client`, `orhanerday/open-ai`)
- **Frontend:** Vue 3 + Vue Router + vue-i18n, ApexCharts/jsVectorMap, Vite; три независимых SPA — `admin`, `company`, `viewer`
- **Данные:** MySQL / PostgreSQL / SQLite / DuckDB как источники; аналитический код выполняется в **Python** (pandas, duckdb, mysql-connector-python) через отдельный venv
- **Тесты:** Pest

## Как это устроено (коротко)

| Слой | Где искать |
|---|---|
| Подключение и роутинг источников данных | `app/Helpers/DataSource/*` (`ConnectionProviderRouter`, `Providers/*`, `DataSourceGrouping`) |
| Промпты и вызовы ИИ | `app/Helpers/Ai/*` (`DashboardAi`, `DataSourceAi`, `DefineTaskAi`, `Providers/*ProviderAi`) |
| Генерация/перегенерация дашборда | `app/Helpers/Dashboard/*` + `app/Jobs/DashboardGeneratorJob.php`, `DashboardReGeneratorJob.php` |
| Проверка и авто-починка виджетов | `app/Helpers/Widget/ReviewWidgetsDashboard.php`, `WidgetCodeRun.php`, `WidgetOutputValidator.php` |
| Каталог типов виджетов | `database/seeders/Widgets/*`, модель `Widget` (флаг `is_ai_selectable` скрывает от ИИ виджеты, ещё не готовые на фронте) |
| Роутинг задачи по сообщению чата | `app/Helpers/Task/RouterTask.php`, `app/Helpers/Ai/DefineTaskAi.php` |
| Рабочие пространства | модель `Workspace`, `app/Http/Controllers/Dashboard/WorkspaceController.php`, `resources/js/company/pages/workspace/*` |

Фронтенд состоит из трёх отдельных Vue-приложений под `/admin`, `/company` и `/` (viewer), каждое со своим `app.js`, роутером и API-клиентом в `resources/js/{admin,company,viewer}`.

## Рабочие пространства

Единица работы — **рабочее пространство**: у него есть имя, один источник данных, сколько угодно дашбордов и один разговор с агентом. Заводит его человек: «Продажи» и «Склад» на одной и той же базе — разные задачи разных людей, и складывать их вместе только потому, что таблицы лежат в одной базе, неправильно.

```
Источник данных
└── Рабочее пространство   (Продажи)
    ├── Дашборд            (собран агентом или руками)
    ├── Дашборд
    └── Чат с агентом      (один на пространство)
```

Список — `/company/workspaces`, сама работа — `/company/workspace/{workspace}/{dashboard?}`. Страница одна на всё: дашборды переключаются селектором, режим «Просмотр ↔ Конструктор» — кнопкой (`?mode=edit` в адресе), чат живёт сбоку и никуда не уводит.

Дашборд, собранный руками, обсуждается с агентом так же, как сгенерированный: разговор пространства заводится кнопкой «AI Ассистент» (`POST /workspaces/{id}/chat`), после чего работают и «добавь график по странам», и перестройка целиком.

Перегенерация по-прежнему создаёт **следующую версию** дашборда, а не правит существующий, — новая версия остаётся в том же пространстве и встаёт первой в списке.

Прежние адреса (`/chat/{id}/{dashboard?}`, `/project/dashboard/{id}`, `/project/dashboard/{id}/edit`) остались редиректами: они ведут на входы `/workspace/d/{dashboard}` и `/workspace/chat/{chat}`, которые сами находят пространство и приводят адрес к каноническому.

## Требования

- PHP >= 8.3, Composer
- Node.js + npm
- MySQL (или иной источник для очередей/сессий/кэша — по умолчанию используется MySQL)
- Python 3 + виртуальное окружение с зависимостями из `requirements.txt` (pandas, duckdb, драйверы БД, openpyxl/reportlab/python-docx для выгрузок, scikit-learn для классификатора намерений; Python-раннер по умолчанию ищет `venv/bin/python` в корне проекта, иначе — `python3` в `PATH`)
- Ключ OpenAI API

## Установка

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Заполните в `.env`:

- `DB_*` — подключение к БД приложения;
- `OPENAI_API_KEY` — ключ OpenAI (опционально `GPT_MODEL`, по умолчанию `gpt-5-nano`);
- `REVERB_*` / `BROADCAST_CONNECTION=reverb` — для realtime-обновлений статусов генерации на фронте;
- `QUEUE_CONNECTION=database` — генерация дашбордов и виджетов выполняется в очереди.

Затем:

```bash
php artisan migrate --seed
npm install
```

Python-окружение (если ещё не создано):

```bash
python3 -m venv venv
venv/bin/pip install -r requirements.txt
```

## Запуск для разработки

```bash
composer run dev
```

Это `php artisan dev` — TUI с вкладками по процессам: `server` (`artisan serve`), `queue` (`queue:listen`), `logs` (`pail`), `vite` (`npm run dev`), `reverb` (`reverb:start`) и `schedule` (`schedule:work`). Список задаётся в `AppServiceProvider::boot()` через `DevCommands`; посмотреть его можно командой `php artisan dev:list`.

Приложение будет доступно на `http://127.0.0.1:8000`:

- `/` — публичный viewer (лендинг, вход/регистрация);
- `/company/*` — кабинет компании: рабочие пространства, дашборды, источники данных;
- `/admin/*` — административная панель.

## Тесты

```bash
composer test
```

## Известные ограничения

- Виджет `map` (картограмма) добавлен в каталог, но пока не подключён на фронтенде (`WidgetContainer.vue`/`Map.vue` не читают данные виджета) — помечен `is_ai_selectable = false`, чтобы ИИ не предлагал его при выборе типа визуализации.

## Android-приложение

Capacitor-оболочка с автономным интерфейсом: JS, CSS и шрифты лежат внутри APK
(`dist-mobile`), а с сервера берутся только данные по API и сокеты Reverb.
Адрес сервера задаётся при сборке (`MOBILE_API_URL`, по умолчанию `https://datavue.xyz`).
Точка входа — `resources/js/mobile.js`, конфиг сборки — `vite.mobile.config.js`.

```bash
npm run android:sync                     # собрать интерфейс и скопировать в android/
cd android && ./gradlew assembleDebug    # APK: android/app/build/outputs/apk/debug/app-debug.apk
```

Другой сервер: `MOBILE_API_URL=https://example.com npm run android:sync`.
Для работы сокетов на сервере должен быть задеплоен `config/cors.php` (разрешает `/broadcasting/auth`).

## Почта: проверка и тесты

```bash
php artisan mail:check                  # настройки, вход на SMTP, тестовое письмо на адрес отправителя
php artisan mail:check --to=me@mail.ru  # отправить проверочное письмо на другой адрес
php artisan mail:check --no-send        # только настройки и вход на SMTP-сервер
```

Команда подсказывает причину, если что-то не так (режим `log`, неверный пароль, закрытый порт, нужен «пароль приложения»).
Запускайте её и на сервере после настройки `MAIL_*` в `.env`.

Обычные тесты (`./vendor/bin/pest`) почту не отправляют. **Живые** тесты отправляют настоящие письма на указанный ящик
и проверяют по IMAP, что они дошли (вложение PDF/DOCX, ссылка на скачивание, письма алертов):

```bash
MAIL_LIVE_TEST_TO=you@gmail.com DB_CONNECTION=mysql DB_DATABASE=datavue \
    ./vendor/bin/pest tests/Feature/Live --group=live-mail     # 1. почта сама по себе
    ./vendor/bin/pest tests/Feature/Live --group=live-report   # 2. отчёты по расписанию
    ./vendor/bin/pest tests/Feature/Live --group=live-alert    # 3. алерты
```

Нужны: настроенный SMTP, локальная MySQL с данными, запущенное приложение (`EXPORT_RENDER_URL`) и Chrome.
Тесты работают с базой разработки и удаляют только то, что создали сами. Письма в ящике остаются (тема содержит метку `LIVE-…`).
