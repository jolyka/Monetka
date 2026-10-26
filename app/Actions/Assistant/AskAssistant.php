<?php

namespace App\Actions\Assistant;

use App\Models\Workspace;
use App\Support\AiChat;
use App\Support\AiSettings;
use App\Support\AssistantDraft;
use App\Support\AssistantPrompt;
use App\Support\AssistantReplyGuard;
use App\Support\AssistantSnapshot;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AskAssistant
{
    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{text: string, draft: array<string, mixed>|null}
     */
    public function execute(Workspace $workspace, string $message, array $history = []): array
    {
        $settings = AiSettings::connection();
        $key = $settings['key'];

        if ($key === '' && AiSettings::requiresKey($settings['provider'])) {
            throw ValidationException::withMessages([
                'message' => 'Нет API-ключа AI-провайдера. Журнал и чек работают, чат недоступен.',
            ]);
        }

        if (AssistantReplyGuard::shouldSkipModel($message)) {
            return AssistantReplyGuard::refusal();
        }

        $snapshot = AssistantSnapshot::for($workspace);
        $messages = [
            ['role' => 'system', 'content' => AssistantPrompt::system($snapshot)],
        ];

        foreach (array_slice($history, -10) as $turn) {
            $role = $turn['role'] === 'assistant' ? 'assistant' : 'user';
            $content = $turn['content'];

            if ($role === 'user') {
                $content = AssistantPrompt::wrapUserMessage($content);
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        $messages[] = ['role' => 'user', 'content' => AssistantPrompt::wrapUserMessage($message)];

        $preferred = $settings['model'];
        [$response, $connectionError] = $this->complete($settings, $preferred, $messages);

        if ($settings['provider'] === 'openrouter' && $response !== null && ! $response->successful() && in_array($response->status(), [404, 429], true) && $preferred !== 'openrouter/free') {
            Log::warning('OpenRouter primary model failed, falling back', [
                'model' => $preferred,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            [$fallback] = $this->complete($settings, 'openrouter/free', $messages);

            if ($fallback !== null && $fallback->successful()) {
                $response = $fallback;
            }
        }

        if ($response === null) {
            throw ValidationException::withMessages([
                'message' => $this->connectionMessage($connectionError, $settings['provider']),
            ]);
        }

        if (! $response->successful()) {
            Log::warning('AI provider request failed', [
                'provider' => $settings['provider'],
                'status' => $response->status(),
                'error' => $response->json('error.message') ?? $response->body(),
            ]);

            throw ValidationException::withMessages([
                'message' => $this->userMessage($response, $settings['provider']),
            ]);
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw ValidationException::withMessages([
                'message' => 'Модель вернула пустой ответ.',
            ]);
        }

        return $this->parse($content, $snapshot);
    }

    /**
     * @param  array{provider: string, key: string, model: string, base_url: string}  $settings
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{0: Response|null, 1: string|null}
     */
    private function complete(array $settings, string $model, array $messages): array
    {
        return AiChat::send($settings, $model, $messages);
    }

    private function connectionMessage(?string $error, string $provider): string
    {
        $timeout = is_string($error) && (str_contains($error, 'timed out') || str_contains($error, 'cURL error 28'));

        if ($provider === 'openrouter') {
            return $timeout
                ? 'OpenRouter не успел ответить. Бесплатная очередь зависла — подождите минуту и спросите снова.'
                : 'Нет связи с OpenRouter. Проверьте интернет и попробуйте ещё раз.';
        }

        if ($provider === 'ollama') {
            return $timeout
                ? 'Ollama не успел ответить. Модель может ещё загружаться — подождите и спросите снова.'
                : 'Нет связи с сервером Ollama. Проверьте, что он запущен, и адрес API в AI-настройках.';
        }

        return $timeout
            ? 'AI-провайдер не успел ответить. Подождите минуту и спросите снова.'
            : 'Нет связи с AI-провайдером. Проверьте адрес API и попробуйте ещё раз.';
    }

    private function userMessage(Response $response, string $provider): string
    {
        $name = match ($provider) {
            'openrouter' => 'OpenRouter',
            'proxyapi' => 'ProxyAPI',
            'vsegpt' => 'VseGPT',
            'ollama' => 'Ollama',
            default => 'AI-провайдер',
        };

        if ($provider === 'ollama') {
            return match ($response->status()) {
                404 => 'Модель не найдена на сервере Ollama. Проверьте имя в AI-настройках.',
                default => 'Ollama не ответил. Проверьте, что сервер запущен и модель скачана.',
            };
        }

        if ($provider === 'openrouter') {
            return match ($response->status()) {
                401, 403 => 'Ключ OpenRouter не принят. Проверьте его в AI-настройках.',
                404 => 'Эта модель сейчас недоступна. Выберите другую в AI-настройках.',
                429 => 'Бесплатная очередь OpenRouter перегружена. Подождите минуту и спросите снова.',
                default => 'Модель не ответила. Попробуйте ещё раз через минуту.',
            };
        }

        return match ($response->status()) {
            401, 403 => "Ключ {$name} не принят. Проверьте его в AI-настройках.",
            404 => 'Модель или API-адрес не найдены. Проверьте AI-настройки.',
            429 => "{$name} ограничил частоту запросов. Подождите минуту и спросите снова.",
            default => 'Модель не ответила. Попробуйте ещё раз через минуту.',
        };
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{text: string, draft: array<string, mixed>|null}
     */
    private function parse(string $content, array $snapshot): array
    {
        $payload = $this->decode($content);
        $text = trim((string) ($payload['text'] ?? ''));

        if ($text === '') {
            throw ValidationException::withMessages([
                'message' => 'Модель ответила без текста.',
            ]);
        }

        if (AssistantReplyGuard::looksUnsafeOutbound($text) || AssistantReplyGuard::looksUnsafeOutbound($content)) {
            return AssistantReplyGuard::refusal();
        }

        return [
            'text' => $text,
            'draft' => $this->draft($payload['draft'] ?? null, $snapshot),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $content): array
    {
        $raw = trim($content);

        if (preg_match('/\{.*\}/s', $raw, $matches) === 1) {
            $raw = $matches[0];
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return ['text' => $content, 'draft' => null];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function draft(mixed $draft, array $snapshot): ?array
    {
        return AssistantDraft::from($draft, $snapshot);
    }
}
