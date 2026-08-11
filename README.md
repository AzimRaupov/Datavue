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

Фронтенд состоит из трёх отдельных Vue-приложений под `/admin`, `/company` и `/` (viewer), каждое со своим `app.js`, роутером и API-клиентом в `resources/js/{admin,company,viewer}`.

## Требования

- PHP >= 8.3, Composer
- Node.js + npm
- MySQL (или иной источник для очередей/сессий/кэша — по умолчанию используется MySQL)
- Python 3 + виртуальное окружение с `pandas`, `duckdb`, `mysql-connector-python` (Python-раннер по умолчанию ищет `venv/bin/python` в корне проекта, иначе — `python3` в `PATH`)
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
venv/bin/pip install pandas duckdb mysql-connector-python numpy
```

## Запуск для разработки

```bash
composer run dev
```

Эта команда параллельно поднимает: `php artisan serve`, воркер очереди (`queue:listen`), `php artisan pail` (логи) и `npm run dev` (Vite с HMR). Отдельно нужно запустить Reverb-сервер:

```bash
php artisan reverb:start
```

Приложение будет доступно на `http://127.0.0.1:8000`:

- `/` — публичный viewer (лендинг, вход/регистрация);
- `/company/*` — рабочее пространство компании (чаты, дашборды, источники данных);
- `/admin/*` — административная панель.

## Тесты

```bash
composer test
```

## Известные ограничения

- Виджет `map` (картограмма) добавлен в каталог, но пока не подключён на фронтенде (`WidgetContainer.vue`/`Map.vue` не читают данные виджета) — помечен `is_ai_selectable = false`, чтобы ИИ не предлагал его при выборе типа визуализации.
