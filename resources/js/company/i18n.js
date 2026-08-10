import { createI18n } from 'vue-i18n';

const messages = {
    ru: {
        tasks: {
            determine_changes: 'Проверка изменений',
            updating_dashboard: 'Обновление',
            detect_schema_dashboard: 'Создание схемы',
            define_task: 'Определение задачи',
            generate_widgets_dashboard: 'Создание виджетов',
            data_processing: 'Обработка данных',
            data_source_grouping: 'Группировка данных',
            determine_data_source_groups: 'Определение групп',
            generating_widget_instructions: 'Планирование виджетов',
            review_and_correction_widgets: 'Проверка виджетов',
            response_in_chat: 'Подготовка ответа',
            export_data: 'Выгрузка в файл',
        },

        chatPage: {
            login: 'Login',
            register: 'Register',
            dashboard: 'Dashboard',
        },

        header: {
            logout: 'Выход',
        },
    },

    en: {
        tasks: {
            determine_changes: 'Checking changes',
            updating_dashboard: 'Updating',
            detect_schema_dashboard: 'Creating schema',
            define_task: 'Defining task',
            generate_widgets_dashboard: 'Creating widgets',
            data_processing: 'Processing data',
            data_source_grouping: 'Grouping data',
            determine_data_source_groups: 'Finding groups',
            generating_widget_instructions: 'Planning widgets',
            review_and_correction_widgets: 'Checking widgets',
            response_in_chat: 'Preparing answer',
            export_data: 'Exporting to file',
        },

        chatPage: {
            login: 'Login',
            register: 'Register',
            dashboard: 'Dashboard',
        },

        header: {
            logout: 'Logout',
        },
    },

    tj: {
        tasks: {
            determine_changes: 'Санҷиши тағйирот',
            updating_dashboard: 'Навсозӣ',
            detect_schema_dashboard: 'Сохтани сохтор',
            define_task: 'Муайянкунии вазифа',
            generate_widgets_dashboard: 'Сохтани виҷетҳо',
            data_processing: 'Коркарди маълумот',
            data_source_grouping: 'Гурӯҳбандии маълумот',
            determine_data_source_groups: 'Муайянкунии гурӯҳҳо',
            generating_widget_instructions: 'Банақшагирии виҷетҳо',
            review_and_correction_widgets: 'Санҷиши виҷетҳо',
            response_in_chat: 'Омодасозии ҷавоб',
            export_data: 'Содирот ба файл',
        },

        chatPage: {
            login: 'Login',
            register: 'Register',
            dashboard: 'Dashboard',
        },

        header: {
            logout: 'Баромад',
        },
    },
};

export default createI18n({
    legacy: false,
    locale: localStorage.getItem('lang') || 'ru',
    fallbackLocale: 'en',
    messages,
});
