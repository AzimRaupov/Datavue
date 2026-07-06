<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use Illuminate\Http\Request;

class DashboardController extends Controller
{

    public function index(){
        $user = auth()->user();

        $dashboards = Dashboard::query()->where('company_id',$user->company->id)
        ->get();
        return response()->json($dashboards);
    }
    public function show($id){
        $dashboard = Dashboard::with([
            'widgets' => function ($query) {
                $query->select(
                    'id',
                    'dashboard_id', // обязательно для hasMany
                    'widget_id',
                    'title',
                    'position',
                    'status'
                );
            },
            'widgets.widget' => function ($query) {
                $query->select('id', 'name');
            }
        ])->find($id);
        return response()->json($dashboard);
    }
}
