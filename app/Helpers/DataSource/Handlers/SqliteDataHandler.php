<?php

namespace App\Helpers\DataSource\Handlers;

use RuntimeException;

/**
 * Загруженный файл SQLite.
 *
 * В отличие от csv/xlsx, конвертировать нечего: файл уже является базой данных.
 * Задача обработчика — убедиться, что это действительно SQLite, и положить файл
 * туда же, где лежат остальные извлечённые данные чата.
 */
class SqliteDataHandler
{
    /** Первые байты любого файла SQLite 3. */
    private const SIGNATURE = "SQLite format 3\0";

    public function __construct(
        private string $sourcePath,
        private string $targetPath
    ) {
    }

    public function handle(): array
    {
        if (!is_file($this->sourcePath)) {
            return [
                'success' => false,
                'message' => "Файл не найден: {$this->sourcePath}",
            ];
        }

        if (!$this->looksLikeSqlite()) {
            return [
                'success' => false,
                'message' => 'Файл не является базой данных SQLite: '
                    . 'подпись в начале файла не совпадает.',
            ];
        }

        $directory = dirname($this->targetPath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return [
                'success' => false,
                'message' => "Не удалось создать каталог: {$directory}",
            ];
        }

        // Копируем, а не перемещаем: исходная загрузка остаётся на месте
        // и учтена в uploaded_files.
        if (!copy($this->sourcePath, $this->targetPath)) {
            return [
                'success' => false,
                'message' => "Не удалось сохранить базу данных: {$this->targetPath}",
            ];
        }

        return [
            'success' => true,
            'message' => 'База данных SQLite подключена.',
        ];
    }

    private function looksLikeSqlite(): bool
    {
        $handle = fopen($this->sourcePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Не удалось открыть файл: {$this->sourcePath}");
        }

        try {
            return fread($handle, strlen(self::SIGNATURE)) === self::SIGNATURE;
        } finally {
            fclose($handle);
        }
    }
}
