<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class AiChat
{
    /**
     * @param  array{provider: string, key: string, model: string, base_url: string}  $settings
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array{timeout?: int, max_tokens?: int}  $options
     * @return array{0: Response|null, 1: string|null}
     */
    public static function send(array $settings, string $model, array $messages, array $options = []): array
    {
        try {
            $timeout = $options['timeout'] ?? ($settings['provider'] === 'ollama' ? 120 : 45);
            $request = Http::acceptJson()
                ->connectTimeout(10)
                ->timeout($timeout);

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

            if (isset($options['max_tokens'])) {
                $payload['max_tokens'] = $options['max_tokens'];
            }

            if ($settings['provider'] === 'openrouter') {
                $payload['provider'] = ['allow_fallbacks' => true];
            }

            return [$request->post(rtrim($settings['base_url'], '/'), $payload), null];
        } catch (ConnectionException $exception) {
            Log::warning('AI provider connection failed', [
                'provider' => $settings['provider'],
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return [null, $exception->getMessage()];
        }
    }
}
