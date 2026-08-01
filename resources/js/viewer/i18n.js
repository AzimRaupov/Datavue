import { createI18n } from 'vue-i18n';

const messages = {
    ru: {
        header: {
            home: 'Главная',
            about: 'О нас',
            pricing: 'Цены',
            startBtn: 'Начать'
        },

        auth: {
            page_login: 'Вход',
            page_register: 'Регистрация',

            input_name: 'Имя',
            input_email: 'Адрес электронной почты',
            input_password: 'Пароль',
            input_confirm_password: 'Подтвердите пароль',

            already_have_account: 'Уже есть аккаунт?',
            no_account: 'У вас ещё нет аккаунта?'
        }
    },

    en: {
        header: {
            home: 'Home',
            about: 'About Us',
            pricing: 'Pricing',
            startBtn: 'Get Started'
        },

        auth: {
            page_login: 'Login',
            page_register: 'Sign Up',

            input_name: 'Name',
            input_email: 'Email address',
            input_password: 'Password',
            input_confirm_password: 'Confirm Password',

            already_have_account: 'Already have an account?',
            no_account: "Don't have an account yet?"
        }
    },

    tj: {
        header: {
            home: 'Асосӣ',
            about: 'Дар бораи мо',
            pricing: 'Нархҳо',
            startBtn: 'Оғоз кардан'
        },

        auth: {
            page_login: 'Воридшавӣ',
            page_register: 'Бақайдгирӣ',

            input_name: 'Ном',
            input_email: 'Суроғаи почтаи электронӣ',
            input_password: 'Парол',
            input_confirm_password: 'Паролро тасдиқ кунед',

            already_have_account: 'Аллакай ҳисоб доред?',
            no_account: 'Ҳанӯз ҳисоб надоред?'
        }
    }
};

export default createI18n({
    legacy: false,
    locale: localStorage.getItem('lang') || 'ru',
    fallbackLocale: 'en',
    messages
});
