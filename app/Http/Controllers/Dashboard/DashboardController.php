<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\WidgetType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $dashboards = Dashboard::query()
            ->where('company_id', $request->user()->company_id)
            ->latest('id')
            ->get();

        return response()->json($dashboards);
    }

    public function show(Request $request, $id)
    {
        // ВАЖНО: фильтр по company_id обязателен. Раньше здесь был Dashboard::find($id)
        // без проверки — любой авторизованный пользователь мог прочитать дашборд
        // чужой компании, просто подставив его id.
        $dashboard = Dashboard::query()
            ->where('company_id', $request->user()->company_id)
            ->with([
                'widgets' => function ($query) {
                    $query->select(
                        'id',
                        'dashboard_id', // обязательно для hasMany
                        'widget_id',
                        'widget_type_id',
                        'title',
                        'position',
                        'status',
                        // updated_at обязателен: по нему фронт понимает, какие
                        // виджеты реально изменились. Без него WidgetContainer
                        // сравнивал undefined с undefined, granular-обновление
                        // не срабатывало, и приходилось перезапрашивать данные
                        // сразу у ВСЕХ виджетов дашборда.
                        'updated_at'
                        // orderBy('id') — не косметика: у виджетов может совпасть
                        // position, и без второго ключа MySQL волен возвращать их
                        // в разном порядке при каждом запросе.
                    )->orderBy('position')->orderBy('id');
                },
                'widgets.widget' => function ($query) {
                    $query->select('id', 'name');
                },
                // Вариант отрисовки: фронт по options решает, рисовать круг или
                // кольцо, вертикальные столбцы или горизонтальные.
                'widgets.widget.types' => function ($query) {
                    $query->select('id', 'widget_id', 'name', 'options', 'is_default');
                },
                'widgets.widgetType' => function ($query) {
                    $query->select('id', 'widget_id', 'name', 'options');
                },
            ])
            ->findOrFail($id);

        return response()->json($dashboard);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'chat_id' => [
                'nullable',
                // Чат обязан принадлежать той же компании — иначе можно было бы
                // привязать дашборд к чужому чату.
                Rule::exists('ai_chats', 'id')->where('company_id', $user->company_id),
            ],
        ]);

        $dashboard = Dashboard::query()->create([
            'company_id' => $user->company_id,
            'chat_id' => $data['chat_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => 'empty',
        ]);

        return response()->json($dashboard, 201);
    }

    public function update(Request $request, $id)
    {
        $dashboard = $this->findForCompany($request, $id);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
        ]);

        $dashboard->fill($data)->save();

        return response()->json($dashboard);
    }

    /**
     * Ручная смена типа отрисовки у виджетов дашборда.
     *
     * Тип меняется ТОЛЬКО внутри семейства виджета: круг → кольцо,
     * вертикальные столбцы → горизонтальные. Семейство менять нельзя —
     * сгенерированный Python-код отдаёт данные в форме конкретного
     * семейства, и, например, таблица не нарисуется данными для круга.
     *
     * Сохранение пакетное: пользователь перебирает варианты на нескольких
     * виджетах сразу и жмёт «Сохранить» один раз.
     */
    public function updateWidgets(Request $request, $id)
    {
        $dashboard = $this->findForCompany($request, $id);

        $data = $request->validate([
            'widgets' => 'required|array|min:1',
            'widgets.*.id' => 'required|integer',
            'widgets.*.widget_type_id' => 'required|integer|exists:widget_types,id',
        ]);

        // Виджеты берём разом и только этого дашборда: чужой id в списке
        // не должен привести к правке чужого виджета.
        $widgets = DashboardWidget::query()
            ->where('dashboard_id', $dashboard->id)
            ->whereIn('id', collect($data['widgets'])->pluck('id'))
            ->get()
            ->keyBy('id');

        $types = WidgetType::query()
            ->whereIn('id', collect($data['widgets'])->pluck('widget_type_id'))
            ->get()
            ->keyBy('id');

        $updated = 0;

        DB::transaction(function () use ($data, $widgets, $types, &$updated) {
            foreach ($data['widgets'] as $row) {
                $widget = $widgets->get($row['id']);
                $type = $types->get($row['widget_type_id']);

                if (!$widget || !$type) {
                    continue;
                }

                // Главная проверка: тип обязан принадлежать тому же семейству.
                if ($type->widget_id !== $widget->widget_id) {
                    throw ValidationException::withMessages([
                        'widgets' => 'Тип отрисовки не подходит этому виджету.',
                    ]);
                }

                if ($widget->widget_type_id !== $type->id) {
                    $widget->widget_type_id = $type->id;
                    $widget->save();
                    $updated++;
                }
            }
        });

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'message' => $updated
                ? 'Изменения сохранены.'
                : 'Менять было нечего.',
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $dashboard = $this->findForCompany($request, $id);

        $dashboard->widgets()->delete();
        $dashboard->delete();

        return response()->json(['message' => 'Дашборд удалён.']);
    }

    private function findForCompany(Request $request, $id): Dashboard
    {
        return Dashboard::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);
    }
}
