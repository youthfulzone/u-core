<?php

namespace App\Http\Requests\Spv;

use Illuminate\Foundation\Http\FormRequest;

class MessagesListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'cif' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'days.integer' => 'Number of days must be a valid integer.',
            'days.min' => 'Number of days must be at least 1.',
            'days.max' => 'Number of days cannot exceed 365.',
            'cif.string' => 'CIF must be a valid string.',
            'cif.max' => 'CIF cannot exceed 20 characters.',
        ];
    }
}