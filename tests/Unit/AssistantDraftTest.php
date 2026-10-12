<?php

use App\Support\AssistantDraft;

function assistantDraftSnapshot(): array
{
    return [
        'today' => '2026-10-12',
        'accounts' => [
            ['id' => 1, 'name' => 'Карта', 'balance' => 100],
            ['id' => 2, 'name' => 'Наличные', 'balance' => 50],
        ],
        'expense_categories' => [
            ['id' => 10, 'name' => 'Кафе', 'emoji' => '☕️'],
        ],
        'income_categories' => [
            ['id' => 20, 'name' => 'Зарплата', 'emoji' => '💼'],
        ],
        'banks' => [
            ['id' => 3, 'name' => 'Сбер'],
        ],
    ];
}

it('builds an expense draft that still posts kopecks as a decimal', function () {
    $draft = AssistantDraft::from([
        'type' => 'expense',
        'amount_minor' => 35000,
        'account_name' => 'Карта',
        'category_name' => 'Кафе',
        'date' => '2026-09-28',
        'description' => 'кофе',
    ], assistantDraftSnapshot());

    expect($draft)
        ->not->toBeNull()
        ->and($draft['type'])->toBe('Расход')
        ->and($draft['amount'])->toBe(35000)
        ->and($draft['account_id'])->toBe(1)
        ->and($draft['category_id'])->toBe(10)
        ->and($draft['submit']['action'])->toBe('/transactions/expense')
        ->and($draft['submit']['fields']['amount'])->toBe('350.00');
});

it('matches an income draft to an income category', function () {
    $draft = AssistantDraft::from([
        'type' => 'income',
        'amount_minor' => 8000000,
        'account_name' => 'Карта',
        'category_name' => 'Зарплата',
        'description' => 'аванс',
    ], assistantDraftSnapshot());

    expect($draft['submit']['action'])->toBe('/transactions/income')
        ->and($draft['submit']['fields']['category_id'])->toBe('20')
        ->and($draft['category_id'])->toBe(20)
        ->and($draft['date'])->toBe('2026-10-12');
});

it('builds a transfer between two different accounts', function () {
    $draft = AssistantDraft::from([
        'type' => 'transfer',
        'amount_minor' => 100000,
        'account_name' => 'Карта',
        'counterparty_account_name' => 'Наличные',
        'description' => 'себе',
    ], assistantDraftSnapshot());

    expect($draft['submit']['action'])->toBe('/transactions/transfer')
        ->and($draft['submit']['fields']['account_id'])->toBe('1')
        ->and($draft['submit']['fields']['counterparty_account_id'])->toBe('2')
        ->and($draft['blocked'])->toBeNull();
});

it('refuses a transfer when the budget has one account', function () {
    $snapshot = assistantDraftSnapshot();
    $snapshot['accounts'] = [$snapshot['accounts'][0]];

    $draft = AssistantDraft::from([
        'type' => 'transfer',
        'amount_minor' => 1000,
        'account_name' => 'Карта',
        'counterparty_account_name' => 'Карта',
    ], $snapshot);

    expect($draft['submit'])->toBeNull()
        ->and($draft['blocked'])->toBe('Нужны два разных счёта из бюджета.');
});

it('builds drafts for a category, account, goal and debt', function () {
    $snapshot = assistantDraftSnapshot();

    $category = AssistantDraft::from([
        'type' => 'category',
        'kind' => 'income',
        'name' => 'Такси',
        'emoji' => '🚕',
        'color' => 'blue',
    ], $snapshot);

    $account = AssistantDraft::from([
        'type' => 'account',
        'name' => 'Кубышка',
        'account_type' => 'savings',
        'opening_balance_minor' => 0,
        'bank_name' => 'Сбер',
    ], $snapshot);

    $goal = AssistantDraft::from([
        'type' => 'goal',
        'name' => 'Отпуск',
        'amount_minor' => 15000000,
        'date' => '2026-12-01',
    ], $snapshot);

    $debt = AssistantDraft::from([
        'type' => 'debt',
        'direction' => 'i_owe',
        'name' => 'Вася',
        'amount_minor' => 300000,
        'description' => 'за обед',
    ], $snapshot);

    expect($category['submit']['action'])->toBe('/categories')
        ->and($category['submit']['fields']['kind'])->toBe('income')
        ->and($category['submit']['fields']['color'])->toBe('#1C6CFF')
        ->and($account['submit']['fields']['type'])->toBe('savings')
        ->and($account['submit']['fields']['bank_id'])->toBe('3')
        ->and($account['submit']['fields']['opening_balance'])->toBe('0.00')
        ->and($goal['submit']['action'])->toBe('/goals')
        ->and($goal['submit']['fields']['target_amount'])->toBe('150000.00')
        ->and($goal['submit']['fields']['target_date'])->toBe('2026-12-01')
        ->and($debt['submit']['fields']['direction'])->toBe('i_owe')
        ->and($debt['submit']['fields']['original_amount'])->toBe('3000.00');
});

it('builds a limit only for an existing expense category', function () {
    $known = AssistantDraft::from([
        'type' => 'limit',
        'category_name' => 'Кафе',
        'amount_minor' => 500000,
    ], assistantDraftSnapshot());

    $unknown = AssistantDraft::from([
        'type' => 'limit',
        'category_name' => 'Нет такой',
        'amount_minor' => 500000,
    ], assistantDraftSnapshot());

    expect($known['submit']['action'])->toBe('/limits')
        ->and($known['submit']['fields']['category_id'])->toBe('10')
        ->and($unknown['submit'])->toBeNull()
        ->and($unknown['blocked'])->not->toBeNull();
});

it('builds a monthly recurrence without creating it', function () {
    $draft = AssistantDraft::from([
        'type' => 'recurrence',
        'recurrence_type' => 'expense',
        'frequency' => 'ежемесячно',
        'amount_minor' => 49900,
        'account_name' => 'Карта',
        'category_name' => 'Кафе',
        'description' => 'музыка',
    ], assistantDraftSnapshot());

    expect($draft['type'])->toBe('Регулярный платёж')
        ->and($draft['submit']['action'])->toBe('/recurrences')
        ->and($draft['submit']['fields']['frequency'])->toBe('monthly')
        ->and($draft['submit']['fields']['amount'])->toBe('499.00')
        ->and($draft['submit']['fields']['category_id'])->toBe('10');
});

it('drops a draft the budget cannot save', function () {
    expect(AssistantDraft::from(['type' => 'category', 'name' => '   '], assistantDraftSnapshot()))->toBeNull()
        ->and(AssistantDraft::from(['type' => 'mystery'], assistantDraftSnapshot()))->toBeNull()
        ->and(AssistantDraft::from(null, assistantDraftSnapshot()))->toBeNull();
});
