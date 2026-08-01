<?php

namespace App\Helpers\Ai\Dashboard;

use App\Helpers\Ai\AIService;
use App\Models\DataSource;

class DashboardGeneratorAi
{
    public $codeTemplate;
     public $codeComponents;
     public $dataSource;
    public function __construct($dataSource){
        $this->dataSource = $dataSource;

        if($dataSource->connection_type){

        }
        $this->codeComponents=[
            ['mysql'=>`
              engine = create_engine(
    "mysql+pymysql://root:password@localhost/test_db"
)


def query(sql, params=None):
    with engine.connect() as connection:
        result = connection.execute(
            text(sql),
            params or {}
        )

        return result
`,
                ]
        ];
    }

    public function generateWidgets($tables, $widgets, $text)
    {
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
- Виджеты mini-cards используй для общих метрик.
- Размети первыми виджеты mini-cards
- Используй разные графики для разных срезов
- Не дублируй смысл виджетов

---

### 5. УНИКАЛЬНОСТЬ:
- Каждый "title" должен быть уникальным
- Каждый объект должен отражать отдельную бизнес-логику
- Запрещено дублировать один и тот же смысл разными виджетами

---

## 📦 ТРЕБОВАНИЯ К JSON:

1. Выводи ТОЛЬКО валидный JSON объект (НЕ массив, именно объект с ключами "dashboard_name" и "widgets")
2. Никакого markdown, текста или пояснений
3. Ключи виджета строго: "name", "title", "instruction", "tables"
4. "name" — только из списка виджетов
5. "tables" — только реально используемые таблицы из схемы
6. "title" — короткий человекочитаемый заголовок
7. "instruction" — строго визуальное описание без выдуманных полей
8. "dashboard_name" — короткое человекочитаемое название дашборда (обязательный ключ верхнего уровня)

---

## 📐 ЭТАЛОННЫЙ ФОРМАТ (СТРОГО ТАКОЙ, БЕЗ ИСКЛЮЧЕНИЙ — ОБЪЕКТ, НЕ МАССИВ):
{
  "dashboard_name": "Название дашборда",
  "widgets": [
    {
      "name": "название_виджета_из_списка",
      "title": "Уникальный заголовок",
      "instruction": "Описание визуализации без выдуманных полей",
      "tables": []
    }
  ]
}
TEXT;

        return (new AIService(responseFormat: 'json'))->ask($prompt);
    }

    public function generateContentWidget($dashboard_widget, $position, $tables_scheme)
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
1. Импорт модулей: `duckdb`, `pandas as pd`, `json`, `sys`, `argparse`.
2. Парсинг единственного аргумента `--path` (через sys.argv или argparse).
3. Подключение к базе данных через `duckdb.connect()`.
5. Получение DataFrame через `.df()`, финальная подгонка под JSON-структуру.
6. Вывод итогового JSON в stdout через `print(json.dumps(..., ensure_ascii=False))`.

ВАЖНО:
- Используй только реально существующие таблицы и поля из доступной схемы.
- Если нужных данных или таблиц для выполнения инструкции нет, сформируй пустой результат, соответствующий целевой схеме.

ДОСТУПНАЯ СХЕМА DUCKDB:
{$tablesJson}

ИНСТРУКЦИЯ ПО КАК ДОЛЖНО БЫТ:
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

        $response = (new AIService(responseFormat: 'text'))->ask($prompt, $system);

        $pythonCode = trim((string) $response['content']);
        $pythonCode = preg_replace('/^```(?:python)?\s*/i', '', $pythonCode);
        $pythonCode = preg_replace('/\s*```$/', '', $pythonCode);
        $pythonCode = preg_replace('/["\']\s*$/', '', $pythonCode);

        return $pythonCode;
    }
}
