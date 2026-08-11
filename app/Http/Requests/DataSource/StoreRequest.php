<?php

namespace App\Http\Requests\DataSource;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ проверяется middleware 'permission:manage data sources'.
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            // google_sheet — отдельный вид подключения: не файл с диска
            // пользователя и не прямое соединение с базой.
            'connection_type' => 'required|in:local,remote,google_sheet',
            'name' => 'nullable|string|max:255',
            'version' => 'nullable|string|max:20',
        ];

        if ($this->connection_type === 'google_sheet') {
            return $rules + [
                'sheet_url' => 'required|string|max:1000',
            ];
        }

        if ($this->connection_type === 'remote') {
            return $rules + [
                'type_id' => 'required|integer|exists:data_source_types,id',
                'host' => 'required|string',
                'port' => 'required|integer',
                'database' => 'required|string',
                'username' => 'required|string',
                'password' => 'nullable|string',
            ];
        }

        /*
        | Готовые базы SQLite (.db/.sqlite/.sqlite3) проверяем по расширению:
        | у них нет устойчивого MIME-типа, и правило mimes их отбраковывало.
        | Содержимое подтверждается отдельно — SqliteDataHandler читает
        | сигнатуру в начале файла и отказывается работать с подделкой.
        */
        $rules += [
            'data_file' => [
                'required',
                'file',
                'extensions:csv,txt,xlx,xls,xlsx,pdf,doc,docx,sql,db,sqlite,sqlite3',
            ],
        ];

        $extension = strtolower((string) $this->file('data_file')?->getClientOriginalExtension());

        // Для дампа .sql тип источника выбирает пользователь: по файлу не понять,
        // в какую СУБД его импортировать.
        if ($extension === 'sql') {
            $rules += [
                'type_id' => 'required|integer|exists:data_source_types,id',
            ];
        }

        return $rules;
    }
}
