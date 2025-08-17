<?php

namespace App\Http\Requests\Spv;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\AnafSpvService;

class DocumentRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $spvService = app(AnafSpvService::class);
        $documentTypes = array_keys($spvService->getAvailableDocumentTypes());
        $incomeReasons = $spvService->getIncomeStatementReasons();

        return [
            'tip' => ['required', 'string', 'in:' . implode(',', $documentTypes)],
            'cui' => ['required', 'string', 'max:20'],
            'an' => ['nullable', 'integer', 'min:2000', 'max:' . (date('Y') + 1)],
            'luna' => ['nullable', 'integer', 'min:1', 'max:12'],
            'motiv' => ['nullable', 'string', 'in:' . implode(',', $incomeReasons)],
            'numar_inregistrare' => ['nullable', 'string', 'max:100'],
            'cui_pui' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'tip.required' => 'Document type is required.',
            'tip.in' => 'Invalid document type selected.',
            'cui.required' => 'CUI/CNP is required.',
            'cui.string' => 'CUI/CNP must be a valid string.',
            'cui.max' => 'CUI/CNP cannot exceed 20 characters.',
            'an.integer' => 'Year must be a valid integer.',
            'an.min' => 'Year must be at least 2000.',
            'an.max' => 'Year cannot be in the future.',
            'luna.integer' => 'Month must be a valid integer.',
            'luna.min' => 'Month must be at least 1.',
            'luna.max' => 'Month cannot exceed 12.',
            'motiv.in' => 'Invalid reason selected.',
            'numar_inregistrare.string' => 'Registration number must be a valid string.',
            'numar_inregistrare.max' => 'Registration number cannot exceed 100 characters.',
            'cui_pui.string' => 'PUI CUI must be a valid string.',
            'cui_pui.max' => 'PUI CUI cannot exceed 20 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('an') && empty($this->an)) {
            $this->merge(['an' => null]);
        }
        
        if ($this->has('luna') && empty($this->luna)) {
            $this->merge(['luna' => null]);
        }
    }
}