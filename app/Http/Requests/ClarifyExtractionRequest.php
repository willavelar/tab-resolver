<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ClarifyExtractionRequest extends FormRequest
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
            'answers' => ['required', 'array', 'min:1'],
            'answers.*' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Every pending question must be answered, otherwise the clarification round
     * would be consumed with unanswered questions silently dropped.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $answers = $this->input('answers', []);

            foreach (data_get($this->route('session'), 'clarifications.pending', []) as $question) {
                if (blank(data_get($answers, $question['id']))) {
                    $validator->errors()->add(
                        "answers.{$question['id']}",
                        'Responda todas as perguntas para continuar.',
                    );
                }
            }
        });
    }
}
