<?php

namespace App\Helpers\DataHandlers;

use App\Helpers\Ai\AIService;
use App\Models\AiChatMessage;
use App\Models\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\File;

class TableDataHandler
{
    public $pathData;
    public $message;
    public $uploadData;

    public function __construct($message_id, $upload_id)
    {
        $this->message = AiChatMessage::query()->findOrFail($message_id);
        $this->uploadData = UploadedFile::query()->findOrFail($upload_id);

        $this->pathData = storage_path(
            'app/company/' . $this->uploadData->file_path
        );

        $data = match ($this->uploadData->file_type) {
            'xlsx' => $this->xlsx(),
            'xls'  => $this->xls(),
            'csv'  => $this->csv(),
            default => [],
        };

        $jsonPath = storage_path(
            'app/company/j/' .
            pathinfo($this->uploadData->file_path, PATHINFO_FILENAME) .
            '.json'
        );

        $this->saveJson($data, $jsonPath);
        $this->saveScheme();
    }
    /**
     * Получить заголовки из первой строки Excel/CSV
     */
    protected function getHeaders(): array
    {
        $sheets = Excel::toArray([], $this->pathData);

        if (empty($sheets) || empty($sheets[0])) {
            return [];
        }

        return array_values(
            array_filter(
                $sheets[0][0] ?? [],
                fn ($value) => !is_null($value) && $value !== ''
            )
        );
    }
    protected function saveScheme(?int $upload_id = null)
    {
        $upload_id ??= $this->uploadData->id;

        $data=$this->to_json(3);
        $data=json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $prompt = <<<PROMPT
You are a data schema analyst. Analyze the provided data sample and generate a JSON Schema that accurately describes its structure.

## Input Data (first rows of the dataset):
{$data}

## Task:
Generate a JSON Schema for this dataset following these strict rules:

1. The schema must be a JSON object with these exact top-level keys:
   - "title" — short name for the schema (in the same language as the column headers)
   - "description" — one sentence describing what the dataset represents
   - "type" — always "array"
   - "items" — object describing a single row

2. Inside "items.properties", create one entry per column with:
   - "type" — infer correctly: "integer", "number", "string", "boolean"
   - "format" — add "date" for date strings (YYYY-MM-DD), omit otherwise
   - "description" — one sentence explaining what this field represents (same language as headers)

3. "items.required" must list ALL column names.

4. Column names must be taken EXACTLY as they appear in the header row (row 0).

5. Return ONLY valid JSON. No markdown, no backticks, no explanation — just the raw JSON object.
PROMPT;


        $res = (new AIService(responseFormat: 'json'))->ask($prompt);
        dd($res);

    }

    protected function to_json(?int $line = null, ?string $outputPath = null): array
    {
        $sheets = Excel::toArray([], $this->pathData);

        if (empty($sheets) || empty($sheets[0])) {
            return [];
        }

        $rows = $sheets[0];

        // лимит строк (если задан)
        if ($line !== null) {
            $rows = array_slice($rows, 0, $line);
        }

        $result = [];

        foreach ($rows as $row) {
            $row = array_values($row);

            // пропускаем полностью пустые строки
            if (count(array_filter($row, fn($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }

            $result[] = $row;
        }

        // ===== СОХРАНЕНИЕ В JSON =====
        if ($outputPath) {
            file_put_contents(
                $outputPath,
                json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        }

        return $result;
    }

    protected function saveJson(array $data, string $path): bool
    {
        File::ensureDirectoryExists(dirname($path));

        return File::put(
                $path,
                json_encode(
                    $data,
                    JSON_UNESCAPED_UNICODE |
                    JSON_PRETTY_PRINT |
                    JSON_INVALID_UTF8_SUBSTITUTE
                )
            ) !== false;
    }
    public function xlsx(): array
    {
        return $this->to_json();
    }

    public function xls(): array
    {
        return $this->getHeaders();
    }

    public function csv(): array
    {
        return $this->to_json();
    }
}
