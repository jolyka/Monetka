<?php

namespace App\Http\Requests\Admin;

use App\Support\AiSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in(array_keys(AiSettings::providers()))],
            'api_key' => ['nullable', 'string', 'max:500'],
            'clear_api_key' => ['sometimes', 'boolean'],
            'model' => ['required', 'string', 'max:200', 'regex:/^[^\s]+$/'],
            'base_url' => ['required', 'url:http,https', 'max:500'],
            'system_prompt' => ['required', 'string', 'max:20000'],
        ];
    }

    /**
     * @return array{provider: string, api_key: string|null, clear_api_key: bool, model: string, base_url: string, system_prompt: string}
     */
    public function settings(): array
    {
        $data = $this->validated();

        return [
            'provider' => (string) $data['provider'],
            'api_key' => isset($data['api_key']) ? (string) $data['api_key'] : null,
            'clear_api_key' => (bool) ($data['clear_api_key'] ?? false),
            'model' => (string) $data['model'],
            'base_url' => (string) $data['base_url'],
            'system_prompt' => (string) $data['system_prompt'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.required' => 'Выберите провайдера.',
            'model.required' => 'Укажите ID модели.',
            'model.regex' => 'ID модели не должен содержать пробелы.',
            'base_url.required' => 'Укажите полный адрес эндпоинта.',
            'base_url.url' => 'Адрес эндпоинта должен начинаться с http:// или https://.',
            'system_prompt.required' => 'Укажите системный промпт.',
            'system_prompt.max' => 'Промпт слишком длинный.',
        ];
    }
}
