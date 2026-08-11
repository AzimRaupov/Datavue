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

from common import context_tail, features  # noqa: E402

MODEL_PATH = Path(__file__).resolve().parent / "model.json"


def load_model(path: Path = MODEL_PATH) -> dict:
    if not path.exists():
        sys.exit(f"Нет модели {path}. Сначала: venv/bin/python ml/intents/train.py")

    with path.open(encoding="utf-8") as handle:
        return json.load(handle)


def predict(model: dict, text: str, context: str = ""):
    """Повторяет вычисление признаков и softmax вручную — ровно так же, как PHP."""
    vocabulary = model["vocabulary"]
    idf = model["idf"]
    weight = model.get("context_weight", 1.0)

    counts = Counter(
        gram for gram in features(text, context) if gram in vocabulary
    )

    vector = {}
    norms = {"m": 0.0, "c": 0.0}

    for gram, count in counts.items():
        index = vocabulary[gram]
        tf = 1.0 + math.log(count) if model["sublinear_tf"] else float(count)
        value = tf * idf[index]
        vector[index] = (gram[0], value)
        norms[gram[0]] += value * value

    # Блоки нормируются раздельно, и лишь потом контекст умножается на вес:
    # так его влияние не зависит от длины реплики агента.
    scaled = {}
    total = 0.0

    for index, (block, value) in vector.items():
        norm = math.sqrt(norms[block])

        if norm <= 0:
            continue

        value = value / norm * (weight if block == "c" else 1.0)
        scaled[index] = value
        total += value * value

    length = math.sqrt(total)
    vector = {i: v / length for i, v in scaled.items()} if length > 0 else scaled

    scores = []

    for row, bias in zip(model["coef"], model["intercept"]):
        scores.append(bias + sum(value * row[index] for index, value in vector.items()))

    top = max(scores)
    exponents = [math.exp(score - top) for score in scores]
    total = sum(exponents)
    probabilities = [value / total for value in exponents]

    order = sorted(range(len(probabilities)), key=lambda i: probabilities[i], reverse=True)

    return [(model["classes"][i], probabilities[i]) for i in order]


def show(model: dict, text: str, context: str = "") -> None:
    ranked = predict(model, text, context)
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
