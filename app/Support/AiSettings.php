<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class AiSettings
{
    /**
     * @return array<string, array{label: string, description: string, base_url: string, model: string, requires_key: bool}>
     */
    public static function providers(): array
    {
        return [
            'openrouter' => [
                'label' => 'OpenRouter',
                'description' => 'Большой каталог моделей и бесплатный автороутер.',
                'base_url' => 'https://openrouter.ai/api/v1',
                'model' => 'openrouter/free',
                'requires_key' => true,
            ],
            'proxyapi' => [
                'label' => 'ProxyAPI',
                'description' => 'Единый API с оплатой в рублях и моделями разных вендоров.',
                'base_url' => 'https://api.proxyapi.ru/v1',
                'model' => 'openai/gpt-5-mini',
                'requires_key' => true,
            ],
            'vsegpt' => [
                'label' => 'VseGPT',
                'description' => 'OpenAI-совместимый агрегатор с большим каталогом моделей.',
                'base_url' => 'https://api.vsegpt.ru/v1',
                'model' => 'openai/gpt-4o-mini',
                'requires_key' => true,
            ],
            'ollama' => [
                'label' => 'Ollama',
                'description' => 'Локальный или свой сервер Ollama. Ключ обычно не нужен, имя модели — как в ollama list.',
                'base_url' => 'http://127.0.0.1:11434/v1',
                'model' => 'llama3.2',
                'requires_key' => false,
            ],
            'custom' => [
                'label' => 'Свой API',
                'description' => 'Любой шлюз с совместимым OpenAI Chat Completions API.',
                'base_url' => '',
                'model' => '',
                'requires_key' => true,
            ],
        ];
    }

    public static function requiresKey(string $provider): bool
    {
        return self::providers()[$provider]['requires_key'] ?? true;
    }

    public function __construct(private readonly ?string $path = null) {}

    /**
     * @return array{provider: string, api_key_configured: bool, api_key_hint: string|null, model: string, base_url: string, system_prompt: string, default_system_prompt: string}
     */
    public function current(): array
    {
        $key = config('services.ai.key');

        return [
            'provider' => (string) config('services.ai.provider', 'openrouter'),
            'api_key_configured' => is_string($key) && $key !== '',
            'api_key_hint' => $this->keyHint(is_string($key) ? $key : null),
            'model' => (string) config('services.ai.model', ''),
            'base_url' => (string) config('services.ai.base_url', ''),
            'system_prompt' => AssistantPrompt::editableTemplate(),
            'default_system_prompt' => AssistantPrompt::defaultTemplate(),
        ];
    }

    /**
     * @param  array{provider: string, api_key?: string|null, clear_api_key?: bool, model: string, base_url: string, system_prompt: string}  $settings
     */
    public function update(array $settings): void
    {
        $values = [
            'AI_PROVIDER' => $settings['provider'],
            'AI_MODEL' => $settings['model'],
            'AI_BASE_URL' => rtrim($settings['base_url'], '/'),
            'AI_SYSTEM_PROMPT' => $this->normalizeSystemPrompt($settings['system_prompt']),
        ];

        if (($settings['clear_api_key'] ?? false) === true) {
            $values['AI_API_KEY'] = '';
        } elseif (filled($settings['api_key'] ?? null)) {
            $values['AI_API_KEY'] = (string) $settings['api_key'];
        }

        $path = $this->path ?? base_path('.env');
        $contents = is_file($path) ? file_get_contents($path) : file_get_contents(base_path('.env.example'));

        if (! is_string($contents)) {
            throw new RuntimeException('Не удалось прочитать файл окружения.');
        }

        foreach ($values as $name => $value) {
            $contents = $this->upsertEnvValue($contents, $name, $value);
        }

        $directory = dirname($path);
        $temporary = tempnam($directory, '.env-ai-');

        if ($temporary === false || file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать файл окружения.');
        }

        if (is_file($path)) {
            chmod($temporary, fileperms($path) & 0777);
        }

        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось заменить файл окружения.');
        }

        config([
            'services.ai.provider' => $settings['provider'],
            'services.ai.model' => $settings['model'],
            'services.ai.base_url' => rtrim($settings['base_url'], '/'),
            'services.ai.system_prompt' => $values['AI_SYSTEM_PROMPT'],
            ...array_key_exists('AI_API_KEY', $values) ? ['services.ai.key' => $values['AI_API_KEY']] : [],
        ]);

        Artisan::call('config:clear');
    }

    private function normalizeSystemPrompt(string $prompt): string
    {
        $prompt = str_replace(["\r\n", "\r"], "\n", $prompt);

        if (trim($prompt) === trim(AssistantPrompt::defaultTemplate())) {
            return '';
        }

        return $prompt;
    }

    private function upsertEnvValue(string $contents, string $name, string $value): string
    {
        $line = $name.'='.$this->quote($value);
        $pattern = '/^'.preg_quote($name, '/').'\s*=(?:[^\r\n]*|"(?:[^"\\\\]|\\\\.)*")(?:\r?\n)?/m';

        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace($pattern, $line.PHP_EOL, $contents, 1);
        }

        return rtrim($contents).PHP_EOL.$line.PHP_EOL;
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    private function keyHint(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return mb_strlen($key) <= 8
            ? str_repeat('•', mb_strlen($key))
            : mb_substr($key, 0, 4).str_repeat('•', 8).mb_substr($key, -4);
    }
}
