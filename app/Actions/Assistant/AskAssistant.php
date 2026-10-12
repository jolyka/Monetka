<?php

namespace App\Actions\Assistant;

use App\Models\Workspace;
use App\Support\AiSettings;
use App\Support\AssistantPrompt;
use App\Support\AssistantReplyGuard;
use App\Support\AssistantSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AskAssistant
{
    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{text: string, draft: array{type: string, amount: int, account: string, account_id: int|null, category: string, category_id: int|null, date: string, description: string}|null}
     */
    public function execute(Workspace $workspace, string $message, array $history = []): array
    {
        $settings = $this->settings();
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
        try {
            $request = Http::baseUrl($settings['base_url'])
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout($settings['provider'] === 'ollama' ? 120 : 45);

            if ($settings['key'] !== '') {
                $request = $request->withToken($settings['key']);
            }

            if ($settings['provider'] === 'openrouter') {
                $request = $request->withHeaders([
                    'HTTP-Referer' => (string) config('app.url'),
                    'X-OpenRouter-Title' => 'Monetka',
                ]);
            }

            $payload = [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.2,
            ];

            if ($settings['provider'] === 'openrouter') {
                $payload['provider'] = ['allow_fallbacks' => true];
            }

            $response = $request->post('/chat/completions', $payload);

            return [$response, null];
        } catch (ConnectionException $exception) {
            Log::warning('AI provider connection failed', [
                'provider' => $settings['provider'],
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return [null, $exception->getMessage()];
        }
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
     * @return array{provider: string, key: string, model: string, base_url: string}
     */
    private function settings(): array
    {
        $provider = (string) config('services.ai.provider', 'openrouter');
        $aiKey = config('services.ai.key');
        $legacyKey = config('services.openrouter.key');

        if ($provider === 'openrouter' && (! is_string($aiKey) || $aiKey === '') && is_string($legacyKey) && $legacyKey !== '') {
            return [
                'provider' => 'openrouter',
                'key' => $legacyKey,
                'model' => (string) config('services.openrouter.model'),
                'base_url' => rtrim((string) config('services.openrouter.base_url'), '/'),
            ];
        }

        return [
            'provider' => (string) config('services.ai.provider', 'openrouter'),
            'key' => is_string($aiKey) ? $aiKey : '',
            'model' => (string) config('services.ai.model'),
            'base_url' => rtrim((string) config('services.ai.base_url'), '/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{text: string, draft: array{type: string, amount: int, account: string, account_id: int|null, category: string, category_id: int|null, date: string, description: string}|null}
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
     * @return array{type: string, amount: int, account: string, account_id: int|null, category: string, category_id: int|null, date: string, description: string}|null
     */
    private function draft(mixed $draft, array $snapshot): ?array
    {
        if (! is_array($draft)) {
            return null;
        }

        $type = (string) ($draft['type'] ?? 'expense');

        if (! in_array($type, ['expense', 'income', 'transfer'], true)) {
            return null;
        }

        $amount = (int) ($draft['amount_minor'] ?? 0);

        if ($amount < 1) {
            return null;
        }

        $account = $this->matchAccount((string) ($draft['account_name'] ?? ''), $snapshot['accounts'] ?? []);
        $category = $this->matchCategory((string) ($draft['category_name'] ?? ''), $snapshot['expense_categories'] ?? []);
        $date = (string) ($draft['date'] ?? now()->toDateString());

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $date = now()->toDateString();
        }

        $labels = [
            'expense' => 'Расход',
            'income' => 'Доход',
            'transfer' => 'Перевод',
        ];

        return [
            'type' => $labels[$type],
            'amount' => $amount,
            'account' => $account['name'] ?? (string) ($draft['account_name'] ?? ''),
            'account_id' => $account['id'] ?? null,
            'category' => trim(($category['emoji'] ?? '').' '.($category['name'] ?? (string) ($draft['category_name'] ?? ''))),
            'category_id' => $category['id'] ?? null,
            'date' => $date,
            'description' => mb_substr(trim((string) ($draft['description'] ?? '')), 0, 255),
        ];
    }

    /**
     * @param  list<array{id: int, name: string, balance: int}>  $accounts
     * @return array{id: int, name: string}|null
     */
    private function matchAccount(string $needle, array $accounts): ?array
    {
        $needle = mb_strtolower(trim($needle));

        if ($needle === '') {
            return $accounts[0] ?? null;
        }

        foreach ($accounts as $account) {
            if (mb_strtolower($account['name']) === $needle) {
                return $account;
            }
        }

        foreach ($accounts as $account) {
            if (str_contains(mb_strtolower($account['name']), $needle) || str_contains($needle, mb_strtolower($account['name']))) {
                return $account;
            }
        }

        return $accounts[0] ?? null;
    }

    /**
     * @param  list<array{id: int, name: string, emoji: string}>  $categories
     * @return array{id: int, name: string, emoji: string}|null
     */
    private function matchCategory(string $needle, array $categories): ?array
    {
        $needle = mb_strtolower(trim($needle));

        if ($needle === '' || $categories === []) {
            return $categories[0] ?? null;
        }

        foreach ($categories as $category) {
            if (mb_strtolower($category['name']) === $needle) {
                return $category;
            }
        }

        foreach ($categories as $category) {
            if (str_contains(mb_strtolower($category['name']), $needle) || str_contains($needle, mb_strtolower($category['name']))) {
                return $category;
            }
        }

        return $categories[0] ?? null;
    }
}
