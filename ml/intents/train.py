

import argparse
import csv
import hashlib
import json
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import classification_report, confusion_matrix, f1_score
from sklearn.model_selection import StratifiedKFold, cross_val_predict
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import normalize

sys.path.insert(0, str(Path(__file__).resolve().parent))

from common import (
    CONTEXT_CHARS, CONTEXT_WEIGHT, LABELS, NGRAM_MAX, NGRAM_MIN, SEPARATOR,
    analyzer, document,
)

HERE = Path(__file__).resolve().parent


def load(path: Path):

    if not path.exists():
        sys.exit(f"Нет файла {path}. Формат описан в ml/intents/README.md")

    documents, labels, previews = [], [], []
    seen = set()
    duplicates = 0

    with path.open(encoding="utf-8") as handle:
        for line_no, row in enumerate(csv.DictReader(handle), start=2):
            text = (row.get("text") or "").strip()
            context = (row.get("context") or "").strip()
            label = (row.get("label") or "").strip()

            if not text:
                continue

            if label not in LABELS:
                sys.exit(f"{path}:{line_no}: неизвестная метка '{label}'. Допустимы: {LABELS}")

            key = (text.lower(), context.lower())

            if key in seen:
                duplicates += 1
                continue

            seen.add(key)
            documents.append(document(text, context))
            labels.append(label)
            previews.append(f"«{text}»" + (f" ← агент: «{context[:60]}»" if context else ""))

    if duplicates:
        print(f"  {path.name}: пропущено дубликатов — {duplicates}")

    return documents, labels, previews


class ContextTfidfVectorizer(TfidfVectorizer):


    def __init__(
        self,
        analyzer=None,
        min_df=1,
        max_features=None,
        sublinear_tf=False,
        context_weight=CONTEXT_WEIGHT,
    ):
        super().__init__(
            analyzer=analyzer,
            min_df=min_df,
            max_features=max_features,
            sublinear_tf=sublinear_tf,
            norm=None,
        )
        self.context_weight = context_weight

    def _weight_context(self, matrix):

        matrix = matrix.tocsr()
        names = self.get_feature_names_out()

        is_context = np.array([name.startswith("c:") for name in names])

        squares = matrix.multiply(matrix)
        norm_context = np.sqrt(np.asarray(squares[:, is_context].sum(axis=1)).ravel())
        norm_message = np.sqrt(np.asarray(squares[:, ~is_context].sum(axis=1)).ravel())

        row_of = np.repeat(np.arange(matrix.shape[0]), np.diff(matrix.indptr))

        factor = np.where(
            is_context[matrix.indices],
            self.context_weight / np.maximum(norm_context[row_of], 1e-12),
            1.0 / np.maximum(norm_message[row_of], 1e-12),
        )

        matrix.data = matrix.data * factor

        return normalize(matrix, norm="l2")

    def fit_transform(self, raw_documents, y=None):
        return self._weight_context(super().fit_transform(raw_documents, y))

    def transform(self, raw_documents):
        return self._weight_context(super().transform(raw_documents))


def file_hash(path: Path) -> str:
    if not path.exists():
        return ""

    return hashlib.sha256(path.read_bytes()).hexdigest()[:16]


def document_text(document: str) -> str:
    return document.split(SEPARATOR)[0].strip()


def build_model(c_value: float, max_features: int) -> Pipeline:
    return Pipeline([
        ("tfidf", ContextTfidfVectorizer(
            analyzer=analyzer,
            min_df=2,
            max_features=max_features,
            sublinear_tf=True,
            context_weight=CONTEXT_WEIGHT,
        )),
        ("clf", LogisticRegression(
            C=c_value,
            max_iter=3000,
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

    vectorizer = model.named_steps["tfidf"]
    classifier = model.named_steps["clf"]
    names = np.array(vectorizer.get_feature_names_out())

    print("\n=== Решающие признаки по классам")

    for index, label in enumerate(classifier.classes_):
        weights = classifier.coef_[index]
        best = np.argsort(weights)[-top:][::-1]
        grams = ", ".join(f"«{names[i]}»" for i in best)
        print(f"  {label}: {grams}")


def threshold_table(probabilities, y_true, title: str) -> None:

    confidence = probabilities.max(axis=1)
    predicted = np.array(LABELS)[probabilities.argmax(axis=1)]
    truth = np.array(y_true)

    print(f"\n=== {title}")
    print("  порог   решено локально   точность на них   уходит к GPT")

    for threshold in (0.50, 0.60, 0.70, 0.80, 0.90):
        taken = confidence >= threshold
        share = taken.mean()

        accuracy = float((predicted[taken] == truth[taken]).mean()) if taken.any() else float("nan")

        print(f"  {threshold:.2f}    {share * 100:14.1f}%   {accuracy * 100:14.1f}%   {(1 - share) * 100:11.1f}%")


def learning_curve(texts, labels, c_value: float, max_features: int, out: Path) -> None:

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

    vectorizer = model.named_steps["tfidf"]
    classifier = model.named_steps["clf"]

    probabilities = model.predict_proba(samples)

    payload = {
        "version": 2,
        "created_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "classes": list(classifier.classes_),
        "ngram_min": NGRAM_MIN,
        "ngram_max": NGRAM_MAX,
        "context_chars": CONTEXT_CHARS,
        "context_weight": CONTEXT_WEIGHT,
        "sublinear_tf": True,
        "vocabulary": {gram: int(index) for gram, index in vectorizer.vocabulary_.items()},
        "idf": [round(float(value), 6) for value in vectorizer.idf_],
        "coef": [[round(float(value), 6) for value in row] for row in classifier.coef_],
        "intercept": [float(value) for value in classifier.intercept_],
        "selftest": [
            {
                "text": text.split(SEPARATOR)[0],
                "context": (text.split(SEPARATOR) + [""])[1],
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
    parser.add_argument("--out", type=Path, default=HERE / "model.json")
    parser.add_argument("--C", type=float, default=4.0, help="Обратная сила регуляризации")
    parser.add_argument("--max-features", type=int, default=30000)
    parser.add_argument(
        "--extra", type=Path, action="append", default=[],
        help="Дополнительные обучающие данные (можно несколько раз). "
             "Сюда подаются фразы, размеченные языковой моделью в проде.",
    )
    parser.add_argument(
        "--extra-weight", type=float, default=25.0,
        help="Во сколько раз живой пример весит больше сгенерированного",
    )
    parser.add_argument(
        "--report", type=Path,
        help="Куда записать метрики JSON — по ним решается, заменять ли модель",
    )
    args = parser.parse_args()

    print("Загрузка данных")
    train_texts, train_labels, _ = load(args.train)
    test_texts, test_labels, test_previews = load(args.test)

    weights = [1.0] * len(train_texts)

    test_only = {document_text(doc).lower() for doc in test_texts}

    for extra in args.extra:
        if not extra.exists():
            print(f"  {extra}: нет файла, пропускаю")
            continue

        extra_texts, extra_labels, _ = load(extra)

        known = {text.lower() for text in train_texts}
        added = 0
        leaked = 0

        for text, label in zip(extra_texts, extra_labels):
            if document_text(text).lower() in test_only:
                leaked += 1
                continue

            if text.lower() in known:
                continue

            known.add(text.lower())
            train_texts.append(text)
            train_labels.append(label)
            weights.append(args.extra_weight)
            added += 1

        note = f", отсеяно как тестовые: {leaked}" if leaked else ""
        print(f"  {extra.name}: добавлено {added} из {len(extra_texts)} с весом {args.extra_weight}{note}")

    print(f"  обучающих: {len(train_texts)} {dict(Counter(train_labels))}")
    print(f"  отложенных: {len(test_texts)} {dict(Counter(test_labels))}")

    overlap = {t.lower() for t in train_texts} & {t.lower() for t in test_texts}

    if overlap:
        print(f"\n  ВНИМАНИЕ: {len(overlap)} фраз есть и в обучении, и в тесте — метрика завышена.")
        print("  Например: " + "; ".join(list(overlap)[:3]))

    model = build_model(args.C, args.max_features)

    cv_accuracy = None
    cv_probabilities = None
    smallest_class = min(Counter(train_labels).values())

    if smallest_class >= 2:
        folds = min(5, smallest_class)
        splitter = StratifiedKFold(n_splits=folds, shuffle=True, random_state=42)

        cv_probabilities = cross_val_predict(
            model, train_texts, train_labels, cv=splitter, method="predict_proba",
        )
        cv_predicted = np.array(LABELS)[cv_probabilities.argmax(axis=1)]
        cv_accuracy = report(
            f"Перекрёстная проверка на обучающем наборе ({folds} блока)",
            train_labels, cv_predicted,
        )

    model.fit(train_texts, train_labels, clf__sample_weight=np.array(weights))

    predicted = model.predict(test_texts)
    accuracy = report("Отложенный набор (в обучении не участвовал)", test_labels, predicted)
    show_mistakes(test_previews, test_labels, predicted)
    show_top_features(model)

    if cv_probabilities is not None:
        threshold_table(cv_probabilities, train_labels, "выбор порога (перекрёстная проверка на обучающей)")

    threshold_table(model.predict_proba(test_texts), test_labels, "проверка порога на отложенном наборе")
    learning_curve(train_texts, train_labels, args.C, args.max_features, HERE / "learning_curve.csv")

    export_weights(model, args.out, test_texts[:20])

    if args.report:
        macro_f1 = f1_score(test_labels, predicted, labels=LABELS, average="macro", zero_division=0)

        args.report.parent.mkdir(parents=True, exist_ok=True)

        with args.report.open("w", encoding="utf-8") as handle:
            json.dump({
                "created_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
                "test_hash": file_hash(args.test),
                "accuracy": round(float(accuracy), 4),
                "macro_f1": round(float(macro_f1), 4),
                "cv_accuracy": round(float(cv_accuracy), 4) if cv_accuracy is not None else None,
                "train_size": len(train_texts),
                "test_size": len(test_texts),
                "features": len(model.named_steps["tfidf"].vocabulary_),
                "extra_sources": [str(path) for path in args.extra],
                "extra_weight": args.extra_weight,
                "live_samples": sum(1 for w in weights if w != 1.0),
            }, handle, ensure_ascii=False, indent=2)

        print(f"Отчёт: {args.report}")


if __name__ == "__main__":
    main()
