/**
 * Адрес сервера для запросов к API и сокетам.
 *
 * На сайте Laravel отдаёт и страницы, и API, поэтому адрес пустой и запросы идут
 * на тот же хост. Приложение лежит внутри APK (https://app.datavue.local), и на
 * сервер уходят только запросы к API: адрес задаётся при сборке
 * (VITE_API_URL, см. vite.mobile.config.js).
 */
export const API_URL = (import.meta.env.VITE_API_URL || '').replace(/\/$/, '');
