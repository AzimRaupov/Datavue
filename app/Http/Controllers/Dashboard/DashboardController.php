<?php

namespace App\Http\Controllers\Dashboard;

use App\Helpers\Widget\ManualWidgetAuthor;
use App\Http\Controllers\Controller;
use App\Models\AiChat;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\DataSource;
use App\Models\WidgetType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    /**
     * Список дашбордов компании.
     *
     * Отдаёт то, из чего собирается карточка в списке: сколько в дашборде
     * виджетов, по какому источнику он считает и из какого чата вырос.
     * Раньше приходило только имя со статусом, и список нельзя было
     * показать иначе как строчкой текста.
     */
    public function index(Request $request)
    {
        $dashboards = Dashboard::query()
            ->where('company_id', $request->user()->company_id)
            ->withCount('widgets')
            ->with([
                'dataSource:id,name',
                'chat:id,title',
            ])
            ->latest('id')
            ->get();

        $this->fillSourcesFromChats($dashboards);

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
            // Источник обязателен, когда дашборд собирают руками: чата у него
            // нет, и виджетам иначе не по чему считать.
            'data_source_id' => [
                'required_without:chat_id',
                'nullable',
                Rule::exists('data_sources', 'id')->where('company_id', $user->company_id),
            ],
        ]);

        $dashboard = Dashboard::query()->create([
            'company_id' => $user->company_id,
            'created_by' => $user->id,
            'chat_id' => $data['chat_id'] ?? null,
            'data_source_id' => $data['data_source_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => 'empty',
            // Через этот метод дашборд заводит человек: пайплайн ИИ создаёт
            // свои дашборды напрямую в DashboardGenerator.
            'origin' => Dashboard::ORIGIN_MANUAL,
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

    public function destroy(Request $request, $id, ManualWidgetAuthor $author)
    {
        $dashboard = $this->findForCompany($request, $id);

        // Файлы со скриптами удаляются вместе со строками: раньше они
        // оставались в storage навсегда, хотя ссылаться на них было уже некому.
        foreach ($dashboard->widgets()->get() as $widget) {
            $widget->setRelation('dashboard', $dashboard);
            $author->deleteFiles($widget);
        }

        $dashboard->widgets()->delete();
        $dashboard->delete();

        return response()->json(['message' => 'Дашборд удалён.']);
    }

    /**
     * Подставляет источник дашбордам, у которых он лежит на чате.
     *
     * Для списка разница между «источник на дашборде» и «источник на чате»
     * значения не имеет — показать нужно одно и то же название. Делается это
     * двумя запросами на весь список, а не обращением к базе на каждую строку.
     */
    private function fillSourcesFromChats($dashboards): void
    {
        $chatIds = $dashboards
            ->whereNull('data_source_id')
            ->pluck('chat_id')
            ->filter()
            ->unique();

        if ($chatIds->isEmpty()) {
            return;
        }

        // chat_id => data_source_id
        $sourceIdByChat = AiChat::query()
            ->whereIn('id', $chatIds)
            ->pluck('data_source_id', 'id');

        // Только id и name: в список не должны попадать хост, база и логин
        // подключения — это детали, которые видно в разделе источников.
        $sources = DataSource::query()
            ->whereIn('id', $sourceIdByChat->filter()->unique()->values())
            ->get(['id', 'name'])
            ->keyBy('id');

        foreach ($dashboards as $dashboard) {
            if ($dashboard->data_source_id || !$dashboard->chat_id) {
                continue;
            }

            $source = $sources->get($sourceIdByChat->get($dashboard->chat_id));

            if ($source) {
                $dashboard->setRelation('dataSource', $source);
            }
        }
    }

    private function findForCompany(Request $request, $id): Dashboard
    {
        return Dashboard::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($id);
    }
}
