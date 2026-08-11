<script setup>
import { computed } from "vue";

/**
 * Рендер markdown-ответов AI-агента.
 *
 * Своя реализация вместо библиотеки — потому что нужен ровно тот набор
 * разметки, который агент реально использует (см. ФОРМАТ ОТВЕТА в
 * ChatAgentAi::buildPrompt), и потому что текст ответа содержит данные
 * пользователя из его же базы.
 *
 * ВАЖНО: исходный текст экранируется ПЕРВЫМ делом, и только потом в нём
 * появляются теги. Поэтому в v-html не может попасть чужой HTML —
 * ни из ответа модели, ни из значений, которые она вытащила из базы.
 */
const props = defineProps({
    text: {
        type: String,
        default: "",
    },
});

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

/** Инлайн-разметка: код, жирный, курсив, ссылки. Работает уже по экранированному тексту. */
function renderInline(text) {
    return text
        .replace(/`([^`]+)`/g, '<code class="md-code">$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>")
        .replace(/(^|[^*])\*([^*\n]+)\*/g, "$1<em>$2</em>")
        .replace(/\b_([^_\n]+)_\b/g, "<em>$1</em>")
        // Ссылку собираем сами: href берём только из http(s), поэтому javascript: сюда не попадёт.
        .replace(
            /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
            '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>'
        );
}

function splitRow(line) {
    return line
        .replace(/^\||\|$/g, "")
        .split("|")
        .map(cell => cell.trim());
}

const isTableDivider = line => /^\|?[\s:-]*-[\s|:-]*\|?$/.test(line) && line.includes("-");

const html = computed(() => {
    const source = (props.text ?? "").trim();

    if (!source) return "";

    const lines = escapeHtml(source).split("\n");
    const out = [];

    let listType = null;   // 'ul' | 'ol' | null
    let paragraph = [];
    let codeLines = null;  // накопитель для ``` блока

    const closeList = () => {
        if (listType) {
            out.push(`</${listType}>`);
            listType = null;
        }
    };

    const closeParagraph = () => {
        if (paragraph.length) {
            out.push(`<p>${renderInline(paragraph.join("<br>"))}</p>`);
            paragraph = [];
        }
    };

    const openList = type => {
        if (listType !== type) {
            closeList();
            out.push(`<${type}>`);
            listType = type;
        }
    };

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const trimmed = line.trim();

        // Блок кода ```
        if (trimmed.startsWith("```")) {
            if (codeLines) {
                out.push(`<pre class="md-pre"><code>${codeLines.join("\n")}</code></pre>`);
                codeLines = null;
            } else {
                closeParagraph();
                closeList();
                codeLines = [];
            }
            continue;
        }

        if (codeLines) {
            codeLines.push(line);
            continue;
        }

        if (!trimmed) {
            closeParagraph();
            closeList();
            continue;
        }

        // Таблица: строка с | и разделителем на следующей строке
        if (trimmed.includes("|") && isTableDivider((lines[i + 1] ?? "").trim())) {
            closeParagraph();
            closeList();

            const head = splitRow(trimmed);
            const rows = [];

            i += 2;
            while (i < lines.length && lines[i].trim().includes("|")) {
                rows.push(splitRow(lines[i].trim()));
                i++;
            }
            i--;

            out.push(
                '<div class="md-table-wrap"><table class="md-table">' +
                `<thead><tr>${head.map(c => `<th>${renderInline(c)}</th>`).join("")}</tr></thead>` +
                `<tbody>${rows
                    .map(r => `<tr>${r.map(c => `<td>${renderInline(c)}</td>`).join("")}</tr>`)
                    .join("")}</tbody>` +
                "</table></div>"
            );
            continue;
        }

        // Заголовки
        const heading = trimmed.match(/^(#{1,4})\s+(.*)$/);
        if (heading) {
            closeParagraph();
            closeList();
            const level = Math.min(heading[1].length + 2, 6);
            out.push(`<h${level} class="md-heading">${renderInline(heading[2])}</h${level}>`);
            continue;
        }

        // Горизонтальная линия
        if (/^(-{3,}|\*{3,}|_{3,})$/.test(trimmed)) {
            closeParagraph();
            closeList();
            out.push("<hr>");
            continue;
        }

        // Цитата
        const quote = trimmed.match(/^&gt;\s?(.*)$/);
        if (quote) {
            closeParagraph();
            closeList();
            out.push(`<blockquote class="md-quote">${renderInline(quote[1])}</blockquote>`);
            continue;
        }

        // Нумерованный список
        const ordered = trimmed.match(/^\d+[.)]\s+(.*)$/);
        if (ordered) {
            closeParagraph();
            openList("ol");
            out.push(`<li>${renderInline(ordered[1])}</li>`);
            continue;
        }

        // Маркированный список
        const unordered = trimmed.match(/^[-*+]\s+(.*)$/);
        if (unordered) {
            closeParagraph();
            openList("ul");
            out.push(`<li>${renderInline(unordered[1])}</li>`);
            continue;
        }

        paragraph.push(trimmed);
    }

    if (codeLines) {
        out.push(`<pre class="md-pre"><code>${codeLines.join("\n")}</code></pre>`);
    }

    closeParagraph();
    closeList();

    return out.join("");
});
</script>

<template>
    <div class="md-body" v-html="html"></div>
</template>

<style scoped>
.md-body :deep(p) {
    margin: 0 0 0.5rem;
}

.md-body :deep(p:last-child),
.md-body :deep(ul:last-child),
.md-body :deep(ol:last-child) {
    margin-bottom: 0;
}

.md-body :deep(ul),
.md-body :deep(ol) {
    margin: 0 0 0.5rem;
    padding-left: 1.25rem;
}

.md-body :deep(li) {
    margin-bottom: 0.15rem;
}

.md-body :deep(.md-heading) {
    font-size: 0.95rem;
    font-weight: 600;
    margin: 0.75rem 0 0.35rem;
}

.md-body :deep(.md-heading:first-child) {
    margin-top: 0;
}

.md-body :deep(.md-code) {
    background: rgba(0, 0, 0, 0.06);
    border-radius: 3px;
    padding: 0.05rem 0.3rem;
    font-size: 0.85em;
}

.md-body :deep(.md-pre) {
    background: rgba(0, 0, 0, 0.06);
    border-radius: 4px;
    padding: 0.5rem 0.65rem;
    margin: 0 0 0.5rem;
    overflow-x: auto;
}

.md-body :deep(.md-pre code) {
    background: none;
    padding: 0;
    font-size: 0.85em;
}

.md-body :deep(.md-quote) {
    border-left: 3px solid rgba(0, 0, 0, 0.15);
    margin: 0 0 0.5rem;
    padding: 0.1rem 0 0.1rem 0.6rem;
    color: #626976;
}

/* Таблица может быть шире пузыря чата — скроллим её саму, а не всё сообщение */
.md-body :deep(.md-table-wrap) {
    overflow-x: auto;
    margin: 0 0 0.5rem;
}

.md-body :deep(.md-table) {
    border-collapse: collapse;
    font-size: 0.85em;
    width: 100%;
}

.md-body :deep(.md-table th),
.md-body :deep(.md-table td) {
    border: 1px solid rgba(0, 0, 0, 0.12);
    padding: 0.25rem 0.45rem;
    text-align: left;
    white-space: nowrap;
}

.md-body :deep(.md-table th) {
    font-weight: 600;
    background: rgba(0, 0, 0, 0.04);
}

.md-body :deep(hr) {
    border: 0;
    border-top: 1px solid rgba(0, 0, 0, 0.12);
    margin: 0.6rem 0;
}

.md-body :deep(a) {
    text-decoration: underline;
}
</style>
