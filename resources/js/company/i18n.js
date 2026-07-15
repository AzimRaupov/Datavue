import { createI18n } from 'vue-i18n';

const messages = {
    ru: {
        tasks: {
            determine_changes: 'Определение изменений',
            updating_dashboard: 'Обновление дашборда',
            detect_schema_dashboard: 'Создание схемы дашборда',
            define_task: 'Определение задачи',
            generate_widgets_dashboard: 'Генерация виджетов',
            data_processing: 'Обработка данных',

        },
        chatPage: {
            login: 'Login',
            register: 'Register',
            dashboard: 'Dashboard',
        },
        header: {
            logout: 'Выход'
        }
    },

    en: {
        tasks: {
            determine_changes: 'Determining changes',
            updating_dashboard: 'Updating dashboard',
            detect_schema_dashboard: 'Creating dashboard schema',
            define_task: 'Defining task',
            generate_widgets_dashboard: 'Generating dashboard widgets',
            data_processing: 'Data processing',

        },
        chatPage: {
            login: 'Login',
            register: 'Register',
            dashboard: 'Dashboard',
        },
        header: {
            logout: 'Logout'
        }
    },
    tj: {
        tasks: {
            determine_changes: 'Муайян кардани тағйирот',
            updating_dashboard: 'Навсозии панели идоракунӣ',
            detect_schema_dashboard: 'Сохтани сохтори панели идоракунӣ',
            define_task: 'Муайян кардани вазифа',
            generate_widgets_dashboard: 'Эҷоди виҷетҳои панели идоракунӣ',
            data_processing: 'Коркарди маълумот',

        },
        chatPage: {
            login: 'Login',
            register: 'Register',
            dashboard: 'Dashboard',
        },
        header: {
            logout: 'Буромад'
        }
    }
};

export default createI18n({
    legacy: false,
    locale: localStorage.getItem('lang') || 'ru',
    fallbackLocale: 'en',
    messages,
});
