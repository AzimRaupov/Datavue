/*
 * Тексты главной страницы. Вынесены из i18n.js: лендинг стал большим,
 * а в общем файле рядом лежат ещё формы входа и юридические страницы.
 */

const ru = {
    hero: {
        badge: 'ИИ-аналитика',
        title: 'Дашборды и алерты из ваших данных',
        title_accent: 'с ИИ или вручную',
        description: 'Подключите данные — и спросите ИИ, или соберите всё сами.',
        cta_primary: 'Попробовать бесплатно',
        cta_secondary: 'Как это работает',
        works_with: 'Работает с:',
    },

    sources: {
        title: 'Любой источник данных',
        db: 'Базы данных',
        files: 'Файлы',
        sheets: 'Google Таблицы',
        erp: '1С',
    },

    how: {
        title: 'Четыре шага',
        source: { title: 'Источник', description: 'База, файл или таблица' },
        workspace: { title: 'Пространство', description: 'Своё место под задачу' },
        dashboard: { title: 'Дашборд', description: 'ИИ или вручную' },
        alert: { title: 'Алерт', description: 'Отчёт на почту' },
    },

    dashboards: {
        title: 'Дашборды',
        ai: { title: 'Спросите ИИ', description: 'Опишите в чате — агент соберёт виджеты.' },
        manual: { title: 'Или соберите сами', description: 'Перетаскивайте виджеты в конструкторе.' },
        widget: {
            bar: 'Столбцы', line: 'Линии', pie: 'Круговая', combo: 'Комбо', scatter: 'Точки',
            radar: 'Радар', radial: 'Радиальная', funnel: 'Воронка', heatmap: 'Тепловая карта',
            treemap: 'Древовидная', map: 'Карта', table: 'Таблица', counters: 'Счётчики',
        },
    },

    alerts: {
        title: 'Алерты',
        description: 'Условие сработало — отчёт у вас на почте.',
        modes: { builder: 'Конструктор', sql: 'SQL', python: 'Python', ai: 'ИИ-чат' },
    },

    security: {
        schema: 'ИИ видит только структуру',
        readonly: 'Только чтение',
        transparent: 'Любую цифру можно проверить',
        link: 'Подробнее',
    },

    cta: {
        title: 'Попробуйте на своих данных',
        start: 'Создать аккаунт',
        login: 'Войти',
    },

    mock: {
        dashboard_title: 'Аналитика продаж',
        dashboard_sub: 'Обновлено только что',
        live: 'Онлайн',
        kpi: { revenue: 'Выручка', orders: 'Заказы', avg: 'Средний чек', customers: 'Клиенты' },
        trend: 'Тренд продаж',
        regions: 'По регионам',
        assistant: 'ИИ-ассистент',
        assistant_state: 'Аналитика продаж',
        user_msg: 'Сделай аналитику продаж и клиентов',
        step1: 'Определил задачу',
        step2: 'Собрал схему дашборда',
        step3: 'Создал виджеты',
        placeholder: 'Спросите о ваших данных…',
        chat_user: 'Покажи долю выручки по каналам продаж',
        chat_ai: 'Готово — добавил виджет на дашборд:',
        chat_widget: 'Выручка по каналам',
        chat_user2: 'Добавь график по странам',
        pie: ['Сайт', 'Маркетплейсы', 'Офлайн', 'Партнёры'],
        alert_title: 'Падение продаж',
        alert_interval: 'Проверка каждый час',
        alert_firing: 'Сработал',
        mode_builder: 'Конструктор',
        rule_metric: 'Метрика',
        rule_by: 'за',
        rule_day: 'день',
        rule_when: 'Условие',
        mail_subject: 'Алерт: продажи упали ниже порога',
        mail_body: 'Выручка за вчера — 11 240, это ниже допустимых 15 000. Подробности — в приложенном отчёте.',
        mode_view: 'Просмотр',
        mode_edit: 'Конструктор',
        drag_hint: 'Перетащите виджет',
        widgets: 'Виджеты',
        drop_here: 'Перетащите сюда',
    },
}

const en = {
    hero: {
        badge: 'AI analytics',
        title: 'Dashboards and alerts from your data',
        title_accent: 'with AI or by hand',
        description: 'Connect your data — then ask the AI, or build it yourself.',
        cta_primary: 'Try it for free',
        cta_secondary: 'How it works',
        works_with: 'Works with:',
    },

    sources: {
        title: 'Any data source',
        db: 'Databases',
        files: 'Files',
        sheets: 'Google Sheets',
        erp: '1C',
    },

    how: {
        title: 'Four steps',
        source: { title: 'Source', description: 'Database, file or sheet' },
        workspace: { title: 'Workspace', description: 'A place for each job' },
        dashboard: { title: 'Dashboard', description: 'AI or by hand' },
        alert: { title: 'Alert', description: 'Report by email' },
    },

    dashboards: {
        title: 'Dashboards',
        ai: { title: 'Ask the AI', description: 'Describe it in chat — the agent builds the widgets.' },
        manual: { title: 'Or build it yourself', description: 'Drag widgets around in the builder.' },
        widget: {
            bar: 'Bar', line: 'Line', pie: 'Pie', combo: 'Combo', scatter: 'Scatter',
            radar: 'Radar', radial: 'Radial', funnel: 'Funnel', heatmap: 'Heatmap',
            treemap: 'Treemap', map: 'Map', table: 'Table', counters: 'Counters',
        },
    },

    alerts: {
        title: 'Alerts',
        description: 'Condition met — the report lands in your inbox.',
        modes: { builder: 'Builder', sql: 'SQL', python: 'Python', ai: 'AI chat' },
    },

    security: {
        schema: 'The AI sees structure only',
        readonly: 'Read-only',
        transparent: 'Every number can be verified',
        link: 'Learn more',
    },

    cta: {
        title: 'Try it on your own data',
        start: 'Create an account',
        login: 'Sign in',
    },

    mock: {
        dashboard_title: 'Sales analytics',
        dashboard_sub: 'Updated just now',
        live: 'Live',
        kpi: { revenue: 'Revenue', orders: 'Orders', avg: 'Avg. check', customers: 'Customers' },
        trend: 'Sales trend',
        regions: 'By region',
        assistant: 'AI assistant',
        assistant_state: 'Sales analytics',
        user_msg: 'Build sales and customer analytics',
        step1: 'Defined the task',
        step2: 'Designed the dashboard',
        step3: 'Created the widgets',
        placeholder: 'Ask about your data…',
        chat_user: 'Show revenue share by sales channel',
        chat_ai: 'Done — I added a widget to the dashboard:',
        chat_widget: 'Revenue by channel',
        chat_user2: 'Add a chart by country',
        pie: ['Website', 'Marketplaces', 'Offline', 'Partners'],
        alert_title: 'Sales drop',
        alert_interval: 'Checked every hour',
        alert_firing: 'Firing',
        mode_builder: 'Builder',
        rule_metric: 'Metric',
        rule_by: 'per',
        rule_day: 'day',
        rule_when: 'Condition',
        mail_subject: 'Alert: sales fell below the threshold',
        mail_body: 'Yesterday’s revenue is 11,240, below the allowed 15,000. Details are in the attached report.',
        mode_view: 'View',
        mode_edit: 'Builder',
        drag_hint: 'Drag a widget',
        widgets: 'Widgets',
        drop_here: 'Drop here',
    },
}

const tj = {
    hero: {
        badge: 'Таҳлили ИИ',
        title: 'Дашбордҳо ва огоҳиномаҳо аз маълумоти шумо',
        title_accent: 'бо ИИ ё дастӣ',
        description: 'Маълумотро пайваст кунед — ва аз ИИ бипурсед, ё худатон бисозед.',
        cta_primary: 'Ройгон санҷед',
        cta_secondary: 'Чӣ тавр кор мекунад',
        works_with: 'Бо инҳо кор мекунад:',
    },

    sources: {
        title: 'Ҳар манбаи маълумот',
        db: 'Пойгоҳҳои додаҳо',
        files: 'Файлҳо',
        sheets: 'Ҷадвалҳои Google',
        erp: '1С',
    },

    how: {
        title: 'Чор қадам',
        source: { title: 'Манбаъ', description: 'Пойгоҳ, файл ё ҷадвал' },
        workspace: { title: 'Фазои корӣ', description: 'Ҷой барои ҳар вазифа' },
        dashboard: { title: 'Дашборд', description: 'ИИ ё дастӣ' },
        alert: { title: 'Огоҳинома', description: 'Ҳисобот ба почта' },
    },

    dashboards: {
        title: 'Дашбордҳо',
        ai: { title: 'Аз ИИ бипурсед', description: 'Дар чат тасвир кунед — агент виҷетҳоро месозад.' },
        manual: { title: 'Ё худатон бисозед', description: 'Виҷетҳоро дар конструктор кашед.' },
        widget: {
            bar: 'Сутунӣ', line: 'Хатӣ', pie: 'Доиравӣ', combo: 'Омехта', scatter: 'Нуқтавӣ',
            radar: 'Радар', radial: 'Радиалӣ', funnel: 'Қиф', heatmap: 'Харитаи гармӣ',
            treemap: 'Дарахтӣ', map: 'Харита', table: 'Ҷадвал', counters: 'Ҳисобкунакҳо',
        },
    },

    alerts: {
        title: 'Огоҳиномаҳо',
        description: 'Шарт амалӣ шуд — ҳисобот ба почтаи шумо меояд.',
        modes: { builder: 'Конструктор', sql: 'SQL', python: 'Python', ai: 'Чати ИИ' },
    },

    security: {
        schema: 'ИИ танҳо сохторро мебинад',
        readonly: 'Танҳо хондан',
        transparent: 'Ҳар рақамро санҷидан мумкин аст',
        link: 'Бештар',
    },

    cta: {
        title: 'Бо маълумоти худ санҷед',
        start: 'Сохтани ҳисоб',
        login: 'Даромадан',
    },

    mock: {
        dashboard_title: 'Таҳлили фурӯш',
        dashboard_sub: 'Ҳозир нав шуд',
        live: 'Онлайн',
        kpi: { revenue: 'Даромад', orders: 'Фармоишҳо', avg: 'Чеки миёна', customers: 'Муштариён' },
        trend: 'Тамоюли фурӯш',
        regions: 'Аз рӯи минтақаҳо',
        assistant: 'Ёрдамчии ИИ',
        assistant_state: 'Таҳлили фурӯш',
        user_msg: 'Таҳлили фурӯш ва муштариёнро соз',
        step1: 'Вазифаро муайян кард',
        step2: 'Схемаи дашбордро ҷамъ кард',
        step3: 'Виҷетҳоро сохт',
        placeholder: 'Дар бораи маълумоти худ бипурсед…',
        chat_user: 'Ҳиссаи даромадро аз рӯи каналҳои фурӯш нишон деҳ',
        chat_ai: 'Тайёр — виҷетро ба дашборд илова кардам:',
        chat_widget: 'Даромад аз рӯи каналҳо',
        chat_user2: 'Графикро аз рӯи кишварҳо илова кун',
        pie: ['Сайт', 'Маркетплейсҳо', 'Офлайн', 'Шарикон'],
        alert_title: 'Паст шудани фурӯш',
        alert_interval: 'Санҷиш ҳар соат',
        alert_firing: 'Амалӣ шуд',
        mode_builder: 'Конструктор',
        rule_metric: 'Нишондиҳанда',
        rule_by: 'дар',
        rule_day: 'рӯз',
        rule_when: 'Шарт',
        mail_subject: 'Огоҳинома: фурӯш аз ҳад паст шуд',
        mail_body: 'Даромади дирӯз — 11 240, ин аз 15 000-и иҷозатдода кам аст. Тафсилот дар ҳисоботи замимашуда.',
        mode_view: 'Дидан',
        mode_edit: 'Конструктор',
        drag_hint: 'Виҷетро кашед',
        widgets: 'Виҷетҳо',
        drop_here: 'Ба ин ҷо кашед',
    },
}

export default { ru, en, tj }
