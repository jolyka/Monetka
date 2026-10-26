<?php

namespace App\Enums;

enum RecommendationKind: string
{
    case Spending = 'spending';
    case Limits = 'limits';
    case Goals = 'goals';
    case Debts = 'debts';
    case Recurrences = 'recurrences';
    case Balances = 'balances';
    case Forecast = 'forecast';

    /**
     * Следующая тема, по которой в снимке есть что сказать.
     * Пустые долги, лимиты, цели и шаблоны пропускаются.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function choose(array $snapshot, ?self $previous): ?self
    {
        $order = self::cases();
        $start = 0;

        if ($previous !== null) {
            $previousIndex = array_search($previous, $order, true);
            $start = $previousIndex === false ? 0 : $previousIndex + 1;
        }

        foreach (range(0, count($order) - 1) as $offset) {
            $kind = $order[($start + $offset) % count($order)];

            if ($kind->hasData($snapshot)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function hasData(array $snapshot): bool
    {
        return match ($this) {
            self::Spending => self::amount($snapshot, 'expense') > 0
                || self::amount($snapshot, 'previous_expense') > 0
                || self::rows($snapshot, 'top_expense_categories') !== [],
            self::Limits => self::rows($snapshot, 'limits') !== [],
            self::Goals => self::rows($snapshot, 'goals') !== [],
            self::Debts => self::rows($snapshot, 'debts') !== [],
            self::Recurrences => self::rows($snapshot, 'recurrences') !== [],
            self::Balances => self::rows($snapshot, 'accounts') !== [],
            self::Forecast => self::amount($snapshot, 'income') > 0
                || self::amount($snapshot, 'expense') > 0
                || self::amount($snapshot, 'previous_expense') > 0
                || self::amount($snapshot, 'forecast') > 0,
        };
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function amount(array $snapshot, string $key): int
    {
        $value = $snapshot[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<mixed>
     */
    private static function rows(array $snapshot, string $key): array
    {
        $rows = $snapshot[$key] ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }

    public function label(): string
    {
        return match ($this) {
            self::Spending => 'Расходы',
            self::Limits => 'Лимиты',
            self::Goals => 'Цели',
            self::Debts => 'Долги',
            self::Recurrences => 'Шаблоны',
            self::Balances => 'Остатки',
            self::Forecast => 'Прогноз',
        };
    }

    public function instruction(): string
    {
        return match ($this) {
            self::Spending => 'Тема — расходы месяца. Опирайся на expense, previous_expense и top_expense_categories. Скажи, куда ушли деньги и как это соотносится с прошлым месяцем.',
            self::Limits => 'Тема — лимиты. Опирайся на limits. Если лимит превышен или почти выбран, скажи об этом.',
            self::Goals => 'Тема — цели. Опирайся на goals. Скажи, как идёт накопление.',
            self::Debts => 'Тема — долги. Опирайся на debts. Напомни, кто кому должен и какой остаток.',
            self::Recurrences => 'Тема — регулярные платежи. Опирайся на recurrences. Напомни ближайшие шаблоны.',
            self::Balances => 'Тема — остатки на счетах. Опирайся на accounts и total_visible. Скажи, как распределены деньги.',
            self::Forecast => 'Тема — ход месяца. Опирайся на income, expense, previous_expense и forecast. Скажи, как траты идут относительно прогноза. Если forecast пустой, опирайся на income и expense.',
        };
    }
}
