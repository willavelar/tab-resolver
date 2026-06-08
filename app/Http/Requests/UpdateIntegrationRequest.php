<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'receipt_model' => ['required', 'string', 'max:255'],
            'audio_model' => ['required', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
