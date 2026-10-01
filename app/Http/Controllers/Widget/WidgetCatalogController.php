<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Widget;
use App\Models\WidgetType;

class WidgetCatalogController extends Controller
{

    public function index(\Illuminate\Http\Request $request)
    {
        $widgets = Widget::query()
            ->with('types')
            ->when($request->boolean('ai_only'), fn ($query) => $query->where('is_ai_selectable', true))
            ->orderBy('id')
            ->get();

        return response()->json(
            $widgets->map(fn (Widget $widget) => [

                'id' => $widget->id,
                'name' => $widget->name,
                'description' => $widget->description,
                'scheme' => $widget->scheme,
                'scheme_description' => $widget->scheme_description,
                'is_ai_selectable' => $widget->is_ai_selectable,
                'types' => $widget->types->map(fn (WidgetType $type) => [
                    'id' => $type->id,
                    'widget_id' => $type->widget_id,
                    'name' => $type->name,
                    'title' => $type->title,
                    'description' => $type->description,
                    'options' => $type->options ?? [],
                    'is_default' => $type->is_default,
                    'is_ai_selectable' => $type->is_ai_selectable,
                    'scheme' => $type->scheme,
                ])->values(),
            ])->values()
        );
    }
}
