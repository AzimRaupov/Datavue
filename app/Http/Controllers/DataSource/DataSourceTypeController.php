<?php

namespace App\Http\Controllers\DataSource;

use App\Http\Controllers\Controller;
use App\Models\DataSourceType;

class DataSourceTypeController extends Controller
{
    /**
     * Список провайдеров для мастера подключения.
     *
     * Отдаём только активные и в заданном порядке: фронт рисует карточки
     * выбора прямо по этому ответу и ничего не знает про конкретные
     * провайдеры — форма выбирается по полю kind.
     */
    public function index()
    {
        $types = DataSourceType::query()
            ->active()
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'name', 'label', 'description', 'kind', 'icon', 'default_port'])
            ->map(fn (DataSourceType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'label' => $type->display_name,
                'description' => $type->description,
                'kind' => $type->kind,
                'icon' => $type->icon,
                'default_port' => $type->default_port,
            ]);

        return response()->json($types);
    }
}
