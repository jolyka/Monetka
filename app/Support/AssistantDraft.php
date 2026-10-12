<?php

namespace App\Support;

final class AssistantDraft
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    public static function from(mixed $draft, array $snapshot): ?array
    {
        if (! is_array($draft)) {
            return null;
        }

        $type = (string) ($draft['type'] ?? 'expense');

        return match ($type) {
            'expense', 'income', 'transfer' => self::movement($type, $draft, $snapshot),
            'category' => self::category($draft),
            'account' => self::account($draft, $snapshot),
            'goal' => self::goal($draft),
            'debt' => self::debt($draft),
            'limit' => self::limit($draft, $snapshot),
            'recurrence' => self::recurrence($draft, $snapshot),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private static function movement(string $type, array $draft, array $snapshot): ?array
    {
        $amount = self::minor($draft['amount_minor'] ?? 0);

        if ($amount < 1) {
            return null;
        }

        $accounts = self::accounts($snapshot);
        $account = self::matchAccount((string) ($draft['account_name'] ?? ''), $accounts, true);
        $date = self::date($draft['date'] ?? null, $snapshot);
        $description = self::clip((string) ($draft['description'] ?? ''), 255);
        $labels = [
            'expense' => 'Расход',
            'income' => 'Доход',
            'transfer' => 'Перевод',
        ];
        $decimal = self::decimal($amount);
        $blocked = null;
        $fields = null;
        $action = null;
        $categoryLabel = '—';
        $categoryId = null;

        if ($type === 'transfer') {
            $counterparty = self::matchAccount((string) ($draft['counterparty_account_name'] ?? ''), $accounts, false);

            if ($counterparty === null || ($account !== null && $counterparty['id'] === $account['id'])) {
                $counterparty = self::otherAccount($accounts, $account['id'] ?? null);
            }

            $categoryLabel = $counterparty['name'] ?? '—';

            if ($account === null || $counterparty === null || $account['id'] === $counterparty['id']) {
                $blocked = 'Нужны два разных счёта из бюджета.';
            } else {
                $action = '/transactions/transfer';
                $fields = [
                    'account_id' => (string) $account['id'],
                    'counterparty_account_id' => (string) $counterparty['id'],
                    'amount' => $decimal,
                    'occurred_on' => $date,
                    'description' => $description,
                ];
            }

            $lines = [
                ['label' => 'Тип', 'value' => 'Перевод'],
                ['label' => 'Сумма', 'value' => $decimal],
                ['label' => 'Откуда', 'value' => $account['name'] ?? '—'],
                ['label' => 'Куда', 'value' => $counterparty['name'] ?? '—'],
                ['label' => 'Дата', 'value' => $date],
                ['label' => 'Описание', 'value' => $description !== '' ? $description : '—'],
            ];
        } else {
            $categories = $type === 'income'
                ? self::categories($snapshot, 'income_categories')
                : self::categories($snapshot, 'expense_categories');
            $category = self::matchCategory((string) ($draft['category_name'] ?? ''), $categories, true);
            $categoryId = $category['id'] ?? null;
            $categoryLabel = trim(($category['emoji'] ?? '').' '.($category['name'] ?? (string) ($draft['category_name'] ?? '')));

            if ($account === null) {
                $blocked = 'В бюджете нет счёта для этой операции.';
            } elseif ($category === null) {
                $blocked = $type === 'income'
                    ? 'Нет категории дохода. Сначала создайте её черновиком.'
                    : 'Нет категории расхода. Сначала создайте её черновиком.';
            } else {
                $action = $type === 'income' ? '/transactions/income' : '/transactions/expense';
                $fields = [
                    'account_id' => (string) $account['id'],
                    'category_id' => (string) $category['id'],
                    'amount' => $decimal,
                    'occurred_on' => $date,
                    'description' => $description,
                ];
            }

            $lines = [
                ['label' => 'Тип', 'value' => $labels[$type]],
                ['label' => 'Сумма', 'value' => $decimal],
                ['label' => 'Счёт', 'value' => $account['name'] ?? '—'],
                ['label' => 'Категория', 'value' => $categoryLabel !== '' ? $categoryLabel : '—'],
                ['label' => 'Дата', 'value' => $date],
                ['label' => 'Описание', 'value' => $description !== '' ? $description : '—'],
            ];
        }

        return self::card($labels[$type], $lines, $action, $fields, $blocked, [
            'amount' => $amount,
            'account' => $account['name'] ?? (string) ($draft['account_name'] ?? ''),
            'account_id' => $account['id'] ?? null,
            'category' => $categoryLabel,
            'category_id' => $categoryId,
            'date' => $date,
            'description' => $description,
        ]);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private static function category(array $draft): ?array
    {
        $name = self::clip((string) ($draft['name'] ?? ''), 80);

        if ($name === '') {
            return null;
        }

        $kind = self::kind((string) ($draft['kind'] ?? 'expense'));
        $emoji = self::clip((string) ($draft['emoji'] ?? ''), 16);
        $emoji = $emoji !== '' ? $emoji : '🏷️';
        $color = (string) ($draft['color'] ?? '');
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : '#1C6CFF';
        $kindLabel = $kind === 'income' ? 'Доход' : 'Расход';

        return self::card('Категория', [
            ['label' => 'Тип', 'value' => 'Категория'],
            ['label' => 'Название', 'value' => $name],
            ['label' => 'Вид', 'value' => $kindLabel],
            ['label' => 'Значок', 'value' => $emoji],
        ], '/categories', [
            'name' => $name,
            'kind' => $kind,
            'emoji' => $emoji,
            'color' => $color,
        ], null);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private static function account(array $draft, array $snapshot): ?array
    {
        $name = self::clip((string) ($draft['name'] ?? ''), 80);

        if ($name === '') {
            return null;
        }

        $type = self::accountType((string) ($draft['account_type'] ?? 'checking'));
        $opening = self::minor($draft['opening_balance_minor'] ?? 0);
        $bank = self::matchNamed((string) ($draft['bank_name'] ?? ''), self::rows($snapshot, 'banks'), false);
        $fields = [
            'name' => $name,
            'type' => $type,
            'opening_balance' => self::decimal($opening),
        ];

        if ($bank !== null) {
            $fields['bank_id'] = (string) $bank['id'];
        }

        return self::card('Счёт', [
            ['label' => 'Тип', 'value' => 'Счёт'],
            ['label' => 'Название', 'value' => $name],
            ['label' => 'Вид', 'value' => $type === 'savings' ? 'Накопительный' : 'Расчётный'],
            ['label' => 'Стартовый баланс', 'value' => self::decimal($opening)],
            ['label' => 'Банк', 'value' => $bank['name'] ?? 'Без банка'],
        ], '/accounts', $fields, null);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private static function goal(array $draft): ?array
    {
        $name = self::clip((string) ($draft['name'] ?? ''), 80);
        $amount = self::minor($draft['amount_minor'] ?? 0);

        if ($name === '' || $amount < 1) {
            return null;
        }

        $date = self::optionalDate($draft['date'] ?? null);
        $notes = self::clip((string) ($draft['description'] ?? ''), 2000);
        $fields = [
            'name' => $name,
            'target_amount' => self::decimal($amount),
            'notes' => $notes,
        ];

        if ($date !== null) {
            $fields['target_date'] = $date;
        }

        return self::card('Цель', [
            ['label' => 'Тип', 'value' => 'Цель'],
            ['label' => 'Название', 'value' => $name],
            ['label' => 'Сумма', 'value' => self::decimal($amount)],
            ['label' => 'Дата', 'value' => $date ?? '—'],
            ['label' => 'Заметка', 'value' => $notes !== '' ? $notes : '—'],
        ], '/goals', $fields, null);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private static function debt(array $draft): ?array
    {
        $name = self::clip((string) ($draft['name'] ?? ''), 80);
        $amount = self::minor($draft['amount_minor'] ?? 0);

        if ($name === '' || $amount < 1) {
            return null;
        }

        $direction = self::debtDirection((string) ($draft['direction'] ?? 'they_owe'));
        $notes = self::clip((string) ($draft['description'] ?? ''), 2000);

        return self::card('Долг', [
            ['label' => 'Тип', 'value' => 'Долг'],
            ['label' => 'Кто', 'value' => $name],
            ['label' => 'Направление', 'value' => $direction === 'i_owe' ? 'Я должен' : 'Мне должны'],
            ['label' => 'Сумма', 'value' => self::decimal($amount)],
            ['label' => 'Заметка', 'value' => $notes !== '' ? $notes : '—'],
        ], '/debts', [
            'counterparty_name' => $name,
            'direction' => $direction,
            'original_amount' => self::decimal($amount),
            'notes' => $notes,
        ], null);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private static function limit(array $draft, array $snapshot): ?array
    {
        $amount = self::minor($draft['amount_minor'] ?? 0);

        if ($amount < 1) {
            return null;
        }

        $category = self::matchCategory((string) ($draft['category_name'] ?? ''), self::categories($snapshot, 'expense_categories'), false);
        $label = trim(($category['emoji'] ?? '').' '.($category['name'] ?? (string) ($draft['category_name'] ?? '')));
        $blocked = $category === null ? 'Лимит можно поставить только на существующую категорию расходов.' : null;

        return self::card('Лимит', [
            ['label' => 'Тип', 'value' => 'Лимит'],
            ['label' => 'Категория', 'value' => $label !== '' ? $label : '—'],
            ['label' => 'Сумма', 'value' => self::decimal($amount)],
        ], $category === null ? null : '/limits', $category === null ? null : [
            'category_id' => (string) $category['id'],
            'amount' => self::decimal($amount),
        ], $blocked);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private static function recurrence(array $draft, array $snapshot): ?array
    {
        $amount = self::minor($draft['amount_minor'] ?? 0);

        if ($amount < 1) {
            return null;
        }

        $type = self::recurrenceType((string) ($draft['recurrence_type'] ?? $draft['kind'] ?? 'expense'));
        $frequency = self::frequency((string) ($draft['frequency'] ?? 'monthly'));
        $accounts = self::accounts($snapshot);
        $account = self::matchAccount((string) ($draft['account_name'] ?? ''), $accounts, true);
        $date = self::date($draft['date'] ?? null, $snapshot);
        $description = self::clip((string) ($draft['description'] ?? ''), 255);
        $categories = $type === 'income'
            ? self::categories($snapshot, 'income_categories')
            : self::categories($snapshot, 'expense_categories');
        $category = $type === 'transfer'
            ? null
            : self::matchCategory((string) ($draft['category_name'] ?? ''), $categories, true);
        $counterparty = null;

        if ($type === 'transfer') {
            $counterparty = self::matchAccount((string) ($draft['counterparty_account_name'] ?? ''), $accounts, false)
                ?? self::otherAccount($accounts, $account['id'] ?? null);
        }

        $blocked = null;

        if ($account === null) {
            $blocked = 'В бюджете нет счёта для регулярного платежа.';
        } elseif ($type === 'transfer' && ($counterparty === null || $counterparty['id'] === $account['id'])) {
            $blocked = 'Для регулярного перевода нужны два разных счёта.';
        } elseif ($type !== 'transfer' && $category === null) {
            $blocked = 'Нет подходящей категории для регулярного платежа.';
        }

        $fields = null;

        if ($blocked === null && $account !== null) {
            $fields = [
                'type' => $type,
                'account_id' => (string) $account['id'],
                'amount' => self::decimal($amount),
                'description' => $description,
                'frequency' => $frequency,
                'next_occurred_on' => $date,
            ];

            if ($type === 'transfer' && $counterparty !== null) {
                $fields['counterparty_account_id'] = (string) $counterparty['id'];
            }

            if ($type !== 'transfer' && $category !== null) {
                $fields['category_id'] = (string) $category['id'];
            }
        }

        $typeLabels = [
            'expense' => 'Расход',
            'income' => 'Доход',
            'transfer' => 'Перевод',
        ];
        $frequencyLabels = [
            'weekly' => 'Каждую неделю',
            'monthly' => 'Каждый месяц',
            'yearly' => 'Каждый год',
        ];

        return self::card('Регулярный платёж', [
            ['label' => 'Тип', 'value' => 'Регулярный платёж'],
            ['label' => 'Операция', 'value' => $typeLabels[$type]],
            ['label' => 'Сумма', 'value' => self::decimal($amount)],
            ['label' => 'Как часто', 'value' => $frequencyLabels[$frequency]],
            ['label' => 'Счёт', 'value' => $account['name'] ?? '—'],
            ['label' => 'Следующая дата', 'value' => $date],
            ['label' => 'Описание', 'value' => $description !== '' ? $description : '—'],
        ], $fields === null ? null : '/recurrences', $fields, $blocked);
    }

    /**
     * @param  list<array{label: string, value: string}>  $lines
     * @param  array<string, string>|null  $fields
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private static function card(string $type, array $lines, ?string $action, ?array $fields, ?string $blocked, array $legacy = []): array
    {
        return [
            'type' => $type,
            'lines' => $lines,
            'submit' => $action !== null && $fields !== null ? [
                'action' => $action,
                'fields' => $fields,
            ] : null,
            'blocked' => $blocked,
            ...$legacy,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{id: int, name: string}>
     */
    private static function accounts(array $snapshot): array
    {
        return self::rows($snapshot, 'accounts');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{id: int, name: string, emoji: string}>
     */
    private static function categories(array $snapshot, string $key): array
    {
        $rows = $snapshot[$key] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $categories = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id'], $row['name'])) {
                continue;
            }

            $categories[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'emoji' => (string) ($row['emoji'] ?? ''),
            ];
        }

        return $categories;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{id: int, name: string}>
     */
    private static function rows(array $snapshot, string $key): array
    {
        $rows = $snapshot[$key] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id'], $row['name'])) {
                continue;
            }

            $items[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
            ];
        }

        return $items;
    }

    /**
     * @param  list<array{id: int, name: string}>  $accounts
     * @return array{id: int, name: string}|null
     */
    private static function matchAccount(string $needle, array $accounts, bool $fallback): ?array
    {
        return self::matchNamed($needle, $accounts, $fallback);
    }

    /**
     * @param  list<array{id: int, name: string}>  $accounts
     * @return array{id: int, name: string}|null
     */
    private static function otherAccount(array $accounts, ?int $except): ?array
    {
        foreach ($accounts as $account) {
            if ($account['id'] !== $except) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @param  list<array{id: int, name: string, emoji?: string}>  $rows
     * @return array{id: int, name: string, emoji?: string}|null
     */
    private static function matchNamed(string $needle, array $rows, bool $fallback): ?array
    {
        $needle = mb_strtolower(trim($needle));

        if ($needle === '') {
            return $fallback ? ($rows[0] ?? null) : null;
        }

        foreach ($rows as $row) {
            if (mb_strtolower($row['name']) === $needle) {
                return $row;
            }
        }

        foreach ($rows as $row) {
            $name = mb_strtolower($row['name']);

            if (str_contains($name, $needle) || str_contains($needle, $name)) {
                return $row;
            }
        }

        return $fallback ? ($rows[0] ?? null) : null;
    }

    /**
     * @param  list<array{id: int, name: string, emoji: string}>  $categories
     * @return array{id: int, name: string, emoji: string}|null
     */
    private static function matchCategory(string $needle, array $categories, bool $fallback): ?array
    {
        $match = self::matchNamed($needle, $categories, $fallback);

        if ($match === null) {
            return null;
        }

        return [
            'id' => $match['id'],
            'name' => $match['name'],
            'emoji' => (string) ($match['emoji'] ?? ''),
        ];
    }

    private static function kind(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return in_array($value, ['income', 'доход'], true) ? 'income' : 'expense';
    }

    private static function accountType(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return in_array($value, ['savings', 'накопительный', 'накопления', 'вклад'], true) ? 'savings' : 'checking';
    }

    private static function debtDirection(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if (in_array($value, ['i_owe', 'я должен', 'я должна'], true) || str_contains($value, 'я дол')) {
            return 'i_owe';
        }

        return 'they_owe';
    }

    private static function recurrenceType(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if (in_array($value, ['income', 'доход'], true)) {
            return 'income';
        }

        if (in_array($value, ['transfer', 'перевод'], true)) {
            return 'transfer';
        }

        return 'expense';
    }

    private static function frequency(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if (in_array($value, ['weekly', 'неделя', 'еженедельно'], true) || str_contains($value, 'недел')) {
            return 'weekly';
        }

        if (in_array($value, ['yearly', 'год', 'ежегодно'], true) || str_contains($value, 'год')) {
            return 'yearly';
        }

        return 'monthly';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function date(mixed $value, array $snapshot): string
    {
        return self::optionalDate($value) ?? self::optionalDate($snapshot['today'] ?? null) ?? now()->toDateString();
    }

    private static function optionalDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private static function minor(mixed $value): int
    {
        return (int) $value;
    }

    private static function decimal(int $minor): string
    {
        $negative = $minor < 0;
        $absolute = abs($minor);

        return ($negative ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function clip(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max);
    }
}
