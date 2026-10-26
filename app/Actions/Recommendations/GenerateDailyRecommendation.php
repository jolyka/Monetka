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
    public function execute(Workspace $workspace, ?CarbonInterface $now = null): ?BudgetRecommendation
    {
        $now ??= now();
        $day = $now->toDateString();
        $existing = $workspace->budgetRecommendations()->whereDate('recommended_on', $day)->first();

        if ($existing instanceof BudgetRecommendation) {
            return $existing;
        }

        $settings = AiSettings::connection();

        if ($settings['base_url'] === '' || $settings['model'] === '') {
            return null;
        }

        if ($settings['key'] === '' && AiSettings::requiresKey($settings['provider'])) {
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
        [$response] = AiChat::send($settings, $model, $messages);

        if ($settings['provider'] === 'openrouter' && $response !== null && ! $response->successful() && in_array($response->status(), [404, 429], true) && $model !== 'openrouter/free') {
            Log::warning('OpenRouter primary model failed, falling back', [
                'model' => $model,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            [$response] = AiChat::send($settings, 'openrouter/free', $messages);
        }

        if ($response === null || ! $response->successful()) {
            Log::warning('Daily recommendation request failed', [
                'provider' => $settings['provider'],
                'status' => $response?->status(),
                'error' => $response?->json('error.message') ?? $response?->body(),
            ]);

            return null;
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content)) {
            return null;
        }

        $text = $this->plainText($content);

        return $text === '' ? null : $text;
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
