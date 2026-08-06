<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
                        'title',
                        'position',
                        'status'
                        // orderBy('id') — не косметика: у виджетов может совпасть
                        // position, и без второго ключа MySQL волен возвращать их
                        // в разном порядке при каждом запросе.
                    )->orderBy('position')->orderBy('id');
                },
                'widgets.widget' => function ($query) {
                    $query->select('id', 'name');
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
