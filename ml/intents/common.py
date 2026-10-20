

import re

LABELS = ["chat", "dashboard", "export"]

NGRAM_MIN = 3
NGRAM_MAX = 5

CONTEXT_CHARS = 80

SEPARATOR = "\x00"

CONTEXT_WEIGHT = 0.35

_WHITESPACE = re.compile(r"\s+")
_SENTENCE_END = re.compile(r"[.!?…]+")
_MARKDOWN = re.compile(r"[*_`#>\[\]()|-]+")


def normalize(text: str) -> str:

    text = (text or "").lower().replace("ё", "е")
    text = _WHITESPACE.sub(" ", text).strip()

    return f" {text} "


def context_tail(answer: str, limit: int = CONTEXT_CHARS) -> str:

    text = _MARKDOWN.sub(" ", answer or "")
    text = _WHITESPACE.sub(" ", text).strip()

    if not text:
        return ""

    sentences = [part.strip() for part in _SENTENCE_END.split(text) if part.strip()]

    if sentences:
        text = sentences[-1]

    if len(text) <= limit:
        return text

    tail = text[-limit:]

    space = tail.find(" ")

    return tail[space + 1:] if 0 <= space < 20 else tail


def char_ngrams(text: str, n_min: int = NGRAM_MIN, n_max: int = NGRAM_MAX):

    chars = list(text)
    length = len(chars)

    for n in range(n_min, n_max + 1):
        for i in range(length - n + 1):
            yield "".join(chars[i:i + n])


def document(message: str, context: str = "") -> str:
    return f"{message or ''}{SEPARATOR}{context or ''}"


def features(message: str, context: str = ""):

    grams = ["m:" + gram for gram in char_ngrams(normalize(message))]

    tail = context_tail(context)

    if tail:
        grams += ["c:" + gram for gram in char_ngrams(normalize(tail))]

    return grams


def analyzer(raw: str):
    message, _, context = (raw or "").partition(SEPARATOR)

    return features(message, context)
