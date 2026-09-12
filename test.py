from faster_whisper import WhisperModel

print("Загрузка Whisper Medium...")

model = WhisperModel(
    "medium",
    device="cpu",
    compute_type="int8"
)

print("Модель загружена!")
print("Распознавание...\n")

segments, info = model.transcribe(
    "audio.wav",
    language="ru",
    beam_size=5,
    vad_filter=True
)

print(f"Язык: {info.language}")
print(f"Вероятность языка: {info.language_probability:.2%}")
print("\n--- РЕЗУЛЬТАТ ---")

for segment in segments:
    print(
        f"[{segment.start:.2f}s -> {segment.end:.2f}s] "
        f"{segment.text}"
    )
