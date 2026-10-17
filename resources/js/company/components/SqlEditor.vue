<script setup>
import { ref, shallowRef, computed, watch, onMounted, onBeforeUnmount } from "vue";

/**
 * Мини-редактор кода на CodeMirror 6: подсветка SQL/Python и подсказки по
 * таблицам и колонкам источника. Заменяет голый <textarea> там, где человек
 * пишет запрос или код виджета/алерта.
 *
 * CodeMirror подгружается динамически: он нужен только на экранах с кодом,
 * и тянуть его в основной бандл (в том числе мобильный) незачем. Пока он
 * грузится, место занимает пустая рамка нужной высоты — страница не прыгает.
 *
 * Схема — тот же массив, что отдают эндпоинты схемы:
 * [{ name, columns: [{ name, type }] }].
 */
const props = defineProps({
    modelValue: { type: String, default: "" },
    // "sql" | "python"
    language: { type: String, default: "sql" },
    // Диалект для подсказок и кавычек идентификаторов:
    // duckdb | postgres | mysql | sqlite. DuckDB и Postgres пишутся одинаково.
    dialect: { type: String, default: "duckdb" },
    schema: { type: Array, default: () => [] },
    rows: { type: Number, default: 6 },
    // Выше этого числа строк редактор не растёт, а прокручивается.
    maxRows: { type: Number, default: 0 },
    disabled: { type: Boolean, default: false },
    placeholder: { type: String, default: "" },
    ariaLabel: { type: String, default: "" },
});

const emit = defineEmits(["update:modelValue", "edit", "run"]);

const LINE_HEIGHT = 20;
const VERTICAL_PADDING = 16;

const host = ref(null);
const ready = ref(false);

const view = shallowRef(null);
const cm = shallowRef(null);
const compartments = shallowRef(null);

const minHeight = computed(() => `${props.rows * LINE_HEIGHT + VERTICAL_PADDING}px`);

const maxHeight = computed(() =>
    props.maxRows > 0 ? `${props.maxRows * LINE_HEIGHT + VERTICAL_PADDING}px` : null
);

/**
 * Схема в формате lang-sql: { таблица: [колонки] }. Колонка несёт тип в
 * подписи — по нему видно, что подставляешь в SUM() или в дату.
 */
function sqlSchema() {
    const result = {};

    for (const table of props.schema ?? []) {
        if (!table?.name) continue;

        result[table.name] = (table.columns ?? []).map((column) => ({
            label: column.name,
            type: "property",
            detail: column.type ?? undefined,
        }));
    }

    return result;
}

function sqlDialect(lib) {
    const { PostgreSQL, MySQL, SQLite, StandardSQL } = lib.sqlLang;

    switch (props.dialect) {
        case "mysql":
            return MySQL;
        case "sqlite":
            return SQLite;
        case "postgres":
        case "duckdb":
            return PostgreSQL;
        default:
            return StandardSQL;
    }
}

const SQL_WORDS = [
    "SELECT", "FROM", "WHERE", "GROUP BY", "ORDER BY", "LIMIT", "JOIN", "LEFT JOIN",
    "ON", "AND", "OR", "AS", "COUNT", "SUM", "AVG", "MIN", "MAX", "DISTINCT", "WITH",
];

/**
 * Подсказки внутри строк Python — там живёт SQL: query("SELECT ... FROM ...").
 * После «таблица.» предлагаем колонки этой таблицы, в остальных местах строки —
 * таблицы, колонки и пару ключевых слов.
 */
function pythonStringHints(lib) {
    const tables = (props.schema ?? []).filter((table) => table?.name);

    return (context) => {
        const node = lib.language.syntaxTree(context.state).resolveInner(context.pos, -1);

        if (!node || !/String/.test(node.name)) return null;

        const word = context.matchBefore(/[\p{L}\p{N}_.]*/u);

        if (!word || (word.from === word.to && !context.explicit)) return null;

        const dot = word.text.lastIndexOf(".");

        if (dot >= 0) {
            const table = tables.find((candidate) => candidate.name === word.text.slice(0, dot));

            if (!table) return null;

            return {
                from: word.from + dot + 1,
                options: (table.columns ?? []).map((column) => ({
                    label: column.name,
                    type: "property",
                    detail: column.type ?? undefined,
                })),
            };
        }

        const options = [
            ...tables.map((table) => ({ label: table.name, type: "class", detail: "таблица", boost: 2 })),
            ...tables.flatMap((table) =>
                (table.columns ?? []).map((column) => ({
                    label: column.name,
                    type: "property",
                    detail: `${table.name}${column.type ? ` · ${column.type}` : ""}`,
                }))
            ),
            ...SQL_WORDS.map((label) => ({ label, type: "keyword", boost: -1 })),
        ];

        return { from: word.from, options, validFor: /^[\p{L}\p{N}_]*$/u };
    };
}

// Заготовки для Python-виджетов: то, что код получает готовым из шаблона.
function pythonHints(lib) {
    const options = [
        { label: "query", type: "function", detail: "(sql, params=None)", info: "Запрос к источнику данных" },
        { label: "main", type: "function", detail: "()" },
        { label: "json.dumps", type: "function", detail: "(obj)" },
        { label: "print", type: "function", detail: "(...)" },
    ];

    const names = lib.autocomplete.completeFromList(options);
    const strings = pythonStringHints(lib);

    return (context) => strings(context) ?? names(context);
}

function languageExtension(lib) {
    if (props.language === "python") {
        return [
            lib.pythonLang.python(),
            lib.pythonLang.pythonLanguage.data.of({ autocomplete: pythonHints(lib) }),
        ];
    }

    return lib.sqlLang.sql({
        dialect: sqlDialect(lib),
        schema: sqlSchema(),
        upperCaseKeywords: true,
    });
}

async function loadLibs() {
    const [state, viewMod, commands, language, sqlLang, pythonLang, autocomplete, highlight] =
        await Promise.all([
            import("@codemirror/state"),
            import("@codemirror/view"),
            import("@codemirror/commands"),
            import("@codemirror/language"),
            import("@codemirror/lang-sql"),
            import("@codemirror/lang-python"),
            import("@codemirror/autocomplete"),
            import("@lezer/highlight"),
        ]);

    return { state, view: viewMod, commands, language, sqlLang, pythonLang, autocomplete, highlight };
}

// Цвета — переменные из стиля ниже: тема переключает их одним атрибутом,
// а не пересборкой редактора.
function highlightStyle(lib) {
    const t = lib.highlight.tags;

    return lib.language.HighlightStyle.define([
        { tag: [t.keyword, t.operatorKeyword, t.modifier], color: "var(--sqle-keyword)", fontWeight: "600" },
        { tag: [t.string, t.special(t.string)], color: "var(--sqle-string)" },
        { tag: [t.number, t.bool, t.null], color: "var(--sqle-number)" },
        { tag: [t.comment, t.lineComment, t.blockComment], color: "var(--sqle-comment)", fontStyle: "italic" },
        { tag: [t.function(t.variableName), t.standard(t.name)], color: "var(--sqle-function)" },
        { tag: [t.typeName, t.className], color: "var(--sqle-type)" },
        { tag: [t.operator, t.punctuation, t.separator], color: "var(--sqle-punctuation)" },
        { tag: [t.propertyName, t.definition(t.variableName)], color: "var(--sqle-name)" },
    ]);
}

function theme(lib) {
    return lib.view.EditorView.theme({
        "&": {
            fontSize: "13px",
            backgroundColor: "transparent",
            color: "var(--sqle-text)",
        },
        "&.cm-focused": { outline: "none" },
        ".cm-scroller": {
            fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
            lineHeight: `${LINE_HEIGHT}px`,
            overflow: "auto",
        },
        ".cm-content": { padding: "8px 0", caretColor: "var(--sqle-text)" },
        ".cm-gutters": {
            backgroundColor: "var(--sqle-gutter-bg)",
            color: "var(--sqle-gutter)",
            border: "none",
            borderRight: "1px solid var(--sqle-border)",
        },
        ".cm-activeLine": { backgroundColor: "var(--sqle-active-line)" },
        ".cm-activeLineGutter": { backgroundColor: "var(--sqle-active-line)", color: "var(--sqle-text)" },
        "&.cm-focused .cm-selectionBackground, .cm-selectionBackground, ::selection": {
            backgroundColor: "var(--sqle-selection)",
        },
        ".cm-placeholder": { color: "var(--sqle-gutter)" },
        ".cm-matchingBracket": { backgroundColor: "var(--sqle-selection)", outline: "none" },
        ".cm-tooltip": {
            border: "1px solid var(--sqle-border)",
            backgroundColor: "var(--sqle-popup-bg)",
            color: "var(--sqle-text)",
            borderRadius: "6px",
            boxShadow: "0 6px 18px rgba(0, 0, 0, 0.12)",
        },
        ".cm-tooltip-autocomplete > ul": {
            fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace',
            fontSize: "12.5px",
            maxHeight: "260px",
        },
        ".cm-tooltip-autocomplete ul li[aria-selected]": {
            backgroundColor: "var(--sqle-accent)",
            color: "#fff",
        },
        ".cm-completionDetail": { color: "var(--sqle-gutter)", fontStyle: "normal", marginLeft: "0.8em" },
        ".cm-tooltip-autocomplete ul li[aria-selected] .cm-completionDetail": { color: "rgba(255,255,255,0.8)" },
    });
}

function sizing(lib) {
    return lib.view.EditorView.theme({
        ".cm-scroller": {
            minHeight: minHeight.value,
            ...(maxHeight.value ? { maxHeight: maxHeight.value } : {}),
        },
    });
}

function lockExtensions(lib) {
    return [
        lib.state.EditorState.readOnly.of(props.disabled),
        lib.view.EditorView.editable.of(!props.disabled),
    ];
}

async function mountEditor() {
    const lib = await loadLibs();

    // Компонент мог уйти, пока грузились модули.
    if (!host.value) return;

    const { EditorState, Compartment, Prec } = lib.state;
    const { EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter, drawSelection, placeholder } = lib.view;

    const parts = {
        language: new Compartment(),
        sizing: new Compartment(),
        lock: new Compartment(),
    };

    const runKeys = Prec.highest(
        keymap.of([
            {
                key: "Mod-Enter",
                run: () => {
                    emit("run");
                    return true;
                },
            },
        ])
    );

    const attributes = {};
    if (props.ariaLabel) attributes["aria-label"] = props.ariaLabel;
    attributes.spellcheck = "false";

    const state = EditorState.create({
        doc: props.modelValue ?? "",
        extensions: [
            lineNumbers(),
            highlightActiveLine(),
            highlightActiveLineGutter(),
            drawSelection(),
            lib.commands.history(),
            lib.language.indentOnInput(),
            lib.language.bracketMatching(),
            lib.autocomplete.closeBrackets(),
            lib.autocomplete.autocompletion({ activateOnTyping: true, icons: false }),
            lib.language.syntaxHighlighting(highlightStyle(lib)),
            lib.state.EditorState.tabSize.of(4),
            runKeys,
            keymap.of([
                ...lib.autocomplete.closeBracketsKeymap,
                ...lib.commands.defaultKeymap,
                ...lib.commands.historyKeymap,
                ...lib.autocomplete.completionKeymap,
                lib.commands.indentWithTab,
            ]),
            theme(lib),
            placeholder(props.placeholder),
            EditorView.contentAttributes.of(attributes),
            parts.language.of(languageExtension(lib)),
            parts.sizing.of(sizing(lib)),
            parts.lock.of(lockExtensions(lib)),
            EditorView.updateListener.of((update) => {
                if (!update.docChanged) return;

                const value = update.state.doc.toString();

                emit("update:modelValue", value);

                // Только правки пользователя: программная подстановка значения
                // (watch ниже) отметкой «человек редактировал» быть не должна.
                if (update.transactions.some((tr) => tr.isUserEvent("input") || tr.isUserEvent("delete") || tr.isUserEvent("undo") || tr.isUserEvent("redo") || tr.isUserEvent("move") )) {
                    emit("edit", value);
                }
            }),
        ],
    });

    cm.value = lib;
    compartments.value = parts;
    view.value = new EditorView({ state, parent: host.value });
    ready.value = true;
}

// Значение изменили снаружи (загрузили виджет, подставили собранный запрос).
watch(
    () => props.modelValue,
    (value) => {
        const editor = view.value;

        if (!editor) return;

        const next = value ?? "";

        if (editor.state.doc.toString() === next) return;

        editor.dispatch({
            changes: { from: 0, to: editor.state.doc.length, insert: next },
        });
    }
);

// Схема пришла позже редактора или сменился источник — подсказки обновляем
// на месте, не пересоздавая редактор и не теряя курсор.
watch(
    () => [props.schema, props.language, props.dialect],
    () => {
        const editor = view.value;

        if (!editor) return;

        editor.dispatch({
            effects: compartments.value.language.reconfigure(languageExtension(cm.value)),
        });
    },
    { deep: false }
);

watch(
    () => [props.rows, props.maxRows],
    () => {
        view.value?.dispatch({
            effects: compartments.value.sizing.reconfigure(sizing(cm.value)),
        });
    }
);

watch(
    () => props.disabled,
    () => {
        view.value?.dispatch({
            effects: compartments.value.lock.reconfigure(lockExtensions(cm.value)),
        });
    }
);

onMounted(mountEditor);

onBeforeUnmount(() => {
    view.value?.destroy();
    view.value = null;
});

defineExpose({
    focus: () => view.value?.focus(),

    // Вставка в позицию курсора (или поверх выделения): так в запрос
    // подставляют имя таблицы или колонки из браузера схемы.
    insert(text) {
        const editor = view.value;

        if (!editor) return;

        const { from, to } = editor.state.selection.main;

        editor.dispatch({
            changes: { from, to, insert: text },
            selection: { anchor: from + text.length },
            userEvent: "input",
        });

        editor.focus();
    },
});
</script>

<template>
    <div class="sql-editor form-control p-0"
         :class="{ 'sql-editor-disabled': disabled, 'sql-editor-loading': !ready }"
         :style="{ '--sqle-min-height': minHeight }">
        <div ref="host" class="sql-editor-host"></div>
    </div>
</template>

<style>
/*
 * Цвета редактора — переменные, а не значения внутри JS-темы: так подсветка
 * и подсказки следуют теме Tabler (data-bs-theme) без пересоздания редактора.
 * Стиль не scoped: поповер подсказок CodeMirror рисует вне корня компонента.
 */
.sql-editor {
    --sqle-text: #1f2937;
    --sqle-keyword: #7c3aed;
    --sqle-string: #047857;
    --sqle-number: #b45309;
    --sqle-comment: #6b7280;
    --sqle-function: #1d4ed8;
    --sqle-type: #0e7490;
    --sqle-punctuation: #64748b;
    --sqle-name: #1f2937;
    --sqle-gutter: #94a3b8;
    --sqle-gutter-bg: #f8fafc;
    --sqle-border: #e2e8f0;
    --sqle-active-line: rgba(100, 116, 139, 0.08);
    --sqle-selection: rgba(32, 107, 196, 0.22);
    --sqle-popup-bg: #fff;
    --sqle-accent: var(--tblr-primary, #206bc4);
}

[data-bs-theme="dark"] .sql-editor,
[data-bs-theme="dark"] .cm-tooltip {
    --sqle-text: #e5e7eb;
    --sqle-keyword: #c4b5fd;
    --sqle-string: #6ee7b7;
    --sqle-number: #fbbf24;
    --sqle-comment: #94a3b8;
    --sqle-function: #93c5fd;
    --sqle-type: #67e8f9;
    --sqle-punctuation: #94a3b8;
    --sqle-name: #e5e7eb;
    --sqle-gutter: #64748b;
    --sqle-gutter-bg: rgba(255, 255, 255, 0.03);
    --sqle-border: rgba(255, 255, 255, 0.12);
    --sqle-active-line: rgba(255, 255, 255, 0.05);
    --sqle-selection: rgba(96, 165, 250, 0.3);
    --sqle-popup-bg: #1f2937;
}

/* Поповер подсказок живёт в body, вне .sql-editor, — ему переменные нужны отдельно. */
.cm-tooltip {
    --sqle-text: #1f2937;
    --sqle-gutter: #94a3b8;
    --sqle-border: #e2e8f0;
    --sqle-popup-bg: #fff;
    --sqle-accent: var(--tblr-primary, #206bc4);
}

.sql-editor {
    overflow: hidden;
    height: auto;
}

.sql-editor:focus-within {
    border-color: var(--tblr-primary, #206bc4);
    box-shadow: 0 0 0 0.25rem rgba(var(--tblr-primary-rgb, 32, 107, 196), 0.25);
}

.sql-editor-disabled {
    background-color: var(--tblr-bg-surface-secondary, #f6f8fb);
    opacity: 0.75;
}

.sql-editor-loading .sql-editor-host {
    min-height: var(--sqle-min-height);
}
</style>
