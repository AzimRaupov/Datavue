"""
Ручная проверка обученного классификатора.

    venv/bin/python ml/intents/predict.py "выгрузи заказы в excel"
    venv/bin/python ml/intents/predict.py            # интерактивно, по строке

Читает тот же JSON, что использует PHP в проде, и считает предсказание тем же
способом — это заодно проверка, что выгрузка весов не испорчена.
"""

import json
import math
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from common import char_ngrams, normalize  # noqa: E402

MODEL_PATH = Path(__file__).resolve().parent / "model.json"


def load_model(path: Path = MODEL_PATH) -> dict:
    if not path.exists():
        sys.exit(f"Нет модели {path}. Сначала: venv/bin/python ml/intents/train.py")

    with path.open(encoding="utf-8") as handle:
        return json.load(handle)


def predict(model: dict, text: str):
    """Повторяет вычисление TF-IDF и softmax вручную — ровно так же, как PHP."""
    vocabulary = model["vocabulary"]
    idf = model["idf"]

    counts = Counter(
        gram for gram in char_ngrams(normalize(text), model["ngram_min"], model["ngram_max"])
        if gram in vocabulary
    )

    vector = {}

    for gram, count in counts.items():
        index = vocabulary[gram]
        tf = 1.0 + math.log(count) if model["sublinear_tf"] else float(count)
        vector[index] = tf * idf[index]

    # L2-нормализация: длина фразы не должна влиять на уверенность.
    length = math.sqrt(sum(value * value for value in vector.values()))

    if length > 0:
        vector = {index: value / length for index, value in vector.items()}

    scores = []

    for row, bias in zip(model["coef"], model["intercept"]):
        scores.append(bias + sum(value * row[index] for index, value in vector.items()))

    top = max(scores)
    exponents = [math.exp(score - top) for score in scores]
    total = sum(exponents)
    probabilities = [value / total for value in exponents]

    order = sorted(range(len(probabilities)), key=lambda i: probabilities[i], reverse=True)

    return [(model["classes"][i], probabilities[i]) for i in order]


def show(model: dict, text: str) -> None:
    ranked = predict(model, text)
    best, confidence = ranked[0]

    print(f"  {best}  (уверенность {confidence:.3f})")
    print("  " + "  ".join(f"{label}={value:.3f}" for label, value in ranked))


def main() -> None:
    model = load_model()

    if len(sys.argv) > 1:
        show(model, " ".join(sys.argv[1:]))
        return

    print("Введите фразу (пустая строка — выход)")

    while True:
        try:
            text = input("> ").strip()
        except (EOFError, KeyboardInterrupt):
            break

        if not text:
            break

        show(model, text)


if __name__ == "__main__":
    main()
