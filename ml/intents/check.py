"""
Проверка обученного классификатора — вручную и на файле.

    venv/bin/python ml/intents/check.py                      # интерактивно
    venv/bin/python ml/intents/check.py "выгрузи в эксель"    # одна фраза
    venv/bin/python ml/intents/check.py --file ml/intents/test.csv   # весь набор
    venv/bin/python ml/intents/check.py --demo               # показательный прогон

Важно: скрипт читает не sklearn-пайплайн, а выгруженный JSON — тот самый файл,
по которому в проде считает PHP. То есть проверяется ровно то, что поедет
пользователям, а не то, что осталось в памяти после обучения.
"""

import argparse
import csv
import sys
import time
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from predict import MODEL_PATH, load_model, predict  # noqa: E402

HERE = Path(__file__).resolve().parent

# Ниже этого порога решение отдаём GPT: локальная модель не уверена.
# Значение подбирается по таблице порогов в train.py.
DEFAULT_THRESHOLD = 0.70

DEMO = [
    "сколько всего клиентов?",
    "покажи топ 10 клиентов",
    "покажи топ 10 клиентов и сохрани в csv",
    "выгрузи заказы за год в эксель",
    "сохрани это в pdf",
    "добавь график по странам",
    "что можно добавить на дашборд?",
    "удали второй виджет",
    "карточки не внезу должы быт они должны быт в верху",
    "добаф виджет па регионам",
    "а теперь в ворде",
    "спасибо",
]


def decide(model: dict, text: str, threshold: float):
    ranked = predict(model, text)
    label, confidence = ranked[0]
    local = confidence >= threshold

    return label, confidence, local, ranked


def show(model: dict, text: str, threshold: float) -> None:
    started = time.perf_counter()
    label, confidence, local, ranked = decide(model, text, threshold)
    elapsed = (time.perf_counter() - started) * 1000

    mark = "локально" if local else "→ к GPT (не уверен)"

    print(f"\n  «{text}»")
    print(f"    {label}   уверенность {confidence:.3f}   {mark}   {elapsed:.1f} мс")
    print("    " + "   ".join(f"{name}={value:.3f}" for name, value in ranked))


def check_file(model: dict, path: Path, threshold: float) -> None:
    if not path.exists():
        sys.exit(f"Нет файла {path}")

    with path.open(encoding="utf-8") as handle:
        rows = [row for row in csv.DictReader(handle) if (row.get("text") or "").strip()]

    if not rows:
        sys.exit("Файл пустой")

    labelled = bool(rows[0].get("label"))
    errors = []
    correct = 0
    sent_to_gpt = 0
    correct_local = 0
    local_total = 0

    started = time.perf_counter()

    for row in rows:
        text = row["text"].strip()
        label, confidence, local, _ = decide(model, text, threshold)

        if local:
            local_total += 1
        else:
            sent_to_gpt += 1

        if not labelled:
            continue

        expected = row["label"].strip()

        if label == expected:
            correct += 1

            if local:
                correct_local += 1
        else:
            errors.append((text, expected, label, confidence))

    elapsed = time.perf_counter() - started

    print(f"\nПроверено фраз: {len(rows)}")
    print(f"Время: {elapsed * 1000:.0f} мс всего, {elapsed / len(rows) * 1000:.2f} мс на фразу")

    if labelled:
        print(f"Точность: {correct / len(rows):.3f}  ({correct} из {len(rows)})")

    print(f"Решено локально: {local_total} ({local_total / len(rows) * 100:.1f}%)")
    print(f"Ушло бы к GPT: {sent_to_gpt} ({sent_to_gpt / len(rows) * 100:.1f}%)")

    if labelled and local_total:
        print(f"Точность на локальных решениях: {correct_local / local_total:.3f}")

    if errors:
        print(f"\nОшибки ({len(errors)}):")

        for text, expected, got, confidence in errors:
            print(f"  «{text}»")
            print(f"      ожидалось: {expected}   получено: {got}   уверенность {confidence:.3f}")

    if not labelled:
        print("\nВ файле нет колонки label — считать точность не по чему, показан только разбор.")


def selftest(model: dict) -> None:
    """Сверяет ручной расчёт с тем, что посчитал sklearn при обучении.

    Расхождение здесь означает, что нормализация текста или формула TF-IDF
    разъехались с обучением — и модель в проде видит не те признаки.
    """
    cases = model.get("selftest") or []

    if not cases:
        print("В модели нет контрольных примеров — пропускаю сверку.")
        return

    worst = 0.0

    for case in cases:
        mine = dict(predict(model, case["text"]))

        for name, expected in zip(model["classes"], case["proba"]):
            worst = max(worst, abs(mine[name] - expected))

    status = "совпадает" if worst < 1e-5 else "РАСХОЖДЕНИЕ"
    print(f"Сверка с sklearn: {status} (максимальное отклонение {worst:.1e}, примеров {len(cases)})")


def main() -> None:
    parser = argparse.ArgumentParser(description="Проверка классификатора намерений")
    parser.add_argument("text", nargs="*", help="Фраза для проверки")
    parser.add_argument("--file", type=Path, help="CSV с колонками text[,label]")
    parser.add_argument("--demo", action="store_true", help="Показательный прогон")
    parser.add_argument("--threshold", type=float, default=DEFAULT_THRESHOLD)
    parser.add_argument("--model", type=Path, default=MODEL_PATH)
    args = parser.parse_args()

    model = load_model(args.model)

    print(f"Модель: {args.model}")
    print(f"  обучена: {model['created_at']}   классы: {', '.join(model['classes'])}")
    print(f"  признаков: {len(model['vocabulary'])}   порог каскада: {args.threshold}")

    selftest(model)

    if args.file:
        check_file(model, args.file, args.threshold)
        return

    if args.demo:
        print(f"\nПоказательный прогон ({len(DEMO)} фраз):")

        for text in DEMO:
            show(model, text, args.threshold)

        return

    if args.text:
        show(model, " ".join(args.text), args.threshold)
        return

    print("\nВводите фразы (пустая строка — выход)")

    while True:
        try:
            text = input("> ").strip()
        except (EOFError, KeyboardInterrupt):
            break

        if not text:
            break

        show(model, text, args.threshold)


if __name__ == "__main__":
    main()
