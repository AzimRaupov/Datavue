<?php

namespace App\Http\Requests\DataSource;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {

        return true;
    }

    public function rules(): array
    {
        $rules = [

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

        $rules += [
            'data_file' => [
                'required',
                'file',
                'extensions:csv,txt,xlx,xls,xlsx,pdf,doc,docx,sql,db,sqlite,sqlite3',
            ],
        ];

        $extension = strtolower((string) $this->file('data_file')?->getClientOriginalExtension());

        if ($extension === 'sql') {
            $rules += [
                'type_id' => 'required|integer|exists:data_source_types,id',
            ];
        }

        return $rules;
    }
}
