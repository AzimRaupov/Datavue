import { createI18n } from 'vue-i18n';

const messages = {
    ru: {
        login: 'Вход',
        register: 'Регистрация',
        dashboard: 'Панель управления',
    },

    en: {
        login: 'Login',
        register: 'Register',
        dashboard: 'Dashboard',
    },

    tj: {
        login: 'Воридшавӣ',
        register: 'Бақайдгирӣ',
        dashboard: 'Панели идоракунӣ',
    }
};

export default createI18n({
    legacy: false,
    locale: localStorage.getItem('lang') || 'ru',
    fallbackLocale: 'en',
    messages,
});
