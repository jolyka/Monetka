<?php

namespace App\Http\Requests\Assistant;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AskAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->attributes->get('workspace');

        return $workspace instanceof Workspace
            && $this->user()?->can('view', $workspace);
    }

    /**
     * @return array<string, list<string>|ValidationRule>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:1000'],
            'conversation_id' => ['nullable', 'integer', 'exists:assistant_conversations,id'],
            'history' => ['nullable', 'array', 'max:10'],
            'history.*.role' => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Напишите вопрос.',
            'message.max' => 'Слишком длинный вопрос.',
        ];
    }
}
