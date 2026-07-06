import { createI18n } from 'vue-i18n';

const messages = {
    ru: {
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
