<?php

namespace App\Helpers\Export;

use App\Helpers\DataSource\CodeTemplater;
use App\Models\DataSource;

/**
 * Собирает Python-скрипт выгрузки: рантайм платформы + main() от модели.
 *
 * Ключевое решение: запись файла пишем мы, а не модель. Модель отвечает только
 * за «что посчитать» — SQL и подготовку таблицы, — и заканчивает вызовом
 * save_result(). Всё остальное (openpyxl со стилями, reportlab с кириллическим
 * шрифтом, python-docx) — код платформы.
 *
 * Иначе каждый экспорт превращался бы в лотерею: модель по памяти пишет работу
 * с четырьмя разными библиотеками, промахивается мимо версии API, забывает про
 * шрифт с кириллицей в PDF — и пользователь получает файл с квадратами вместо
 * букв или падение на импорте. Здесь же путь до файла и формат вообще не в её
 * руках: она физически не может записать файл не туда.
 */
class ExportCodeTemplater
{
    private CodeTemplater $codeTemplater;

    public function __construct(
        private DataSource $dataSource,
        private string $format,
        private string $outputPath,
        private string $title,
    ) {
        $this->codeTemplater = new CodeTemplater($this->dataSource->id);
        $this->format = ExportFormat::normalize($this->format);
    }

    /**
     * Полный исполняемый скрипт: рантайм + main() модели + запуск.
     */
    public function assemble(string $mainBody): string
    {
        return implode("\n\n", [
            rtrim($this->runtime()),
            rtrim($this->normalizeMainBody($mainBody)),
            rtrim($this->footer()),
        ])."\n";
    }

    /**
     * Рантайм целиком: импорты, query(), query_df() и запись файла.
     */
    public function runtime(): string
    {
        return implode("\n\n", [
            rtrim($this->codeTemplater->getLibraries()),
            rtrim($this->constants()),
            rtrim($this->codeTemplater->getQueryTemplate(false)),
            rtrim($this->codeTemplater->getQueryDataFrameTemplate()),
            rtrim($this->exportRuntime()),
        ]);
    }

    /**
     * Что показать модели в промпте.
     *
     * Не рантайм целиком: двести строк работы с openpyxl и reportlab модели
     * не нужны, а место в контексте занимают то, которое нужнее схеме таблиц.
     * Показываем ровно контракт — что уже есть и как этим пользоваться.
     */
    public function runtimeSummary(): string
    {
        $template = <<<'PYTHON'
__LIBRARIES__


EXPORT_FORMAT = '__FORMAT__'
EXPORT_ROW_LIMIT = __ROW_LIMIT__


def query(sql_query, params=None):
    """Выполняет SQL и возвращает список кортежей (без имён колонок)."""


def query_df(sql_query, params=None):
    """Выполняет SQL и возвращает pandas.DataFrame с именами колонок из запроса.

    Это основной способ получить данные для файла: заголовки колонок в готовом
    документе берутся именно отсюда.
    """


def save_result(data, columns=None, title=None):
    """Записывает таблицу в файл нужного формата и печатает отчёт в stdout.

    data    — pandas.DataFrame (обычно результат query_df) либо список словарей
              или список кортежей;
    columns — имена колонок, если их нет в самих данных;
    title   — заголовок документа (используется в PDF, Word и как имя листа Excel).

    Путь и формат файла заданы платформой — указывать их не нужно и нельзя.
    Функция сама обрежет результат до EXPORT_ROW_LIMIT строк и сообщит об этом.
    """
PYTHON;

        return str_replace(
            ['__LIBRARIES__', '__FORMAT__', '__ROW_LIMIT__'],
            [
                rtrim($this->codeTemplater->getLibraries()),
                $this->format,
                (string) ExportFormat::rowLimit($this->format),
            ],
            $template
        );
    }

    private function constants(): string
    {
        $format = $this->pyString($this->format);
        $path = $this->pyString($this->outputPath);
        $title = $this->pyString($this->title);
        $delimiter = $this->pyString((string) config('exports.csv_delimiter', ';'));
        $rowLimit = ExportFormat::rowLimit($this->format);
        $previewRows = (int) config('exports.preview_rows', 10);

        $fontsRegular = $this->pyList((array) config('exports.pdf_fonts.regular', []));
        $fontsBold = $this->pyList((array) config('exports.pdf_fonts.bold', []));

        // os нужен рантайму экспорта (каталог файла, поиск шрифта), а в общих
        // библиотеках CodeTemplater его нет — виджетам он не требуется.
        return <<<PYTHON
import os
import re

EXPORT_FORMAT = {$format}
EXPORT_PATH = {$path}
EXPORT_TITLE = {$title}
EXPORT_ROW_LIMIT = {$rowLimit}
EXPORT_PREVIEW_ROWS = {$previewRows}
CSV_DELIMITER = {$delimiter}
PDF_FONTS_REGULAR = {$fontsRegular}
PDF_FONTS_BOLD = {$fontsBold}
PYTHON;
    }

    /**
     * Тело main() от модели: снимаем markdown-обёртку и лишний текст вокруг.
     */
    private function normalizeMainBody(string $mainBody): string
    {
        $mainBody = trim($mainBody);
        $mainBody = preg_replace('/^```(?:python)?\s*/i', '', $mainBody);
        $mainBody = preg_replace('/\s*```$/', '', $mainBody);
        $mainBody = trim($mainBody);

        if (!preg_match('/^\s*def\s+main\s*\(\s*\)\s*:/', $mainBody)
            && preg_match('/def\s+main\s*\(\s*\)\s*:.*/s', $mainBody, $matches)) {
            $mainBody = $matches[0];
        }

        // Табуляции ломают питоновские отступы вперемешку с пробелами.
        $mainBody = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $mainBody);

        // Модель иногда дописывает запуск, хотя её просили этого не делать.
        // Свой footer мы добавим сами, а два запуска main() означали бы две
        // записи файла и два отчёта в stdout.
        $mainBody = preg_replace('/\n\s*if\s+__name__\s*==\s*[\'"]__main__[\'"]\s*:.*$/s', '', $mainBody);

        return rtrim($mainBody);
    }

    private function footer(): string
    {
        return <<<'PYTHON'
if __name__ == "__main__":
    main()

    if not _SAVED:
        raise RuntimeError(
            'Функция main() завершилась, не вызвав save_result() — файл не создан.'
        )
PYTHON;
    }

    private function pyString(?string $value): string
    {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value);

        return "'".$escaped."'";
    }

    /**
     * @param  array<int, string>  $values
     */
    private function pyList(array $values): string
    {
        $items = array_map(fn ($value) => $this->pyString((string) $value), $values);

        return '['.implode(', ', $items).']';
    }

    /**
     * Запись файла: одна точка входа save_result() и по писателю на формат.
     */
    private function exportRuntime(): string
    {
        return <<<'PYTHON'
_SAVED = False

# Выгрузка только читает данные. Запрос пишет модель, а исполняется он под теми
# же правами, что и виджеты, — поэтому запрещающая проверка стоит прямо перед
# обращением к базе, а не только в тексте промпта.
_FORBIDDEN_SQL = re.compile(
    r'\b(insert|update|delete|drop|alter|create|truncate|replace|grant|revoke|'
    r'attach|detach|outfile|dumpfile|call|merge|upsert|vacuum|pragma)\b',
    re.IGNORECASE,
)


def _assert_read_only(sql_query):
    text = str(sql_query).strip().rstrip('; \t\n\r')

    if not re.match(r'^\s*(select|with)\b', text, re.IGNORECASE):
        raise ValueError('В выгрузке разрешены только запросы SELECT/WITH.')

    if ';' in text:
        raise ValueError('В выгрузке разрешён только один SQL-запрос без ";".')

    forbidden = _FORBIDDEN_SQL.search(text)

    if forbidden:
        raise ValueError(
            'Запрещённая операция в запросе: {0}.'.format(forbidden.group(0))
        )


_unchecked_query = query
_unchecked_query_df = query_df


def query(sql_query, params=None):
    _assert_read_only(sql_query)

    return _unchecked_query(sql_query, params)


def query_df(sql_query, params=None):
    _assert_read_only(sql_query)

    return _unchecked_query_df(sql_query, params)


def _is_missing(value):
    try:
        result = pd.isna(value)
    except (TypeError, ValueError):
        return False

    return bool(result) if isinstance(result, (bool, int)) else False


def _stringify(value):
    """Значение ячейки в виде строки — для PDF, Word и предпросмотра в чате."""
    if value is None or _is_missing(value):
        return ''

    if isinstance(value, Decimal):
        value = float(value)

    if isinstance(value, datetime):
        return value.strftime('%Y-%m-%d %H:%M:%S')

    if isinstance(value, date):
        return value.isoformat()

    if isinstance(value, float) and value.is_integer():
        return str(int(value))

    return str(value)


def _to_frame(data, columns=None):
    """Приводит что угодно табличное к DataFrame со строковыми именами колонок."""
    if isinstance(data, pd.DataFrame):
        frame = data.copy()
    elif isinstance(data, pd.Series):
        frame = data.to_frame()
    elif isinstance(data, dict):
        frame = pd.DataFrame(data)
    else:
        rows = list(data or [])

        if rows and isinstance(rows[0], dict):
            frame = pd.DataFrame(rows)
        elif columns:
            frame = pd.DataFrame(rows, columns=list(columns))
        else:
            frame = pd.DataFrame(rows)

    if columns and len(list(columns)) == len(frame.columns):
        frame.columns = [str(column) for column in columns]
    else:
        frame.columns = [str(column) for column in frame.columns]

    return frame


def _column_width(frame, column):
    header = len(str(column))

    if len(frame) == 0:
        return header

    longest = int(frame[column].map(lambda value: len(_stringify(value))).max() or 0)

    return max(header, longest)


def _sheet_name(title):
    name = (title or 'Данные').strip() or 'Данные'

    for bad in '[]:*?/\\':
        name = name.replace(bad, ' ')

    return name[:31]


def _escape_xml(text):
    return (
        text.replace('&', '&amp;')
        .replace('<', '&lt;')
        .replace('>', '&gt;')
    )


def _write_csv(frame, title):
    frame.to_csv(
        EXPORT_PATH,
        index=False,
        sep=CSV_DELIMITER,
        # utf-8-sig — иначе Excel открывает русские заголовки крокозябрами.
        encoding='utf-8-sig',
    )


def _write_xlsx(frame, title):
    from openpyxl.styles import Alignment, Font, PatternFill
    from openpyxl.utils import get_column_letter

    sheet = _sheet_name(title)

    with pd.ExcelWriter(EXPORT_PATH, engine='openpyxl') as writer:
        frame.to_excel(writer, index=False, sheet_name=sheet)
        worksheet = writer.sheets[sheet]

        for index, column in enumerate(frame.columns, start=1):
            width = min(max(_column_width(frame, column) + 2, 10), 60)
            worksheet.column_dimensions[get_column_letter(index)].width = width

        header_fill = PatternFill('solid', fgColor='F1F5F9')

        for cell in worksheet[1]:
            cell.font = Font(bold=True)
            cell.fill = header_fill
            cell.alignment = Alignment(vertical='center')

        if len(frame.columns):
            worksheet.freeze_panes = 'A2'
            worksheet.auto_filter.ref = worksheet.dimensions


def _pdf_fonts():
    """Регистрирует TTF с кириллицей: встроенные шрифты reportlab её не знают."""
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.ttfonts import TTFont

    regular = next((path for path in PDF_FONTS_REGULAR if os.path.exists(path)), None)
    bold = next((path for path in PDF_FONTS_BOLD if os.path.exists(path)), None)

    if not regular:
        return 'Helvetica', 'Helvetica-Bold'

    pdfmetrics.registerFont(TTFont('ExportFont', regular))

    if not bold:
        return 'ExportFont', 'ExportFont'

    pdfmetrics.registerFont(TTFont('ExportFontBold', bold))

    return 'ExportFont', 'ExportFontBold'


def _write_pdf(frame, title):
    from reportlab.lib import colors
    from reportlab.lib.pagesizes import A4, landscape
    from reportlab.lib.styles import ParagraphStyle
    from reportlab.lib.units import mm
    from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

    font, font_bold = _pdf_fonts()
    page_size = landscape(A4) if len(frame.columns) > 5 else A4
    margin = 12 * mm

    document = SimpleDocTemplate(
        EXPORT_PATH,
        pagesize=page_size,
        leftMargin=margin,
        rightMargin=margin,
        topMargin=margin,
        bottomMargin=margin,
        title=title or 'Export',
    )

    title_style = ParagraphStyle('title', fontName=font_bold, fontSize=14, leading=18)
    meta_style = ParagraphStyle('meta', fontName=font, fontSize=8, leading=11, textColor=colors.HexColor('#667085'))
    head_style = ParagraphStyle('head', fontName=font_bold, fontSize=8, leading=10, textColor=colors.white)
    cell_style = ParagraphStyle('cell', fontName=font, fontSize=8, leading=10)

    story = []

    if title:
        story.append(Paragraph(_escape_xml(title), title_style))
        story.append(Spacer(1, 3 * mm))

    story.append(Paragraph(
        'Строк: {0} · сформировано {1}'.format(len(frame), datetime.now().strftime('%d.%m.%Y %H:%M')),
        meta_style,
    ))
    story.append(Spacer(1, 4 * mm))

    if len(frame.columns):
        table_data = [[Paragraph(_escape_xml(str(column)), head_style) for column in frame.columns]]

        for row in frame.itertuples(index=False, name=None):
            table_data.append([Paragraph(_escape_xml(_stringify(value)), cell_style) for value in row])

        available = page_size[0] - 2 * margin
        weights = [max(_column_width(frame, column), 4) for column in frame.columns]
        total_weight = float(sum(weights)) or 1.0
        # Ширину делим пропорционально содержимому, но не даём одной колонке
        # с длинным текстом съесть страницу целиком.
        widths = [max(available * 0.05, available * (weight / total_weight)) for weight in weights]
        scale = available / float(sum(widths))
        widths = [width * scale for width in widths]

        table = Table(table_data, colWidths=widths, repeatRows=1)
        table.setStyle(TableStyle([
            ('BACKGROUND', (0, 0), (-1, 0), colors.HexColor('#334155')),
            ('GRID', (0, 0), (-1, -1), 0.4, colors.HexColor('#CBD5E1')),
            ('VALIGN', (0, 0), (-1, -1), 'TOP'),
            ('LEFTPADDING', (0, 0), (-1, -1), 4),
            ('RIGHTPADDING', (0, 0), (-1, -1), 4),
            ('TOPPADDING', (0, 0), (-1, -1), 3),
            ('BOTTOMPADDING', (0, 0), (-1, -1), 3),
            ('ROWBACKGROUNDS', (0, 1), (-1, -1), [colors.white, colors.HexColor('#F8FAFC')]),
        ]))

        story.append(table)

    document.build(story)


def _write_docx(frame, title):
    from docx import Document
    from docx.shared import Pt

    document = Document()

    if title:
        document.add_heading(title, level=1)

    document.add_paragraph(
        'Строк: {0} · сформировано {1}'.format(len(frame), datetime.now().strftime('%d.%m.%Y %H:%M'))
    )

    if len(frame.columns):
        table = document.add_table(rows=1, cols=len(frame.columns))
        table.style = 'Table Grid'

        header_cells = table.rows[0].cells

        for index, column in enumerate(frame.columns):
            header_cells[index].text = str(column)

            for paragraph in header_cells[index].paragraphs:
                for run in paragraph.runs:
                    run.font.bold = True
                    run.font.size = Pt(10)

        for row in frame.itertuples(index=False, name=None):
            cells = table.add_row().cells

            for index, value in enumerate(row):
                cells[index].text = _stringify(value)

    document.save(EXPORT_PATH)


_WRITERS = {
    'csv': _write_csv,
    'xlsx': _write_xlsx,
    'pdf': _write_pdf,
    'docx': _write_docx,
}


def save_result(data, columns=None, title=None):
    """Записывает подготовленную таблицу в файл и печатает отчёт для платформы."""
    global _SAVED

    frame = _to_frame(data, columns)

    total_rows = int(len(frame))
    truncated = total_rows > EXPORT_ROW_LIMIT

    if truncated:
        frame = frame.head(EXPORT_ROW_LIMIT)

    heading = (title or EXPORT_TITLE or '').strip()

    directory = os.path.dirname(EXPORT_PATH)

    if directory:
        os.makedirs(directory, exist_ok=True)

    writer = _WRITERS.get(EXPORT_FORMAT)

    if writer is None:
        raise ValueError('Неизвестный формат экспорта: {0}'.format(EXPORT_FORMAT))

    writer(frame, heading)

    preview = frame.head(EXPORT_PREVIEW_ROWS)

    _SAVED = True

    print(json.dumps({
        'ok': True,
        'file': EXPORT_PATH,
        'format': EXPORT_FORMAT,
        'title': heading,
        'rows': int(len(frame)),
        'total_rows': total_rows,
        'truncated': bool(truncated),
        'columns': [str(column) for column in frame.columns],
        'preview': [
            [_stringify(value) for value in row]
            for row in preview.itertuples(index=False, name=None)
        ],
    }, ensure_ascii=False, default=json_default))
PYTHON;
    }
}
