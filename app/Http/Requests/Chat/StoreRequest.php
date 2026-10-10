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
            'workspace_id' => 'required_without:data_source_id|nullable|integer',
            'data_source_id' => 'required_without:workspace_id|nullable|integer',
            'title' => 'nullable|string|max:255',
        ];
    }
}
