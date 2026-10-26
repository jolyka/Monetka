<?php

use App\Actions\Accounts\CreateAccount;
use App\Actions\Goals\CreateGoal;
use App\Actions\Recommendations\GenerateDailyRecommendation;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\RecommendationKind;
use App\Models\BudgetRecommendation;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
});

it('stores one text recommendation and does not ask the model again the same day', function () {
    $this->travelTo('2026-10-27 08:00:00');

    config([
        'services.ai.provider' => 'openrouter',
        'services.ai.key' => 'test-key',
        'services.ai.base_url' => 'https://openrouter.ai/api/v1',
        'services.ai.model' => 'openrouter/free',
        'services.openrouter.key' => null,
    ]);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => ['content' => 'На карте лежит 20.00.'],
            ]],
        ]),
    ]);

    $user = User::factory()->create();
    $workspace = (new CreateWorkspace)->execute($user, 'Семья', 'RUB');
    (new CreateAccount)->execute($workspace, $user, ['name' => 'Карта', 'type' => 'checking', 'bank_id' => null, 'user_id' => null, 'opening_balance' => 2000]);
    $generate = app(GenerateDailyRecommendation::class);

    $recommendation = $generate->execute($workspace);

    expect($recommendation)->not->toBeNull()
        ->and($recommendation->kind)->toBe(RecommendationKind::Balances)
        ->and($recommendation->body)->toBe('На карте лежит 20.00.')
        ->and($recommendation->recommended_on->toDateString())->toBe('2026-10-27');

    $generate->execute($workspace);

    expect(BudgetRecommendation::query()->count())->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_contains($request['messages'][0]['content'], 'Тема — остатки')
        && str_contains($request['messages'][0]['content'], 'Не предлагай черновик')
        && ! str_contains($request['messages'][0]['content'], 'Тема — долги')
        && ! str_contains($request['messages'][0]['content'], 'Тема — лимиты'));
});

it('does not ask about debts or limits when the workspace does not use them', function () {
    $this->travelTo('2026-10-27 08:00:00');

    config([
        'services.ai.provider' => 'openrouter',
        'services.ai.key' => 'test-key',
        'services.ai.base_url' => 'https://openrouter.ai/api/v1',
        'services.ai.model' => 'openrouter/free',
        'services.openrouter.key' => null,
    ]);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => ['content' => 'До цели «Отпуск» ещё далеко.'],
            ]],
        ]),
    ]);

    $user = User::factory()->create();
    $workspace = (new CreateWorkspace)->execute($user, 'Семья', 'RUB');
    (new CreateAccount)->execute($workspace, $user, ['name' => 'Карта', 'type' => 'checking', 'bank_id' => null, 'user_id' => null, 'opening_balance' => 2000]);
    (new CreateGoal)->execute($workspace, ['name' => 'Отпуск', 'target_amount' => 50000, 'target_date' => null, 'notes' => null]);
    $workspace->budgetRecommendations()->create([
        'kind' => RecommendationKind::Spending,
        'body' => 'Вчера речь шла о расходах.',
        'recommended_on' => '2026-10-26',
    ]);

    $recommendation = app(GenerateDailyRecommendation::class)->execute($workspace);

    expect($recommendation?->kind)->toBe(RecommendationKind::Goals);
    Http::assertSent(fn ($request): bool => str_contains($request['messages'][0]['content'], 'Тема — цели')
        && ! str_contains($request['messages'][0]['content'], 'Тема — долги')
        && ! str_contains($request['messages'][0]['content'], 'Тема — лимиты'));
});

it('does not call the model when the workspace has nothing to discuss', function () {
    config([
        'services.ai.provider' => 'openrouter',
        'services.ai.key' => 'test-key',
        'services.ai.base_url' => 'https://openrouter.ai/api/v1',
        'services.ai.model' => 'openrouter/free',
        'services.openrouter.key' => null,
    ]);

    Http::fake();

    $user = User::factory()->create();
    $workspace = (new CreateWorkspace)->execute($user, 'Семья', 'RUB');

    expect(app(GenerateDailyRecommendation::class)->execute($workspace))->toBeNull()
        ->and(BudgetRecommendation::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not call the model when the provider key is missing', function () {
    config([
        'services.ai.provider' => 'openrouter',
        'services.ai.key' => '',
        'services.ai.base_url' => 'https://openrouter.ai/api/v1',
        'services.ai.model' => 'openrouter/free',
        'services.openrouter.key' => null,
    ]);

    Http::fake();

    $user = User::factory()->create();
    $workspace = (new CreateWorkspace)->execute($user, 'Семья', 'RUB');

    expect(app(GenerateDailyRecommendation::class)->execute($workspace))->toBeNull();
    Http::assertNothingSent();
});

it('generates a recommendation per workspace and drops advice older than three weeks', function () {
    $this->travelTo('2026-10-27 08:00:00');

    config([
        'services.ai.provider' => 'openrouter',
        'services.ai.key' => 'test-key',
        'services.ai.base_url' => 'https://openrouter.ai/api/v1',
        'services.ai.model' => 'openrouter/free',
        'services.openrouter.key' => null,
    ]);

    Http::fake([
        'https://openrouter.ai/api/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => ['content' => 'Свежий совет.'],
            ]],
        ]),
    ]);

    $first = User::factory()->create();
    $firstWorkspace = (new CreateWorkspace)->execute($first, 'Семья', 'RUB');
    (new CreateAccount)->execute($firstWorkspace, $first, ['name' => 'Карта', 'type' => 'checking', 'bank_id' => null, 'user_id' => null, 'opening_balance' => 2000]);
    $second = User::factory()->create();
    $secondWorkspace = (new CreateWorkspace)->execute($second, 'Личное', 'RUB');
    (new CreateAccount)->execute($secondWorkspace, $second, ['name' => 'Наличные', 'type' => 'checking', 'bank_id' => null, 'user_id' => null, 'opening_balance' => 500]);

    $firstWorkspace->budgetRecommendations()->create([
        'kind' => RecommendationKind::Spending,
        'body' => 'Старый совет.',
        'recommended_on' => '2026-10-05',
    ]);

    $this->artisan('recommendations:generate')->assertSuccessful();

    expect(BudgetRecommendation::query()->where('body', 'Свежий совет.')->count())->toBe(2)
        ->and(BudgetRecommendation::query()->where('body', 'Старый совет.')->exists())->toBeFalse();
});

it('shows this week recommendations on the overview of that workspace', function () {
    $user = User::factory()->create();
    $workspace = (new CreateWorkspace)->execute($user, 'Семья', 'RUB');
    $workspace->budgetRecommendations()->create([
        'kind' => RecommendationKind::Balances,
        'body' => 'Посмотрите кафе.',
        'recommended_on' => now()->toDateString(),
    ]);

    $other = User::factory()->create();
    $otherWorkspace = (new CreateWorkspace)->execute($other, 'Чужой', 'RUB');
    $otherWorkspace->budgetRecommendations()->create([
        'kind' => RecommendationKind::Balances,
        'body' => 'Чужой совет.',
        'recommended_on' => now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->get(route('overview'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Overview')
            ->has('recommendations', 1)
            ->where('recommendations.0.body', 'Посмотрите кафе.')
            ->where('recommendations.0.label', 'Остатки'));
});
