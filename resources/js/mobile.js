import axios from 'axios';
import { API_URL } from './shared/server.js';
import '../css/mobile-fonts.css';

/**
 * Точка входа Android-приложения. Интерфейс (JS, CSS, шрифты) лежит внутри APK,
 * а с сервера берутся только данные по API.
 *
 * На сайте Laravel отдаёт три разные страницы — витрину (/), кабинет компании
 * (/company) и админку (/admin), у каждой свой вход и свой роутер. В приложении
 * HTML один: Capacitor на любой адрес без расширения отдаёт index.html, поэтому
 * по пути выбираем, какое из трёх приложений запустить. Переходы вроде
 * window.location.href = '/company' после входа работают так же, как на сайте.
 */
if (!API_URL) {
    // Сборка без адреса сервера открыла бы пустое приложение — лучше сказать об этом сразу.
    document.getElementById('app').textContent = 'VITE_API_URL is not set';
} else {
    // Прямые вызовы axios в коде ('/api/logout', '/api/get-user') ходят на сервер, а не на APK.
    axios.defaults.baseURL = API_URL;

    start().catch((error) => {
        console.error(error);
        document.getElementById('app').textContent = 'Не удалось запустить приложение';
    });
}

function hasToken() {
    try {
        return Boolean(localStorage.getItem('token'));
    } catch {
        return false;
    }
}

async function start() {
    let path = window.location.pathname;

    // Приложение открывается сразу на входе или в кабинете, а не на рекламной витрине.
    if (path === '/' || path === '/index.html') {
        path = hasToken() ? '/company' : '/login';
        window.history.replaceState(null, '', path);
    }

    if (path === '/admin' || path.startsWith('/admin/')) {
        await import('../css/admin/app.css');
        await import('./admin/app.js');
    } else if (path === '/company' || path.startsWith('/company/')) {
        await import('../css/company/app.css');
        await import('./company/app.js');
    } else {
        // Витрина на сайте задаёт классы страницы в blade-шаблоне.
        document.body.classList.add('body-marketing', 'body-gradient');
        window.__DATAVUE__ = { showCompanies: false };
        await import('../css/viewer/app.css');
        await import('./viewer/app.js');
    }
}
