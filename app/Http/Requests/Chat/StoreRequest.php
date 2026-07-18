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
        $rules += [
            'data_file' => 'required|file|mimes:csv,txt,xlx,xls,xlsx,pdf,doc,docx,sql',
        ];

        if ($this->file('data_file')?->getClientOriginalExtension() === 'sql') {
            $rules += [
                'type_id' => 'required|integer|exists:data_source_types,id',
            ];
        }
    }

    return $rules;
}

}
