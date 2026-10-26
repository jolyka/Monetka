<?php

use App\Enums\RecommendationKind;

it('skips recommendation topics the workspace does not use', function () {
    $snapshot = [
        'expense' => 35000,
        'previous_expense' => 0,
        'income' => 0,
        'forecast' => 40000,
        'top_expense_categories' => [['name' => 'Кафе']],
        'limits' => [],
        'goals' => [],
        'debts' => [],
        'recurrences' => [],
        'accounts' => [['id' => 1, 'name' => 'Карта']],
    ];

    expect(RecommendationKind::choose($snapshot, null))->toBe(RecommendationKind::Spending)
        ->and(RecommendationKind::choose($snapshot, RecommendationKind::Spending))->toBe(RecommendationKind::Balances)
        ->and(RecommendationKind::choose($snapshot, RecommendationKind::Balances))->toBe(RecommendationKind::Forecast)
        ->and(RecommendationKind::choose($snapshot, RecommendationKind::Forecast))->toBe(RecommendationKind::Spending);
});

it('returns no recommendation topic when the snapshot has nothing to discuss', function () {
    $snapshot = [
        'expense' => 0,
        'previous_expense' => 0,
        'income' => 0,
        'forecast' => 0,
        'top_expense_categories' => [],
        'limits' => [],
        'goals' => [],
        'debts' => [],
        'recurrences' => [],
        'accounts' => [],
    ];

    expect(RecommendationKind::choose($snapshot, RecommendationKind::Debts))->toBeNull();
});
