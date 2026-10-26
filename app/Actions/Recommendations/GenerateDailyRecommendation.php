<?php

namespace App\Actions\Recommendations;

use App\Enums\RecommendationKind;
use App\Models\BudgetRecommendation;
use App\Models\Workspace;
use App\Support\AiChat;
use App\Support\AiSettings;
use App\Support\AssistantSnapshot;
use App\Support\RecommendationPrompt;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

class GenerateDailyRecommendation
{
    public string $failure = '';

    public function execute(Workspace $workspace, ?CarbonInterface $now = null): ?BudgetRecommendation
    {
        $this->failure = '';
        $now ??= now();
        $day = $now->toDateString();
        $existing = $workspace->budgetRecommendations()->whereDate('recommended_on', $day)->first();

        if ($existing instanceof BudgetRecommendation) {
            return $existing;
        }

        $settings = AiSettings::connection();

        if ($settings['base_url'] === '' || $settings['model'] === '') {
            $this->failure = 'не заданы модель или адрес API';

            return null;
        }

        if ($settings['key'] === '' && AiSettings::requiresKey($settings['provider'])) {
            $this->failure = 'нет ключа AI';

            return null;
        }

        $snapshot = AssistantSnapshot::for($workspace, $now);
        $previous = $workspace->budgetRecommendations()
            ->where('recommended_on', '<', $day)
            ->orderByDesc('recommended_on')
            ->orderByDesc('id')
            ->first();
        $kind = RecommendationKind::choose($snapshot, $previous?->kind);

        if ($kind === null) {
            $this->failure = 'в бюджете нет данных для совета';

            return null;
        }
        $messages = [
            ['role' => 'system', 'content' => RecommendationPrompt::system($snapshot, $kind)],
            ['role' => 'user', 'content' => 'Дай рекомендацию на сегодня.'],
        ];

        $text = $this->reply($settings, $messages);

        if ($text === null) {
            return null;
        }

        return $workspace->budgetRecommendations()->create([
            'kind' => $kind,
            'body' => $text,
            'recommended_on' => $day,
        ]);
    }

    /**
     * @param  array{provider: string, key: string, model: string, base_url: string}  $settings
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function reply(array $settings, array $messages): ?string
    {
        $model = $settings['model'];
        $options = ['timeout' => 180, 'max_tokens' => 8192];
        [$response, $error] = AiChat::send($settings, $model, $messages, $options);

        if ($settings['provider'] === 'openrouter' && $response !== null && ! $response->successful() && in_array($response->status(), [404, 429], true) && $model !== 'openrouter/free') {
            Log::warning('OpenRouter primary model failed, falling back', [
                'model' => $model,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            [$response, $error] = AiChat::send($settings, 'openrouter/free', $messages, $options);
        }

        if ($response === null || ! $response->successful()) {
            $detail = $error ?? $response?->json('error.message') ?? $response?->body();
            $this->failure = $this->failureText($response?->status(), $detail);

            Log::warning('Daily recommendation request failed', [
                'provider' => $settings['provider'],
                'status' => $response?->status(),
                'error' => $detail,
            ]);

            return null;
        }

        $text = $this->contentText($response->json('choices.0.message.content'));

        if ($text === '') {
            $this->failure = 'модель вернула пустой текст';

            return null;
        }

        return $text;
    }

    private function failureText(?int $status, mixed $detail): string
    {
        $snippet = trim(preg_replace('/\s+/', ' ', mb_substr((string) $detail, 0, 300)) ?? '');
        $prefix = $status === null ? 'нет ответа от провайдера' : "провайдер ответил HTTP {$status}";

        return $snippet === '' ? $prefix : "{$prefix}: {$snippet}";
    }

    private function contentText(mixed $content): string
    {
        if (is_string($content)) {
            return $this->plainText($content);
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part) && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return $this->plainText(implode("\n", $parts));
    }

    private function plainText(string $content): string
    {
        $text = trim($content);

        if (preg_match('/^```(?:json|text)?\s*(.*?)\s*```$/s', $text, $matches) === 1) {
            $text = trim($matches[1]);
        }

        $decoded = json_decode($text, true);

        if (is_array($decoded) && is_string($decoded['text'] ?? null)) {
            $text = trim($decoded['text']);
        }

        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));

        return mb_substr($text, 0, 2000);
    }
}
