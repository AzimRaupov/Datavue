<?php

namespace App\Helpers\DataSource\Handlers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleSheetDataHandler
{

    private const TIMEOUT_SECONDS = 60;

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

    public static function buildExportUrl(string $url): string
    {
        $url = trim($url);

        if (preg_match('~/spreadsheets/d/([a-zA-Z0-9-_]+)~', $url, $matches)) {
            $id = $matches[1];
        } elseif (preg_match('~^[a-zA-Z0-9-_]{20,}$~', $url)) {

            $id = $url;
        } else {
            throw new \RuntimeException(
                'Не похоже на ссылку Google Таблиц. Скопируйте адрес из строки браузера.'
            );
        }

        $gid = '0';

        if (preg_match('~[#&?]gid=(\d+)~', $url, $matches)) {
            $gid = $matches[1];
        }

        return "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv&gid={$gid}";
    }
}
