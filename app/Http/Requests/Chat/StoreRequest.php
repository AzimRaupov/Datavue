<?php

namespace App\Http\Requests\Chat;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
public function rules(): array
{
    $rules = [
        'connection_type' => 'required|in:local,remote',
        'version' => 'nullable|string',
    ];

    if ($this->connection_type === 'remote') {
        $rules += [
            'type_id' => 'required|integer|exists:data_source_types,id',
            'host' => 'required|string',
            'port' => 'required|integer',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'nullable|string',
        ];
    } else {
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
    }

    return $rules;
}

}
