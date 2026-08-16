import Echo from "laravel-echo";
import Pusher from "pusher-js";

window.Pusher = Pusher;

/**
 * Подключение к вещанию — одно на всё приложение.
 *
 * Раньше эта настройка была скопирована в три компонента, и каждый поднимал
 * собственное соединение. Пока каналы были публичными, разницы почти не было;
 * теперь у подписки есть авторизация, и три копии означали бы три места,
 * где легко забыть передать токен, — а забытый токен выглядит как «событие
 * просто не пришло», без единой ошибки на экране.
 *
 * Каналы приватные (см. routes/channels.php): на каждую подписку Echo сходит
 * на /broadcasting/auth, а сервер проверит, что чат, дашборд или источник
 * принадлежат компании подписчика и что у него есть право их видеть.
 */
function createEcho() {
    return new Echo({
        broadcaster: "reverb",
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST || "127.0.0.1",
        wsPort: import.meta.env.VITE_REVERB_PORT || 8080,
        wssPort: import.meta.env.VITE_REVERB_PORT || 8080,
        forceTLS: false,
        disableStats: true,
        enabledTransports: ["ws"],

        // Приложение одностраничное и ходит с токеном в заголовке, а не с кукой,
        // поэтому маршрут авторизации подписки объявлен под охраной sanctum
        // (см. bootstrap/app.php: withBroadcasting).
        authEndpoint: "/broadcasting/auth",

        // Заголовок собирается на каждую подписку, а не один раз при создании:
        // токен появляется после входа и меняется при смене пароля, а соединение
        // живёт всё это время.
        authorizer: (channel) => ({
            authorize: (socketId, callback) => {
                const token = localStorage.getItem("token");

                fetch("/broadcasting/auth", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        Accept: "application/json",
                        ...(token ? { Authorization: `Bearer ${token}` } : {}),
                    },
                    body: JSON.stringify({
                        socket_id: socketId,
                        channel_name: channel.name,
                    }),
                })
                    .then((response) => {
                        if (!response.ok) {
                            // 403 здесь — это нормальный ответ сервера «не твой канал»,
                            // а не сбой. Отдаём его как ошибку подписки: Echo сообщит
                            // об этом через .error() на канале.
                            return Promise.reject(
                                new Error(`Подписка отклонена: ${response.status}`)
                            );
                        }

                        return response.json();
                    })
                    .then((data) => callback(null, data))
                    .catch((error) => callback(error, null));
            },
        }),
    });
}

let echo = null;

/**
 * Общее соединение. Создаётся при первом обращении — до входа в систему
 * поднимать сокет незачем.
 */
export function useEcho() {
    if (!echo) echo = createEcho();

    return echo;
}

/**
 * Разрывает соединение — при выходе из аккаунта.
 *
 * Без этого сокет, авторизованный старым токеном, продолжал бы получать
 * события уже после выхода.
 */
export function disconnectEcho() {
    if (!echo) return;

    echo.disconnect();
    echo = null;
}
