<?php

namespace App\Http\Requests\Chat;

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
        return [

            'data_source_id' => 'required|integer',
            'title' => 'nullable|string|max:255',
        ];
    }
}
