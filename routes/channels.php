<?php

use App\Models\AiChat;
use App\Models\Dashboard;
use App\Models\DataSource;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Каналы вещания
|--------------------------------------------------------------------------
|
| Все каналы платформы — приватные, и это не перестраховка. Публичный канал
| в протоколе Pusher/Reverb не проверяет вообще ничего: ключ приложения лежит
| в собранном фронтенде (он и задуман открытым), а имя канала — это просто
| «tasks.» и номер чата. То есть кто угодно мог подписаться на tasks.123
| и в реальном времени читать ответы агента по данным чужой компании.
|
| Теперь на каждую подписку сервер спрашивает: твой ли это чат, дашборд,
| источник — и есть ли у тебя право их видеть.
|
| Возвращается bool, а не массив: массив в Laravel означает данные участника
| presence-канала и делает канал presence-каналом.
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return $user->isUsable() && (int) $user->id === (int) $id;
});

/**
 * Ход работы агента по сообщению: статусы задач и готовый ответ.
 *
 * Самый чувствительный из каналов — в событии едет текст ответа агента
 * (см. MessageTasksChanged::broadcastWith).
 */
Broadcast::channel('tasks.{chatId}', function (User $user, $chatId) {
    if (!$user->isUsable() || !$user->can('view chats')) {
        return false;
    }

    return AiChat::query()
        ->whereKey($chatId)
        ->where('company_id', $user->company_id)
        ->exists();
});

/** Виджеты дашборда изменились — холст перезапрашивает содержимое. */
Broadcast::channel('dashboard.{dashboardId}', function (User $user, $dashboardId) {
    if (!$user->isUsable() || !$user->can('view dashboards')) {
        return false;
    }

    return Dashboard::query()
        ->whereKey($dashboardId)
        ->where('company_id', $user->company_id)
        ->exists();
});

/**
 * Ход группировки таблиц источника.
 *
 * Право берём то же, что и на сам мастер подключения: в подписях шагов
 * едут имена таблиц клиента.
 */
Broadcast::channel('data_source.{dataSourceId}', function (User $user, $dataSourceId) {
    if (!$user->isUsable() || !$user->can('manage data sources')) {
        return false;
    }

    return DataSource::query()
        ->whereKey($dataSourceId)
        ->where('company_id', $user->company_id)
        ->exists();
});
