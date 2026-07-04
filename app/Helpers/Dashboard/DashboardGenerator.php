<?php

namespace App\Helpers\Dashboard;

use App\Helpers\Ai\AIService;
use App\Helpers\DuckDB;
use App\Helpers\PythonRunner;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Widget;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DashboardGenerator
{
    public $chat;

    public $message;

    public $widgets;

    public $storage;

    public $dashboard;

    public $tables;
    public $duckdb;
    public $dbSchema;

    public function __construct($chat_id, $message_id)
    {
        $this->chat = AiChat::query()->with('user','extractedData')->find($chat_id);
        $this->message = AiChatMessage::query()->find($message_id);

        $this->storage = storage_path(
            'app/company/'.
            $this->chat->company_id.
            '/chats/'.
            $this->chat->id
        );

        $this->duckdb = new DuckDB($this->chat->extractedData->data_path);

        $this->widgets = Widget::all();
        $this->dashboard = Dashboard::query()->create(
            [
                'chat_id' => $this->chat->id,
                'company_id' => $this->chat->user->company_id,
                'name' => 'sas',
                'status' => 'generating',
            ]
        );
        $this->fetchSchemaDb();

    }
    public function fetchSchemaDb()
    {
        $this->tables = $this->duckdb->run("SHOW TABLES;");

        $dbSchema = [];

        foreach ($this->tables as $table) {
            $tableName = $table['name'] ?? $table['table_name'] ?? null;

            if ($tableName) {
                $rawColumns = $this->duckdb->run("DESCRIBE " . $tableName . ";");

                $tableColumns = [];

                foreach ($rawColumns as $column) {
                    $columnName = $column['column_name'] ?? $column['Field'] ?? null;

                    if ($columnName) {
                        $tableColumns[$columnName] = [
                            'type' => $column['column_type'] ?? $column['Type'] ?? 'unknown',
                            'nullable' => $column['null'] ?? $column['Null'] ?? 'YES',
                            'key' => $column['key'] ?? $column['Key'] ?? '',
                            'default' => $column['default'] ?? $column['Default'] ?? null,
                        ];
                    }
                }

                $dbSchema[$tableName] = $tableColumns;
            }
        }
        $this->dbSchema=$dbSchema;
    }
    public function getDashboard()
    {
        return $this->dashboard;
    }

    public function generateWidgets()
    {
        $text=$this->message->message;
        $widgetsList = $this->widgets->select(['name', 'description']);
        $widgets = json_encode($widgetsList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $tables=json_encode($this->tables,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);


        $prompt = <<<TEXT

### 1. СПИСОК ДОСТУПНЫХ ТАБЛИЦ:
$tables

### 2. СПИСОК ДОСТУПНЫХ ТИПОВ ВИДЖЕТОВ (Используй ТОЛЬКО 'name' из этого списка):
$widgets

### 3. ЗАПРОС ПОЛЬЗОВАТЕЛЯ (ГЛАВНЫЙ ПРИОРИТЕТ):
"{$text}"

---

### ЗАДАЧА:
Сформируй полноценную, профессиональную структуру аналитического дашборда, которая максимально точно отвечает запросу пользователя.

---

## ❗ КРИТИЧЕСКИЕ ПРАВИЛА (ОБЯЗАТЕЛЬНО К СОБЛЮДЕНИЮ):

### 1. ЗАПРЕТ НА ВЫДУМЫВАНИЕ ПОЛЕЙ СХЕМЫ:
- Используй ТОЛЬКО поля, которые реально существуют в списке таблиц.
- НЕЛЬЗЯ придумывать новые названия колонок, метрик или атрибутов.
- Если нужного поля нет в схеме — НЕ ИЗОБРЕТАЙ ЕГО, а адаптируй логику под доступные данные.

---

### 2. ПРАВИЛА ДЛЯ "instruction":
- "instruction" — это ТОЛЬКО описание визуального вида и логики построения виджета.
- ЗАПРЕЩЕНО перечислять или выдумывать названия полей внутри instruction.
- Можно ссылаться только на уже существующие поля из схемы (без изменения их названий).
- Описывай:
  - тип визуализации (график, столбцы, линии, KPI и т.д.)
  - что сравнивается
  - как группируются данные
  - какие фильтры или агрегации применяются
- НЕЛЬЗЯ: придумывать новые атрибуты, поля, ключи или структуры данных.

---

### 3. РАЗБИЕНИЕ НА ЛОГИЧЕСКИЕ ЧАСТИ:
Если запрос комплексный — разбивай на отдельные аналитические блоки.
Каждый блок = отдельный объект JSON.

---

### 4. РАЗНООБРАЗИЕ ВИДЖЕТОВ:
- Используй KPI для общих метрик
- Используй разные графики для разных срезов
- Не дублируй смысл виджетов

---

### 5. УНИКАЛЬНОСТЬ:
- Каждый "title" должен быть уникальным
- Каждый объект должен отражать отдельную бизнес-логику
- Запрещено дублировать один и тот же смысл разными виджетами

---

## 📦 ТРЕБОВАНИЯ К JSON:

1. Выводи ТОЛЬКО валидный JSON массив
2. Никакого markdown, текста или пояснений
3. Ключи строго: "name", "title", "instruction", "tables"
4. "name" — только из списка виджетов
5. "tables" — только реально используемые таблицы из схемы
6. "title" — короткий человекочитаемый заголовок
7. "instruction" — строго визуальное описание без выдуманных полей

---

## 📐 ЭТАЛОННЫЙ ФОРМАТ:
[
  {
    "name": "название_виджета_из_списка",
    "title": "Уникальный заголовок",
    "instruction": "Описание того, как визуально должен выглядеть график, какие данные группируются и как отображаются (без выдуманных полей)",
    "tables": []
  }
]

TEXT;
        $response = (new AIService(responseFormat: 'json'))->ask($prompt);
        $generateWidgets = $response['content'];
        foreach ($generateWidgets as $list) {
            $widget = $this->widgets->where('name', $list['name'])->first();
            DashboardWidget::query()->create([
                'dashboard_id' => $this->dashboard->id,
                'widget_id' => $widget->id,
                'title' => $list['title'],
                'instruction' => $list['instruction'],
                'tables'=> json_encode($list['tables']),
            ]);
        }
    }

    public function generateContentToWidgets()
    {
        $widgets_dash = DashboardWidget::query()->with('widget')
            ->where('dashboard_id', $this->dashboard->id)->get();

        $results = [];

        foreach ($widgets_dash as $index => $widget) {
            $widget_tables = json_decode($widget->tables, true) ?? [];


            $tables_scheme = collect($this->dbSchema)->only($widget_tables)->toArray();

            $results[] = $this->generateContentWidget($widget, $index, $tables_scheme);
        }
        return $results;

    }


    public function generateContentWidget($dashboard_widget, $position,$tables_scheme)
    {

        $system = <<<'TEXT'
Ты — специализированный генератор автономных Python-скриптов для аналитики данных.
Твоя задача — написать чистый, эффективный и рабочий код, сочетающий DuckDB и Python.

СТРОГИЕ ТЕХНИЧЕСКИЕ ОГРАНИЧЕНИЯ:
1. Скрипт должен принимать ровно один аргумент командной строки: --path (путь к файлу базы данных DuckDB). Добавлять другие аргументы (даты, лимиты, флаги) категорически запрещено.
TEXT;

        $tablesJson = json_encode($tables_scheme, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $prompt = <<<TEXT
Напиши автономный Python-скрипт, который агрегирует данные из DuckDB и форматирует их в нужный вид.

ОБЯЗАТЕЛЬНАЯ СТРУКТУРА СКРИПТА:
1. Импорт модулей: `duckdb`, `pandas as pd`, `json`, `sys`.
2. Парсинг единственного аргумента `--path` (через sys.argv или argparse).
3. Подключение к базе данных через `duckdb.connect()`.
5. Получение DataFrame через `.df()`, финальная подгонка под JSON-структуру.
6. Вывод итогового JSON в stdout через `print(json.dumps(..., ensure_ascii=False))`.

ВАЖНО:
- Используй только реально существующие таблицы и поля из доступной схемы.
- Если нужных данных или таблиц для выполнения инструкции нет, сформируй пустой результат, соответствующий целевой схеме.

ДОСТУПНАЯ СХЕМА DUCKDB:
{$tablesJson}

ИНСТРУКЦИЯ ПО ВЫБОРКЕ ДАННЫХ:
{$dashboard_widget->instruction}

ЦЕЛЕВАЯ JSON СХЕМА ВЫВОДА:
{$dashboard_widget->widget->scheme}

ОПИСАНИЕ ПОЛЕЙ JSON ВЫХОДА:
{$dashboard_widget->widget->scheme_description}

ТЕХНИЧЕСКИЕ ПРАВИЛА:
- Аргумент базы передается строго как --path=
- Никаких комментариев в коде.
- Можно использовать любые системный библатеки python и pandas
- Никакого markdown (не используй блоки ```).
- Только чистый, готовый к исполнению Python-код.
TEXT;
        $response = (new AIService(
            responseFormat: 'text',
        ))->ask($prompt, $system);

        $pythonCode = trim((string) $response['content']);
        $pythonCode = preg_replace('/^```(?:python)?\s*/i', '', $pythonCode);
        $pythonCode = preg_replace('/\s*```$/', '', $pythonCode);
        $pythonCode = preg_replace('/["\']\s*$/', '', $pythonCode);

        $path = $this->storage.'/dashboard/widgets/'.$dashboard_widget->id.'/generated_script.py';

        File::ensureDirectoryExists(dirname($path));

        File::put($path, $pythonCode);


        $dashboard_widget->code_path= $path;
        $dashboard_widget->status = 'active';
        $dashboard_widget->position = $position;
        $dashboard_widget->save();
        return $dashboard_widget;
    }
}
