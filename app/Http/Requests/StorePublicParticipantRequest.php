<?php

namespace App\Http\Requests;

use App\Models\Session;
use App\Models\SessionParticipant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePublicParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->uniqueNameRule()],
            'text' => ['nullable', 'required_without:audio', 'string', 'max:256'],
            'audio' => ['nullable', 'required_without:text', 'file', 'mimetypes:audio/webm,audio/ogg,audio/mp4', 'max:10240'],
            'audio_duration' => ['nullable', 'integer', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe seu nome.',
            'text.required_without' => 'Envie um texto ou um áudio.',
            'audio.required_without' => 'Envie um áudio ou um texto.',
            'text.max' => 'O texto pode ter no máximo 256 caracteres.',
            'audio.mimetypes' => 'O áudio deve ser uma gravação válida.',
            'audio_duration.max' => 'O áudio deve ter menos de 2 minutos.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * Case-insensitive uniqueness of the name within this bill session.
     * Enforced in PHP so MySQL (CI collation) and SQLite (CS) behave the same.
     */
    protected function uniqueNameRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $session = Session::where('public_token', $this->route('token'))->first();

            if ($session === null) {
                return;
            }

            $exists = SessionParticipant::query()
                ->where('bill_session_id', $session->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                ->exists();

            if ($exists) {
                $fail('Já existe alguém com esse nome nesta conta.');
            }
        };
    }
}
