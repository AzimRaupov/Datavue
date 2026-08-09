<?php

namespace App\Http\Requests\Chat;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Создание чата.
 *
 * Раньше здесь описывался целый источник данных — файл, хост, порт, логин:
 * чат и источник создавались одним запросом. Теперь источник подключается
 * отдельно, и чату нужен только его id.
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ проверяется middleware 'permission:create chats'.
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Принадлежность источника компании проверяется в контроллере:
            // правило exists подтвердило бы существование чужого источника.
            'data_source_id' => 'required|integer',
            'title' => 'nullable|string|max:255',
        ];
    }
}
