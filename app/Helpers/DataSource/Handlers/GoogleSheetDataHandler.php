<?php

namespace App\Helpers\DataSource\Handlers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Загружает Google-таблицу и превращает её в источник данных.
 *
 * Работает через штатный CSV-экспорт Google:
 *   https://docs.google.com/spreadsheets/d/{id}/export?format=csv&gid={gid}
 *
 * Это сознательно выбранный минимум вместо Google Sheets API:
 * не нужны OAuth, сервисный аккаунт и хранение токенов — достаточно, чтобы
 * у таблицы был доступ «всем, у кого есть ссылка». Ограничения честные:
 *   - закрытая таблица не откроется (пользователь получит понятную ошибку);
 *   - выгружается один лист (тот, что указан в ссылке через gid);
 *   - это снимок на момент подключения, не живая синхронизация.
 *
 * Скачанный CSV дальше идёт по обычному пути CSV → DuckDB через
 * TableDataHandler, поэтому вся логика разбора заголовков и типов
 * переиспользуется как есть.
 */
class GoogleSheetDataHandler
{
    /** Сколько ждём ответ Google. Большие таблицы отдаются не мгновенно. */
    private const TIMEOUT_SECONDS = 60;

    /** Защита от выгрузки гигантских таблиц в память. */
    private const MAX_BYTES = 100 * 1024 * 1024;

    private string $csvPath;

    public function __construct(
        private string $url,
        private string $outputPath,
        private string $dbFilePath
    ) {
        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0775, true);
        }

        $this->csvPath = $this->outputPath . '/google_sheet.csv';
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function handle(): array
    {
        try {
            $exportUrl = self::buildExportUrl($this->url);

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => true])
                ->get($exportUrl);

            if (!$response->successful()) {
                throw new \RuntimeException(
                    'Google вернул код ' . $response->status() .
                    '. Убедитесь, что у таблицы открыт доступ «всем, у кого есть ссылка».'
                );
            }

            $body = $response->body();

            // Закрытая таблица отдаёт не CSV, а HTML-страницу входа —
            // причём с кодом 200, поэтому проверяем содержимое.
            if (str_starts_with(ltrim($body), '<')) {
                throw new \RuntimeException(
                    'Таблица закрыта для чтения. Откройте доступ «всем, у кого есть ссылка» ' .
                    'и попробуйте снова.'
                );
            }

            if (trim($body) === '') {
                throw new \RuntimeException('Таблица пустая — загружать нечего.');
            }

            if (strlen($body) > self::MAX_BYTES) {
                throw new \RuntimeException('Таблица слишком большая для загрузки.');
            }

            file_put_contents($this->csvPath, $body);

            // Дальше — обычный путь CSV → DuckDB.
            $result = (new TableDataHandler(
                $this->csvPath,
                $this->outputPath,
                $this->dbFilePath
            ))->handle();

            if (!($result['success'] ?? false)) {
                throw new \RuntimeException($result['message'] ?? 'Не удалось разобрать таблицу.');
            }

            return [
                'success' => true,
                'message' => 'Google-таблица успешно загружена.',
            ];

        } catch (Throwable $e) {
            Log::error('GoogleSheetDataHandler: ошибка загрузки', [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Собирает ссылку на CSV-экспорт из обычной ссылки на таблицу.
     *
     * Принимает и полную ссылку из адресной строки (с /edit#gid=0), и просто
     * идентификатор таблицы.
     *
     * @throws \RuntimeException если в строке нет идентификатора таблицы
     */
    public static function buildExportUrl(string $url): string
    {
        $url = trim($url);

        if (preg_match('~/spreadsheets/d/([a-zA-Z0-9-_]+)~', $url, $matches)) {
            $id = $matches[1];
        } elseif (preg_match('~^[a-zA-Z0-9-_]{20,}$~', $url)) {
            // Пользователь вставил один идентификатор без ссылки.
            $id = $url;
        } else {
            throw new \RuntimeException(
                'Не похоже на ссылку Google Таблиц. Скопируйте адрес из строки браузера.'
            );
        }

        // gid указывает конкретный лист: он приходит либо в якоре (#gid=),
        // либо в query (?gid=). Без него берём первый лист.
        $gid = '0';

        if (preg_match('~[#&?]gid=(\d+)~', $url, $matches)) {
            $gid = $matches[1];
        }

        return "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv&gid={$gid}";
    }
}
