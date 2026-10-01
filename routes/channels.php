<?php

use App\Models\AiChat;
use App\Models\Dashboard;
use App\Models\DataSource;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return $user->isUsable() && (int) $user->id === (int) $id;
});

Broadcast::channel('tasks.{chatId}', function (User $user, $chatId) {
    if (!$user->isUsable() || !$user->can('view chats')) {
        return false;
    }

    return AiChat::query()
        ->whereKey($chatId)
        ->where('company_id', $user->company_id)
        ->exists();
});

Broadcast::channel('dashboard.{dashboardId}', function (User $user, $dashboardId) {
    if (!$user->isUsable() || !$user->can('view dashboards')) {
        return false;
    }

    return Dashboard::query()
        ->whereKey($dashboardId)
        ->where('company_id', $user->company_id)
        ->exists();
});

Broadcast::channel('data_source.{dataSourceId}', function (User $user, $dataSourceId) {
    if (!$user->isUsable() || !$user->can('manage data sources')) {
        return false;
    }

    return DataSource::query()
        ->whereKey($dataSourceId)
        ->where('company_id', $user->company_id)
        ->exists();
});
