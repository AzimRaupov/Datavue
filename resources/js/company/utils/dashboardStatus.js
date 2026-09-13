export const DASHBOARD_STATUS = {
    empty: { key: "workspacePage.dashboard_status.empty", cls: "bg-secondary-lt" },
    generating_scheme: { key: "workspacePage.dashboard_status.generating_scheme", cls: "bg-azure-lt" },
    generating_widgets: { key: "workspacePage.dashboard_status.generating_widgets", cls: "bg-azure-lt" },
    reviewing: { key: "workspacePage.dashboard_status.reviewing", cls: "bg-azure-lt" },
    completed: { key: "workspacePage.dashboard_status.completed", cls: "bg-green-lt" },
    failed: { key: "workspacePage.dashboard_status.failed", cls: "bg-red-lt" },
};

/**
 * Бейдж статуса дашборда — общий для WorkspacePage (аналитик/админ)
 * и DirectorDashboardViewer (директор): статусы дашборда одни и те же
 * независимо от того, кто на него смотрит.
 */
export function dashboardStatusInfo(status, t) {
    const entry = DASHBOARD_STATUS[status];

    return entry ? { text: t(entry.key), cls: entry.cls } : { text: status ?? "", cls: "bg-secondary-lt" };
}
