import { createI18n } from 'vue-i18n';

const messages = {
    ru: {
        header: {
            home: 'Главная',
            about: 'О нас',
            pricing: 'Цены',
            startBtn: 'Начать'
        }
    },

    en: {
        header: {
            home: 'Home',
            about: 'About Us',
            pricing: 'Pricing',
            startBtn: 'Get Started'
        }
    },

    tj: {
        header: {
            home: 'Асосӣ',
            about: 'Дар бораи мо',
            pricing: 'Нархҳо',
            startBtn: 'Оғоз кардан'
        }
    }
};

export default createI18n({
    legacy: false,
    locale: localStorage.getItem('lang') || 'ru',
    fallbackLocale: 'en',
    messages,
});
