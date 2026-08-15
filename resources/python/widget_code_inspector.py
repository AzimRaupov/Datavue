"""Проверка кода виджета, написанного человеком, до его сохранения.

Разбор идёт по AST, а не регулярками: "import os" можно записать десятком
способов, и текстовый поиск ловит только самый наивный из них. Здесь же
проверяется реальная структура программы.

Запускается как:  python widget_code_inspector.py /path/to/code.py
Печатает один JSON:  {"ok": true, "errors": []}

Важно: это барьер, а не песочница. Он отсекает случайное и ленивое —
обращение к файловой системе, запуск процессов, динамическое выполнение
строк. Мотивированного атакующего останавливает только изоляция процесса
на уровне системы.
"""

import ast
import json
import sys

# Модули, которых достаточно, чтобы посчитать и разложить данные виджета.
# Всё, что умеет ходить в систему или в сеть, сюда не попадает.
ALLOWED_MODULES = {
    "json",
    "math",
    "statistics",
    "datetime",
    "decimal",
    "re",
    "collections",
    "itertools",
    "functools",
    "pandas",
    "numpy",
}

# Имена, через которые обходят любой белый список модулей.
FORBIDDEN_NAMES = {
    "exec",
    "eval",
    "compile",
    "open",
    "input",
    "breakpoint",
    "exit",
    "quit",
    "__import__",
    "globals",
    "locals",
    "vars",
    "getattr",
    "setattr",
    "delattr",
    "memoryview",
}


def root_module(name):
    """'pandas.io.sql' -> 'pandas'."""
    return (name or "").split(".")[0]


def check(tree):
    errors = []
    has_main = False
    prints_json = False

    for node in ast.walk(tree):
        # --- импорты -----------------------------------------------------
        if isinstance(node, ast.Import):
            for alias in node.names:
                module = root_module(alias.name)
                if module not in ALLOWED_MODULES:
                    errors.append(
                        "Импорт «%s» запрещён. Разрешены: %s."
                        % (alias.name, ", ".join(sorted(ALLOWED_MODULES)))
                    )

        elif isinstance(node, ast.ImportFrom):
            module = root_module(node.module)
            if node.level:
                errors.append("Относительные импорты запрещены.")
            elif module not in ALLOWED_MODULES:
                errors.append(
                    "Импорт из «%s» запрещён. Разрешены: %s."
                    % (node.module, ", ".join(sorted(ALLOWED_MODULES)))
                )
            for alias in node.names:
                if alias.name == "*":
                    errors.append("Импорт «*» запрещён — перечислите имена явно.")

        # --- опасные имена -----------------------------------------------
        elif isinstance(node, ast.Name):
            if node.id in FORBIDDEN_NAMES:
                errors.append("Использование «%s» запрещено." % node.id)

        # --- доступ к внутренностям объектов ------------------------------
        elif isinstance(node, ast.Attribute):
            if node.attr.startswith("__") and node.attr.endswith("__"):
                errors.append(
                    "Обращение к служебному атрибуту «%s» запрещено." % node.attr
                )

        # --- обязательная структура ---------------------------------------
        elif isinstance(node, ast.FunctionDef):
            if node.name == "main" and not node.args.args:
                has_main = True

    # Последняя операция main() должна печатать JSON — на этом держится
    # весь путь отрисовки: фронт читает stdout и парсит его.
    for node in ast.walk(tree):
        if not isinstance(node, ast.Call):
            continue

        func = node.func

        if isinstance(func, ast.Name) and func.id == "print":
            for arg in node.args:
                if (
                    isinstance(arg, ast.Call)
                    and isinstance(arg.func, ast.Attribute)
                    and arg.func.attr == "dumps"
                ):
                    prints_json = True

    if not has_main:
        errors.append("В коде нет функции «def main():» без аргументов.")

    if not prints_json:
        errors.append(
            "Код ничего не печатает: последней операцией main() должна быть "
            "print(json.dumps(result, ensure_ascii=False, default=json_default))."
        )

    # Дубли сообщений только зашумляют форму.
    unique = []
    for error in errors:
        if error not in unique:
            unique.append(error)

    return unique


def main():
    if len(sys.argv) < 2:
        print(json.dumps({"ok": False, "errors": ["Не передан файл с кодом."]}))
        return

    try:
        with open(sys.argv[1], "r", encoding="utf-8") as handle:
            source = handle.read()
    except OSError as error:
        print(json.dumps({"ok": False, "errors": ["Не удалось прочитать код: %s" % error]}))
        return

    try:
        tree = ast.parse(source)
    except SyntaxError as error:
        print(
            json.dumps(
                {
                    "ok": False,
                    "errors": [
                        "Синтаксическая ошибка в строке %s: %s"
                        % (error.lineno, error.msg)
                    ],
                },
                ensure_ascii=False,
            )
        )
        return

    errors = check(tree)

    print(json.dumps({"ok": not errors, "errors": errors}, ensure_ascii=False))


if __name__ == "__main__":
    main()
