"""
Обучение классификатора намерений пользователя.

    venv/bin/python ml/intents/train.py

Что делает:
  1. учит TF-IDF по символьным n-граммам + логистическую регрессию;
  2. считает честные метрики на отложенном наборе (test.csv в обучении не участвует);
  3. подбирает порог уверенности для каскада «локально или к GPT»;
  4. печатает признаки, которые модель сочла решающими, — это материал для защиты;
  5. выгружает веса в JSON, чтобы предсказание считал PHP без Python в проде.

Почему логистическая регрессия, а не нейросеть: классов три, обучающих примеров
меньше тысячи, а сигнал в задаче лексический («сохрани в csv» против «покажи»).
Трансформер здесь не даст прироста, зато потребует torch, GPU для дообучения и
2–4 секунды на загрузку в каждом процессе.
"""

import argparse
import csv
import json
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import classification_report, confusion_matrix
from sklearn.model_selection import StratifiedKFold, cross_val_predict
from sklearn.pipeline import Pipeline

sys.path.insert(0, str(Path(__file__).resolve().parent))

from common import LABELS, NGRAM_MAX, NGRAM_MIN, analyzer  # noqa: E402

HERE = Path(__file__).resolve().parent


def load(path: Path):
    """Читает CSV `text,label` и проверяет его пригодность."""
    if not path.exists():
        sys.exit(f"Нет файла {path}. Формат описан в ml/intents/README.md")

    texts, labels = [], []
    seen = set()
    duplicates = 0

    with path.open(encoding="utf-8") as handle:
        for line_no, row in enumerate(csv.DictReader(handle), start=2):
            text = (row.get("text") or "").strip()
            label = (row.get("label") or "").strip()

            if not text:
                continue

            if label not in LABELS:
                sys.exit(f"{path}:{line_no}: неизвестная метка '{label}'. Допустимы: {LABELS}")

            # Дубликат в обучении завышает вес фразы, дубликат между train и test
            # вообще превращает метрику в самообман — поэтому режем сразу.
            key = text.lower()

            if key in seen:
                duplicates += 1
                continue

            seen.add(key)
            texts.append(text)
            labels.append(label)

    if duplicates:
        print(f"  {path.name}: пропущено дубликатов — {duplicates}")

    return texts, labels


def build_model(c_value: float, max_features: int) -> Pipeline:
    return Pipeline([
        ("tfidf", TfidfVectorizer(
            analyzer=analyzer,
            min_df=2,          # n-грамма из единственной фразы — это шум, а не признак
            max_features=max_features,
            sublinear_tf=True,  # 1 + log(tf): длинная фраза не перевешивает короткую
            norm="l2",
        )),
        ("clf", LogisticRegression(
            C=c_value,
            max_iter=3000,
            # Классы в датасете почти наверняка окажутся неравными — веса
            # выравнивают их, иначе модель выучит «отвечай самым частым».
            class_weight="balanced",
        )),
    ])


def report(title: str, y_true, y_pred) -> float:
    print(f"\n=== {title}")
    print(classification_report(y_true, y_pred, labels=LABELS, digits=3, zero_division=0))

    matrix = confusion_matrix(y_true, y_pred, labels=LABELS)
    width = max(len(label) for label in LABELS) + 2

    print("Матрица ошибок (строка — правильный класс, столбец — предсказанный):")
    print(" " * width + "".join(label.rjust(width) for label in LABELS))

    for label, row in zip(LABELS, matrix):
        print(label.ljust(width) + "".join(str(value).rjust(width) for value in row))

    accuracy = float(np.mean(np.array(y_true) == np.array(y_pred)))
    print(f"\nТочность: {accuracy:.3f}")

    return accuracy


def show_mistakes(texts, y_true, y_pred, limit: int = 15) -> None:
    """Ошибки важнее общей цифры: по ним видно, чего не хватает в датасете."""
    mistakes = [
        (text, true, pred)
        for text, true, pred in zip(texts, y_true, y_pred)
        if true != pred
    ]

    if not mistakes:
        print("\nОшибок нет.")
        return

    print(f"\n=== Ошибки ({len(mistakes)}), первые {min(limit, len(mistakes))}:")

    for text, true, pred in mistakes[:limit]:
        print(f"  «{text}»\n      ожидалось: {true}   получено: {pred}")


def show_top_features(model: Pipeline, top: int = 12) -> None:
    """Какие куски слов модель сочла решающими для каждого класса.

    Самый наглядный слайд для защиты: видно, что признаки не прописаны руками,
    а найдены обучением — «выгруз», «csv», «сохран» всплывают сами.
    """
    vectorizer = model.named_steps["tfidf"]
    classifier = model.named_steps["clf"]
    names = np.array(vectorizer.get_feature_names_out())

    print("\n=== Решающие признаки по классам")

    for index, label in enumerate(classifier.classes_):
        weights = classifier.coef_[index]
        best = np.argsort(weights)[-top:][::-1]
        grams = ", ".join(f"«{names[i]}»" for i in best)
        print(f"  {label}: {grams}")


def threshold_table(probabilities, y_true) -> None:
    """Каскад: где проходит граница «решаем сами» и «спрашиваем GPT».

    Смысл в размене. Высокий порог — почти нет ошибок, но много запросов уходит
    к GPT, и экономии нет. Низкий — всё решаем локально, но растёт число неверных
    маршрутов. Выбирать нужно по этой таблице, а не наугад.
    """
    confidence = probabilities.max(axis=1)
    predicted = np.array(LABELS)[probabilities.argmax(axis=1)]
    truth = np.array(y_true)

    print("\n=== Выбор порога уверенности для каскада")
    print("  порог   решено локально   точность на них   уходит к GPT")

    for threshold in (0.50, 0.60, 0.70, 0.80, 0.90):
        taken = confidence >= threshold
        share = taken.mean()

        accuracy = float((predicted[taken] == truth[taken]).mean()) if taken.any() else float("nan")

        print(f"  {threshold:.2f}    {share * 100:14.1f}%   {accuracy * 100:14.1f}%   {(1 - share) * 100:11.1f}%")


def learning_curve(texts, labels, c_value: float, max_features: int, out: Path) -> None:
    """Как растёт точность с размером обучающего набора.

    Отвечает на вопрос «а хватит ли данных»: если кривая ещё идёт вверх — надо
    дописывать примеры, если вышла на полку — датасет можно не наращивать.
    """
    sizes = [0.2, 0.4, 0.6, 0.8, 1.0]
    rows = [("train_size", "cv_accuracy")]

    print("\n=== Кривая обучения (перекрёстная проверка)")

    rng = np.random.default_rng(42)
    order = rng.permutation(len(texts))
    texts = np.array(texts, dtype=object)[order]
    labels = np.array(labels, dtype=object)[order]

    for fraction in sizes:
        count = max(int(len(texts) * fraction), 10)
        subset_texts, subset_labels = texts[:count], labels[:count]

        smallest_class = min(Counter(subset_labels).values())

        if smallest_class < 2:
            continue

        folds = min(5, smallest_class)
        predicted = cross_val_predict(
            build_model(c_value, max_features),
            subset_texts,
            subset_labels,
            cv=StratifiedKFold(n_splits=folds, shuffle=True, random_state=42),
        )

        accuracy = float(np.mean(predicted == subset_labels))
        rows.append((count, round(accuracy, 4)))
        print(f"  {count:5d} примеров → {accuracy:.3f}")

    with out.open("w", encoding="utf-8", newline="") as handle:
        csv.writer(handle).writerows(rows)

    print(f"  сохранено: {out}")


def export_weights(model: Pipeline, out: Path, samples) -> None:
    """Выгружает модель в JSON, чтобы предсказывал PHP.

    В проде Python не запускается вовсе: у платформы каждый вызов Python — это
    новый процесс, а старт интерпретатора с библиотеками съедает больше времени,
    чем сама классификация. Логистическая регрессия — это скалярное произведение,
    PHP считает его сам за доли миллисекунды.
    """
    vectorizer = model.named_steps["tfidf"]
    classifier = model.named_steps["clf"]

    probabilities = model.predict_proba(samples)

    payload = {
        "version": 1,
        "created_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "classes": list(classifier.classes_),
        "ngram_min": NGRAM_MIN,
        "ngram_max": NGRAM_MAX,
        "sublinear_tf": True,
        "vocabulary": {gram: int(index) for gram, index in vectorizer.vocabulary_.items()},
        "idf": [round(float(value), 6) for value in vectorizer.idf_],
        "coef": [[round(float(value), 6) for value in row] for row in classifier.coef_],
        "intercept": [float(value) for value in classifier.intercept_],
        # Контрольные примеры: PHP-реализация обязана повторить эти вероятности.
        # Без них расхождение в нормализации всплыло бы не тестом, а жалобами
        # пользователей на странную маршрутизацию.
        "selftest": [
            {
                "text": text,
                "proba": [round(float(value), 6) for value in row],
            }
            for text, row in zip(samples, probabilities)
        ],
    }

    out.parent.mkdir(parents=True, exist_ok=True)

    with out.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False)

    size_kb = out.stat().st_size / 1024
    print(f"\nМодель выгружена: {out} ({size_kb:.0f} КБ, признаков {len(payload['vocabulary'])})")


def main() -> None:
    parser = argparse.ArgumentParser(description="Обучение классификатора намерений")
    parser.add_argument("--train", type=Path, default=HERE / "train.csv")
    parser.add_argument("--test", type=Path, default=HERE / "test.csv")
    # Модель лежит рядом с кодом, а не в storage/app: там всё содержимое
    # закрыто .gitignore, и после клонирования репозитория на другой машине
    # классификатора просто не оказывалось бы. Весит 300 КБ — версионировать
    # такой артефакт дешевле, чем объяснять каждому, где его взять.
    parser.add_argument("--out", type=Path, default=HERE / "model.json")
    parser.add_argument("--C", type=float, default=4.0, help="Обратная сила регуляризации")
    parser.add_argument("--max-features", type=int, default=30000)
    args = parser.parse_args()

    print("Загрузка данных")
    train_texts, train_labels = load(args.train)
    test_texts, test_labels = load(args.test)

    print(f"  обучающих: {len(train_texts)} {dict(Counter(train_labels))}")
    print(f"  отложенных: {len(test_texts)} {dict(Counter(test_labels))}")

    # Пересечение наборов делает метрику бессмысленной, а заметить его глазами
    # в тысяче строк невозможно.
    overlap = {t.lower() for t in train_texts} & {t.lower() for t in test_texts}

    if overlap:
        print(f"\n  ВНИМАНИЕ: {len(overlap)} фраз есть и в обучении, и в тесте — метрика завышена.")
        print("  Например: " + "; ".join(list(overlap)[:3]))

    model = build_model(args.C, args.max_features)

    # Перекрёстная проверка на обучающем наборе: показывает устойчивость,
    # пока отложенный набор ещё не тронут.
    smallest_class = min(Counter(train_labels).values())

    if smallest_class >= 2:
        folds = min(5, smallest_class)
        cv_predicted = cross_val_predict(
            model, train_texts, train_labels,
            cv=StratifiedKFold(n_splits=folds, shuffle=True, random_state=42),
        )
        report(f"Перекрёстная проверка на обучающем наборе ({folds} блока)", train_labels, cv_predicted)

    model.fit(train_texts, train_labels)

    predicted = model.predict(test_texts)
    report("Отложенный набор (в обучении не участвовал)", test_labels, predicted)
    show_mistakes(test_texts, test_labels, predicted)
    show_top_features(model)

    threshold_table(model.predict_proba(test_texts), test_labels)
    learning_curve(train_texts, train_labels, args.C, args.max_features, HERE / "learning_curve.csv")

    export_weights(model, args.out, test_texts[:20])


if __name__ == "__main__":
    main()
