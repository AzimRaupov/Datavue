import api from '../api.js';

/**
 * Виды отрисовки, доступные виджету (счётчик как число/полоса/круг и т.п.).
 * Общая логика для конструктора (WorkspacePage) и read-only просмотра
 * (DashboardWidgetsView) — оба дают сменить вид без входа в конструктор.
 */
export function typesOf(widget) {
    return widget?.widget?.types ?? [];
}

export function currentTypeId(widget) {
    return widget.widget_type_id ?? widget.widget_type?.id ?? null;
}

/**
 * Смена вида сохраняется сразу: вместе с видом сервер пересобирает запрос
 * виджета (счётчику с полосой выполнения нужен процент, пузырьковой —
 * размер точки), и держать половину дашборда в несохранённом состоянии
 * незачем.
 */
export async function changeWidgetType(dashboardId, widget, typeId) {
    const { data } = await api.patch(
        `/dashboards/${dashboardId}/widgets/${widget.id}`,
        { widget_type_id: Number(typeId) }
    );

    return data;
}
